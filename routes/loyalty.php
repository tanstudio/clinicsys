<?php

use App\Http\Controllers\LoyaltyController;
use Illuminate\Support\Facades\Route;

Route::get('loyalty', [LoyaltyController::class, 'index']);
Route::post('loyalty/tiers/{id?}', [LoyaltyController::class, 'saveTier'])->whereNumber('id');
Route::post('loyalty/plans/{id?}', [LoyaltyController::class, 'savePlan'])->whereNumber('id');
Route::post('patients/{patient}/packages', [LoyaltyController::class, 'sell'])->whereNumber('patient');
Route::post('patients/{patient}/tier', [LoyaltyController::class, 'setTier'])->whereNumber('patient');
Route::post('pos/redeem', [LoyaltyController::class, 'redeem']);
Route::post('packages/ledger/{entry}/void', [LoyaltyController::class, 'void'])->whereNumber('entry');
