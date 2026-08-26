<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UsedYacht extends Yacht
{
    protected $table = 'yachts';

    protected static function booted(): void
    {
        static::addGlobalScope('used', function (Builder $builder) {
            $builder->where('type', 'used');
        });

        static::creating(function ($model) {
            $model->type = 'used';
        });
    }

    /**
     * WordPress sites that are allowed to receive this used yacht.
     */
    public function syncSites(): BelongsToMany
    {
        return $this->belongsToMany(SyncSite::class, 'used_yacht_sync_site')
            ->withTimestamps();
    }
}
