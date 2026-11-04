<?php

use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::view('/embed', 'booking', ['embed' => true]);
Route::get('/appointments/manage', [BookingController::class, 'manage']);
Route::get('/appointments/{reference}/move', [BookingController::class, 'edit'])->whereUuid('reference');
Route::patch('/appointments/{reference}', [BookingController::class, 'move'])->whereUuid('reference')->middleware('throttle:30,1,booking-manage');
Route::post('/appointments/{reference}/cancel', [BookingController::class, 'cancel'])->whereUuid('reference')->middleware('throttle:30,1,booking-manage');
