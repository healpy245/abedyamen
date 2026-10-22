<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FormWorkflowRun;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportDeviceLog;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Local-only sample restaurants, devices, logs, and tickets for the support door.
 * Safe to run again: it replaces rows whose device id or ticket id starts with mock.
 */
class SupportMockSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->clear();

        $now = now();
        $places = [
            [
                'restaurant' => 'shawarma-house',
                'name' => 'شاورما البيت',
                'devices' => [
                    $this->device('mock-shawarma-1', 'ليلى حسن', 'android', '1.0.18', 'tablet', $now, true),
                    $this->device('mock-shawarma-2', 'أحمد ناصر', 'android', '1.0.18', 'tablet', $now->copy()->subMinute(), true),
                ],
            ],
            [
                'restaurant' => 'cafe-noor',
                'name' => 'كافيه نور',
                'devices' => [
                    $this->device('mock-cafe-1', 'نور خليل', 'android', '1.3.40', 'tablet', $now, true),
                    $this->device('mock-cafe-2', 'سامي عمر', 'windows', '1.3.40', 'desktop', $now->copy()->subHours(4), false),
                ],
            ],
            [
                'restaurant' => 'grill-45',
                'name' => 'جريل ٤٥',
                'devices' => [
                    $this->device('mock-grill-1', 'هدى صالح', 'android', '1.0.9', 'tablet', $now->copy()->subHours(3), false),
                ],
            ],
            [
                'restaurant' => 'sushi-lane',
                'name' => 'سوشي لين',
                'devices' => [
                    $this->device('mock-sushi-1', 'مايا كوهين', 'ios', '1.3.21', 'phone', $now, true),
                ],
            ],
        ];

        $devices = [];
        foreach ($places as $place) {
            foreach ($place['devices'] as $row) {
                $devices[$row['device_id']] = SupportDevice::query()->create($row + [
                    'restaurant' => $place['restaurant'],
                    'restaurant_name' => $place['name'],
                    'locale' => 'ar',
                    'log_count' => 0,
                ]);
            }
        }

        $logs = [
            ['mock-shawarma-2', 'error', 'printer', 'POST /print/kitchen -> 500 printer timeout on station 1', 'POST', '/print/kitchen', '500', 4],
            ['mock-shawarma-1', 'error', 'printer', 'POST /print/receipt -> 500 printer paper jam', 'POST', '/print/receipt', '500', 18],
            ['mock-shawarma-1', 'warn', 'flutter', 'Flutter warning: printer queue is behind', '', '', '', 12],
            ['mock-cafe-1', 'error', 'api', 'GET /api/payments/pelecard -> 402 pelecard declined the charge', 'GET', '/api/payments/pelecard', '402', 8],
            ['mock-cafe-1', 'error', 'api', 'POST /api/orders -> 400 missing table id', 'POST', '/api/orders', '400', 26],
            ['mock-cafe-2', 'error', 'app', 'network host lookup failed for sync.kaman.rest', '', '', '', 40],
            ['mock-grill-1', 'error', 'flutter', 'Flutter exception: StateError cashier cart was empty', '', '', '', 55],
            ['mock-grill-1', 'error', 'api', 'API haat POST /orders -> 503 haat is unavailable', 'POST', '/integrations/haat/orders', '503', 70],
            ['mock-sushi-1', 'error', 'api', 'GET /api/shoofi/menu -> 404 shoofi menu missing', 'GET', '/api/shoofi/menu', '404', 15],
            ['mock-sushi-1', 'error', 'api', 'POST /api/alram/auth -> 401 alram token rejected', 'POST', '/api/alram/auth', '401', 22],
            ['mock-sushi-1', 'error', 'api', 'GET /api/catalog -> 500 connection reset while syncing', 'GET', '/api/catalog', '500', 33],
            ['mock-shawarma-1', 'info', 'flutter', 'cashier session opened', '', '', '', 2],
        ];

        $bodies = [
            '/print/kitchen' => ['orderId' => 1842, 'station' => 1, 'items' => [['name' => 'شاورما', 'qty' => 2]]],
            '/print/receipt' => ['orderId' => 1840, 'total' => 46, 'currency' => 'ILS'],
            '/api/payments/pelecard' => ['amount' => 46, 'currency' => 'ILS', 'orderId' => 902],
            '/api/orders' => ['items' => [['sku' => 'latte', 'qty' => 1]], 'note' => 'no table'],
            '/integrations/haat/orders' => ['externalId' => 'haat-441', 'items' => [['name' => 'grill', 'qty' => 1]]],
            '/api/shoofi/menu' => ['branch' => 'sushi-lane', 'locale' => 'he'],
            '/api/alram/auth' => ['client' => 'cashier', 'scope' => 'orders'],
            '/api/catalog' => ['since' => '2026-10-21T12:00:00', 'page' => 1],
        ];

        foreach ($logs as $index => [$deviceId, $level, $source, $message, $method, $path, $code, $minutes]) {
            $device = $devices[$deviceId];
            $slug = (string) $device->restaurant;
            SupportDeviceLog::query()->create([
                'support_device_id' => $device->id,
                'client_id' => 'mock-log-'.($index + 1),
                'level' => $level,
                'source' => $source,
                'message' => $message,
                'context' => array_filter([
                    'method' => $method,
                    'path' => $path,
                    'url' => $path !== '' ? 'https://'.$slug.'.kaman.rest'.$path.'?source=cashier' : '',
                    'status' => $code,
                    'body' => $bodies[$path] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                'logged_at' => $now->copy()->subMinutes($minutes),
            ]);
            $device->increment('log_count');
        }

        $tickets = [
            ['MOCK-1001', 'shawarma-house', 'شاورما البيت', 'mock-shawarma-2', 'open', 'الطابعة لا تطبع طلبات المطبخ', 'المحطة الأولى تتوقف بعد الطلب الثالث. الرسالة على الجهاز: printer timeout on station 1.', null],
            ['MOCK-1002', 'cafe-noor', 'كافيه نور', 'mock-cafe-1', 'in_progress', 'الدفع عبر Pelecard يرفض البطاقة', 'العميل يرى خطأ 402 عند إتمام الطلب من التابلت. حدث مرتين اليوم على جهاز نور.', null],
            ['MOCK-1003', 'grill-45', 'جريل ٤٥', 'mock-grill-1', 'closed', 'السلة تفرغ وحدها', 'الكاشير هدى أغلقت التطبيق بعد رسالة StateError. أُعيد فتح الجلسة.', 'أُعيد تشغيل التطبيق وتأكدنا أن السلة تبقى بعد إضافة الصنف.'],
            ['MOCK-1004', 'sushi-lane', 'سوشي لين', 'mock-sushi-1', 'open', 'قائمة Shoofi لا تصل', 'طلب القائمة يرجع 404، وربط Alram يرفض الرمز. القائمة داخل الجهاز ما زالت ظاهرة.', null],
            ['MOCK-1005', 'cafe-noor', 'كافيه نور', 'mock-cafe-1', 'waiting_restaurant', 'طلب بدون رقم طاولة', 'الجهاز أرسل الطلب بدون رقم الطاولة فرجع 400. ننتظر تأكيد الكاشير أن الحقل ظاهر.', null],
        ];

        foreach ($tickets as [$publicId, $restaurant, $name, $deviceId, $status, $summary, $details, $note]) {
            $row = [
                'public_id' => $publicId,
                'restaurant' => $restaurant,
                'restaurant_name' => $name,
                'device_id' => $deviceId,
                'locale' => 'ar',
                'summary' => $summary,
                'details' => $details,
                'status' => $status,
                'client_created_at' => $now->copy()->subHours(2),
            ];
            if ($status === 'closed' && Schema::hasColumn('support_tickets', 'close_note')) {
                $row['closed_at'] = $now->copy()->subHour();
                $row['close_note'] = $note;
            }
            SupportTicket::query()->create($row);
        }

        $user = User::query()->where('email', 'yamen@kaman.rest')->first();
        if ($user !== null && Schema::hasTable('form_workflow_runs')) {
            FormWorkflowRun::query()->where('subdomain', 'like', 'mock-%')->delete();
            foreach ([
                ['mock-shawarma-house', 'completed'],
                ['mock-cafe-noor', 'running'],
                ['mock-sushi-lane', 'failed'],
            ] as [$subdomain, $status]) {
                FormWorkflowRun::query()->create([
                    'user_id' => $user->id,
                    'method_type' => 'menu',
                    'subdomain' => $subdomain,
                    'environment' => 'rest',
                    'status' => $status,
                    'payload' => ['source' => 'local-mock'],
                    'started_at' => $now->copy()->subMinutes(20),
                    'finished_at' => $status === 'running' ? null : $now->copy()->subMinutes(5),
                    'error' => $status === 'failed' ? 'sample failure' : null,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function device(string $id, string $cashier, string $platform, string $version, string $kind, $seen, bool $open): array
    {
        return [
            'device_id' => $id,
            'cashier_name' => $cashier,
            'cashier_email' => $id.'@mock.local',
            'role' => 'cashier',
            'platform' => $platform,
            'app_version' => $version,
            'hostname' => $id,
            'session_id' => $id.'-session',
            'app_state' => $open ? 'open' : 'closed',
            'logged_in' => $open,
            'opened_at' => $seen->copy()->subHour(),
            'closed_at' => $open ? null : $seen,
            'extra' => ['deviceKind' => $kind, 'mock' => true],
            'last_seen_at' => $seen,
        ];
    }

    private function clear(): void
    {
        SupportDevice::query()->where('device_id', 'like', 'mock-%')->delete();
        SupportTicket::query()->where('public_id', 'like', 'MOCK-%')->delete();
    }
}
