<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'tags',
        'company_id',
        'campaign_id',
        'company_name',
        'address',
        'city',
        'state',
        'notes',
        'stage',
        'value',
        'callback_date',
    ];

    protected $casts = [
        'tags' => 'array',
        'value' => 'float',
        'callback_date' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The lead payload the console renders on a connected call. Every
     * endpoint that hands a lead to an agent returns this exact shape, so
     * the UI never has to care which one it came from.
     */
    public function toLeadPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'company' => $this->company_name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'notes' => $this->notes,
            'stage' => $this->stage ?? 'new',
            'value' => (float) ($this->value ?? 0),
            'callbackDate' => $this->callback_date?->toIso8601String(),
            'campaignId' => $this->campaign_id ? (string) $this->campaign_id : null,
            'tags' => $this->tags ?? [],
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope('company', function ($builder) {
            if (\App\Http\Middleware\TenantScopeMiddleware::$companyId) {
                $builder->where('company_id', \App\Http\Middleware\TenantScopeMiddleware::$companyId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = \App\Http\Middleware\TenantScopeMiddleware::$companyId;
            }
        });
    }
}
