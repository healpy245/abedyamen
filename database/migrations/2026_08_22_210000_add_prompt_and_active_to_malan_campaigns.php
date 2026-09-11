<?php

use App\Models\Malan\MalanCampaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('malan_campaigns', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
            $table->longText('system_prompt')->nullable()->after('opening_message');
        });

        $defaultPrompt = MalanCampaign::defaultLeadSystemPrompt();
        DB::table('malan_campaigns')
            ->whereNull('system_prompt')
            ->orWhere('system_prompt', '')
            ->update([
                'is_active' => true,
                'system_prompt' => $defaultPrompt,
            ]);
    }

    public function down(): void
    {
        Schema::table('malan_campaigns', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'system_prompt']);
        });
    }
};
