<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function yachtModels(): HasMany
    {
        return $this->hasMany(YachtModel::class);
    }

    public function yachts(): HasMany
    {
        return $this->hasMany(Yacht::class);
    }
}
