<?php

use App\Http\Controllers\LivewireUpdateGetFallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/livewire/update', LivewireUpdateGetFallbackController::class)
    ->name('livewire.update.get-fallback');

// Filament handles root path
