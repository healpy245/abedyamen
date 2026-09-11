<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\HebrewCampaignTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HebrewCampaignTermsTest extends TestCase
{
    #[DataProvider('mixedTermProvider')]
    public function test_sanitizes_mixed_script_product_terms(string $input, string $expected): void
    {
        $this->assertSame($expected, HebrewCampaignTerms::sanitize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mixedTermProvider(): array
    {
        return [
            'arabic mem in magdil' => ['مגדיל טווח', 'מגדיל טווח'],
            'arabic in tvach' => ['מגדיל توוח', 'מגדיל טווח'],
            'mangled magdil' => ['مگديل تووح مجاني', 'מגדיל טווח مجاني'],
            'mixed bezeq' => ['احنا أحسن من بזק', 'احنا أحسن من בזק'],
            'dialect bezeq stays arabic' => ['مثل بيزك بتكون تكلفة', 'مثل بيزك بتكون تكلفة'],
            'dialect bezeq with hebrew qof' => [
                'وبباقي الشركات مثل بيزק بتكون تكلفة بين 300-500 شيقل',
                'وبباقي الشركات مثل بيزك بتكون تكلفة بين 300-500 شيقل',
            ],
            'no-fiber install mixed bezeq' => [
                'تمام، התקנה סיב אופטי وتركيب بشكل عام وبباقي الشركات مثل بيزק بتكون تكلفة بين 300-500 شيقل. احنا عاملين حملة لأول 200 زبون بالطيبة تركيب مجاني تماما.',
                'تمام، התקנה סיב אופטי وتركيب بشكل عام وبباقي الشركات مثل بيزك بتكون تكلفة بين 300-500 شيقل. احنا عاملين حملة لأول 200 زبون بالطيبة تركيب مجاني تماما.',
            ],
            'already correct' => ['بيوخذو מגדיל טווח مجاني', 'بيوخذو מגדיל טווח مجاني'],
            'fiber stays hebrew' => ['התקנת סיב אופטי المجانية', 'התקנת סיב אופטי المجانية'],
            'arabic and glued to hebrew install' => ['وהתקנת סיב אופטי المجانية', 'وהתקנת סיב אופטי المجانية'],
        ];
    }
}
