<?php

use App\Http\Controllers\Auth\ResetPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Opened from the emailed reset link; "password.reset" is the route name Laravel's mail uses.
Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
Route::post('/reset-password', [ResetPasswordController::class, 'store'])
    ->middleware('throttle:password-reset')
    ->name('password.update');
