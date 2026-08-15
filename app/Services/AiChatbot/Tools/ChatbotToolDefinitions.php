<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\Tools;

use App\Models\AiChatbot\ChatbotInstance;

class ChatbotToolDefinitions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forInstance(ChatbotInstance $instance, string $channel = 'web', bool $voiceMode = false): array
    {
        if (! $instance->hasMalanIntegration()) {
            return [];
        }

        // Phone calls need fewer tools → smaller schema → faster first model round-trip.
        if ($voiceMode) {
            return [
                $this->lookupMalanCustomer(),
                $this->setMalanPaymentMethodPreference(),
                $this->createMalanSupportReport(),
            ];
        }

        return [
            $this->lookupMalanCustomer(),
            $this->createMalanSupportReport(),
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
                'description' => 'Look up an EXISTING Malan Internet customer by registered phone or identity when they report an outage, debt, or account status. Do NOT use this for new sales sign-up / registration leads. If the customer corrects a mistyped phone/ID or asks to check a different number, call again with force_refresh=true and the new value. If the customer says to use the WhatsApp number they are chatting from (e.g. "على الرقم الي بحكي منه", "هالرقم", "this number"), set lookup_type=phone and value=whatsapp_chat_phone — never invent a phone number.',
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
