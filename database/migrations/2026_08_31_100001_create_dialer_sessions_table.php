<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name')->default('Bulk Session');

            // closed -> active -> draining -> closed
            $table->string('status')->default('closed');

            // Max simultaneous live calls the pacer will allow.
            $table->unsignedSmallInteger('concurrency')->default(2);
            // Lines dialed per free agent. 1.0 = progressive, >1 = predictive.
            $table->decimal('pacing_ratio', 4, 2)->default(1.00);

            $table->string('caller_id')->nullable();
            // Only dial contacts carrying this tag; null = all contacts.
            $table->string('lead_tag')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('auto_close_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_sessions');
    }
};
