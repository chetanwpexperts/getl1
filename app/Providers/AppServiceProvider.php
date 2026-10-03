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
use Illuminate\Support\Carbon;
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

        // Website name, contact details and branding saved in Admin → Website.
        // GST and billing details saved in Admin → Billing & GST (after Website: they fall back to its values).
        $applySettings = function () {
            $this->app->make(\App\Services\WebsiteSettings::class)->apply();
            $this->app->make(\App\Services\BillingSettings::class)->apply();
        };
        $applySettings();
        // The queue worker runs for hours: re-read them before each job so emails and invoices use the latest values.
        \Illuminate\Support\Facades\Queue::before(fn () => $applySettings());

        // Password reset email in GetL1's words.
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(fn ($user, string $token) => (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Reset your GetL1 password')
            ->greeting('Hello '.\Illuminate\Support\Str::before((string) $user->name, ' ').',')
            ->line('We received a request to reset the password for your GetL1 account.')
            ->action('Choose a new password', route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->line('This link works once and expires in 60 minutes.')
            ->line("If you didn't ask for this, you can ignore this email. Your password stays the same.")
            ->salutation('GetL1'));

        // Same header and menu on every authenticated page.
        View::composer('layouts.app', AppLayoutComposer::class);

        // Dates are stored in UTC; show them in IST: $model->created_at->ist()->format('d M Y, h:i A')
        Carbon::macro('ist', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(config('app.display_timezone'));
        });

        // Short, stable names in polymorphic columns (audit_logs, ai_jobs, whatsapp_messages).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, \App\Listeners\GuardOutgoingMail::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'organization' => Organization::class,
            'lead' => \App\Models\Lead::class,
            'rfq' => Rfq::class,
            'rfq_invite' => RfqInvite::class,
            'rfq_question' => \App\Models\RfqQuestion::class,
            'counter_offer' => \App\Models\CounterOffer::class,
            'quote' => Quote::class,
            'auction' => Auction::class,
            'bid' => Bid::class,
            'award' => Award::class,
            'supplier_document' => \App\Models\SupplierDocument::class,
            'buyer_supplier' => \App\Models\BuyerSupplier::class,
            'subscription' => \App\Models\Subscription::class,
            'ai_job' => \App\Models\AiJob::class,
            'payment' => \App\Models\Payment::class,
        ]);
    }
}
