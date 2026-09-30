<?php

namespace App\Providers;

use App\Models\Auction;
use App\Models\Award;
use App\Models\Bid;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqInvite;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentOrganization::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Same header and menu on every authenticated page.
        View::composer(['layouts.app', 'layouts.nav-links'], AppLayoutComposer::class);

        // Short, stable names in polymorphic columns (audit_logs, ai_jobs, whatsapp_messages).
        Relation::enforceMorphMap([
            'user' => User::class,
            'organization' => Organization::class,
            'rfq' => Rfq::class,
            'rfq_invite' => RfqInvite::class,
            'quote' => Quote::class,
            'auction' => Auction::class,
            'bid' => Bid::class,
            'award' => Award::class,
            'supplier_document' => \App\Models\SupplierDocument::class,
            'buyer_supplier' => \App\Models\BuyerSupplier::class,
            'subscription' => \App\Models\Subscription::class,
            'ai_job' => \App\Models\AiJob::class,
        ]);
    }
}
