<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncStatus extends Model
{
    protected $fillable = [
        'sync_site_id',
        'model_type',
        'model_id',
        'last_synced_at',
        'content_hash',
        'status',
        'error_message',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SyncSite::class, 'sync_site_id');
    }
}
