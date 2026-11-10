<?php

declare(strict_types=1);

namespace Tests\Feature\KamanSupport;

use App\Models\KamanSupport\SupportPrinterIssue;
use App\Models\User;
use Database\Seeders\WorkspaceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kitchen print failures from the POS become printer issues that follow one
 * order on one kitchen from the first failure to the reprint.
 */
class PrinterIssuesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'testing-kaman-support-key';

    private const INCIDENT = 'kitchen|ord-77|k-grill';

    /**
     * @param  list<array<string, mixed>>  $logs
     */
    private function heartbeat(array $logs, string $deviceId = 'pos-kitchen-1'): void
    {
        $this->postJson('/api/kaman-support/v1/devices/session', [
            'deviceId' => $deviceId,
            'restaurant' => 'kaman',
            'restaurantName' => 'Kaman Grill',
            'cashierName' => 'Ali',
            'appVersion' => '1.4.0+41',
            'hostname' => 'FRONT-POS',
            'appState' => 'open',
            'loggedIn' => true,
            'logs' => $logs,
        ], ['X-Kaman-Support-Key' => self::KEY])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function failedLog(string $id, string $at, array $context = []): array
    {
        return [
            'id' => $id,
            'level' => 'error',
            'source' => 'printer',
            'message' => 'kitchen ticket #77 not printed on Grill (10.0.0.8:9100): paper_out',
            'at' => $at,
            'context' => array_replace_recursive([
                'event' => 'kitchen_print_failed',
                'incident' => self::INCIDENT,
                'attempt' => 'send',
                'fault' => 'paper_out',
                'class' => 'printer',
                'mayHavePrinted' => false,
                'reason' => 'Printer 10.0.0.8:9100 is out of paper. Load paper and retry',
                'error' => 'printerPaperOut',
                'station' => 'Grill',
                'stationId' => 'k-grill',
                'printer' => ['ip' => '10.0.0.8', 'port' => 9100, 'mac' => 'aa:bb:cc:dd:ee:01', 'link' => 'network', 'name' => 'Grill printer'],
                'order' => ['id' => 'ord-77', 'number' => 77, 'type' => 'dineIn', 'channel' => 'haat', 'table' => 'T4', 'external' => 'H-441'],
                'meals' => [
                    ['line' => 'l1', 'item' => 'it-1', 'name' => 'Burger', 'qty' => 2, 'note' => 'no onion'],
                ],
                'printedOn' => ['Drinks'],
                'cashier' => 'Ali',
                'elapsedMs' => 3400,
                'at' => $at,
            ], $context),
        ];
    }

    public function test_a_kitchen_print_failure_is_stored_as_an_open_printer_issue(): void
    {
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);

        $issue = SupportPrinterIssue::query()->sole();
        $this->assertSame('open', $issue->status);
        $this->assertSame('kaman', $issue->restaurant);
        $this->assertSame(self::INCIDENT, $issue->incident);
        $this->assertSame('paper_out', $issue->fault);
        $this->assertSame('printer', $issue->fault_class);
        $this->assertSame('Grill', $issue->station);
        $this->assertSame('10.0.0.8', $issue->printer_ip);
        $this->assertSame(9100, $issue->printer_port);
        $this->assertSame('aa:bb:cc:dd:ee:01', $issue->printer_mac);
        $this->assertSame(77, $issue->order_number);
        $this->assertSame('T4', $issue->order_table);
        $this->assertSame('dineIn', $issue->order_type);
        $this->assertSame('haat', $issue->order_channel);
        $this->assertSame('H-441', $issue->external_order_id);
        $this->assertSame('Grill printer', $issue->printer_name);
        $this->assertSame(['Drinks'], $issue->printed_on);
        $this->assertSame('Ali', $issue->cashier_name);
        $this->assertSame('1.4.0+41', $issue->app_version);
        $this->assertSame(1, $issue->attempts);
        $this->assertFalse($issue->may_have_printed);
        $this->assertSame('Burger', $issue->meals[0]['name']);
        $this->assertSame(2, $issue->meals[0]['qty']);
        $this->assertSame('2026-11-10 18:00:00', $issue->first_failed_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['Drinks'], $issue->attempts_log[0]['printedOn']);
        $this->assertSame(3400, $issue->attempts_log[0]['elapsedMs']);
    }

    public function test_retries_add_to_the_same_issue_and_a_resent_log_counts_once(): void
    {
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);
        $this->heartbeat([
            $this->failedLog('log-1', '2026-11-10T18:00:00Z'),
            $this->failedLog('log-2', '2026-11-10T18:01:00Z', [
                'attempt' => 'retry',
                'fault' => 'cut_mid_print',
                'mayHavePrinted' => true,
            ]),
        ]);

        $issue = SupportPrinterIssue::query()->sole();
        $this->assertSame(2, $issue->attempts);
        $this->assertSame('cut_mid_print', $issue->fault);
        $this->assertTrue($issue->may_have_printed);
        $this->assertCount(2, $issue->attempts_log);
        $this->assertSame('2026-11-10 18:00:00', $issue->first_failed_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 18:01:00', $issue->last_failed_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_reprint_resolves_the_issue_and_a_new_failure_reopens_it(): void
    {
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);
        $this->heartbeat([[
            'id' => 'log-2',
            'level' => 'warn',
            'source' => 'printer',
            'message' => 'kitchen ticket #77 printed on Grill after a failure',
            'at' => '2026-11-10T18:04:00Z',
            'context' => [
                'event' => 'kitchen_print_resolved',
                'incident' => self::INCIDENT,
                'resolution' => 'printed',
                'attempt' => 'retry',
                'at' => '2026-11-10T18:04:00Z',
            ],
        ]]);

        $issue = SupportPrinterIssue::query()->sole();
        $this->assertSame('resolved', $issue->status);
        $this->assertSame('printed', $issue->resolution);
        $this->assertSame('retry', $issue->resolved_by);
        $this->assertSame(4, $issue->minutesToResolve());

        $this->heartbeat([$this->failedLog('log-3', '2026-11-10T19:00:00Z', ['fault' => 'timeout'])]);

        $issue->refresh();
        $this->assertSame('open', $issue->status);
        $this->assertNull($issue->resolved_at);
        $this->assertSame(2, $issue->attempts);
        $this->assertSame('reopened', $issue->attempts_log[2]['event']);
    }

    public function test_closing_the_alert_without_retry_is_counted(): void
    {
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);
        $this->heartbeat([[
            'id' => 'log-2',
            'level' => 'warn',
            'source' => 'printer',
            'message' => 'kitchen failure alert closed without retry: Grill',
            'at' => '2026-11-10T18:00:30Z',
            'context' => [
                'event' => 'kitchen_print_dismissed',
                'kitchens' => ['Grill'],
                'incidents' => [self::INCIDENT],
                'at' => '2026-11-10T18:00:30Z',
            ],
        ]]);

        $issue = SupportPrinterIssue::query()->sole();
        $this->assertSame('open', $issue->status);
        $this->assertSame(1, $issue->dismissed_count);
    }

    public function test_meals_that_match_no_kitchen_keep_the_configured_printers(): void
    {
        $this->heartbeat([[
            'id' => 'log-none',
            'level' => 'error',
            'source' => 'printer',
            'message' => 'kitchen ticket #77 not printed: no kitchen printer takes these meals',
            'at' => '2026-11-10T18:00:00Z',
            'context' => [
                'event' => 'kitchen_print_failed',
                'incident' => 'kitchen|ord-77|*',
                'attempt' => 'send',
                'fault' => 'no_printer',
                'class' => 'app',
                'mayHavePrinted' => false,
                'reason' => 'No configured kitchen printer matches the kitchen types of these meals',
                'station' => '',
                'configuredPrinters' => ['Grill', 'Drinks'],
                'order' => ['id' => 'ord-77', 'number' => 77, 'type' => 'takeaway', 'channel' => 'cashier'],
                'meals' => [
                    ['line' => 'l1', 'name' => 'Burger', 'qty' => 1],
                ],
                'cashier' => 'Ali',
                'at' => '2026-11-10T18:00:00Z',
            ],
        ]]);

        $issue = SupportPrinterIssue::query()->sole();
        $this->assertSame('no_printer', $issue->fault);
        $this->assertSame('app', $issue->fault_class);
        $this->assertNull($issue->station);
        $this->assertNull($issue->printer_ip);
        $this->assertSame('takeaway', $issue->order_type);
        $this->assertSame(['Grill', 'Drinks'], $issue->configured_printers);
        $this->assertNull($issue->printed_on);

        $this->seed(WorkspaceUserSeeder::class);
        $this->actingAs(User::query()->where('email', 'yamen@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.printer-issues', ['q' => 'Burger']))
            ->assertOk()
            ->assertSee('لا مطبخ يطابق هذه الوجبات', false)
            ->assertSee('Grill, Drinks', false);
    }

    public function test_ordinary_printer_logs_do_not_create_issues(): void
    {
        $this->heartbeat([[
            'id' => 'log-1',
            'level' => 'warn',
            'source' => 'printer',
            'message' => 'Grill 10.0.0.8 link down class=lan',
            'context' => ['event' => 'link', 'class' => 'lan'],
        ]]);

        $this->assertSame(0, SupportPrinterIssue::query()->count());
    }

    public function test_the_console_lists_printer_issues_and_limited_viewers_cannot_open_it(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);

        $this->actingAs(User::query()->where('email', 'yamen@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.printer-issues'))
            ->assertOk()
            ->assertSee('data-printer-issue-fault="paper_out"', false)
            ->assertSee('Grill')
            ->assertSee('#77')
            ->assertSee('10.0.0.8:9100')
            ->assertSee('Burger')
            ->assertSee('داخل المطعم')
            ->assertSee('H-441')
            ->assertSee('هأت')
            ->assertSee('Grill printer')
            ->assertSee('طُبع أيضاً على');

        $this->actingAs(User::query()->where('email', 'yamen@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.printer-issues', ['status' => 'resolved']))
            ->assertOk()
            ->assertSee('data-printer-issues-empty', false);

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->get(route('kaman-support.printer-issues'))
            ->assertForbidden();
    }

    public function test_the_phone_app_reads_printer_issues_as_json(): void
    {
        $this->seed(WorkspaceUserSeeder::class);
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);
        $yamen = User::query()->where('email', 'yamen@kaman.rest')->firstOrFail();

        $this->actingAs($yamen)
            ->getJson(route('kaman-support.printer-issues.feed'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('counts.open', 1)
            ->assertJsonPath('filters.status', 'open')
            ->assertJsonPath('issues.0.fault', 'paper_out')
            ->assertJsonPath('issues.0.open', true)
            ->assertJsonPath('issues.0.station', 'Grill')
            ->assertJsonPath('issues.0.orderNumber', 77)
            ->assertJsonPath('issues.0.printerAddress', '10.0.0.8:9100')
            ->assertJsonPath('issues.0.printerName', 'Grill printer')
            ->assertJsonPath('issues.0.external', 'H-441')
            ->assertJsonPath('issues.0.printedOn.0', 'Drinks')
            ->assertJsonPath('issues.0.meals.0.name', 'Burger')
            ->assertJsonPath('issues.0.meals.0.qty', 2)
            ->assertJsonPath('issues.0.timeline.0.event', 'failed')
            ->assertJsonPath('issues.0.timeline.0.seconds', 3.4)
            ->assertJsonStructure(['labels' => ['title', 'open_count', 'status_open'], 'faults' => [['value', 'label']], 'choices']);

        $this->actingAs($yamen)
            ->getJson(route('kaman-support.printer-issues.feed', ['status' => 'resolved']))
            ->assertOk()
            ->assertJsonCount(0, 'issues');

        $this->actingAs(User::query()->where('email', 'burhan@kaman.rest')->firstOrFail())
            ->getJson(route('kaman-support.printer-issues.feed'))
            ->assertForbidden();
    }

    public function test_prune_keeps_open_issues_and_drops_old_resolved_ones(): void
    {
        $this->heartbeat([$this->failedLog('log-1', '2026-11-10T18:00:00Z')]);
        $this->heartbeat([$this->failedLog('log-2', '2026-11-10T18:00:00Z', [
            'incident' => 'kitchen|ord-old|k-grill',
        ])]);
        SupportPrinterIssue::query()->where('incident', 'kitchen|ord-old|k-grill')->update([
            'status' => 'resolved',
            'resolved_at' => now()->subDays(400),
        ]);

        $this->artisan('kaman-support:prune-logs')->assertSuccessful();

        $this->assertSame([self::INCIDENT], SupportPrinterIssue::query()->pluck('incident')->all());
    }
}
