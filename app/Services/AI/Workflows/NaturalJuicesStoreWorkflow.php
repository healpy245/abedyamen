<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class NaturalJuicesStoreWorkflow extends AbstractImageDrinksWorkflow
{
    protected function getItemLabel(): string
    {
        return 'juice';
    }

    protected function getDefaultCategory(): string
    {
        return 'عصائر طبيعية';
    }

    protected function getTranslations(): array
    {
        return [
            'carrot juice jug' => ['ar' => 'عصير جزر إبريق', 'he' => 'מיץ גזר קנקן'],
            'carrot juice' => ['ar' => 'عصير جزر', 'he' => 'מיץ גזר'],
            'lemon juice jug' => ['ar' => 'عصير ليمون إبريق', 'he' => 'מיץ לימון קנקן'],
            'lemon juice' => ['ar' => 'عصير ليمون', 'he' => 'מיץ לימון'],
            'orange juice jug' => ['ar' => 'عصير برتقال إبريق', 'he' => 'מיץ תפוזים קנקן'],
            'orange juice' => ['ar' => 'عصير برتقال', 'he' => 'מיץ תפוזים'],
            'pomegranate juice' => ['ar' => 'عصير رمان', 'he' => 'מיץ רימון'],
            'carrot' => ['ar' => 'جزر', 'he' => 'גזר'],
            'lemon' => ['ar' => 'ليمون', 'he' => 'לימון'],
            'orange' => ['ar' => 'برتقال', 'he' => 'תפוזים'],
            'pomegranate' => ['ar' => 'رمان', 'he' => 'רימון'],
            'juice' => ['ar' => 'عصير', 'he' => 'מיץ'],
            'jug' => ['ar' => 'إبريق', 'he' => 'קנקן'],
        ];
    }

    protected function getBrands(): array
    {
        return [
            'carrotjuicejug' => 'Carrot Juice Jug',
            'carrotjuice' => 'Carrot Juice',
            'lemonjuicejug' => 'Lemon Juice Jug',
            'lemonjuice' => 'Lemon Juice',
            'orangejuicejug' => 'Orange Juice Jug',
            'orangejuice' => 'Orange Juice',
            'pomegranatejuice' => 'Pomegranate Juice',
        ];
    }
}
