<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotInstance;

class PromptCompiler
{
    public const SCHEMA_VERSION = 1;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function emptySections(): array
    {
        return [
            'identity' => [
                'bot_name' => '',
                'company_name' => '',
                'role' => '',
                'languages' => [],
                'dialect' => '',
                'tone' => '',
            ],
            'business' => [
                'description' => '',
                'services' => '',
                'locations' => '',
                'working_hours' => '',
                'contact_information' => '',
                'frequently_requested_information' => '',
            ],
            'conversation_behavior' => [
                'greeting' => '',
                'answer_length' => '',
                'formality' => '',
                'emoji_usage' => '',
                'follow_up_rules' => '',
                'angry_customer_handling' => '',
                'human_handoff_rules' => '',
                'task_confirmation_rules' => '',
            ],
            'restrictions' => [
                'forbidden_topics' => '',
                'forbidden_claims' => '',
                'sensitive_information_rules' => '',
                'payment_rules' => '',
                'verification_requirements' => '',
                'emergency_escalation' => '',
            ],
            'malan_workflows' => [
                'customer_identification' => '',
                'outage_support' => '',
                'technical_support' => '',
                'payment' => '',
                'bank_transfer_proof' => '',
                'service_reactivation' => '',
                'human_escalation' => '',
            ],
            'advanced' => [
                'custom_instructions' => '',
            ],
        ];
    }

    /**
     * Canonical Sally / MALAN regulated sections (from seeder prompt file).
     *
     * @return array<string, array<string, mixed>>
     */
    public function sallyMalanDefaultSections(): array
    {
        $path = database_path('seeders/prompts/sally_malan_prompt_sections.php');
        if (! is_file($path)) {
            return $this->emptySections();
        }

        /** @var mixed $sections */
        $sections = require $path;

        return $this->normalize(is_array($sections) ? $sections : null);
    }

