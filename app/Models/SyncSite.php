<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncSite extends Model
{
    protected $fillable = [
        'name',
        'url',
        'api_key',
        'default_language',
        'supported_languages',
        'sync_all_brands',
        'brand_restrictions',
        'is_active',
        'order',
        'last_synced_at',
        'last_sync_result',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supported_languages' => 'array',
        'sync_all_brands' => 'boolean',
        'brand_restrictions' => 'array',
        'last_synced_at' => 'datetime',
        'last_sync_result' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
