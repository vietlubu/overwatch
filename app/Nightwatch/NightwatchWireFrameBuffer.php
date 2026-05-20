<?php

namespace App\Nightwatch;

use App\Nightwatch\Exceptions\InvalidNightwatchPayloadException;

use function ctype_digit;
use function strlen;
use function strpos;
use function substr;

final class NightwatchWireFrameBuffer
{
    private string $buffer = '';

    public function __construct(
        private readonly int $maxFrameBytes,
    ) {}

    public function append(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    public function hasPendingBytes(): bool
    {
        return $this->buffer !== '';
    }

    public function nextFrame(): ?string
    {
        $colon = strpos($this->buffer, ':');

        if ($colon === false) {
            if (strlen($this->buffer) > 12) {
                throw new InvalidNightwatchPayloadException('Nightwatch frame length prefix is too long.');
            }

            return null;
        }

        $lengthText = substr($this->buffer, 0, $colon);

        if ($lengthText === '' || ! ctype_digit($lengthText)) {
            throw new InvalidNightwatchPayloadException('Nightwatch frame length prefix must be numeric.');
        }

        $length = (int) $lengthText;

        if ($length > $this->maxFrameBytes) {
            throw new InvalidNightwatchPayloadException("Nightwatch frame exceeds the configured limit of [{$this->maxFrameBytes}] bytes.");
        }

        $totalLength = $colon + 1 + $length;

        if (strlen($this->buffer) < $totalLength) {
            return null;
        }

        $frame = substr($this->buffer, 0, $totalLength);
        $this->buffer = substr($this->buffer, $totalLength);

        return $frame;
    }
}
