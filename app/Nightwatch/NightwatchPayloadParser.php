<?php

namespace App\Nightwatch;

use App\Nightwatch\Exceptions\InvalidNightwatchPayloadException;
use JsonException;

use function array_is_list;
use function is_array;
use function is_numeric;
use function json_decode;
use function strlen;
use function strpos;
use function substr;

final class NightwatchPayloadParser
{
    /**
     * @return array{length: int, protocol_version: string, token_hash: string, payload: string}
     */
    public function split(string $wirePayload): array
    {
        $first = strpos($wirePayload, ':');

        if ($first === false) {
            throw new InvalidNightwatchPayloadException('Nightwatch payload is missing the length prefix.');
        }

        $length = substr($wirePayload, 0, $first);

        if (! is_numeric($length)) {
            throw new InvalidNightwatchPayloadException('Nightwatch payload length prefix must be numeric.');
        }

        $remainder = substr($wirePayload, $first + 1);
        $second = strpos($remainder, ':');

        if ($second === false) {
            throw new InvalidNightwatchPayloadException('Nightwatch payload is missing the protocol version.');
        }

        $protocolVersion = substr($remainder, 0, $second);
        $afterProtocol = substr($remainder, $second + 1);
        $third = strpos($afterProtocol, ':');

        if ($third === false) {
            throw new InvalidNightwatchPayloadException('Nightwatch payload is missing the token hash.');
        }

        $tokenHash = substr($afterProtocol, 0, $third);
        $payload = substr($afterProtocol, $third + 1);

        if ((int) $length !== strlen($remainder)) {
            throw new InvalidNightwatchPayloadException('Nightwatch payload length does not match the envelope.');
        }

        if ($protocolVersion === '') {
            throw new InvalidNightwatchPayloadException('Nightwatch protocol version cannot be empty.');
        }

        if ($tokenHash === '') {
            throw new InvalidNightwatchPayloadException('Nightwatch token hash cannot be empty.');
        }

        return [
            'length' => (int) $length,
            'protocol_version' => $protocolVersion,
            'token_hash' => $tokenHash,
            'payload' => $payload,
        ];
    }

    public function parse(string $wirePayload): ParsedNightwatchEnvelope
    {
        $parts = $this->split($wirePayload);

        if ($parts['payload'] === 'PING') {
            return new ParsedNightwatchEnvelope(
                protocolVersion: $parts['protocol_version'],
                tokenHash: $parts['token_hash'],
                payload: $parts['payload'],
                payloadBytes: $parts['length'],
                records: [],
                isPing: true,
            );
        }

        try {
            $decoded = json_decode($parts['payload'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidNightwatchPayloadException('Nightwatch JSON payload is invalid.', previous: $e);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidNightwatchPayloadException('Nightwatch JSON payload must decode to a list of event records.');
        }

        return new ParsedNightwatchEnvelope(
            protocolVersion: $parts['protocol_version'],
            tokenHash: $parts['token_hash'],
            payload: $parts['payload'],
            payloadBytes: $parts['length'],
            records: $decoded,
        );
    }
}
