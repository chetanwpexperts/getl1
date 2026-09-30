<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/onboarding', [OrganizationController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding', [OrganizationController::class, 'store'])->name('onboarding.store');
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->name('organizations.switch');

    // Everything below acts on behalf of the current organization.
    Route::middleware('org')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Buyer area (steps 3–6 add RFQs, auctions, awards here)
        Route::middleware('org.type:buyer')->prefix('buyer')->name('buyer.')->group(function () {
            //
        });

        // Supplier area (steps 2–4 add profile, KYC, invites, quotes, bidding here)
        Route::middleware('org.type:supplier')->prefix('supplier')->name('supplier.')->group(function () {
            //
        });
    });
});
