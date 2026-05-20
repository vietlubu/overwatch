<?php

namespace App\Providers;

use App\Cache\NightwatchSelfTestFailingStore;
use App\Models\User;
use App\Nightwatch\SelfTest\NightwatchSelfTestSupport;
use App\Notifications\NightwatchSelfTestNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Mail;
use Laravel\Nightwatch\Records\Notification;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Query;
use Laravel\Nightwatch\Records\QueuedJob;

use function str_contains;
use function str_starts_with;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cache::extend('nightwatch-self-test-failing', function () {
            return Cache::repository(new NightwatchSelfTestFailingStore);
        });

        Nightwatch::user(static function (Authenticatable $user): array {
            return [
                'id' => $user->getAuthIdentifier(),
                'name' => $user instanceof User ? $user->name : ($user->name ?? ''),
                'username' => $user instanceof User ? $user->email : ($user->email ?? ''),
            ];
        });

        if (! config('overwatch.self_test.enabled')) {
            return;
        }

        Nightwatch::rejectQueries(static fn (Query $query) => ! str_contains($query->sql, NightwatchSelfTestSupport::MARKER));
        Nightwatch::rejectOutgoingRequests(static fn (OutgoingRequest $request) => ! str_contains($request->url, NightwatchSelfTestSupport::outgoingPath()));
        Nightwatch::rejectCacheEvents(static fn (CacheEvent $event) => ! str_starts_with($event->key, NightwatchSelfTestSupport::MARKER.':cache:'));
        Nightwatch::rejectQueuedJobs(static fn (QueuedJob $job) => ! str_contains($job->queue, (string) config('overwatch.self_test.queue_prefix')));
        Nightwatch::rejectMail(static fn (Mail $mail) => ! str_contains($mail->subject, '[Nightwatch Self Test]'));
        Nightwatch::rejectNotifications(static fn (Notification $notification) => $notification->class !== NightwatchSelfTestNotification::class);
    }
}
