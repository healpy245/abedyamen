<?php

namespace Tests\Unit\AiChatbot;

use App\Services\AiChatbot\HebrewName;
use PHPUnit\Framework\TestCase;

class HebrewNameTest extends TestCase
{
    public function test_campaign_hebrew_names_become_arabic(): void
    {
        $names = [
            'אימאן' => 'إيمان',
            'אינאס' => 'إيناس',
            'מדיחה' => 'مديحة',
            'משכס' => 'مشكس',
            'אם פיראס' => 'أم فراس',
            'אמאן' => 'أمان',
            'אמל אם סלמאן' => 'أمل أم سلمان',
            'הודא' => 'هدى',
            'היבא' => 'هبة',
            'חוסניה' => 'حسنية',
            'חיתאם' => 'ختام',
            'מראם' => 'مرام',
            'נגאח' => 'نجاح',
            'נואל' => 'نوال',
            'נוהא' => 'نهى',
            'נעאמה' => 'نعامة',
            'סבריה' => 'صبرية',
            'סוהילה' => 'سهيلة',
            'סועאד' => 'سعاد',
            'סלאם' => 'سلام',
            'עביר' => 'عبير',
            'עואטף' => 'عواطف',
            'פאטמה' => 'فاطمة',
            'רחאב' => 'رحاب',
            'רדא' => 'رضا',
            'חדרה' => 'حدرة',
            'סומיה' => 'سمية',
            'אסמאא' => 'أسماء',
            'זקיה אלאפינש' => 'زكية الأفينش',
            'נרדין' => 'نردين',
            'אבתסאם' => 'ابتسام',
        ];

        foreach ($names as $hebrew => $arabic) {
            $this->assertSame($arabic, HebrewName::arabic($hebrew), $hebrew);
        }
    }

    public function test_arabic_names_and_plain_hebrew_spellings_stay_readable(): void
    {
        $this->assertSame('فتحي', HebrewName::arabic('فتحي'));
        $this->assertSame('', HebrewName::arabic('  '));
        $this->assertSame('محمود', HebrewName::arabic('מחמוד'));
        $this->assertSame('أحمد', HebrewName::arabic('אחמד'));
        $this->assertSame('أبو محمود', HebrewName::arabic('אבומחמוד'));
        $this->assertSame('عمر', HebrewName::arabic('עומר'));
        $this->assertSame('عمر', HebrewName::arabic('עומאר'));
        $this->assertSame('خالد', HebrewName::arabic('חאלד'));
        $this->assertSame('محمد', HebrewName::arabic('מוחמד'));
        $this->assertSame('عبدالله', HebrewName::arabic('עבדאללה'));
        $this->assertSame('الخاطر', HebrewName::arabic("אלח'אטר"));
        $this->assertSame('أبو غنم', HebrewName::arabic("אבו ע'נם"));
        $this->assertSame('عمر خالد', HebrewName::repair('عومار حالد'));
        $this->assertSame('محمد', HebrewName::repair('موحمد'));
        $this->assertSame('محمد الأطرش', HebrewName::arabic('מחמד אל אטרש'));
        $this->assertSame('ليث الرازق', HebrewName::repair('لييت أل رازق'));
        $this->assertSame('مامون عبد الرحمن', HebrewName::repair('مامون عبد ال الرحمن'));
        $this->assertSame('عبد الرحمن', HebrewName::repair('عبد الالرحمن'));
        $this->assertSame('تامر أحمد', HebrewName::arabic('תאמר שיח אחמד'));
        $this->assertSame('عمر كيال', HebrewName::repair('الشيخ د. عمر كيال'));
        $this->assertSame('تامر أحمد', HebrewName::repair('تامر شيح أحمد'));
        $this->assertSame('إبراهيم أبو غوش', HebrewName::repair('إبراهيم أبو جوش'));
        $this->assertSame('سلام قدسي', HebrewName::repair('د.سلام قدسي'));
        $this->assertSame('محمد عبد الجعفر', HebrewName::repair('محمد عبدال جعفر'));
            $this->assertSame('محيي الدين عليان', HebrewName::repair('محيي أدين عليان'));
        $this->assertSame('سحر أبو نيات', HebrewName::arabic('סהראב אבו ניאת'));
        $this->assertSame('سحر', HebrewName::arabic('סחר'));
    }
}
