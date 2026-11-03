<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class CustomResetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('🔐 Restablecé tu contraseña de BIMS')
            ->view('emails.reset-password', [
                'userName' => $notifiable->name,
                'resetUrl' => $resetUrl,
            ]);
    }

    protected function resetUrl(object $notifiable): string
    {
        $frontendUrl = Config::get('app.frontend_url', 'http://localhost:3000');
        $email = urlencode($notifiable->getEmailForPasswordReset());

        return "{$frontendUrl}/reset-password?token={$this->token}&email={$email}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
