<?php

use App\Http\Controllers\StaffBookingController;
use Illuminate\Support\Facades\Route;

Route::get('/schedule', [StaffBookingController::class, 'index']);
Route::post('/schedule/slots', [StaffBookingController::class, 'addSlot']);
Route::post('/schedule/slots/{id}', [StaffBookingController::class, 'availability'])->whereNumber('id');
Route::post('/schedule/book', [StaffBookingController::class, 'book']);
Route::post('/schedule/{reference}/move', [StaffBookingController::class, 'move'])->whereUuid('reference');
Route::post('/schedule/{reference}/cancel', [StaffBookingController::class, 'cancel'])->whereUuid('reference');
Route::get('/api/appointments', [StaffBookingController::class, 'api']);
