<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class CharterYacht extends Yacht
{
    protected $table = 'yachts';

    protected static function booted(): void
    {
        static::addGlobalScope('charter', function (Builder $builder) {
            $builder->where('type', 'charter');
        });

        static::creating(function ($model) {
            $model->type = 'charter';
        });
    }
    public function charterLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CharterLocation::class);
    }
}
