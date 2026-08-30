<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DialerSession extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'status',
        'concurrency',
        'pacing_ratio',
        'caller_id',
        'lead_tag',
        'created_by',
        'started_at',
        'auto_close_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'auto_close_at' => 'datetime',
            'ended_at' => 'datetime',
            'pacing_ratio' => 'float',
            'concurrency' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function attempts()
    {
        return $this->hasMany(CallAttempt::class);
    }

    /** Calls currently occupying a line. */
    public function liveAttempts()
    {
        return $this->attempts()->whereIn('status', CallAttempt::LIVE_STATUSES);
    }
}
