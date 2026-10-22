<?php

use App\Models\AiChatbot\ChatbotInstance;
use App\Services\AiChatbot\PromptCompiler;
use Database\Seeders\SpeedcomChatbotInstanceSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $compiler = app(PromptCompiler::class);
        $sections = $compiler->speedcomDefaultSections();
        $compiled = $compiler->compile($sections);

        ChatbotInstance::query()
            ->where(function ($query): void {
                $query->where('integration_type', 'speedcom')
                    ->orWhere('name', SpeedcomChatbotInstanceSeeder::INSTANCE_NAME);
            })
            ->each(function (ChatbotInstance $instance) use ($sections, $compiled): void {
                $instance->forceFill([
                    'system_prompt' => $compiled,
                    'prompt_sections' => $sections,
                    'settings_schema_version' => PromptCompiler::SCHEMA_VERSION,
                ])->save();
            });
    }

    public function down(): void
    {
        // Prompt copy refresh. Previous wording is not restored.
    }
};
