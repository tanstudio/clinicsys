<?php

use App\Http\Controllers\CommerceController;
use App\Http\Controllers\OperationsReportController;
use Illuminate\Support\Facades\Route;

Route::get('inventory', [CommerceController::class, 'inventory']);
Route::post('inventory', [CommerceController::class, 'move']);
Route::get('pos', [CommerceController::class, 'pos']);
Route::post('pos', [CommerceController::class, 'collect']);
Route::get('pos/{receipt}', [CommerceController::class, 'receipt'])->whereNumber('receipt');
Route::get('reports', [OperationsReportController::class, '__invoke']);
