<?php

namespace App\Mail;

use App\Models\User;
use App\Support\UiLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
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
            subject: __('password.mail_subject'),
        );
    }

    public function content(): Content
    {
        app()->setLocale($this->lang);

        return new Content(
            view: 'emails.password-reset',
        );
    }
}
