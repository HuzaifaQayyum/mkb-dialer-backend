<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts carried only name/phone/email/tags, so an agent taking a call
     * saw almost nothing about who they were talking to. These are the fields
     * the console renders on a connected call.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('email');
            $table->string('address')->nullable()->after('company_name');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->text('notes')->nullable()->after('state');

            // Pipeline position. Mirrors the frontend's Opportunity stages.
            $table->string('stage')->default('new')->after('notes');
            $table->decimal('value', 12, 2)->default(0)->after('stage');
            $table->timestamp('callback_date')->nullable()->after('value');

            $table->index(['company_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'stage']);
            $table->dropColumn([
                'company_name', 'address', 'city', 'state',
                'notes', 'stage', 'value', 'callback_date',
            ]);
        });
    }
};
