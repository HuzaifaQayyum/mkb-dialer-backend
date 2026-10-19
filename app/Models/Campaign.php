<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = [
        'company_id', 'name', 'type', 'status',
        'import_file_name', 'dial_rate', 'created_by',
    ];

    protected function casts(): array
    {
        return ['dial_rate' => 'float'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Leads belong to this campaign alone — never the global contact list. */
    public function leads()
    {
        return $this->hasMany(Contact::class);
    }

    public function agents()
    {
        return $this->belongsToMany(User::class, 'campaign_user')->withTimestamps();
    }
}
