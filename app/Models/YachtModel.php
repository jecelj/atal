<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class YachtModel extends Model
{
    use HasFactory;

    protected $fillable = ['brand_id', 'name', 'slug'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function yachts(): HasMany
    {
        return $this->hasMany(Yacht::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $brand = Brand::find($model->brand_id);
                $baseName = $brand ? ($brand->name . ' ' . $model->name) : $model->name;
                $model->slug = \Illuminate\Support\Str::slug($baseName);
            }
        });
    }
}
