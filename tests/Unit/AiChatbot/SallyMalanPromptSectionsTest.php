<?php

namespace Tests\Unit\AiChatbot;

use App\Services\AiChatbot\PromptCompiler;
use Tests\TestCase;

class SallyMalanPromptSectionsTest extends TestCase
{
    public function test_compiled_sections_preserve_original_prompt_content(): void
    {
        $compiler = app(PromptCompiler::class);
        $original = trim((string) file_get_contents(database_path('seeders/prompts/sally_malan_system_prompt.txt')));
        $sections = $compiler->sallyMalanDefaultSections();
        $compiled = $compiler->compile($sections, $original);

        $this->assertNotSame('', $compiled);

        // Critical phrases / rules from the original prompt that must survive the split.
        $required = [
            'سالي',
            'ملان انترنت',
            'فلسطيني محلّي',
            'جملة أو جملتين',
            'ممنوع إيموجي زيادة',
            'أهلين، معك سالي من ملان انترنت',
            'هلأ هاد اللي عندي، بحوّل لمندوب',
            'كفرقاسم',
            'كفربرا',
            'الطيبة',
            'الرملة',
            'قلنسوة',
            'وعارة/عرعرة',
            '1000 ميجا',
            'ما تخترعي رقم',
            'lookup_malan_customer',
            'اشتراك **جديد**',
            'رقم الهوية مرة وحدة',
            'ممنوع** تطلبيه مرة ثانية',
            'create_malan_support_report',
            'موافق أرفعها',
            'confirmed_by_customer=true',
            'تأكيد المهمة قبل الرفع',
            'رفعت بلاغ للـ תמיכה הטיכנית، إن شاء الله قريب بنتواصل معك',
            'DEBT_DISCONNECTED',
            'set_malan_payment_method_preference',
            'מספר אסמכתה',
            'ممنوع "رقم الأسنخة"',
            'integration_pending',
            'request_malan_service_reactivation',
            'פורטל לקוח',
            'سوزي من المالية',
            'تلخيص داخلي',
            'بدون هوية كاملة',
            'إذا مش عن ملان: false',
            'ممنوع JSON',
            'CVV',
        ];

        foreach ($required as $needle) {
            $this->assertStringContainsString(
                $needle,
                $compiled,
                "Compiled prompt is missing required original content: {$needle}"
            );
        }

        $this->assertContains('identity', $compiler->activeSectionNames($sections));
        $this->assertContains('business', $compiler->activeSectionNames($sections));
        $this->assertContains('malan_workflows', $compiler->activeSectionNames($sections));
    }

    public function test_empty_sections_fall_back_to_legacy_system_prompt(): void
    {
        $compiler = app(PromptCompiler::class);
        $fallback = $compiler->compile($compiler->normalize([]), 'Legacy Sally prompt');

        $this->assertSame('Legacy Sally prompt', $fallback);
    }
}
