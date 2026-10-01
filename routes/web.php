<?php

use App\Http\Controllers\Admin\KycReviewController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Buyer\AuctionController as BuyerAuctionController;
use App\Http\Controllers\Buyer\AwardController as BuyerAwardController;
use App\Http\Controllers\Buyer\BillingController;
use App\Http\Controllers\RazorpayWebhookController;
use App\Http\Controllers\Supplier\OrderController as SupplierOrderController;
use App\Http\Controllers\Buyer\RfqController as BuyerRfqController;
use App\Http\Controllers\Buyer\SupplierController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteLinkController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\Supplier\AuctionController as SupplierAuctionController;
use App\Http\Controllers\Supplier\DocumentController;
use App\Http\Controllers\Supplier\RfqController as SupplierRfqController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Invitation link from email / WhatsApp. Public, but bound to one supplier on first use.
Route::get('/i/{token}', [InviteLinkController::class, 'show'])->middleware('throttle:30,1')->name('invites.show');

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

            // RFQs: everyone in the buyer company can view
            Route::get('/rfqs', [BuyerRfqController::class, 'index'])->name('rfqs.index');
            Route::get('/rfqs/live', [BuyerRfqController::class, 'liveIndex'])->middleware('throttle:60,1')->name('rfqs.live');
            Route::get('/rfqs/{rfq}', [BuyerRfqController::class, 'show'])->whereNumber('rfq')->name('rfqs.show');
            Route::get('/rfqs/{rfq}/live', [BuyerRfqController::class, 'live'])->whereNumber('rfq')->middleware('throttle:60,1')->name('rfqs.show.live');
            Route::get('/rfqs/{rfq}/attachments/{attachment}', [BuyerRfqController::class, 'downloadAttachment'])
                ->whereNumber(['rfq', 'attachment'])->name('rfqs.attachments.download');

            // Live auctions: everyone in the buyer company can watch
            Route::get('/auctions/{auction}', [BuyerAuctionController::class, 'show'])->whereNumber('auction')->name('auctions.show');
            Route::get('/auctions/{auction}/state', [BuyerAuctionController::class, 'state'])
                ->whereNumber('auction')->middleware('throttle:120,1')->name('auctions.state');
            Route::get('/auctions/{auction}/bids.csv', [BuyerAuctionController::class, 'bidsCsv'])
                ->whereNumber('auction')->middleware('throttle:20,1')->name('auctions.bids');

            Route::get('/reports/savings', [\App\Http\Controllers\Buyer\ReportController::class, 'savings'])->name('reports.savings');
            Route::get('/reports/savings.csv', [\App\Http\Controllers\Buyer\ReportController::class, 'savingsCsv'])->middleware('throttle:20,1')->name('reports.savings.csv');

            // Billing: everyone can see the plan and invoices; only admins pay or cancel.
            Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
            Route::get('/billing/invoices/{payment}', [BillingController::class, 'invoice'])->whereNumber('payment')->name('billing.invoice');
            Route::middleware(['org.role:buyer_admin', 'throttle:20,1'])->group(function () {
                Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
                Route::post('/billing/subscribe/confirm', [BillingController::class, 'confirmSubscription'])->name('billing.subscribe.confirm');
                Route::post('/billing/credits', [BillingController::class, 'buyCredits'])->name('billing.credits');
                Route::post('/billing/ai-packs', [BillingController::class, 'buyAiPacks'])->name('billing.ai-packs');
                Route::post('/billing/credits/confirm', [BillingController::class, 'confirmCredits'])->name('billing.credits.confirm');
                Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
            });

            // Awards: everyone can see and download the PO; approvers and admins decide.
            Route::get('/awards/{award}/po', [BuyerAwardController::class, 'po'])->whereNumber('award')->name('awards.po');
            Route::middleware('org.role:buyer_admin,approver')->group(function () {
                Route::get('/approvals', [BuyerAwardController::class, 'approvals'])->name('approvals.index');
                Route::post('/awards/{award}/approve', [BuyerAwardController::class, 'approve'])->whereNumber('award')->name('awards.approve');
                Route::post('/awards/{award}/reject', [BuyerAwardController::class, 'reject'])->whereNumber('award')->name('awards.reject');
            });

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

                Route::get('/rfqs/create', [BuyerRfqController::class, 'create'])->name('rfqs.create');
                Route::post('/rfqs/ai', [\App\Http\Controllers\Buyer\AiRfqController::class, 'store'])->middleware('throttle:10,1')->name('rfqs.ai.store');
                Route::get('/rfqs/ai/{job}', [\App\Http\Controllers\Buyer\AiRfqController::class, 'show'])->whereNumber('job')->name('rfqs.ai.show');
                Route::get('/rfqs/ai/{job}/status', [\App\Http\Controllers\Buyer\AiRfqController::class, 'status'])->whereNumber('job')->middleware('throttle:120,1')->name('rfqs.ai.status');
                Route::get('/rfqs/ai/{job}/original', [\App\Http\Controllers\Buyer\AiRfqController::class, 'original'])->whereNumber('job')->middleware('throttle:60,1')->name('rfqs.ai.original');
                Route::get('/rfqs/ai/{job}/use', [\App\Http\Controllers\Buyer\AiRfqController::class, 'use'])->whereNumber('job')->name('rfqs.ai.use');
                Route::post('/rfqs', [BuyerRfqController::class, 'store'])->middleware('throttle:30,1')->name('rfqs.store');
                Route::get('/rfqs/{rfq}/edit', [BuyerRfqController::class, 'edit'])->whereNumber('rfq')->name('rfqs.edit');
                Route::put('/rfqs/{rfq}', [BuyerRfqController::class, 'update'])->whereNumber('rfq')->name('rfqs.update');
                Route::post('/rfqs/{rfq}/publish', [BuyerRfqController::class, 'publish'])->whereNumber('rfq')->name('rfqs.publish');
                Route::post('/rfqs/{rfq}/extend', [BuyerRfqController::class, 'extend'])->whereNumber('rfq')->name('rfqs.extend');
                Route::post('/rfqs/{rfq}/cancel', [BuyerRfqController::class, 'cancel'])->whereNumber('rfq')->name('rfqs.cancel');
                Route::post('/rfqs/{rfq}/invites', [BuyerRfqController::class, 'invite'])->whereNumber('rfq')->name('rfqs.invites.store');
                Route::delete('/rfqs/{rfq}/invites/{invite}', [BuyerRfqController::class, 'removeInvite'])
                    ->whereNumber(['rfq', 'invite'])->name('rfqs.invites.destroy');
                Route::post('/rfqs/{rfq}/attachments', [BuyerRfqController::class, 'addAttachment'])
                    ->whereNumber('rfq')->middleware('throttle:20,1')->name('rfqs.attachments.store');
                Route::delete('/rfqs/{rfq}/attachments/{attachment}', [BuyerRfqController::class, 'removeAttachment'])
                    ->whereNumber(['rfq', 'attachment'])->name('rfqs.attachments.destroy');

                Route::get('/rfqs/{rfq}/auction/create', [BuyerAuctionController::class, 'create'])->whereNumber('rfq')->name('auctions.create');
                Route::post('/rfqs/{rfq}/auction', [BuyerAuctionController::class, 'store'])->whereNumber('rfq')->name('auctions.store');
                Route::post('/auctions/{auction}/cancel', [BuyerAuctionController::class, 'cancel'])->whereNumber('auction')->name('auctions.cancel');
                Route::post('/rfqs/{rfq}/award', [BuyerAwardController::class, 'store'])->whereNumber('rfq')->middleware('throttle:20,1')->name('awards.store');
            });
        });

        // Supplier area
        Route::middleware('org.type:supplier')->prefix('supplier')->name('supplier.')->group(function () {
            Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:10,1')->name('documents.store');
            Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->name('documents.download');
            Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('documents.destroy');

            Route::get('/rfqs', [SupplierRfqController::class, 'index'])->name('rfqs.index');
            Route::get('/rfqs/live', [SupplierRfqController::class, 'liveIndex'])->middleware('throttle:60,1')->name('rfqs.live');
            Route::get('/rfqs/{invite}', [SupplierRfqController::class, 'show'])->whereNumber('invite')->name('rfqs.show');
            Route::get('/rfqs/{invite}/live', [SupplierRfqController::class, 'live'])->whereNumber('invite')->middleware('throttle:60,1')->name('rfqs.show.live');
            Route::post('/rfqs/{invite}/accept', [SupplierRfqController::class, 'accept'])->whereNumber('invite')->name('rfqs.accept');
            Route::post('/rfqs/{invite}/decline', [SupplierRfqController::class, 'decline'])->whereNumber('invite')->name('rfqs.decline');
            Route::post('/rfqs/{invite}/quote', [SupplierRfqController::class, 'quote'])
                ->whereNumber('invite')->middleware('throttle:20,1')->name('rfqs.quote');
            Route::get('/rfqs/{invite}/attachments/{attachment}', [SupplierRfqController::class, 'downloadAttachment'])
                ->whereNumber(['invite', 'attachment'])->name('rfqs.attachments.download');

            Route::get('/auctions/{auction}', [SupplierAuctionController::class, 'show'])->whereNumber('auction')->name('auctions.show');
            Route::get('/auctions/{auction}/state', [SupplierAuctionController::class, 'state'])
                ->whereNumber('auction')->middleware('throttle:120,1')->name('auctions.state');
            Route::post('/auctions/{auction}/bid', [SupplierAuctionController::class, 'bid'])
                ->whereNumber('auction')->middleware('throttle:60,1')->name('auctions.bid');

            Route::get('/orders', [SupplierOrderController::class, 'index'])->name('orders.index');
            Route::get('/orders/{award}', [SupplierOrderController::class, 'show'])->whereNumber('award')->name('orders.show');
            Route::get('/orders/{award}/po', [SupplierOrderController::class, 'po'])->whereNumber('award')->name('orders.po');
            Route::post('/orders/{award}/accept', [SupplierOrderController::class, 'accept'])->whereNumber('award')->name('orders.accept');
        });
    });
});

// Razorpay webhooks: signed by Razorpay, so no login or CSRF token.
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:120,1')->name('webhooks.razorpay');
