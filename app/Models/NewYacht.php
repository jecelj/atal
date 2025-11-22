<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class NewYacht extends Yacht
{
    protected $table = 'yachts';

    protected static function booted(): void
    {
        static::addGlobalScope('new', function (Builder $builder) {
            $builder->where('type', 'new');
        });

        static::creating(function ($model) {
            $model->type = 'new';
        });
    }
}
