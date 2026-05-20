<?php

namespace App\Http\Controllers;

use App\Jobs\NightwatchSelfTestJob;
use App\Mail\NightwatchSelfTestMail;
use App\Models\User;
use App\Nightwatch\SelfTest\NightwatchSelfTestSupport;
use App\Notifications\Channels\NightwatchSelfTestChannel;
use App\Notifications\NightwatchSelfTestNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class NightwatchSelfTestController extends Controller
{
    public function exercise(Request $request): JsonResponse
    {
        $runId = $this->resolveRunId($request);

        $user = User::query()->firstOrCreate(
            ['email' => NightwatchSelfTestSupport::userEmail($runId)],
            [
                'name' => 'Nightwatch Self Test',
                'password' => Hash::make('nightwatch-self-test'),
            ],
        );

        Auth::guard()->setUser($user);

        DB::select(NightwatchSelfTestSupport::sql());

        $response = Http::timeout((int) config('overwatch.self_test.request_timeout', 10))
            ->acceptJson()
            ->get((string) config('overwatch.self_test.stub_base_url').NightwatchSelfTestSupport::outgoingPath(), [
                'run' => $runId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Nightwatch self-test stub request failed.');
        }

        Log::channel('nightwatch')->warning('Nightwatch self test log', [
            'marker' => NightwatchSelfTestSupport::MARKER,
            'run_id' => $runId,
        ]);

        Mail::to('nightwatch-self-test@example.com')
            ->send(new NightwatchSelfTestMail($runId));

        Notification::route(NightwatchSelfTestChannel::class, $runId)
            ->notify(new NightwatchSelfTestNotification($runId));

        $missKey = NightwatchSelfTestSupport::cacheKey($runId, 'miss');
        $hitKey = NightwatchSelfTestSupport::cacheKey($runId, 'hit');
        $writeFailureKey = NightwatchSelfTestSupport::cacheKey($runId, 'write-failure');
        $deleteFailureKey = NightwatchSelfTestSupport::cacheKey($runId, 'delete-failure');

        Cache::store('array')->get($missKey);
        Cache::store('array')->put($hitKey, 'value', 60);
        Cache::store('array')->get($hitKey);
        Cache::store('array')->forget($hitKey);
        Cache::store('nightwatch-self-test-failing')->put($writeFailureKey, 'value', 60);
        Cache::store('nightwatch-self-test-failing')->forget($deleteFailureKey);

        dispatch(new NightwatchSelfTestJob($runId, 'processed'))
            ->onQueue(NightwatchSelfTestSupport::queueName($runId, 'processed'));

        dispatch(new NightwatchSelfTestJob($runId, 'released'))
            ->onQueue(NightwatchSelfTestSupport::queueName($runId, 'released'));

        dispatch(new NightwatchSelfTestJob($runId, 'failed'))
            ->onQueue(NightwatchSelfTestSupport::queueName($runId, 'failed'));

        report(new RuntimeException("Nightwatch handled self-test exception [{$runId}]"));

        return response()->json([
            'ok' => true,
            'run_id' => $runId,
        ]);
    }

    public function payloadAbsent(Request $request): JsonResponse
    {
        return $this->payloadResponse($request);
    }

    public function payloadPresent(Request $request): JsonResponse
    {
        return $this->payloadResponse($request);
    }

    public function payloadUnsupported(Request $request): JsonResponse
    {
        return $this->payloadResponse($request);
    }

    public function payloadNotEnabled(Request $request): JsonResponse
    {
        return $this->payloadResponse($request);
    }

    public function outgoingStub(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'run' => $this->resolveRunId($request),
        ]);
    }

    public function unhandledException(Request $request): never
    {
        throw new RuntimeException("Nightwatch unhandled self-test exception [{$this->resolveRunId($request)}]");
    }

    private function payloadResponse(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'run_id' => $this->resolveRunId($request),
        ], 500);
    }

    private function resolveRunId(Request $request): string
    {
        return (string) ($request->input('run_id')
            ?? $request->query('run')
            ?? config('overwatch.self_test.run_id')
            ?? 'unknown');
    }
}
