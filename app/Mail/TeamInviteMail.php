<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "You've been added to <company> on GetL1". With a set-password link for new people, a login link otherwise. */
class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $member, public Organization $org, public User $by, public ?string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->by->name} added you to {$this->org->name} on GetL1");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-invite', with: [
            'role' => $this->member->roleIn($this->org)?->label(),
        ]);
    }
}
