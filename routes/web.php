<?php

use Illuminate\Support\Facades\Route;

// Redirect root to admin panel
Route::redirect('/', '/admin');
