<?php

namespace App\Mail;

use App\Models\Devotee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DevoteePasswordResetCode extends Mailable
{
    use Queueable;

    public function __construct(public Devotee $devotee, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: setting('password_reset_subject') ?: 'Your password reset code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset-code', with: [
            'app' => setting('brand_name', 'brand.name') ?: config('app.name'),
        ]);
    }
}
