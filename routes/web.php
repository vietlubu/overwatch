<?php

use App\Http\Controllers\NightwatchSelfTestController;
use App\Http\Middleware\EnsureNightwatchSelfTestEnabled;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Http\Middleware\Sample;

Route::get('/', function () {
    return view('welcome');
});

if (config('overwatch.self_test.enabled')) {
    Route::prefix((string) config('overwatch.self_test.route_prefix', '__nightwatch-test'))
        ->middleware([EnsureNightwatchSelfTestEnabled::class])
        ->name('nightwatch.self-test.')
        ->group(function (): void {
            Route::get('/exercise', [NightwatchSelfTestController::class, 'exercise'])
                ->middleware(Sample::always())
                ->name('exercise');

            Route::get('/payload/absent', [NightwatchSelfTestController::class, 'payloadAbsent'])
                ->middleware(Sample::always())
                ->name('payload.absent');

            Route::post('/payload/present', [NightwatchSelfTestController::class, 'payloadPresent'])
                ->middleware(Sample::always())
                ->name('payload.present');

            Route::post('/payload/unsupported', [NightwatchSelfTestController::class, 'payloadUnsupported'])
                ->middleware(Sample::always())
                ->name('payload.unsupported');

            Route::post('/payload/not-enabled', [NightwatchSelfTestController::class, 'payloadNotEnabled'])
                ->middleware(Sample::always())
                ->name('payload.not-enabled');

            Route::get('/outgoing-stub', [NightwatchSelfTestController::class, 'outgoingStub'])
                ->middleware(Sample::never())
                ->name('outgoing-stub');

            Route::get('/exception/unhandled', [NightwatchSelfTestController::class, 'unhandledException'])
                ->middleware(Sample::always())
                ->name('exception.unhandled');
        });
}
