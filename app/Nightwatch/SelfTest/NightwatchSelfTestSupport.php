<?php

namespace App\Nightwatch\SelfTest;

use App\Notifications\NightwatchSelfTestNotification;

final class NightwatchSelfTestSupport
{
    public const MARKER = 'nightwatch_self_test';

    public static function queueName(string $runId, string $scenario): string
    {
        $prefix = (string) config('overwatch.self_test.queue_prefix', 'nightwatch-self-test');

        return "{$prefix}:{$runId}:{$scenario}";
    }

    public static function cacheKey(string $runId, string $scenario): string
    {
        return self::MARKER.":cache:{$runId}:{$scenario}";
    }

    public static function mailSubject(string $runId): string
    {
        return "[Nightwatch Self Test][{$runId}] Mail";
    }

    public static function notificationClass(): string
    {
        return NightwatchSelfTestNotification::class;
    }

    public static function sql(): string
    {
        return "select 'nightwatch_self_test' as nightwatch_self_test_marker";
    }

    public static function outgoingPath(): string
    {
        return '/'.trim((string) config('overwatch.self_test.route_prefix', '__nightwatch-test'), '/').'/outgoing-stub';
    }

    public static function routePath(string $path = ''): string
    {
        $prefix = '/'.trim((string) config('overwatch.self_test.route_prefix', '__nightwatch-test'), '/');
        $path = trim($path, '/');

        return $path === '' ? $prefix : $prefix.'/'.$path;
    }

    public static function userEmail(string $runId): string
    {
        return "nightwatch-self-test+{$runId}@example.com";
    }
}
