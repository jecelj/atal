<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncSite extends Model
{
    protected $fillable = [
        'name',
        'url',
        'api_key',
        'is_active',
        'order',
        'last_synced_at',
        'last_sync_result',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
