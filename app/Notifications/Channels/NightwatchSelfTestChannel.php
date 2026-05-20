<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;

class NightwatchSelfTestChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (method_exists($notification, 'toNightwatchSelfTest')) {
            $notification->toNightwatchSelfTest($notifiable);
        }
    }
}
