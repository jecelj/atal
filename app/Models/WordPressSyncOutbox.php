<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordPressSyncOutbox extends Model
{
    protected $table = 'wordpress_sync_outbox';

    protected $fillable = [
        'sync_site_id', 'model_type', 'model_id', 'source_id', 'action', 'state',
        'version', 'payload', 'media_cursor', 'content_hash', 'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'media_cursor' => 'integer',
        'version' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SyncSite::class, 'sync_site_id');
    }
}
