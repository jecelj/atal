<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

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
}
