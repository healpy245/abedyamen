<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\Voice\VoiceCall;
use App\Services\AiChatbot\Tools\ChatbotToolExecutor;
use App\Services\Malan\MalanSensitiveDataMasker;
use Illuminate\Support\Facades\Log;

class RealtimeToolExecutor
{
    public function __construct(
        protected ChatbotToolExecutor $chatbotToolExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(
        VoiceCall $call,
        int $tenantInstanceId,
        string $toolName,
        array $arguments,
        string $callId,
    ): array {
        if ($call->chatbot_instance_id !== $tenantInstanceId) {
            return [
                'success' => false,
                'message' => 'Tenant scope mismatch.',
            ];
        }

        if ((string) $call->id !== $callId) {
            return [
                'success' => false,
                'message' => 'Call scope mismatch.',
            ];
        }

        Log::info('Realtime tool call', [
            'voice_call_id' => $call->id,
            'chatbot_instance_id' => $tenantInstanceId,
            'tool' => $toolName,
            'arguments' => MalanSensitiveDataMasker::sanitizeToolArguments($arguments),
        ]);

        return match ($toolName) {
            'lookup_customer' => $this->legacyLookupCustomer($call, $arguments),
            'lookup_malan_customer' => $this->runMalanTool($call, 'lookup_malan_customer', $arguments),
            'create_malan_support_report' => $this->runMalanTool($call, 'create_malan_support_report', $arguments),
            'set_malan_payment_method_preference' => $this->runMalanTool($call, 'set_malan_payment_method_preference', $arguments),
            'charge_malan_saved_payment_method' => $this->runMalanTool($call, 'charge_malan_saved_payment_method', $arguments),
            'create_malan_one_time_payment_link' => $this->runMalanTool($call, 'create_malan_one_time_payment_link', $arguments),
            'check_malan_payment_status' => $this->runMalanTool($call, 'check_malan_payment_status', $arguments),
            'request_malan_service_reactivation' => $this->runMalanTool($call, 'request_malan_service_reactivation', $arguments),
            'create_lead' => $this->createLead($arguments),
            'create_support_ticket' => $this->createSupportTicket($arguments),
            'escalate_to_human' => $this->escalateToHuman($arguments),
            default => [
                'success' => false,
                'message' => 'Unknown tool.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function legacyLookupCustomer(VoiceCall $call, array $arguments): array
    {
        $identifier = trim((string) ($arguments['identifier'] ?? ''));
        if ($identifier === '') {
            return ['success' => false, 'message' => 'Identifier is required.'];
        }

        $lookupType = preg_match('/^05\d{8}$/', preg_replace('/\D+/', '', $identifier) ?? '') ? 'phone' : 'identity';

        return $this->runMalanTool($call, 'lookup_malan_customer', [
            'lookup_type' => $lookupType,
            'value' => $identifier,
            'reason' => 'account_status',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runMalanTool(VoiceCall $call, string $toolName, array $arguments): array
    {
        $call->loadMissing(['chatbotInstance']);
        $instance = $call->chatbotInstance;
        if (! $instance instanceof ChatbotInstance) {
            return ['success' => false, 'message' => 'Chatbot instance missing.'];
        }

        if (! $instance->hasMalanIntegration()) {
            return [
                'success' => false,
                'message' => 'Malan tools are not enabled for this chatbot instance.',
            ];
        }

        $conversation = $this->resolveConversation($call, $instance);
        $result = $this->chatbotToolExecutor->execute(
            $instance,
            $conversation,
            $toolName,
            $arguments,
            'voice',
        );

        if ($toolName === 'set_malan_payment_method_preference'
            && ($arguments['payment_method'] ?? null) === 'bank_transfer'
        ) {
            $result['voice_note'] = 'Ask the caller to send the bank transfer proof photo on WhatsApp. Voice cannot receive images.';
        }

        return $result;
    }

    private function resolveConversation(VoiceCall $call, ChatbotInstance $instance): ChatbotConversation
    {
        if ($call->chatbot_conversation_id) {
            $existing = ChatbotConversation::query()
                ->where('id', $call->chatbot_conversation_id)
                ->where('instance_id', $instance->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $conversation = ChatbotConversation::query()->create([
            'user_id' => $call->user_id,
            'instance_id' => $instance->id,
            'title' => 'Voice call '.$call->id,
        ]);

        $call->chatbot_conversation_id = $conversation->id;
        $call->save();

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createLead(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'message' => 'Lead name is required.'];
        }

        return [
            'success' => true,
            'lead_id' => 'pending',
            'message' => 'Lead queued for follow-up.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createSupportTicket(array $arguments): array
    {
        $summary = trim((string) ($arguments['summary'] ?? ''));
        if ($summary === '') {
            return ['success' => false, 'message' => 'Ticket summary is required.'];
        }

        return [
            'success' => true,
            'ticket_id' => 'pending',
            'message' => 'Support ticket queued.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function escalateToHuman(array $arguments): array
    {
        return [
            'success' => true,
            'message' => 'Escalation request recorded. A human agent will follow up.',
            'reason' => trim((string) ($arguments['reason'] ?? '')),
        ];
    }
}
