<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Security notice sent to the previous address after an account email change. */
class EmailChangedNotification extends Notification
{
    public function __construct(
        private readonly string $userName,
        private readonly string $newEmail,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Adresa de email a contului Symbiot a fost schimbată')
            ->greeting('Salut, ' . $this->userName . '!')
            ->line('Adresa de email a contului tău Symbiot a fost schimbată în: ' . $this->newEmail . '.')
            ->line('Dacă nu ai făcut tu această modificare, contactează imediat administratorul aplicației.');
    }
}
