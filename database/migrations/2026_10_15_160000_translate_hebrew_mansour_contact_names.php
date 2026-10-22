<?php

use App\Services\AiChatbot\HebrewName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mansour_contacts')
            ->whereNotNull('name')
            ->orderBy('id')
            ->get(['id', 'name', 'conversation_id'])
            ->each(function (object $contact): void {
                $arabic = HebrewName::arabic((string) $contact->name);
                if ($arabic === '' || $arabic === $contact->name) {
                    return;
                }

                DB::table('mansour_contacts')->where('id', $contact->id)->update(['name' => $arabic]);

                if ($contact->conversation_id === null) {
                    return;
                }

                $conversation = DB::table('ai_chatbot_conversations')
                    ->where('id', $contact->conversation_id)
                    ->first(['id', 'contact_name', 'title']);
                if ($conversation === null) {
                    return;
                }

                $updates = [];
                if ((string) $conversation->contact_name === (string) $contact->name) {
                    $updates['contact_name'] = $arabic;
                }
                if ((string) $conversation->title === (string) $contact->name) {
                    $updates['title'] = $arabic;
                }
                if ($updates !== []) {
                    DB::table('ai_chatbot_conversations')->where('id', $conversation->id)->update($updates);
                }
            });
    }

    public function down(): void
    {
        // The Hebrew spelling is not kept beside the Arabic name.
    }
};
