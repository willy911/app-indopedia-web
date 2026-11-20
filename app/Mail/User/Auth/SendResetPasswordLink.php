<?php

/*
|--------------------------------------------------------------------------
| Source by jhonroot
|--------------------------------------------------------------------------
| * Facebook : https://fb.me/jhonroott  
| * Whatsapp : https://wa.me/6285349680016  
| * Telegram : https://t.me/jhondelton   
| * Web      : www.jhonroot.dev
|--------------------------------------------------------------------------
*/

namespace App\Mail\User\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;

class SendResetPasswordLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $props,
    ) {
        $this->onConnection('sync');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(website_config('smtp')->from ?: config('mail.from.address'), website_config()->name ?: config('mail.from.name')),
            subject: 'Atur Ulang Kata Sandi - ' . website_config()->name,
        );
    }

    public function content(): Content
    {
        // Link reset mengarah ke aplikasi/web utama (frontend), bukan backend API ini:
        // {APP_FRONTEND_URL|APP_URL}/auth/reset-password?token=...
        $resetUrl = rtrim(config('services.app.frontend_url') ?: url('/'), '/') . '/auth/reset-password?token=' . $this->props->token;

        return new Content(
            view: 'emails.user.auth.reset-password',
            with: [
                'props'    => $this->props,
                'resetUrl' => $resetUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
