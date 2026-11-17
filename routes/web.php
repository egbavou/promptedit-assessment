<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('admin/report', ReportController::class)->middleware('admin')->name('admin.report');
    Route::get('dashboard', fn () => to_route('generations.index'))->name('dashboard');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::get('ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::post('credits/purchase', CreditPurchaseController::class)->name('credits.purchase');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('generations/{generation}/image', [GenerationController::class, 'image'])->name('generations.image');
});

require __DIR__.'/settings.php';
