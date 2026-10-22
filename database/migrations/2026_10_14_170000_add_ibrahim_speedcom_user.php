<?php

use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotInstanceUser;
use App\Models\User;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use App\Services\AiChatbot\PromptCompiler;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Database\Seeders\SpeedcomCompanyMemberSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => SpeedcomCompanyMemberSeeder::IBRAHIM_EMAIL],
            [
                'name' => 'Ibrahim',
                'password' => Hash::make(SpeedcomCompanyMemberSeeder::IBRAHIM_PASSWORD),
                'email_verified_at' => now(),
                'is_admin' => false,
                'projects' => ['ai-chatbot'],
            ],
        );

        $instances = ChatbotInstance::query()
            ->where(function ($query): void {
                $query->where('integration_type', 'speedcom')
                    ->orWhere('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME);
            })
            ->get();

        $otherGrants = ChatbotInstanceUser::query()->where('user_id', $user->id);
        if ($instances->isNotEmpty()) {
            $otherGrants->whereNotIn('instance_id', $instances->pluck('id'));
        }
        $otherGrants->delete();

        $compiler = app(PromptCompiler::class);
        $sections = $compiler->speedcomDefaultSections();
        $compiled = $compiler->compile($sections);
        $authz = app(ChatbotAuthorizationService::class);

        foreach ($instances as $instance) {
            $authz->grantAccess($instance, $user, ChatbotInstanceUser::ROLE_MANAGER);
            $instance->forceFill([
                'system_prompt' => $compiled,
                'prompt_sections' => $sections,
                'settings_schema_version' => PromptCompiler::SCHEMA_VERSION,
            ])->save();
        }
    }

    public function down(): void
    {
        User::query()->where('email', SpeedcomCompanyMemberSeeder::IBRAHIM_EMAIL)->delete();
    }
};
