<?php

use App\Http\Controllers\Api\ClinicController;
use Illuminate\Support\Facades\Route;

// Loaded by web.php to retain session authentication and CSRF protection.
Route::prefix('api/v1')->name('api.v1.')->middleware(['auth', 'throttle:clinic-api'])->group(function () {
    Route::get('me', [ClinicController::class, 'me'])->name('me');
    Route::get('{section}', [ClinicController::class, 'index'])->name('index');
    Route::get('{section}/{id}', [ClinicController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('show');
    Route::post('{section}', [ClinicController::class, 'store'])->name('store');
    Route::match(['put', 'patch'], '{section}/{id}', [ClinicController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('update');
    Route::delete('{section}/{id}', [ClinicController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('destroy');
});
