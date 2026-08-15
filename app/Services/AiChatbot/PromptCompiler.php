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
