<?php

namespace App\Nightwatch;

final class ParsedNightwatchEnvelope
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(
        public readonly string $protocolVersion,
        public readonly string $tokenHash,
        public readonly string $payload,
        public readonly int $payloadBytes,
        public readonly array $records,
        public readonly bool $isPing = false,
    ) {
        //
    }
}
