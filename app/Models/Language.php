<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_default', 'sort_order'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted()
    {
        static::addGlobalScope('ordered', function ($query) {
            $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
        });
    }

    public function moveUp()
    {
        $previous = static::withoutGlobalScope('ordered')
            ->where('sort_order', '<', $this->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();

        if ($previous) {
            $temp = $this->sort_order;
            $this->sort_order = $previous->sort_order;
            $previous->sort_order = $temp;

            $this->save();
            $previous->save();
        }
    }

    public function moveDown()
    {
        $next = static::withoutGlobalScope('ordered')
            ->where('sort_order', '>', $this->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($next) {
            $temp = $this->sort_order;
            $this->sort_order = $next->sort_order;
            $next->sort_order = $temp;

            $this->save();
            $next->save();
        }
    }
}
