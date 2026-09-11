<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\Tools;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;

class ChatbotToolDefinitions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forInstance(
        ChatbotInstance $instance,
        string $channel = 'web',
        bool $voiceMode = false,
        ?ChatbotConversation $conversation = null,
    ): array {
        if (! $instance->hasMalanIntegration()) {
            return [];
        }

        // Campaign outreach chats are sales-first — only expose lead tool with campaign-safe description.
        if ($conversation !== null && $conversation->isCampaignLeadBot()) {
            return [
                $this->createMalanLeadForCampaign($instance),
            ];
        }

        // Phone calls need fewer tools → smaller schema → faster first model round-trip.
        if ($voiceMode) {
            return [
                $this->lookupMalanCustomer(),
                $this->setMalanPaymentMethodPreference(),
                $this->createMalanSupportReport(),
                $this->createMalanTask(),
                $this->createMalanLead(),
            ];
        }

        return [
            $this->lookupMalanCustomer(),
            $this->createMalanSupportReport(),
            $this->createMalanTask(),
            $this->createMalanLead(),
            $this->chargeMalanSavedPaymentMethod(),
            $this->createMalanOneTimePaymentLink(),
            $this->checkMalanPaymentStatus(),
            $this->requestMalanServiceReactivation(),
            $this->setMalanPaymentMethodPreference(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupMalanCustomer(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'lookup_malan_customer',
                'description' => 'Look up an EXISTING Malan Internet customer by registered phone or identity when they report an outage, debt, or account status. Returns customer status, debt, and radius (classification/state) for technical decisions. FORBIDDEN during new sign-up / "بدي نت" / package registration — use create_malan_lead instead, and never ask for identity on new signup. If the customer corrects a mistyped phone/ID or asks to check a different number, call again with force_refresh=true and the new value. If the customer says to use the WhatsApp number they are chatting from (e.g. "على الرقم الي بحكي منه", "هالرقم", "this number"), set lookup_type=phone and value=whatsapp_chat_phone — never invent a phone number.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'lookup_type' => [
                            'type' => 'string',
                            'enum' => ['phone', 'identity'],
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Phone digits, identity number, or the sentinel whatsapp_chat_phone when the customer means the WhatsApp chat number.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'enum' => ['internet_outage', 'account_status', 'debt_payment'],
                        ],
                        'force_refresh' => [
                            'type' => 'boolean',
                            'description' => 'Set true when the customer asks to re-check with a corrected or different phone/identity.',
                        ],
                    ],
                    'required' => ['lookup_type', 'value', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createMalanSupportReport(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_malan_support_report',
                'description' => 'Create an internal technical support report (תמיכה טכנית) for a verified Malan customer ONLY after: ACTIVE status, basic troubleshooting, you showed the customer a draft task summary, and the customer explicitly approved it. Pass confirmed_by_customer=true only after that approval. Never create the report before confirmation.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'issue_type' => [
                            'type' => 'string',
                            'enum' => ['full_outage'],
                        ],
                        'summary' => [
                            'type' => 'string',
                            'description' => 'Final short task text agreed with the customer.',
                        ],
                        'confirmed_by_customer' => [
                            'type' => 'boolean',
                            'description' => 'Must be true only after the customer approved the draft task text.',
                        ],
                    ],
                    'required' => ['issue_type', 'summary', 'confirmed_by_customer'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createMalanTask(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_malan_task',
                'description' => 'Create a Malan CRM task for a specialist (accounting/collections or technical) ONLY after you showed the customer a draft task text and they explicitly approved it. Always pass confirmed_by_customer=true only after approval. Use department=accounting for DEBT_DISCONNECTED. Use department=technical for ACTIVE outage after checking radius from lookup (connected=do not rush escalate; expired=mention renew radius; other=normal tech task). Never invent customer_id or to_user_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'department' => [
                            'type' => 'string',
                            'enum' => ['accounting', 'technical'],
                            'description' => 'accounting = גבייה/محاسبة; technical = תמיכה טכנית when CRM assignee is configured.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Short task title (max ~255 chars).',
                        ],
                        'subject' => [
                            'type' => 'string',
                            'description' => 'Final task body agreed with the customer (what you read in the draft).',
                        ],
                        'summary' => [
                            'type' => 'string',
                            'description' => 'Alias for subject if subject is omitted.',
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['urgent', 'non_urgent'],
                        ],
                        'confirmed_by_customer' => [
                            'type' => 'boolean',
                            'description' => 'Must be true only after the customer approved the draft task text.',
                        ],
                    ],
                    'required' => ['department', 'subject', 'confirmed_by_customer'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createMalanLead(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_malan_lead',
                'description' => 'Create a Malan CRM sales lead for a NEW sign-up (اشتراك جديد). Do NOT use lookup_malan_customer for new customers. Collect full_name, phone, and city_name, read them back for confirmation, then call with confirmed_by_customer=true. Server picks leads_sources_id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'full_name' => [
                            'type' => 'string',
                            'description' => 'Customer full name.',
                        ],
                        'phone' => [
                            'type' => 'string',
                            'description' => 'Contact phone digits (Israeli mobile preferred).',
                        ],
                        'city_name' => [
                            'type' => 'string',
                            'description' => 'Town / area name.',
                        ],
                        'identity' => [
                            'type' => 'string',
                            'description' => 'Optional identity number digits only.',
                        ],
                        'with_fiber' => [
                            'type' => 'integer',
                            'enum' => [0, 1],
                            'description' => '1 if the customer already has fiber at home (عنده סיב אופטי), 0 if they do not.',
                        ],
                        'note' => [
                            'type' => 'string',
                            'description' => 'Optional CRM note: preferred call time (e.g. 16:00), call later, or other handoff details from this chat.',
                        ],
                        'confirmed_by_customer' => [
                            'type' => 'boolean',
                            'description' => 'Must be true only after the customer confirmed name/phone/city.',
                        ],
                    ],
                    'required' => ['full_name', 'phone', 'city_name', 'confirmed_by_customer'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Campaign variant: tool must NOT push the model to collect details on greetings.
     *
     * @return array<string, mixed>
     */
    private function createMalanLeadForCampaign(ChatbotInstance $instance): array
    {
        $city = $instance->campaignDefaultCity();
        $tool = $this->createMalanLead();
        $tool['function']['description'] = 'Campaign sales ONLY after clear buying intent or next-step question. '
            .'FORBIDDEN to ask for lead fields on greetings. '
            .'While MODE=campaign_sales: do not call this tool. '
            .'While MODE=campaign_lead_collection: if contact_on_current_number is false, ask once '
            .'«بدك اخليها تتواصل معك عهاذ الرقم؟». Affirmatives (بنفع/اه/تمام…) mean phone=whatsapp_chat_phone — NEVER re-ask. '
            .'Then ask «تمام، اعطيني اسمك الكامل بس.» NEVER ask for city — always city_name='.$city.'. '
            .'When name+phone ready, call immediately with confirmed_by_customer=true, '
            .'with_fiber=1 if they said they have סיב אופטי at home else 0, '
            .'and note=preferred call time / later-call request if they said that. '
            .'then close with «تمام {first_name}، رح تتواصل معك الصبيه كمان شوي 👍». '
            .'Opt-out customers: never call this tool. Server picks leads_sources_id.';

        $tool['function']['parameters']['properties']['phone']['description'] =
            'Contact phone digits, OR the sentinel whatsapp_chat_phone when the customer means the current WhatsApp chat number.';
        $tool['function']['parameters']['properties']['city_name']['description'] =
            'Always '.$city.' for this campaign. Do not ask the customer for city.';
        $tool['function']['parameters']['properties']['confirmed_by_customer']['description'] =
            'True when full_name and phone are known from the customer (city is always '.$city.').';

        return $tool;
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeMalanSavedPaymentMethod(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'charge_malan_saved_payment_method',
                'description' => 'Attempt to charge the customer saved payment method. Amount and customer are taken from verified server context only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'confirmed_by_customer' => ['type' => 'boolean'],
                    ],
                    'required' => ['confirmed_by_customer'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createMalanOneTimePaymentLink(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_malan_one_time_payment_link',
                'description' => 'Create a hosted one-time payment link for another card. Never collect card details in chat.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'confirmed_by_customer' => ['type' => 'boolean'],
                        'delivery_channel' => [
                            'type' => 'string',
                            'enum' => ['whatsapp', 'web', 'voice'],
                        ],
                    ],
                    'required' => ['confirmed_by_customer', 'delivery_channel'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMalanPaymentStatus(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'check_malan_payment_status',
                'description' => 'Check status of a previously issued Malan payment attempt id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'payment_attempt_id' => ['type' => 'string'],
                    ],
                    'required' => ['payment_attempt_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMalanServiceReactivation(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'request_malan_service_reactivation',
                'description' => 'Request service reactivation after payment verification. Returns integration_pending until the real endpoint exists.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['reason'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function setMalanPaymentMethodPreference(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'set_malan_payment_method_preference',
                'description' => 'Record that the verified customer chose bank transfer or visa so the conversation can continue the correct flow.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'payment_method' => [
                            'type' => 'string',
                            'enum' => ['bank_transfer', 'visa_saved', 'visa_other'],
                        ],
                    ],
                    'required' => ['payment_method'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
