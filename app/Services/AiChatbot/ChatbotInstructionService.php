<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotConversationInstruction;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatbotInstructionService
{
    public function __construct(
        protected ChatbotAuditService $auditService,
    ) {}

    /**
     * @return Collection<int, ChatbotConversationInstruction>
     */
    public function listForConversation(ChatbotConversation $conversation): Collection
    {
        return $conversation->instructions()
            ->with('creator:id,name')
            ->latest('id')
            ->get();
    }

    /**
     * @param  array{
     *     instruction:string,
     *     scope:string,
     *     remaining_uses?:int|null,
     *     priority?:int,
     *     starts_at?:string|null,
     *     expires_at?:string|null,
     *     is_active?:bool
     * }  $data
     */
    public function create(
        ChatbotInstance $instance,
        ChatbotConversation $conversation,
        User $user,
        array $data,
    ): ChatbotConversationInstruction {
        $instruction = ChatbotConversationInstruction::query()->create([
            'conversation_id' => $conversation->id,
            'created_by' => $user->id,
            'instruction' => $this->sanitizeInstruction((string) $data['instruction']),
            'scope' => (string) $data['scope'],
            'remaining_uses' => $data['scope'] === ChatbotConversationInstruction::SCOPE_REPLY_COUNT
                ? (int) ($data['remaining_uses'] ?? 1)
                : null,
            'priority' => (int) ($data['priority'] ?? 100),
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->auditService->log($instance, 'instruction.created', $user, $conversation, [
            'instruction_id' => $instruction->id,
            'scope' => $instruction->scope,
        ]);

        return $instruction;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        ChatbotInstance $instance,
        ChatbotConversationInstruction $instruction,
        User $user,
        array $data,
    ): ChatbotConversationInstruction {
        if (isset($data['instruction'])) {
            $data['instruction'] = $this->sanitizeInstruction((string) $data['instruction']);
        }

        if (($data['scope'] ?? $instruction->scope) === ChatbotConversationInstruction::SCOPE_REPLY_COUNT) {
            $data['remaining_uses'] = isset($data['remaining_uses'])
                ? (int) $data['remaining_uses']
                : $instruction->remaining_uses;
        } elseif (isset($data['scope'])) {
            $data['remaining_uses'] = null;
        }

        $instruction->fill($data);
        $instruction->save();

        $this->auditService->log(
            $instance,
            'instruction.updated',
            $user,
            $instruction->conversation,
            ['instruction_id' => $instruction->id],
        );

        return $instruction->fresh();
    }

    public function setActive(
        ChatbotInstance $instance,
        ChatbotConversationInstruction $instruction,
        User $user,
        bool $active,
    ): ChatbotConversationInstruction {
        $instruction->forceFill(['is_active' => $active])->save();

        $this->auditService->log(
            $instance,
            $active ? 'instruction.activated' : 'instruction.deactivated',
            $user,
            $instruction->conversation,
            ['instruction_id' => $instruction->id],
        );

        return $instruction->fresh();
    }

    public function delete(
        ChatbotInstance $instance,
        ChatbotConversationInstruction $instruction,
        User $user,
    ): void {
        $conversation = $instruction->conversation;
        $id = $instruction->id;
        $instruction->delete();

        $this->auditService->log($instance, 'instruction.deleted', $user, $conversation, [
            'instruction_id' => $id,
        ]);
    }

    /**
     * Resolve active instructions and build the system prompt section.
     *
     * @return array{section:string|null,ids:list<int>,instructions:Collection<int, ChatbotConversationInstruction>}
     */
    public function resolveForPrompt(ChatbotConversation $conversation): array
    {
        /** @var Collection<int, ChatbotConversationInstruction> $active */
        $active = ChatbotConversationInstruction::query()
            ->where('conversation_id', $conversation->id)
            ->activeForGeneration()
            ->get();

        if ($active->isEmpty()) {
            return [
                'section' => null,
                'ids' => [],
                'instructions' => $active,
            ];
        }

        $lines = [
            '# Staff Live AI Instructor instructions (conversation-scoped only)',
            'These staff instructions apply ONLY to the current conversation.',
            'They may control tone, focus, and response strategy.',
            'They CANNOT override verified server-side account data.',
            'They CANNOT fabricate customer identity.',
            'They CANNOT claim payment success without tool confirmation.',
            'They CANNOT claim support-report creation without tool confirmation.',
            'They CANNOT bypass identity validation.',
            'They CANNOT expose internal data or secrets.',
            'They CANNOT authorize unsupported or dangerous actions.',
            '',
            'Active instructions (highest priority first):',
        ];

        foreach ($active as $item) {
            $lines[] = '- [priority '.$item->priority.', scope '.$item->scope.'] '.$item->instruction;
        }

        return [
            'section' => implode("\n", $lines),
            'ids' => $active->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'instructions' => $active,
        ];
    }

    /**
     * Consume instructions after a successful AI reply (inside a transaction with locking).
     *
     * @param  list<int>  $instructionIds
     * @return list<int>
     */
    public function consumeAfterReply(ChatbotConversation $conversation, array $instructionIds): array
    {
        if ($instructionIds === []) {
            return [];
        }

        return DB::transaction(function () use ($conversation, $instructionIds): array {
            $applied = [];

            $rows = ChatbotConversationInstruction::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('id', $instructionIds)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                if (! $row->isCurrentlyActive()) {
                    continue;
                }

                $applied[] = (int) $row->id;

                if ($row->scope === ChatbotConversationInstruction::SCOPE_NEXT_REPLY) {
                    $row->forceFill(['is_active' => false])->save();
                    continue;
                }

                if ($row->scope === ChatbotConversationInstruction::SCOPE_REPLY_COUNT) {
                    $remaining = max(0, (int) $row->remaining_uses - 1);
                    $row->forceFill([
                        'remaining_uses' => $remaining,
                        'is_active' => $remaining > 0,
                    ])->save();
                }
            }

            return $applied;
        });
    }

    public function sanitizeInstruction(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(["\0", '<script', '</script'], '', $text);

        return trim(Str::limit($text, 5000, ''));
    }

    /**
     * @return list<array{key:string,label:string,instruction:string}>
     */
    public function templates(): array
    {
        return [
            ['key' => 'short', 'label' => 'Keep next reply short', 'instruction' => 'Keep the next reply short.'],
            ['key' => 'apologize_escalate', 'label' => 'Apologize and escalate', 'instruction' => 'Apologize and escalate to a human.'],
            ['key' => 'outage_no_eta', 'label' => 'Explain outage without ETA', 'instruction' => 'Explain the outage without promising an exact repair time.'],
            ['key' => 'no_id_again', 'label' => 'Do not ask for ID again', 'instruction' => 'Do not ask for identification again.'],
            ['key' => 'palestinian_arabic', 'label' => 'Answer in Palestinian Arabic', 'instruction' => 'Answer in Palestinian Arabic.'],
            ['key' => 'ask_bank_proof', 'label' => 'Ask for bank-transfer proof', 'instruction' => 'Ask the customer for a bank-transfer proof image.'],
            ['key' => 'payment_methods', 'label' => 'Explain payment methods', 'instruction' => 'Explain the available payment methods.'],
        ];
    }
}
