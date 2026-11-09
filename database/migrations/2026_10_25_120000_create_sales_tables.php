<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Packages and features agents can put on a quote. Equipment comes from storage_items.
        Schema::create('sales_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing', 20)->default('monthly');
            $table->string('note')->nullable();
            $table->json('bullets')->nullable();
            $table->boolean('popular')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['kind', 'active', 'sort']);
        });

        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('finance_client_id')->constrained('finance_clients')->cascadeOnDelete();
            $table->string('contact_name', 120)->nullable();
            $table->string('lang', 2)->default('he');
            $table->string('status', 20)->default('draft');
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('monthly_total', 12, 2)->default(0);
            $table->decimal('one_time_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('finance_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'agent_id']);
        });

        Schema::create('sales_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quote_id')->constrained('sales_quotes')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('sales_catalog_item_id')->nullable()->constrained('sales_catalog_items')->nullOnDelete();
            $table->foreignId('storage_item_id')->nullable()->constrained('storage_items')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->string('billing', 20);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        foreach ($this->packages() as $i => [$name, $price, $bullets, $popular]) {
            $rows[] = ['kind' => 'package', 'name' => $name, 'price' => $price, 'billing' => 'monthly', 'note' => null, 'bullets' => json_encode($bullets, JSON_UNESCAPED_UNICODE), 'popular' => $popular, 'sort' => $i, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($this->features() as $i => [$name, $price, $note, $bullets]) {
            $rows[] = ['kind' => 'feature', 'name' => $name, 'price' => $price, 'billing' => 'monthly', 'note' => $note, 'bullets' => json_encode($bullets, JSON_UNESCAPED_UNICODE), 'popular' => false, 'sort' => $i, 'active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('sales_catalog_items')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_items');
        Schema::dropIfExists('sales_quotes');
        Schema::dropIfExists('sales_catalog_items');
    }

    /**
     * The price list copied from the Kaman Agents system.
     *
     * @return list<array{0: string, 1: int, 2: list<string>, 3: bool}>
     */
    private function packages(): array
    {
        return [
            ['חבילת הבסיס', 299, ['קופת מכירה', 'ממשק ניהול מתקדם CRM', 'סליקת אשראי'], false],
            ['Kaman Custom', 599, ['קופת מכירה', 'דשבורד מנהל', 'תפריט דיגיטלי', 'ניהול מלאי', 'חיבור HAAT', 'מערכת מלצרים'], false],
            ['kaman 2', 599, ['קופת מכירה', 'סליקת אשראי', 'דשבורד ניהול', 'נתוני לקוחות', 'חיבור Haat', 'הודעות Sms', 'תפריט דיגיטלי'], false],
            ['חבילת אקספרס', 759, ['קופת מכירה', 'ממשק ניהול מתקדם CRM', 'סליקת אשראי', 'תפריט דיגיטלי', 'עמדת קיוסק', 'ניהול לקוחות'], true],
            ['Kaman 1', 599, ['קופת מכירה', 'סליקת אשראי', 'דשבורד מנהל', 'מערכת מלצרים', 'מסכים KDS', 'תפריט דיגיטלי', 'נתונים לקוחות', 'מערכת הודעות SMS'], true],
            ['חבילת TIER 1', 767, ['קופת מכירה', 'ממשק ניהול מתקדם CRM', 'סליקת אשראי', 'עובדים', 'ניהול מלאי', 'ניהול לקוחות'], false],
            ['חבילת ה DRIVER', 847, ['קופת מכירה', 'ממשק ניהול מתקדם CRM', 'סליקת אשראי', 'שיוך לאפליקציות האט ו וולט', 'תפריט דיגיטלי', 'ניהול לקוחות', 'ניהול עובדים'], false],
        ];
    }

    /**
     * @return list<array{0: string, 1: int, 2: ?string, 3: list<string>}>
     */
    private function features(): array
    {
        return [
            ['תפריט דיגיטלי', 179, null, ['תפריט דיגיטלי מתקדם עם פרטי המסעדה כמו מיקום, שעות פעילות, התקשרות ועוד', 'תומך 3 שפות: ערבית, עברית, אנגלית', 'מאפשר ללקוח להזמין מהתפריט (ההזמנה מתקבלת בקופה)']],
            ['נוכחות עובדים', 80, null, ['מערכת נוכחות מתקדמת — הבעלים רואה כל פעולה שנעשית על ידי העובדים', 'כניסה/יציאה דרך אפליקציה פרטית לעובדים, רק בתוך החנות + כתובת IP', 'אפשרות לשלוח סידור עבודה באמצעות האפליקציה', 'שליטה מלאה על שכר, תלוש, שעות ועוד']],
            ['עמדת קיוסק', 189, '149₪ תוכנת קיוסק + 40₪ מסוף אשראי', ['מערכת הזמנות נוחה מאוד ללקוחות', 'אפשרות לשלם במזומן או באשראי', 'תמיכה ב 3 שפות: ערבית, עברית, אנגלית', 'מאפשרת איסוף מידע יפה']],
            ['ניהול מלאי', 299, null, ['מערכת מתקדמת לניהול מלאי מסעדה', 'קליטת תעודות משלוח וחשבוניות סחורה באופן אוטומטי', 'התקשרות עם ספקים', 'מיזוג המלאי עם מנות המסעדה']],
            ['נתונים ו DATA הלקוחות', 89, null, ['איסוף מידע על כל לקוח שנכנס למסעדה', 'שמירת מידע אוטומטית אם המסעדה מחוברת עם HAAT או WOLT', 'ניתוח מדויק לכל לקוחות ועסקאות המסעדה']],
            ['מדפסת מדבקות', 59, null, ['סידור מיוחד להזמנות ה TAKE AWAY שמפחית בלבול בין המנות היוצאות', 'הלקוחות יודעים איזה מנה למי שייכת בלי לפתוח אותה']],
            ['מערכת מלצרים', 50, 'המחיר ל 3 משתמשי מלצרים, המשתמש הרביעי ומעלה ב 10₪', ['קבלת הזמנות באמצעות המלצרים', 'ניצוח הזמנות מדויק — לכל מלצר יוזר משלו', 'סרטוט של שולחני המסעדה']],
            ['הודעות SMS', 50, 'המחיר לחבילה של 500 הודעות', ['שליחת עדכוני הזמנה דרך SMS (הזמנה באיסוף עצמי או TAKE AWAY)', 'שליחת חשבוניות למספר טלפון']],
            ['מסכים למטבח KDS', 50, 'המחיר ל 3 משתמשי מסכים, המשתמש הרביעי ומעלה ב 10₪', ['מסך מסודר ויפה וקבלת ההזמנות במטבח', 'הפחתת טעויות', 'זריזות בהכנת המנות', 'סימון מנות מוכנות']],
            ['חיבור הקופה עם HAAT ו DAS HAAT', 100, null, ['חיבור הקופה לחנות המסעדה באפליקציית האט וקבלת הזמנות דרך הקופה', 'ניתוח מסודר להזמנות המשלוחים דרך ממשק הניהול', 'אפשרות למסור משלוח דרך שליח של האט באמצעות DAS HAAT']],
            ['חיבור הקופה עם WOLT ו DRIVE WOLT', 100, null, ['חיבור הקופה לחנות המסעדה באפליקציית וולט וקבלת הזמנות דרך הקופה', 'ניתוח מסודר להזמנות המשלוחים דרך ממשק הניהול', 'אפשרות למסור משלוח דרך שליח של וולט באמצעות DRIVE WOLT']],
        ];
    }
};
