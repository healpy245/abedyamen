<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class DrinksStoreWorkflow extends AbstractImageDrinksWorkflow
{
    protected function getItemLabel(): string
    {
        return 'drink';
    }

    protected function getDefaultCategory(): string
    {
        return 'مشروبات باردة';
    }

    protected function getTranslations(): array
    {
        return [
            'cola zero glass' => ['ar' => 'كولا زيرو زجاج', 'he' => 'קולה זירו כוס'],
            'cola zero big' => ['ar' => 'كولا زيرو كبيرة', 'he' => 'קולה זירו ביג'],
            'cola zero' => ['ar' => 'كولا زيرو', 'he' => 'קולה זירו'],
            'cola glass' => ['ar' => 'كولا زجاج', 'he' => 'קולה כוס'],
            'cola big' => ['ar' => 'كولا كبيرة', 'he' => 'קולה ביג'],
            'cola' => ['ar' => 'كولا', 'he' => 'קולה'],
            'sprite zero' => ['ar' => 'سبرايت زيرو', 'he' => 'ספרייט זירו'],
            'sprite' => ['ar' => 'سبرايت', 'he' => 'ספרייט'],
            'pepsi' => ['ar' => 'بيبسي', 'he' => 'פפסי'],
            'fanta' => ['ar' => 'فانتا', 'he' => 'פאנטה'],
            'red bull' => ['ar' => 'ريد بول', 'he' => 'רד בול'],
            '7up' => ['ar' => 'سفن أب', 'he' => 'סבן אפ'],
            'xl ten' => ['ar' => 'إكس إل تين', 'he' => 'אקס אל טן'],
            'xl' => ['ar' => 'إكس إل', 'he' => 'אקס אל'],
            'big' => ['ar' => 'كبيرة', 'he' => 'ביג'],
            'glass' => ['ar' => 'زجاج', 'he' => 'כוס'],
            'zero' => ['ar' => 'زيرو', 'he' => 'זירו'],
        ];
    }

    protected function getBrands(): array
    {
        return [
            'cocacola' => 'Coca-Cola',
            'pepsi' => 'Pepsi',
            'sprite' => 'Sprite',
            'spritezero' => 'Sprite Zero',
            'fanta' => 'Fanta',
            'redbull' => 'Red Bull',
            '7up' => '7Up',
            'xl' => 'XL',
            'xlten' => 'XL Ten',
            'fuzetea' => 'Fuze Tea',
            'nescafe' => 'Nescafé',
            'espresso' => 'Espresso',
            'americano' => 'Americano',
            'cappuccino' => 'Cappuccino',
            'latte' => 'Latte',
            'mocha' => 'Mocha',
            'macchiato' => 'Macchiato',
            'colazero' => 'Cola Zero',
            'colabig' => 'Cola Big',
        ];
    }
}