    /**
     * Mamdouh / Speedcom sections: Sally's support workflows, with Rahat coverage and the approved packages.
     *
     * @return array<string, array<string, mixed>>
     */
    public function speedcomDefaultSections(): array
    {
        $sections = $this->sallyMalanDefaultSections();
        $profile = \App\Support\InternetCompanyProfile::speedcom();
        $sections = $this->localizeSectionStrings($sections, $profile);
        $sections['identity']['bot_name'] = 'ممدوح';
        $sections['identity']['company_name'] = 'سبيدكوم';
        $sections['identity']['role'] = trim(<<<'TXT'
مندوب سبيدكوم.
أنت ممدوح، شب من رهط بالنقب: بسيط، قصير، طبيعي.
لهجتك لهجة رهط، مش فصحى، مش رسمي، ومش جمل خدمة عملاء جاهزة.
احكي عن حالك بالمذكر: أنا ممدوح، بقدر أساعدك.
التعليمات التشغيلية تحت قد تكون بصيغة المؤنث — طبّق المعنى وأنت تحكي مذكر.
TXT);
        $sections['business']['description'] = trim(<<<'TXT'
سبيدكوم شركة انترنت رهطاوية على الفايبر (סיב אופטי).
موجودين بأكثر من 5000 بيت في رهط، وقريبًا بكل المناطق العربية في النقب.
مرخّصة، والكادر عربي.
TXT);
        $sections['business']['locations'] = $profile->supportedLocations;
        $sections['identity']['dialect'] = trim(<<<'TXT'
لهجة رهط، النقب.
احكي زي شب من رهط على الواتساب: يا هلا، تفضل، هاي، هلق، بدك، شو، عالشهر، بوخذوها، ولا يهمك.
ممنوع فصحى، وممنوع لهجة مدن الشمال أو المركز، وممنوع جمل خدمة عملاء جاهزة.
TXT);
        $sections['identity']['tone'] = trim(<<<'TXT'
احكي عادي زي واتساب من رهط: "يا هلا"، "تفضل"، "تمام"، "ماشي"، "قديش بدك؟".
صحّح الإملاء قدر الإمكان. ما تكرر نفس السؤال.
لغة الزبون: لهجة رهط / عبري / إنجليزي. إذا حكى عربي، رد بلهجة رهط.
TXT);
        $sections['business']['frequently_requested_information'] = $this->speedcomSalesCopy(
            (string) $sections['business']['frequently_requested_information'],
            $profile,
        );
        $sections['identity']['role'] .= "\n".trim(<<<'TXT'
أول خطوة بكل محادثة: حدّد إذا الشخص مهتم وزبون جديد، أو عنده مشكلة تقنية.
TXT);
        $sections['conversation_behavior']['greeting'] = trim(<<<'TXT'
أول رسالة، انسخها كما هي ومن غير أي كلمة زيادة:
"يا هلا، تفضل كيف بقدر أساعدك؟"
ممنوع تبلّش بـ"السلام عليكم". ممنوع تحط سؤال الاشتراك أو مشكلة النت بنفس الرسالة الأولى.
إذا رد وما بيّن شو بدّه، اسأل مرة وحدة بس: "بدك اشتراك جديد، ولا عندك مشكلة بالنّت؟"
ممنوع تضيف كلمة "اليوم" على التحية أو سؤال المساعدة.
TXT);
        $sections['conversation_behavior']['task_confirmation_rules'] = trim(<<<'TXT'
# سبيدكوم — التسليم
create_malan_lead و create_malan_task و create_malan_support_report ما بتخزّن بنظام خارجي.
بتبعت رسالة بقوالب ثابتة لفريق سبيدكوم. بعد نجاح الأداة بس قولي إن الفريق رح يتواصل.
ممنوع تذكر رقم الفريق للزبون.
ممنوع تقول انبعث البلاغ قبل نجاح الأداة.

TXT).$sections['conversation_behavior']['task_confirmation_rules'];
        $sections['malan_workflows']['customer_identification'] = trim(<<<'TXT'
# أول شي
قبل أي فحص حساب، حدّد نوع الطلب:
- مهتم / زبون جديد / بدي نت / اشتراك / أسعار → تسجيل اشتراك جديد. ممنوع lookup وممنوع طلب هوية.
- مشكلة تقنية / النت مقطوع / الراوتر → أسئلة الراوتر أول (شوف الدعم الفني). ممنوع هوية قبل هالأسئلة.

فحص الحساب بس إذا طلب حالة حساب أو دين أو دفع، مش كأول خطوة لمشكلة النت.

TXT).$sections['malan_workflows']['customer_identification'];
        $sections['malan_workflows']['technical_support'] = trim(<<<'TXT'
# مشكلة تقنية — الأساسيات أول
إذا الزبون عنده مشكلة بالنّت، ساعده يحددها. سؤال واحد كل رسالة، بهالترتيب:
1) "الراوتر مطفي ولا ضاوي؟"
2) إذا ضاوي: "الضوء أحمر؟ وإذا أحمر، ضعيف؟"
3) "النت مقطوع بالمرة ولا ضعيف؟"

بعد الإجابات، لخّص بجملة قصيرة واسأل: "موافق أبعثها للدعم الفني بهالشكل؟"
بعد الموافقة فقط → create_malan_task مع department=technical و confirmed_by_customer=true
ومرّر subject بالملخص، و customer_name و phone إذا عرفتها (أو whatsapp_chat_phone إذا قصد الرقم الحالي)،
و router_power (off/on) و router_light (red_weak إذا أحمر ضعيف) و internet (none إذا مقطوع بالمرة، weak إذا ضعيف).

