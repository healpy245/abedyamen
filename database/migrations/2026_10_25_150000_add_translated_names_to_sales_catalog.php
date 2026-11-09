<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Hebrew name stays the main one; Arabic and English are used when a quote is shown or downloaded in that language.
     */
    public function up(): void
    {
        Schema::table('sales_catalog_items', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
        });

        foreach ($this->names() as $he => [$ar, $en]) {
            DB::table('sales_catalog_items')
                ->whereIn('kind', ['base', 'feature'])
                ->where('name', $he)
                ->update(['name_ar' => $ar, 'name_en' => $en]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_catalog_items', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en']);
        });
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function names(): array
    {
        return [
            'מנוי בסיס קמאן' => ['اشتراك كمان الأساسي', 'Kaman base subscription'],
            'תפריט דיגיטלי' => ['قائمة طعام رقمية', 'Digital menu'],
            'נוכחות עובדים' => ['حضور الموظفين', 'Staff attendance'],
            'עמדת קיוסק' => ['كشك الطلب الذاتي', 'Self-order kiosk'],
            'ניהול מלאי' => ['إدارة المخزون', 'Inventory management'],
            'נתונים ו DATA הלקוחות' => ['بيانات العملاء', 'Customer data'],
            'מדפסת מדבקות' => ['طابعة ملصقات', 'Label printer'],
            'מערכת מלצרים' => ['نظام النُّدُل', 'Waiter system'],
            'הודעות SMS' => ['رسائل SMS', 'SMS messages'],
            'מסכים למטבח KDS' => ['شاشات المطبخ KDS', 'Kitchen screens (KDS)'],
            'חיבור הקופה עם HAAT ו DAS HAAT' => ['ربط الكاشير مع HAAT و DAS HAAT', 'POS link with HAAT and DAS HAAT'],
            'חיבור הקופה עם WOLT ו DRIVE WOLT' => ['ربط الكاشير مع WOLT و DRIVE WOLT', 'POS link with WOLT and DRIVE WOLT'],
        ];
    }
};
