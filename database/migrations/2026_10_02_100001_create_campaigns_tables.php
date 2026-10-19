<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A campaign owns its own lead list and its own calling team. Leads
     * imported into a campaign must not leak into the company-wide contact
     * list, and an agent working campaign A must only ever be handed A's
     * numbers.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('call');      // call | sms
            $table->string('status')->default('active');  // active | paused | completed
            $table->string('import_file_name')->nullable();
            $table->decimal('dial_rate', 4, 2)->default(1.00);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Agents assigned to a campaign. One agent may sit on many campaigns.
        Schema::create('campaign_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
        });

        // A lead belongs to exactly one campaign, or to none (the global list).
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('company_id')
                ->constrained('campaigns')->nullOnDelete();
            $table->index(['campaign_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropIndex(['campaign_id', 'stage']);
            $table->dropColumn('campaign_id');
        });
        Schema::dropIfExists('campaign_user');
        Schema::dropIfExists('campaigns');
    }
};
