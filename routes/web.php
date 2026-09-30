<?php

use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Buyer\SupplierController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\Supplier\DocumentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:30,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/onboarding', [OrganizationController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding', [OrganizationController::class, 'store'])->name('onboarding.store');
    Route::post('/organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->name('organizations.switch');

    // GetL1 staff only. No organization context needed.
    Route::middleware('platform.admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/kyc', [KycReviewController::class, 'index'])->name('kyc.index');
        Route::get('/kyc/{document}/download', [KycReviewController::class, 'download'])->name('kyc.download');
        Route::post('/kyc/{document}/approve', [KycReviewController::class, 'approve'])->name('kyc.approve');
        Route::post('/kyc/{document}/reject', [KycReviewController::class, 'reject'])->name('kyc.reject');
    });

    // Everything below acts on behalf of the current organization.
    Route::middleware('org')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/company', [CompanyProfileController::class, 'edit'])->name('company.edit');
        Route::put('/company', [CompanyProfileController::class, 'update'])->name('company.update');

        // Buyer area
        Route::middleware('org.type:buyer')->prefix('buyer')->name('buyer.')->group(function () {
            Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');

            // Changing the list: admins and buyers, not approvers
            Route::middleware('org.role:buyer_admin,buyer_user')->group(function () {
                Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
                Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
                Route::get('/suppliers/import', [SupplierController::class, 'importForm'])->name('suppliers.import');
                Route::post('/suppliers/import', [SupplierController::class, 'import'])
                    ->middleware('throttle:10,1')->name('suppliers.import.store');
                Route::get('/suppliers/template', [SupplierController::class, 'template'])->name('suppliers.template');
                Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->whereNumber('supplier')->name('suppliers.edit');
                Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
                Route::post('/suppliers/{supplier}/block', [SupplierController::class, 'toggleBlock'])->whereNumber('supplier')->name('suppliers.block');
                Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->name('suppliers.destroy');
            });
        });

        // Supplier area
        Route::middleware('org.type:supplier')->prefix('supplier')->name('supplier.')->group(function () {
            Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:10,1')->name('documents.store');
            Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->name('documents.download');
            Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');
        });
    });
});
