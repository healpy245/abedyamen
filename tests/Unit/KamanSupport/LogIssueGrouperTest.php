<?php

declare(strict_types=1);

namespace Tests\Unit\KamanSupport;

use App\Services\KamanSupport\LogIssueGrouper;
use PHPUnit\Framework\TestCase;

class LogIssueGrouperTest extends TestCase
{
    public function test_groups_same_failure_across_ids_and_keeps_every_record(): void
    {
        $grouper = new LogIssueGrouper;
        $first = 'API POST https://gcp.kaman.rest/api/cashier/print/01a0c484-7849-70a9-83ae-6958b1d5f8b7 body={"order":1} -> 400 No kitchen items to print request_id=abc123456';
        $second = 'API POST https://gcp.kaman.rest/api/cashier/print/9ef35062-c5fe-4a6d-9ad8-12f8b31d9034 body={"order":2} -> 400 No kitchen items to print request_id=zzz987654 at 2026-10-15T15:41:02Z';

        $issues = $grouper->group([
            $this->row(2, $first, ['device' => 'Cashier', 'deviceId' => 'pos-1', 'at' => '2026-10-15 15:41:02', 'clock' => '15:41:02']),
            $this->row(1, $second, ['device' => 'Kitchen', 'deviceId' => 'pos-2', 'at' => '2026-10-15 15:10:00', 'clock' => '15:10:00']),
        ]);

        $this->assertCount(1, $issues);
        $this->assertSame('No kitchen items to print', $issues[0]['title']);
        $this->assertSame(2, $issues[0]['count']);
        $this->assertSame(['Cashier', 'Kitchen'], $issues[0]['devices']);
        $this->assertSame('2026-10-15 15:10:00', $issues[0]['firstAt']);
        $this->assertSame('2026-10-15 15:41:02', $issues[0]['lastAt']);
        $this->assertSame('POST', $issues[0]['method']);
        $this->assertSame(400, (int) $issues[0]['code']);
        $this->assertSame([$first, $second], array_column($issues[0]['occurrences'], 'message'));
        $this->assertSame([2, 1], array_column($issues[0]['occurrences'], 'id'));
    }

    public function test_does_not_merge_different_messages_that_share_http_400(): void
    {
        $grouper = new LogIssueGrouper;
        $issues = $grouper->group([
            $this->row(1, 'API POST https://gcp.kaman.rest/api/cashier/orders/01a0c484-7849-70a9-83ae-6958b1d5f8b7 -> 400 No kitchen items to print'),
            $this->row(2, 'API POST https://gcp.kaman.rest/api/cashier/orders/9ef35062-c5fe-4a6d-9ad8-12f8b31d9034 -> 400 Invalid coupon'),
        ]);

        $this->assertCount(2, $issues);
        $titles = array_column($issues, 'title');
        sort($titles);
        $this->assertSame(['Invalid coupon', 'No kitchen items to print'], $titles);
    }

    public function test_keeps_restaurants_and_endpoints_apart(): void
    {
        $grouper = new LogIssueGrouper;
        $issues = $grouper->group([
            $this->row(1, 'API GET https://gcp.kaman.rest/api/sync -> 400 Host lookup failed'),
            $this->row(2, 'API GET https://gcp.kaman.rest/api/sync -> 400 Host lookup failed', ['restaurant' => 'Other', 'restaurantId' => 'other']),
            $this->row(3, 'API GET https://gcp.kaman.rest/api/print -> 400 Host lookup failed'),
        ]);

        $this->assertCount(3, $issues);
    }

    public function test_same_host_lookup_groups_across_endpoints(): void
    {
        $grouper = new LogIssueGrouper;
        $issues = $grouper->group([
            $this->row(1, "GET /me -> failed The connection errored: Failed host lookup: 'kaman.kaman.rest'"),
            $this->row(2, "GET /access -> failed The connection errored: Failed host lookup: 'kaman.kaman.rest'"),
            $this->row(3, "GET /menu?lang=ar -> failed The connection errored: Failed host lookup: 'kaman.kaman.rest'", ['restaurant' => 'Other', 'restaurantId' => 'other']),
        ]);

        $this->assertCount(2, $issues);
        $this->assertSame(2, max(array_column($issues, 'count')));
    }

