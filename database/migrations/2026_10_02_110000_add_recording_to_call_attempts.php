<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            $table->string('recording_name')->nullable()->after('bridge_id');
            $table->string('recording_format', 8)->default('wav')->after('recording_name');
            $table->unsignedInteger('recording_duration')->nullable()->after('recording_format');
            $table->string('recording_status')->nullable()->after('recording_duration');
        });
    }

    public function down(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'recording_name', 'recording_format', 'recording_duration', 'recording_status',
            ]);
        });
    }
};
