<?php

use App\Http\Controllers\ClinicalController;
use App\Http\Controllers\PatientCrmController;
use Illuminate\Support\Facades\Route;

Route::match(['get','post'], 'patients', [PatientCrmController::class, 'index']);
Route::post('patients/{patient}/open-branch', [PatientCrmController::class, 'openBranch'])->whereNumber('patient');
Route::get('patients/{patient}', [PatientCrmController::class, 'show'])->whereNumber('patient');
Route::post('patients/{patient}/profile', [PatientCrmController::class, 'profile']);
Route::post('patients/{patient}/followups', [PatientCrmController::class, 'followup']);
Route::post('patients/{patient}/followups/{id}/complete', [PatientCrmController::class, 'complete']);

Route::match(['get','post'], 'encounters', [ClinicalController::class, 'index']);
Route::get('encounters/new/{appointment}', [ClinicalController::class, 'create'])->whereNumber('appointment');
Route::post('encounters/new/{appointment}', [ClinicalController::class, 'store'])->whereNumber('appointment');
Route::post('encounters/templates', [ClinicalController::class, 'template']);
Route::get('encounters/{encounter}', [ClinicalController::class, 'show'])->whereNumber('encounter');
