<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class HotDrinksStoreWorkflow extends AbstractImageDrinksWorkflow
{
    protected function getItemLabel(): string
    {
        return 'drink';
    }

    protected function getDefaultCategory(): string
    {
        return 'مشروبات ساخنة';
    }

    protected function getTranslations(): array
    {
        return [
            'affogato' => ['ar' => 'أفوجاتو', 'he' => 'אפוגאטו'],
            'americano' => ['ar' => 'أمريكانو', 'he' => 'אמריקנו'],
            'cafe latte' => ['ar' => 'لاتيه', 'he' => 'לטה'],
            'cappuccino' => ['ar' => 'كابوتشينو', 'he' => 'קפוצ\'ינו'],
            'double espresso' => ['ar' => 'إسبريسو مزدوج', 'he' => 'אספרסו כפול'],
            'espresso con panna' => ['ar' => 'إسبريسو كون بانا', 'he' => 'אספרסו קון פאנה'],
            'espresso' => ['ar' => 'إسبريسو', 'he' => 'אספרסו'],
            'french vanilla latte' => ['ar' => 'لاتيه فانيليا فرنسية', 'he' => 'לטה וניל צרפתי'],
            'french vanilla' => ['ar' => 'فانيليا فرنسية', 'he' => 'וניל צרפתי'],
            'green tea' => ['ar' => 'شاي أخضر', 'he' => 'תה ירוק'],
            'hot caramel' => ['ar' => 'كراميل ساخن', 'he' => 'קרמל חם'],
            'hot chocolate' => ['ar' => 'شوكولاتة ساخنة', 'he' => 'שוקו חם'],
            'hot hazelnut' => ['ar' => 'بندق ساخن', 'he' => 'אגוז לוז חם'],
            'hot lotus' => ['ar' => 'لوتس ساخن', 'he' => 'לוטוס חם'],
            'latte tea' => ['ar' => 'شاي لاتيه', 'he' => 'תה לטה'],
            'macchiato' => ['ar' => 'مكياتو', 'he' => 'מקיאטו'],
            'mocha' => ['ar' => 'موكا', 'he' => 'מוקה'],
            'nescafe' => ['ar' => 'نسكافيه', 'he' => 'נסקפה'],
            'nescafé' => ['ar' => 'نسكافيه', 'he' => 'נסקפה'],
            'sahlab' => ['ar' => 'سحلب', 'he' => 'סחלב'],
            'spanish latte' => ['ar' => 'لاتيه إسباني', 'he' => 'לטה ספרדי'],
            'tea' => ['ar' => 'شاي', 'he' => 'תה'],
            'zhorat' => ['ar' => 'زهورات', 'he' => 'זוחות'],
            'turkish coffee' => ['ar' => 'قهوة تركية', 'he' => 'קפה טורקי'],
            'mint tea' => ['ar' => 'شاي نعناع', 'he' => 'תה נענע'],
            'chai' => ['ar' => 'شاي', 'he' => 'צ\'אי'],
            'hot milk' => ['ar' => 'حليب ساخن', 'he' => 'חלב חם'],
            'big' => ['ar' => 'كبيرة', 'he' => 'ביג'],
            'glass' => ['ar' => 'زجاج', 'he' => 'כוס'],
            'regular' => ['ar' => 'عادي', 'he' => 'רגיל'],
        ];
    }

    protected function getBrands(): array
    {
        return [
            'affogato' => 'Affogato',
            'americano' => 'Americano',
            'cafelatte' => 'Cafe Latte',
            'cappuccino' => 'Cappuccino',
            'doubleespresso' => 'Double Espresso',
            'espressoconpanna' => 'Espresso Con Panna',
            'espresso' => 'Espresso',
            'frenchvanillalatte' => 'French Vanilla Latte',
            'frenchvanilla' => 'French Vanilla',
            'greentea' => 'Green Tea',
            'hotcaramel' => 'Hot Caramel',
            'hotchocolate' => 'Hot Chocolate',
            'hothazelnut' => 'Hot Hazelnut',
            'hotlotus' => 'Hot Lotus',
            'lattetea' => 'Latte Tea',
            'macchiato' => 'Macchiato',
            'mocha' => 'Mocha',
            'nescafe' => 'Nescafé',
            'nescafé' => 'Nescafé',
            'sahlab' => 'Sahlab',
            'spanishlatte' => 'Spanish Latte',
            'tea' => 'Tea',
            'zhorat' => 'Zhorat',
            'turkishcoffee' => 'Turkish Coffee',
            'chai' => 'Chai',
            'hotmilk' => 'Hot Milk',
        ];
    }
}
