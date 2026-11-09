<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The devices from the Kaman Agents price list (admin/catalog → ציוד), 25.10.2026.
     * Stock is main stock plus the units agents hold, since both now live in one Finance stock.
     * Devices already in Finance stock are left as they are.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->devices() as [$name, $price, $stock]) {
            if (DB::table('storage_items')->where('name', $name)->exists()) {
                continue;
            }

            $id = DB::table('storage_items')->insertGetId([
                'sku' => null,
                'name' => $name,
                'category' => 'hardware',
                'unit_price' => $price,
                'track_stock' => true,
                'stock' => $stock,
                'low_stock' => 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($stock > 0) {
                DB::table('storage_movements')->insert([
                    'storage_item_id' => $id,
                    'change' => $stock,
                    'reason' => 'purchase',
                    'finance_invoice_id' => null,
                    'note' => 'יתרת פתיחה מ-Kaman Agents',
                    'user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Devices may have sales and movements by now; they are not removed.
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function devices(): array
    {
        return [
            ["טאבלט 11 אינץ' – GALAXY TAP S9", 1050, 3],
            ["מסך 21 אינץ'", 2360, 1],
            ['מכשיר סליקה - Nexgo N86', 1500, 6],
            ['מכשיר סליקה - Nexgo N62', 1500, 5],
            ['מדפסת מדבקות - Sewoo', 2000, 5],
            ['תושבת טעינה למכשיר אשראי N62', 100, 4],
            ['תושבת טעינה למכשיר אשראי לקיוסק N62', 100, 2],
            ['מגן טאבלט למלצרים 11 אינץ׳', 100, 0],
            ["טאבלט 8 אינץ' – GALAXY TAP S9", 900, 0],
            ['Apple iPad A16 - אייפד אפל', 1600, 0],
            ['מגירת כסף', 472, 3],
            ['מדפסת בונים וחשבון', 900, 27],
            ['מעמד 2.5 ק"ג לטאבלט קופה', 250, 26],
            ['מתלה טאבלט למטבח', 200, 0],
            ['מעמד למכשיר סליקה', 200, 0],
        ];
    }
};
