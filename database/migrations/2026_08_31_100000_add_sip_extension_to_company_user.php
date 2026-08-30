<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each agent needs a SIP endpoint the dialer can bridge an answered
     * lead into. Extensions 1001-1006 are defined in asterisk-config/pjsip.conf.
     */
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('sip_extension')->nullable()->after('queue_eligible');
        });

        // Hand out the pre-provisioned extensions to existing members.
        $rows = DB::table('company_user')->orderBy('id')->get();
        $next = [];
        foreach ($rows as $row) {
            $seq = ($next[$row->company_id] ?? 0);
            if ($seq > 5) {
                continue; // only 1001-1006 exist in pjsip.conf
            }
            DB::table('company_user')
                ->where('id', $row->id)
                ->update(['sip_extension' => (string) (1001 + $seq)]);
            $next[$row->company_id] = $seq + 1;
        }
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn('sip_extension');
        });
    }
};
