<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('clinic-welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [WorkspaceController::class, 'index'])->name('dashboard');
    Route::get('clinic/{section}', [WorkspaceController::class, 'index'])->name('clinic.index');
    Route::post('clinic/{section}', [WorkspaceController::class, 'store'])->name('clinic.store');
    Route::patch('clinic/{section}/{id}', [WorkspaceController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('clinic.update');
    Route::delete('clinic/{section}/{id}', [WorkspaceController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('clinic.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/api.php';
