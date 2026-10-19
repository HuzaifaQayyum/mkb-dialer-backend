<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallAttempt extends Model
{
    /** Statuses that mean the call still holds a line on the trunk. */
    public const LIVE_STATUSES = ['queued', 'dialing', 'ringing', 'answered', 'bridged'];

    /** Statuses that mean the call is over. */
    public const TERMINAL_STATUSES = ['completed', 'failed', 'no-answer', 'busy', 'abandoned'];

    protected $fillable = [
        'company_id',
        'dialer_session_id',
        'contact_id',
        'user_id',
        'phone',
        'direction',
        'status',
        'campaign_id',
        'channel_id',
        'agent_channel_id',
        'third_party_channel_id',
        'third_party_number',
        'bridge_id',
        'recording_name',
        'recording_format',
        'recording_duration',
        'recording_status',
        'hangup_cause',
        'disposition',
        'notes',
        'dialed_at',
        'answered_at',
        'bridged_at',
        'ended_at',
        'duration',
        'talk_duration',
    ];

    protected function casts(): array
    {
        return [
            'dialed_at' => 'datetime',
            'answered_at' => 'datetime',
            'bridged_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration' => 'integer',
            'talk_duration' => 'integer',
        ];
    }

    public function session()
    {
        return $this->belongsTo(DialerSession::class, 'dialer_session_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
