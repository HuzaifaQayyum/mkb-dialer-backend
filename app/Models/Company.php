<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'three_way_slots',
    ];

    protected $casts = [
        'three_way_slots' => 'array',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user')
                    ->withPivot('role', 'status', 'queue_eligible', 'sip_extension', 'active_campaign_id')
                    ->withTimestamps();
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }
}
