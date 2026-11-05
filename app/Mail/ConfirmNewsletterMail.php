<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ConfirmNewsletterMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your Azhayon Media newsletter subscription');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.newsletter-confirm', text: 'mail.newsletter-confirm-text');
    }
}