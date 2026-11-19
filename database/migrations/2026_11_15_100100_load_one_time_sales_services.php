<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The one-time services agents can add to a quote. Finance manages them on the storage page from then on;
     * services already there by name are left as they are.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->services() as [$name, $nameAr, $nameEn, $price]) {
            if (DB::table('storage_items')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('storage_items')->insert([
                'sku' => null,
                'name' => $name,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'category' => 'service',
                'unit_price' => $price,
                'track_stock' => false,
                'stock' => 0,
                'low_stock' => 0,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Services may be on quotes and invoices by now; they are not removed.
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: int}>
     */
    private function services(): array
    {
        return [
            ['בניית תפריט', 'بناء القائمة', 'Menu build', 500],
            ['הדרכה מלאה לעובדי הקופה', 'جلسة تدريب كاملة لعمال الكاشير', 'Full POS workers guide session', 200],
            ['הדרכת CRM בזום', 'جلسة تدريب CRM عبر زوم', 'CRM guide session (Zoom)', 200],
            ['הגדרת מכשירים ורשת LAN', 'إعداد الأجهزة وشبكة LAN', 'Devices and LAN configuration', 500],
        ];
    }
};
