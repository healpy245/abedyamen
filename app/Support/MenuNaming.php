<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One naming guide for every AI step that writes menu names (photo OCR, Wolt and website
 * imports, chat edits, meal/category/ingredient workflows).
 *
 * Restaurants in Israel print the borrowed word customers already say ("اسبريسو دبل",
 * "אספרסו כפול", "نسكافيه", "נס קפה"), not a dictionary or formal Arabic translation
 * ("إسبرسو مزدوج", "قهوة سريعة التحضير"). Add a line to GLOSSARY when a new case comes up;
 * every prompt picks it up.
 */
final class MenuNaming
{
    /**
     * Source term => [Arabic as printed on local menus, Hebrew as printed on local menus].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const GLOSSARY = [
        // Coffee bar
        'Espresso' => ['اسبريسو', 'אספרסו'],
        'Double espresso' => ['اسبريسو دبل', 'אספרסו כפול'],
        'Long espresso' => ['اسبريسو طويل', 'אספרסו ארוך'],
        'Long double espresso' => ['اسبريسو دبل طويل', 'אספרסו כפול ארוך'],
        'Short espresso' => ['اسبريسو قصير', 'אספרסו קצר'],
        'Cappuccino' => ['كابتشينو', 'קפוצ\'ינו'],
        'Macchiato' => ['ماكياتو', 'מקיאטו'],
        'Latte' => ['لاتيه', 'לאטה'],
        'Americano' => ['أمريكانو', 'אמריקנו'],
        'Cortado' => ['كورتادو', 'קורטדו'],
        'Mocha' => ['موكا', 'מוקה'],
        'Flat white' => ['فلات وايت', 'פלאט וייט'],
        'Instant coffee' => ['نسكافيه', 'נס קפה'],
        'Turkish coffee' => ['قهوة تركية', 'קפה טורקי'],
        'Iced coffee' => ['آيس كوفي', 'אייס קפה'],
        'Hot chocolate' => ['شوكو ساخن', 'שוקו חם'],
        'Chocolate milk' => ['شوكو', 'שוקו'],
        'Tea' => ['شاي', 'תה'],
        'Mint tea' => ['شاي بالنعنع', 'תה נענע'],
        'Slush' => ['براد', 'ברד'],
        'Milkshake' => ['ميلك شيك', 'מילקשייק'],
        'Lemonade' => ['ليموناضة', 'לימונדה'],
        'Soda water' => ['صودا', 'סודה'],
        // Kitchen
        'French fries' => ['تشيبس', 'צ\'יפס'],
        'Schnitzel' => ['شنيتسل', 'שניצל'],
        'Shawarma' => ['شاورما', 'שווארמה'],
        'Falafel' => ['فلافل', 'פלאפל'],
        'Hummus' => ['حمص', 'חומוס'],
        'Laffa' => ['لافا', 'לאפה'],
        'Baguette' => ['باجيت', 'באגט'],
        'Toast' => ['توست', 'טוסט'],
        'Pizza' => ['بيتسا', 'פיצה'],
        'Hamburger' => ['همبرغر', 'המבורגר'],
        'Entrecote' => ['انتريكوت', 'אנטריקוט'],
        'Pargit (chicken thigh)' => ['بارجيت', 'פרגית'],
        'Chicken breast' => ['صدر دجاج', 'חזה עוף'],
        'Shishlik' => ['شيشليك', 'שישליק'],
        'Kebab' => ['كباب', 'קבב'],
        'Bell pepper' => ['جامبا', 'גמבה'],
        'Sea bream' => ['دينيس', 'דניס'],
        'Shrimp' => ['شريمبس', 'שרימפס'],
        'Extra / add-on' => ['إضافة', 'תוספת'],
        'Small' => ['صغير', 'קטן'],
        'Large' => ['كبير', 'גדול'],
    ];

    /**
     * Rules + glossary, ready to drop into any system prompt that writes name_ar / name_he / name_en.
     */
    public static function guide(): string
    {
        $lines = [];
        foreach (self::GLOSSARY as $english => [$arabic, $hebrew]) {
            $lines[] = $english.' = '.$arabic.' / '.$hebrew;
        }

        return implode("\n", [
            'NAMING (Israel, Arabic + Hebrew menus):',
            '- Write each name the way restaurants and cafés in Israel print it and the way a waiter says it, not a dictionary or formal Arabic translation.',
            '- When a borrowed word is what people say (espresso, cappuccino, schnitzel, chips, nescafé), spell that word in Arabic or Hebrew letters instead of translating its meaning word by word.',
            '- Arabic follows the spoken usage of Arab towns in Israel: "اسبريسو دبل" not "إسبرسو مزدوج", "نسكافيه" not "قهوة سريعة التحضير", "شوكو" not "حليب بالشوكولاتة".',
            '- Hebrew follows Israeli menus: "אספרסו כפול", "נס קפה", "שוקו חם", "צ\'יפס".',
            '- English uses standard café and restaurant English ("Double Espresso", "Instant Coffee", "Fries").',
            '- Keep names short. Size words go after the name ("كابتشينو كبير", "קפוצ\'ינו גדול", "Large Cappuccino").',
            '- If the owner wrote a name a certain way, keep the owner\'s spelling.',
            'Common names (English = Arabic / Hebrew):',
            implode("\n", $lines),
        ]);
    }
}
