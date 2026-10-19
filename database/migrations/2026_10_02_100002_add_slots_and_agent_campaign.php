<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Six admin-managed numbers an agent can pull into a 3-way call.
            $table->json('three_way_slots')->nullable()->after('slug');
        });

        Schema::table('company_user', function (Blueprint $table) {
            // Which campaign this agent is currently working. Scopes every
            // lead handed to them while it is set.
            $table->foreignId('active_campaign_id')->nullable()->after('sip_extension')
                ->constrained('campaigns')->nullOnDelete();
        });

        Schema::table('call_attempts', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('dialer_session_id')
                ->constrained('campaigns')->nullOnDelete();
            // Third-party leg of a 3-way, so it can be dropped on its own.
            $table->string('third_party_channel_id')->nullable()->after('agent_channel_id');
            $table->string('third_party_number')->nullable()->after('third_party_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn(['campaign_id', 'third_party_channel_id', 'third_party_number']);
        });
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropForeign(['active_campaign_id']);
            $table->dropColumn('active_campaign_id');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('three_way_slots');
        });
    }
};
