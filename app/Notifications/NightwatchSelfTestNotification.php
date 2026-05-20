<?php

namespace App\Notifications;

use App\Notifications\Channels\NightwatchSelfTestChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NightwatchSelfTestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $runId,
    ) {}

    public function via(object $notifiable): array
    {
        return [NightwatchSelfTestChannel::class];
    }

    public function toNightwatchSelfTest(object $notifiable): array
    {
        return [
            'run_id' => $this->runId,
        ];
    }
}
