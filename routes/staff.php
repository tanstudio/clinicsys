<?php

use App\Http\Controllers\StaffPortalController;
use App\Http\Middleware\RequireStaff;
use Illuminate\Support\Facades\Route;

Route::prefix('staff')->group(function () {
    Route::view('/login', 'staff.login');
    Route::post('/login', [StaffPortalController::class, 'login'])->middleware('throttle:10,1,staff-login');
    Route::middleware(RequireStaff::class)->group(function () {
        Route::view('/', 'staff.home');
        Route::post('/branch', [StaffPortalController::class, 'selectBranch']);
        Route::post('/logout', [StaffPortalController::class, 'logout']);
        foreach (['staff_booking', 'clinical', 'commerce', 'hr', 'messaging'] as $module) {
            require __DIR__."/$module.php";
        }
    });
});
