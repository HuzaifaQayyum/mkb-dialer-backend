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
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
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
