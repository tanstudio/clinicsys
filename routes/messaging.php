<?php

use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

Route::controller(InboxController::class)->prefix('inbox')->group(function () {
    Route::get('/', 'index');
    Route::post('/rules', 'saveRule');
    Route::post('/rules/{id}/delete', 'deleteRule');
    Route::post('/campaign', 'campaign');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/{id}/assign', 'assign');
    Route::post('/{id}/link', 'link');
    Route::post('/{id}/reply', 'reply');
    Route::post('/{id}/retry/{entry}', 'retry');
});
