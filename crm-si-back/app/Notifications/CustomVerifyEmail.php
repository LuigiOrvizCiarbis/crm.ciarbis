<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

class CustomVerifyEmail extends Notification
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('✉️ Verificá tu cuenta de BIMS')
            ->view('emails.verify-email', [
                'userName' => $notifiable->name,
                'verificationUrl' => $verificationUrl,
            ]);
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(object $notifiable): string
    {
        $frontendUrl = Config::get('app.frontend_url', 'http://localhost:3000');

        // Crear los parámetros de verificación
        $id = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());
        $expires = Carbon::now()->addMinutes(60)->getTimestamp();

        // Crear la firma usando la misma lógica que Laravel
        $signature = hash_hmac('sha256', "{$id}/{$hash}?expires={$expires}", Config::get('app.key'));

        // Construir URL del frontend
        return "{$frontendUrl}/verify-email/confirm?id={$id}&hash={$hash}&expires={$expires}&signature={$signature}";
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
