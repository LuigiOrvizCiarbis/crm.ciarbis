<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Invitation $invitation
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $acceptUrl = "{$frontendUrl}/invitation/accept?token={$this->invitation->token}";

        $inviterName = $this->invitation->invitedBy->name ?? 'Someone';
        $tenantName = $this->invitation->tenant->name ?? 'a team';

        return (new MailMessage)
            ->subject("Te invitaron a unirte a {$tenantName} en BIMS")
            ->view('emails.invitation', [
                'inviterName' => $inviterName,
                'tenantName' => $tenantName,
                'acceptUrl' => $acceptUrl,
            ]);
    }
}
