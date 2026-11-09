<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices keep their main name; Arabic and English are used when a quote is downloaded in that language.
     */
    public function up(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
        });

        foreach ($this->names() as $name => [$ar, $en]) {
            DB::table('storage_items')->where('name', $name)->update(['name_ar' => $ar, 'name_en' => $en]);
        }
    }

    public function down(): void
    {
        Schema::table('storage_items', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en']);
        });
    }

    /**
     * The devices loaded from Kaman Agents.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function names(): array
    {
        return [
            "טאבלט 11 אינץ' – GALAXY TAP S9" => ['تابلت 11 إنش – GALAXY TAB S9', '11" tablet – GALAXY TAB S9'],
            "מסך 21 אינץ'" => ['شاشة 21 إنش', '21" screen'],
            'מכשיר סליקה - Nexgo N86' => ['جهاز دفع بالبطاقات - Nexgo N86', 'Card terminal - Nexgo N86'],
            'מכשיר סליקה - Nexgo N62' => ['جهاز دفع بالبطاقات - Nexgo N62', 'Card terminal - Nexgo N62'],
            'מדפסת מדבקות - Sewoo' => ['طابعة ملصقات - Sewoo', 'Label printer - Sewoo'],
            'תושבת טעינה למכשיר אשראי N62' => ['قاعدة شحن لجهاز الدفع N62', 'Charging dock for N62 card terminal'],
            'תושבת טעינה למכשיר אשראי לקיוסק N62' => ['قاعدة شحن لجهاز الدفع N62 للكشك', 'Kiosk charging dock for N62 card terminal'],
            'מגן טאבלט למלצרים 11 אינץ׳' => ['غطاء حماية لتابلت النُّدُل 11 إنش', '11" waiter tablet case'],
            "טאבלט 8 אינץ' – GALAXY TAP S9" => ['تابلت 8 إنش – GALAXY TAB S9', '8" tablet – GALAXY TAB S9'],
            'Apple iPad A16 - אייפד אפל' => ['آيباد أبل - Apple iPad A16', 'Apple iPad A16'],
            'מגירת כסף' => ['درج نقود', 'Cash drawer'],
            'מדפסת בונים וחשבון' => ['طابعة إيصالات وفواتير', 'Receipt and bill printer'],
            'מעמד 2.5 ק"ג לטאבלט קופה' => ['حامل 2.5 كغ لتابلت الكاشير', '2.5 kg POS tablet stand'],
            'מתלה טאבלט למטבח' => ['حامل تابلت للمطبخ', 'Kitchen tablet holder'],
            'מעמד למכשיר סליקה' => ['حامل لجهاز الدفع', 'Card terminal stand'],
        ];
    }
};
