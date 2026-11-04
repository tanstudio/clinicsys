<?php

use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'booking');
Route::view('/privacy', 'privacy');
Route::get('/availability', [BookingController::class, 'availability']);
Route::post('/appointments', [BookingController::class, 'store'])->middleware('throttle:12,1,booking-create');

require __DIR__.'/staff.php';
foreach (['booking_extra', 'messaging_webhook'] as $module) {
    require __DIR__."/$module.php";
}
