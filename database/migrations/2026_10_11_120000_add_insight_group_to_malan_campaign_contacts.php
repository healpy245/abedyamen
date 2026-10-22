<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('malan_campaign_contacts', 'insight_group')) {
            Schema::table('malan_campaign_contacts', function (Blueprint $table) {
                $table->string('insight_group', 32)->nullable()->after('status');
                $table->index(['campaign_id', 'insight_group'], 'malan_contacts_campaign_insight_idx');
            });
        }

        Schema::table('malan_campaign_contacts', function (Blueprint $table) {
            $table->index('chat_id', 'malan_contacts_chat_id_idx');
            $table->index('phone_normalized', 'malan_contacts_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('malan_campaign_contacts', function (Blueprint $table) {
            $table->dropIndex('malan_contacts_chat_id_idx');
            $table->dropIndex('malan_contacts_phone_idx');
        });

        if (Schema::hasColumn('malan_campaign_contacts', 'insight_group')) {
            Schema::table('malan_campaign_contacts', function (Blueprint $table) {
                $table->dropIndex('malan_contacts_campaign_insight_idx');
                $table->dropColumn('insight_group');
            });
        }
    }
};
