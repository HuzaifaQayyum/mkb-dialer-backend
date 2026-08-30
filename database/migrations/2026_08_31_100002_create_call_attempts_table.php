<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('dialer_session_id')->nullable()
                ->constrained('dialer_sessions')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            // Agent the answered call was bridged to.
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('phone');
            $table->string('direction')->default('outbound');

            // queued -> dialing -> ringing -> answered -> bridged -> completed
            //                                         \-> failed | no-answer | busy
            $table->string('status')->default('queued');

            // Asterisk correlation ids.
            $table->string('channel_id')->nullable()->index();
            $table->string('agent_channel_id')->nullable()->index();
            $table->string('bridge_id')->nullable();

            $table->string('hangup_cause')->nullable();
            $table->string('disposition')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('dialed_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('bridged_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->default(0);      // lead answered -> hangup
            $table->unsignedInteger('talk_duration')->default(0); // agent bridged -> hangup
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['dialer_session_id', 'status']);
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_attempts');
    }
};
