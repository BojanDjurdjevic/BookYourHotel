<?php

namespace App\Notifications;

use App\Support\EmailAddress;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Delivered synchronously: the plaintext code never enters jobs or failed_jobs.
class GuestBookingCode extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return EmailAddress::isDemo($notifiable->routeNotificationFor('mail')) ? [] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your BookYourHotel booking email')
            ->greeting('Verify your email')
            ->line('Your verification code is: '.$this->code)
            ->line('This code expires in 10 minutes. Enter it in the browser where you started your booking.')
            ->line('Rooms are not reserved until verification and availability checks succeed.')
            ->line('If you did not request this code, you can ignore this email.');
    }
}
