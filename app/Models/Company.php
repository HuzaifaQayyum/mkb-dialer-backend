<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user')
                    ->withPivot('role', 'status', 'queue_eligible')
                    ->withTimestamps();
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
