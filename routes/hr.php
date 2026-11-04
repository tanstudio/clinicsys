<?php

use App\Http\Controllers\HrController;
use Illuminate\Support\Facades\Route;

Route::prefix('hr')->controller(HrController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/people/{staff}', 'show')->whereNumber('staff');
    Route::post('/people/{staff}/profile', 'profile')->whereNumber('staff');
    Route::post('/people/{staff}/history', 'history')->whereNumber('staff');
    Route::post('/people/{staff}/documents', 'upload')->whereNumber('staff');
    Route::get('/people/{staff}/documents/{document}', 'download')->whereNumber(['staff', 'document']);
    Route::post('/people/{staff}/shifts', 'shift')->whereNumber('staff');
    Route::post('/people/{staff}/shifts/{shift}/clock', 'clock')->whereNumber(['staff', 'shift']);
    Route::post('/people/{staff}/leave', 'leave')->whereNumber('staff');
    Route::post('/people/{staff}/leave/{leave}/review', 'review')->whereNumber(['staff', 'leave']);
});
