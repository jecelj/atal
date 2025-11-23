<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class News extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'slug',
        'title',
        'content',
        'excerpt',
        'featured_image',
        'published_at',
        'is_active',
        'custom_fields',
    ];

    protected $casts = [
        'title' => 'array',
        'content' => 'array',
        'excerpt' => 'array',
        'published_at' => 'datetime',
        'is_active' => 'boolean',
        'custom_fields' => 'array',
    ];

    public function syncSites()
    {
        return $this->belongsToMany(SyncSite::class);
    }
}
