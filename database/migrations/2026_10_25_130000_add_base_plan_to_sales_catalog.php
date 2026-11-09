<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every monthly offer starts from the base subscription; features are added on top of it.
     */
    public function up(): void
    {
        if (DB::table('sales_catalog_items')->where('kind', 'base')->exists()) {
            return;
        }

        DB::table('sales_catalog_items')->insert([
            'kind' => 'base',
            'name' => 'מנוי בסיס קמאן',
            'price' => 300,
            'billing' => 'monthly',
            'note' => null,
            'bullets' => json_encode(['קופת מכירה', 'ממשק ניהול', 'סליקת אשראי'], JSON_UNESCAPED_UNICODE),
            'popular' => false,
            'sort' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sales_catalog_items')->where('kind', 'base')->delete();
    }
};
