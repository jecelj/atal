<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FormFieldConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'group',
        'field_key',
        'field_type',
        'label',
        'is_required',
        'is_multilingual',
        'order',
        'options',
        'validation_rules',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_multilingual' => 'boolean',
        'options' => 'array',
        'validation_rules' => 'array',
        'order' => 'integer',
    ];

    public function scopeForNewYachts($query)
    {
        return $query->where('entity_type', 'new_yacht');
    }

    public function scopeForUsedYachts($query)
    {
        return $query->where('entity_type', 'used_yacht');
    }

    public function scopeForNews($query)
    {
        return $query->where('entity_type', 'news');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
