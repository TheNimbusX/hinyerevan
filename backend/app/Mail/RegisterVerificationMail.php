<?php

namespace App\Mail;

use App\Support\UiLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegisterVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
        public string $code,
        public string $lang = 'hy',
    ) {
        if (! in_array($this->lang, UiLocale::SUPPORTED, true)) {
            $this->lang = 'hy';
        }
    }

    public function envelope(): Envelope
    {
        app()->setLocale($this->lang);

        return new Envelope(
            subject: __('register.mail_subject'),
        );
    }

    public function content(): Content
    {
        app()->setLocale($this->lang);

        return new Content(
            view: 'emails.register-verification',
        );
    }
}
