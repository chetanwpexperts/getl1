<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailGuardTest extends TestCase
{
    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    public function test_reserved_test_domains_never_receive_email(): void
    {
        $this->assertNull(Mail::raw('x', fn ($m) => $m->to('supplier1@getl1.test')->subject('t')));
        $this->assertCount(0, $this->sent());

        Mail::raw('x', fn ($m) => $m->to(['real@company.in', 'demo@example.com'])->subject('t'));
        $this->assertCount(1, $this->sent());
        $to = array_map(fn ($a) => $a->getAddress(), $this->sent()[0]->getOriginalMessage()->getTo());
        $this->assertSame(['real@company.in'], $to);
    }

    public function test_staging_allowlist(): void
    {
        config(['mail.allowlist' => 'owner@getl1.com, partner.in']);

        $this->assertNull(Mail::raw('x', fn ($m) => $m->to('customer@bigco.in')->subject('t')));
        Mail::raw('x', fn ($m) => $m->to('owner@getl1.com')->subject('t'));
        Mail::raw('x', fn ($m) => $m->to('ravi@partner.in')->subject('t'));
        $this->assertCount(2, $this->sent());
    }

    public function test_test_mail_command(): void
    {
        $this->artisan('getl1:mail-test', ['to' => 'someone@company.in'])->assertSuccessful();
        $this->artisan('getl1:mail-test', ['to' => 'someone@getl1.test'])->assertFailed();
        $this->artisan('getl1:mail-test', ['to' => 'not-an-email'])->assertFailed();
    }
}
