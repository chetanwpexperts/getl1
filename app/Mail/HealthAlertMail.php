<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To GetL1 staff: something on the platform stopped working, or recovered. */
class HealthAlertMail extends Mailable
{
    use Queueable;

    public function __construct(public array $checks, public array $failing, public array $recovered) {}

    public function envelope(): Envelope
    {
        $env = app()->environment('production') ? '' : ' ['.app()->environment().']';

        return new Envelope(subject: $this->failing
            ? 'GetL1 alert'.$env.': '.implode(', ', $this->failing)
            : 'GetL1 all clear'.$env.': '.implode(', ', $this->recovered).' recovered');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.health-alert', with: ['checks' => $this->checks, 'failing' => $this->failing, 'recovered' => $this->recovered, 'url' => route('admin.health')]);
    }
}