    public function test_host_lookup_strips_request_ids_but_keeps_the_host(): void
    {
        $grouper = new LogIssueGrouper;
        $issues = $grouper->group([
            $this->row(1, "SocketException: Failed host lookup: 'api.kaman.rest' request_id=aaa111222 (OS Error: errno = 7)", [
                'logKind' => 'flutter',
                'service' => 'flutter',
                'source' => 'flutter',
                'network' => true,
            ]),
            $this->row(2, "SocketException: Failed host lookup: 'api.kaman.rest' request_id=bbb333444 (OS Error: errno = 7)", [
                'logKind' => 'flutter',
                'service' => 'flutter',
                'source' => 'flutter',
                'network' => true,
                'device' => 'Pending tablet',
            ]),
            $this->row(3, "SocketException: Failed host lookup: 'other.kaman.rest' request_id=ccc555666", [
                'logKind' => 'flutter',
                'service' => 'flutter',
                'source' => 'flutter',
                'network' => true,
            ]),
        ]);

        $this->assertCount(2, $issues);
        $grouped = null;
        foreach ($issues as $issue) {
            if ($issue['count'] === 2) {
                $grouped = $issue;
            }
        }
        $this->assertNotNull($grouped);
        $this->assertSame('Failed host lookup: api.kaman.rest', $grouped['title']);
        $this->assertTrue($grouped['network']);
    }

    public function test_loaded_scope_count_matches_occurrences(): void
    {
        $grouper = new LogIssueGrouper;
        $rows = [
            $this->row(1, 'kitchen printer timeout', ['logKind' => 'print', 'service' => 'printer', 'source' => 'printer']),
            $this->row(2, 'kitchen printer timeout', ['logKind' => 'print', 'service' => 'printer', 'source' => 'printer', 'device' => 'Bar']),
            $this->row(3, 'API GET https://gcp.kaman.rest/api/menu -> 500 upstream'),
        ];
        $issues = $grouper->group($rows);
        $seen = [];
        foreach ($issues as $issue) {
            foreach ($issue['occurrences'] as $occurrence) {
                $seen[] = $occurrence['id'];
            }
        }
        sort($seen);

        $this->assertSame(count($rows), array_sum(array_column($issues, 'count')));
        $this->assertSame([1, 2, 3], $seen);
    }

    public function test_client_filters_do_not_treat_every_400_as_one_category(): void
    {
        $grouper = new LogIssueGrouper;
        $issues = $grouper->group([
            $this->row(1, 'API POST https://gcp.kaman.rest/api/cashier/orders -> 400 Invalid coupon'),
            $this->row(2, 'printer offline', ['logKind' => 'print', 'service' => 'printer', 'source' => 'printer', 'code' => '']),
        ]);

        $this->assertCount(1, array_values(array_filter(
            $issues,
            fn (array $issue): bool => $grouper->accepts($issue, ['http' => '400']),
        )));
        $this->assertCount(1, array_values(array_filter(
            $issues,
            fn (array $issue): bool => $grouper->accepts($issue, ['category' => 'print']),
        )));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(int $id, string $message, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'level' => 'error',
            'source' => 'api',
            'logKind' => 'api',
            'service' => 'api',
            'summary' => $message,
            'method' => '',
            'endpoint' => '',
            'code' => '',
            'network' => false,
            'message' => $message,
            'context' => ['request_id' => 'req-'.$id],
            'at' => sprintf('2026-10-15 15:41:%02d', $id),
            'clock' => sprintf('15:41:%02d', $id),
            'restaurant' => 'Dochan',
            'restaurantId' => 'dochan',
            'device' => 'Cashier',
            'deviceId' => 'dev-1',
            'platform' => 'android',
            'version' => '1.4.0',
            'sourceLabel' => 'API',
        ], $extra);
    }
}
