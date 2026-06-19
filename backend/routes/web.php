<?php

use App\Http\Controllers\SharePreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/photos/{photo}', [SharePreviewController::class, 'photo'])
    ->whereNumber('photo');
