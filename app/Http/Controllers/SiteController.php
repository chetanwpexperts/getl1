<?php

namespace App\Http\Controllers;

use App\Mail\LeadReceivedMail;
use App\Mail\LeadThanksMail;
use App\Models\Lead;
use App\Models\Plan;
use App\Services\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/** The public website: marketing pages, policies and the early-access / demo form. */
class SiteController extends Controller
{
    public function home(): View
    {
        return view('site.home', ['plans' => $this->plans()]);
    }

    public function pricing(): View
    {
        return view('site.pricing', ['plans' => $this->plans()]);
    }

    public function page(string $page): View
    {
        return view('site.'.$page);
    }

    public function contact(Request $request): View
    {
        return view('site.contact', [
            'interest' => $request->query('as') === 'supplier' ? 'supplier' : 'buyer',
            'source' => mb_substr((string) ($request->query('utm_source') ?: $request->query('ref')), 0, 60),
            'startedAt' => encrypt(time()),
        ]);
    }

    public function storeLead(Request $request): RedirectResponse
    {
        // Bots: a hidden field humans never fill, and a minimum time on the page.
        $started = rescue(fn () => (int) decrypt((string) $request->input('t')), 0, false);
        $age = time() - $started;
        if (filled($request->input('website')) || $age < 3 || $age > 2 * 3600) {
            SecurityLog::info('lead_bot_blocked');

            return redirect()->route('site.contact.thanks');
        }

        // People type numbers as "98765 43210" or "+91-98765-43210".
        $request->merge(['phone' => preg_replace('/[\s\-().]/', '', (string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'regex:/^(\+?91|0)?[6-9]\d{9}$/'],
            'city' => ['nullable', 'string', 'max:80'],
            'interest' => ['required', 'in:buyer,supplier'],
            'monthly_spend' => ['nullable', 'in:under_5l,5l_25l,25l_1cr,over_1cr'],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:60'],
        ], ['phone.regex' => 'Enter a 10-digit Indian mobile number.']);

        $data['email'] = strtolower($data['email']);
        $data['phone'] = substr(preg_replace('/\D/', '', $data['phone']), -10);
        $data['source'] = ($data['source'] ?? null) ?: (parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null);

        // The same person (same email and network) sending the form again within a day updates their request.
        $lead = Lead::where('email', $data['email'])->where('ip', $request->ip())->where('created_at', '>=', now()->subDay())->first();
        if ($lead) {
            $lead->update($data);
        } else {
            $lead = Lead::create($data + ['ip' => $request->ip()]);
        }

        // Confirmation emails go to whatever address is typed in, so cap them per network per day
        // to keep the mailbox from being used to spam others. The lead is still saved.
        $todayFromIp = Lead::where('ip', $request->ip())->where('created_at', '>=', now()->subDay())->count();
        if ($todayFromIp > 5) {
            SecurityLog::warning('lead_mail_capped', ['count' => $todayFromIp]);
        } elseif ($lead->wasRecentlyCreated) {
            rescue(function () use ($lead) {
                Mail::to(config('site.leads_to'))->queue(new LeadReceivedMail($lead));
                Mail::to($lead->email)->queue(new LeadThanksMail($lead));
            }, fn ($e) => Log::warning('lead_mail_failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]), false);
        }

        return redirect()->route('site.contact.thanks');
    }

    public function thanks(): View
    {
        return view('site.thanks');
    }

    public function sitemap(): Response
    {
        $urls = collect(['home', 'site.pricing', 'site.suppliers', 'site.contact', 'site.terms', 'site.privacy', 'site.refunds', 'site.shipping'])
            ->map(fn ($r) => route($r));

        return response()->view('site.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $lines = config('site.mode') === 'website' || app()->environment('production')
            ? ['User-agent: *', 'Disallow: /admin', 'Disallow: /login', 'Disallow: /buyer', 'Disallow: /supplier', 'Sitemap: '.route('site.sitemap')]
            : ['User-agent: *', 'Disallow: /']; // staging is never indexed

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain');
    }

    private function plans()
    {
        return rescue(fn () => Plan::where('is_active', true)->orderBy('sort')->get(), collect(), false);
    }
}
