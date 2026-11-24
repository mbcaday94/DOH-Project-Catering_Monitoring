<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CateringDashboardController;
use App\Http\Controllers\PurchaseOrderScanController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::view('/', 'auth.login')->name('login');
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', CateringDashboardController::class)->name('dashboard');
    Route::patch('/dashboard/profile', [CateringDashboardController::class, 'updateDashboardProfile'])->name('dashboard.profile.update');
    Route::get('/dashboard/profile/picture', [CateringDashboardController::class, 'dashboardProfilePicture'])->name('dashboard.profile.picture');
    Route::post('/dashboard/schedule', [CateringDashboardController::class, 'storeScheduleEntry'])->name('dashboard.schedule.store');
    Route::post('/notices-to-conduct', [CateringDashboardController::class, 'storeNotice'])->name('notices.store');
    Route::patch('/notices-to-conduct/{id}', [CateringDashboardController::class, 'updateNotice'])->whereNumber('id')->name('notices.update');
    Route::get('/notices-to-conduct/{id}/document', [CateringDashboardController::class, 'downloadNoticeDocument'])->whereNumber('id')->name('notices.document');
    Route::get('/notices-to-conduct/{id}/document/preview', [CateringDashboardController::class, 'previewNoticeDocument'])->whereNumber('id')->name('notices.document.preview');
    Route::post('/billing-entries', [CateringDashboardController::class, 'storeBillingEntry'])->name('billing-entries.store');
    Route::patch('/billing-entries/{id}', [CateringDashboardController::class, 'updateBillingEntry'])->whereNumber('id')->name('billing-entries.update');
    Route::get('/billing-entries/{id}/document', [CateringDashboardController::class, 'billingDocument'])->whereNumber('id')->name('billing-entries.document');
    Route::post('/purchase-orders', [CateringDashboardController::class, 'store'])->name('purchase-orders.store');
    Route::post('/purchase-orders/scan', PurchaseOrderScanController::class)->middleware('throttle:6,1,po-scan')->name('purchase-orders.scan');
    Route::patch('/purchase-orders/{id}', [CateringDashboardController::class, 'update'])->whereNumber('id')->name('purchase-orders.update');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::post('/session', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('session.store');

    Route::middleware('auth')->group(function (): void {
        Route::get('/session', [SessionController::class, 'show'])->name('session.show');
        Route::delete('/session', [SessionController::class, 'destroy'])->name('session.destroy');
    });
});