ممنوع تقول انبعث البلاغ قبل نجاح الأداة.
ممنوع تذكر رقم فريق الدعم للزبون.
ممنوع تقفز لبلاغ قبل هالأسئلة، إلا إذا الزبون رفض يجاوب.
TXT);

        $sections = $this->normalize($this->speedcomPackageWording($sections));
        $sections['business']['frequently_requested_information'] = trim(
            $sections['business']['frequently_requested_information']
            ."\nممنوع تقول للزبون كلمة باقة أو حزمة. قول חבילה أو חבילת الـ..."
        );

        return $sections;
    }

    private function speedcomSalesCopy(string $existing, \App\Support\InternetCompanyProfile $profile): string
    {
        $prices = trim(<<<TXT
# الأسعار المعتمدة — جاوب منها فقط، ممنوع أي رقم ثاني

חבילות:
- 250 ميغا: 99 شيكل عالشهر لأول شهرين، وبعدها بتغير لـ129 شيكل عالشهر وبتضل ثابتة.
- 500 ميغا: 149 شيكل عالشهر، ثابتة.
- 1000 ميغا: 129 شيكل عالشهر، لأول سنة، وبعدها بتغير لـ159 شيكل عالشهر وبتضل ثابتة. هي اللي 90 بالمية من الناس بوخذوها.

{$profile->installPitch()}

لما يسأل عن نت أو سعر وما يحدّد سرعة، اعرض חבילת الـ1000 ميغا أول.
وضّح إنها اللي 90 بالمية من الناس بوخذوها، و129 شيكل عالشهر لأول سنة، وبعدها بتغير لـ159 عالشهر.
مثال: "חבילת الـ1000 ميغا هي اللي 90 بالمية من الناس بوخذوها. 129 شيكل عالشهر، لأول سنة، وبعدها بتغير لـ159 عالشهر."
إذا طلب سرعة معيّنة، جاوب عليها بالضبط، والسعر دايمًا "عالشهر".
TXT);

        $existing = str_replace(
            [
                '1. اعرضي أولًا باقة **1000 ميجا** — الأشهر لمعظم الناس.',
                'مثال قصير: "أنسب إشي لأغلب الناس باقة الـ1000 ميجا."',
                '2. إذا سأل عن السعر: **ما تخترعي رقم**. إذا ما عندك سعر معتمد بالمعلومات، قولي بتتأكدي من السعر وترجعي عليه أو بتحوّلي لمندوب.',
            ],
            [
                '1. الأسعار فوق هي المعتمدة. חבילת الـ1000 ميغا هي اللي 90 بالمية من الناس بوخذوها إذا ما حدّد سرعة.',
                'مثال قصير: "חבילת الـ1000 ميغا هي اللي 90 بالمية من الناس بوخذوها. 129 شيكل عالشهر، لأول سنة، وبعدها بتغير لـ159 عالشهر."',
                '2. إذا سأل عن السعر: جاوب من الأرقام المعتمدة فورًا، وقل عالشهر. ممنوع سعر غير موجود فوق.',
            ],
            $existing,
        );

        return trim($prices."\n\n".$existing);
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function speedcomPackageWording(array $sections): array
    {
        foreach ($sections as $name => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $sections[$name][$key] = str_replace(
                    ['باقات', 'الباقة', 'باقة الـ', 'باقة ال', 'باقة', 'حزم', 'حزمة'],
                    ['חבילות', 'החבילה', 'חבילת الـ', 'חבילת ال', 'חבילה', 'חבילות', 'חבילה'],
                    $value,
                );
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function localizeSectionStrings(array $sections, \App\Support\InternetCompanyProfile $profile): array
    {
        foreach ($sections as $name => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (is_string($value)) {
                    $sections[$name][$key] = $profile->localize($value);
                }
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, array<string, mixed>>
     */
    public function normalize(?array $input): array
    {
        $base = $this->emptySections();
        if (! is_array($input)) {
            return $base;
        }

        foreach ($base as $section => $fields) {
            $incoming = is_array($input[$section] ?? null) ? $input[$section] : [];
            foreach ($fields as $key => $default) {
                $value = $incoming[$key] ?? $default;
                if ($key === 'languages') {
                    if (is_string($value)) {
                        $value = array_values(array_filter(array_map('trim', explode(',', $value))));
                    } elseif (! is_array($value)) {
                        $value = [];
                    } else {
                        $value = array_values(array_filter(array_map(
                            static fn ($v) => is_string($v) ? trim($v) : '',
                            $value,
                        )));
                    }
                } elseif (is_string($value)) {
                    $value = trim($value);
                } else {
                    $value = $default;
                }
                $base[$section][$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>|null  $sections
     * @return list<string>
     */
    public function activeSectionNames(?array $sections): array
    {
        $normalized = $this->normalize($sections);
        $active = [];

        foreach ($normalized as $name => $fields) {
            foreach ($fields as $value) {
                if (is_array($value) && $value !== []) {
                    $active[] = $name;
                    break;
                }
                if (is_string($value) && trim($value) !== '') {
                    $active[] = $name;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Compile prompt sections into one deterministic system prompt.
     * Uses Arabic section headings so the MALAN persona stays intact.
     *
     * @param  array<string, mixed>|null  $sections
     */
    public function compile(?array $sections, ?string $fallbackSystemPrompt = null): string
    {
        $normalized = $this->normalize($sections);
        $parts = [];
        $seen = [];

        $append = function (string $heading, string $body) use (&$parts, &$seen): void {
            $body = trim($body);
            if ($body === '') {
                return;
            }
            $fingerprint = mb_strtolower(preg_replace('/\s+/u', ' ', $body) ?? $body);
            if (isset($seen[$fingerprint])) {
                return;
            }
            $seen[$fingerprint] = true;
            $parts[] = '## '.$heading."\n".$body;
        };

        $fieldBlock = function (array $pairs): string {
            $lines = [];
            foreach ($pairs as $label => $value) {
                if (is_array($value)) {
                    $value = implode('، ', array_filter(array_map('strval', $value)));
                }
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $trimmed = trim($value);
                // Long multi-line values: label then body
                if (str_contains($trimmed, "\n") || mb_strlen($trimmed) > 80) {
                    $lines[] = $label.":\n".$trimmed;
                } else {
                    $lines[] = $label.': '.$trimmed;
                }
            }

            return implode("\n\n", $lines);
        };

        $id = $normalized['identity'];
        $append('البرومت الرئيسي — مين أنا', $fieldBlock([
            'اسم البوت' => $id['bot_name'],
            'الشركة' => $id['company_name'],
            'الدور' => $id['role'],
            'اللغات' => $id['languages'],
            'اللهجة' => $id['dialect'],
            'النبرة' => $id['tone'],
        ]));

        $biz = $normalized['business'];
        $append('عن الشركة', $fieldBlock([
            'الوصف' => $biz['description'],
            'الخدمات' => $biz['services'],
            'المناطق' => $biz['locations'],
            'ساعات العمل' => $biz['working_hours'],
            'معلومات التواصل' => $biz['contact_information'],
            'معلومات تُطلب كثيرًا / المبيعات' => $biz['frequently_requested_information'],
        ]));

        $beh = $normalized['conversation_behavior'];
        $append('أسلوب الحكي', $fieldBlock([
            'الترحيب' => $beh['greeting'],
            'طول الرد' => $beh['answer_length'],
            'الرسمية' => $beh['formality'],
            'الإيموجي' => $beh['emoji_usage'],
            'قواعد المتابعة' => $beh['follow_up_rules'],
            'الزبون الغاضب' => $beh['angry_customer_handling'],
            'تحويل لمندوب' => $beh['human_handoff_rules'],
            'تأكيد المهمة قبل الرفع' => $beh['task_confirmation_rules'] ?? '',
        ]));

        $res = $normalized['restrictions'];
        $append('قيود وممنوعات', $fieldBlock([
            'مواضيع ممنوعة' => $res['forbidden_topics'],
            'ادعاءات ممنوعة' => $res['forbidden_claims'],
            'معلومات حسّاسة' => $res['sensitive_information_rules'],
            'قواعد الدفع' => $res['payment_rules'],
            'متطلبات التحقق' => $res['verification_requirements'],
            'تصعيد طارئ' => $res['emergency_escalation'],
        ]));

        $wf = $normalized['malan_workflows'];
        $append('سير عمل ملان', $fieldBlock([
            'تعريف الزبون' => $wf['customer_identification'],
            'دعم التقطيع / الانقطاع' => $wf['outage_support'],
            'الدعم الفني' => $wf['technical_support'],
            'الدفع' => $wf['payment'],
            'إثبات التحويل البنكي' => $wf['bank_transfer_proof'],
            'إعادة تفعيل الخدمة' => $wf['service_reactivation'],
            'التصعيد البشري' => $wf['human_escalation'],
        ]));

        $custom = trim((string) ($normalized['advanced']['custom_instructions'] ?? ''));
        $append('تعليمات إضافية', $custom);

        $compiled = trim(implode("\n\n", $parts));

        if ($compiled === '') {
            return trim((string) ($fallbackSystemPrompt ?? ''));
        }

        return $compiled;
    }

    /**
     * Persist compiled system_prompt from prompt_sections on the instance.
     */
    public function applyToInstance(ChatbotInstance $instance, array $sections): ChatbotInstance
    {
        $normalized = $this->normalize($sections);
        $compiled = $this->compile($normalized, $instance->system_prompt);

        $instance->forceFill([
            'prompt_sections' => $normalized,
            'system_prompt' => $compiled !== '' ? $compiled : $instance->system_prompt,
            'settings_schema_version' => self::SCHEMA_VERSION,
        ])->save();

        return $instance->fresh();
    }
}
