<?php

namespace App\Mail;

use App\Nightwatch\SelfTest\NightwatchSelfTestSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NightwatchSelfTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $runId,
    ) {}

    public function build(): self
    {
        return $this->subject(NightwatchSelfTestSupport::mailSubject($this->runId))
            ->html('<p>Nightwatch self-test mail.</p>');
    }
}
