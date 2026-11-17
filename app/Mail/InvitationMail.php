<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Join {$this->organizationName} on RYVR");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation');
    }
}
