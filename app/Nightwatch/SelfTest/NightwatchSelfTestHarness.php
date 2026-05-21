<?php

namespace App\Nightwatch\SelfTest;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function array_count_values;
use function array_filter;
use function array_merge;
use function fclose;
use function fsockopen;
use function in_array;
use function is_array;
use function stream_socket_server;
use function usleep;

final class NightwatchSelfTestHarness
{
    /**
     * @var list<Process>
     */
    private array $backgroundProcesses = [];

    public function __construct(
        private readonly NightwatchProjectKeyManager $keys,
    ) {}

    /**
     * @return array{run_id: string, project_id: int, summary: array<string, int>}
     */
    public function run(?string $runId = null, ?int $listenerPort = null, ?int $webPort = null, ?int $secondaryWebPort = null, ?int $timeout = null): array
    {
        $runId ??= Str::lower((string) Str::ulid());
        $timeout ??= (int) config('overwatch.self_test.startup_timeout', 20);

        [$project, $key] = DB::transaction(function () {
            $slug = (string) config('overwatch.self_test.project_slug', 'overwatch-self-test');
            $name = (string) config('overwatch.self_test.project_name', 'Overwatch Self Test');

            $project = $this->keys->findProject($slug);

            if ($project === null) {
                $created = $this->keys->createProject($slug, $name);

                return [$created, $created];
            }

            return [$project, $this->keys->rotateKey($project)];
        });

        $listenerPort ??= $this->availablePort();
        $webPort ??= $this->availablePort();
        $secondaryWebPort ??= $this->availablePort();
        $host = (string) config('overwatch.self_test.host', '127.0.0.1');
        $deployment = "nightwatch-self-test-{$runId}";
        $ingestUri = "{$host}:{$listenerPort}";
        $primaryBaseUrl = "http://{$host}:{$webPort}";
        $secondaryBaseUrl = "http://{$host}:{$secondaryWebPort}";

        try {
            $this->startListener($listenerPort);
            $this->startServer($webPort, $key['secret'], $ingestUri, $deployment, 'primary', true, $runId, $secondaryBaseUrl);
            $this->startServer($secondaryWebPort, $key['secret'], $ingestUri, $deployment, 'secondary', false, $runId, $secondaryBaseUrl);

            $this->waitForTcpReady($host, $listenerPort, $timeout);
            $this->waitForHttpReady("{$primaryBaseUrl}/up", $timeout);
            $this->waitForHttpReady("{$secondaryBaseUrl}/up", $timeout);

            $this->triggerHttpMatrix($primaryBaseUrl, $secondaryBaseUrl, $runId);
            $this->runHelperCommand(['nightwatch:self-test:command', 'success', "--run={$runId}"], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'command-success', $runId, $secondaryBaseUrl), [0]);
            $this->runHelperCommand(['nightwatch:self-test:command', 'failure', "--run={$runId}"], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'command-failure', $runId, $secondaryBaseUrl), [1]);
            $this->runHelperCommand(['schedule:run'], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'scheduler', $runId, $secondaryBaseUrl), [0]);
            $this->runHelperCommand(['queue:work', 'database', '--once', '--queue='.NightwatchSelfTestSupport::queueName($runId, 'processed'), '--sleep=0', '--tries=1'], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'queue-processed', $runId, $secondaryBaseUrl), [0]);
            $this->runHelperCommand(['queue:work', 'database', '--once', '--queue='.NightwatchSelfTestSupport::queueName($runId, 'released'), '--sleep=0', '--tries=1'], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'queue-released', $runId, $secondaryBaseUrl), [0]);
            $this->runHelperCommand(['queue:work', 'database', '--once', '--queue='.NightwatchSelfTestSupport::queueName($runId, 'failed'), '--sleep=0', '--tries=1'], $this->helperEnvironment($key['secret'], $ingestUri, $deployment, 'queue-failed', $runId, $secondaryBaseUrl), [0]);

            $summary = $this->waitForAndVerify($project['id'], $runId, $timeout);

            return [
                'run_id' => $runId,
                'project_id' => $project['id'],
                'summary' => $summary,
            ];
        } finally {
            DB::table('jobs')
                ->where('queue', 'like', (string) config('overwatch.self_test.queue_prefix', 'nightwatch-self-test').':'.$runId.'%')
                ->delete();

            $this->stopBackgroundProcesses();
        }
    }

    private function startListener(int $port): void
    {
        $env = $this->databaseEnvironment();
        $env['NIGHTWATCH_ENABLED'] = 'false';
        $env['OVERWATCH_TCP_HOST'] = (string) config('overwatch.self_test.host', '127.0.0.1');
        $env['OVERWATCH_TCP_PORT'] = (string) $port;

        $process = new Process(
            [PHP_BINARY, 'artisan', 'nightwatch:listen', "--host={$env['OVERWATCH_TCP_HOST']}", "--port={$port}"],
            base_path(),
            $env,
        );

        $process->setTimeout(null);
        $process->start();

        $this->backgroundProcesses[] = $process;
    }

    private function startServer(int $port, string $secret, string $ingestUri, string $deployment, string $serverSuffix, bool $capturePayload, string $runId, string $secondaryBaseUrl): void
    {
        $env = $this->helperEnvironment(
            secret: $secret,
            ingestUri: $ingestUri,
            deployment: $deployment,
            serverSuffix: $serverSuffix,
            runId: $runId,
            secondaryBaseUrl: $secondaryBaseUrl,
        );

        $env['APP_URL'] = 'http://'.config('overwatch.self_test.host', '127.0.0.1').":{$port}";
        $env['NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD'] = $capturePayload ? 'true' : 'false';
        $env['PHP_CLI_SERVER_WORKERS'] = '2';

        $process = new Process(
            [PHP_BINARY, 'artisan', 'serve', '--host='.(string) config('overwatch.self_test.host', '127.0.0.1'), "--port={$port}"],
            base_path(),
            $env,
        );

        $process->setTimeout(null);
        $process->start();

        $this->backgroundProcesses[] = $process;
    }

    private function triggerHttpMatrix(string $primaryBaseUrl, string $secondaryBaseUrl, string $runId): void
    {
        $timeout = (int) config('overwatch.self_test.request_timeout', 10);

        $this->assertHttp(Http::timeout($timeout)->acceptJson()->get("{$primaryBaseUrl}".NightwatchSelfTestSupport::routePath('exercise'), [
            'run' => $runId,
        ]), [200]);

        $this->assertHttp(Http::timeout($timeout)->acceptJson()->get("{$primaryBaseUrl}".NightwatchSelfTestSupport::routePath('payload/absent'), [
            'run' => $runId,
        ]), [500]);

        $this->assertHttp(Http::timeout($timeout)->acceptJson()->post("{$primaryBaseUrl}".NightwatchSelfTestSupport::routePath('payload/present'), [
            'run_id' => $runId,
            'marker' => NightwatchSelfTestSupport::MARKER,
        ]), [500]);

        $this->assertHttp(
            Http::timeout($timeout)
                ->withBody('nightwatch-self-test', 'application/octet-stream')
                ->post("{$primaryBaseUrl}".NightwatchSelfTestSupport::routePath('payload/unsupported').'?run='.$runId),
            [500],
        );

        $this->assertHttp(Http::timeout($timeout)->acceptJson()->post("{$secondaryBaseUrl}".NightwatchSelfTestSupport::routePath('payload/not-enabled'), [
            'run_id' => $runId,
            'marker' => NightwatchSelfTestSupport::MARKER,
        ]), [500]);

        $this->assertHttp(Http::timeout($timeout)->acceptJson()->get("{$primaryBaseUrl}".NightwatchSelfTestSupport::routePath('exception/unhandled'), [
            'run' => $runId,
        ]), [500]);
    }

    /**
     * @return array<string, int>
     */
    private function waitForAndVerify(int $projectId, string $runId, int $timeout): array
    {
        $expectedRawCount = 35;
        $deployment = "nightwatch-self-test-{$runId}";
        $selfTestUserEmail = NightwatchSelfTestSupport::userEmail($runId);
        $deadline = microtime(true) + $timeout;

        do {
            $deploymentId = $this->resolveDeploymentId($projectId, $deployment);
            $deploymentRawCount = DB::table('nw_raw_events')
                ->where('project_id', $projectId)
                ->when($deploymentId !== null, fn ($query) => $query->where('deployment_id', $deploymentId))
                ->count();
            $userRawCount = $this->resolveSelfTestUserRawCount($projectId, $selfTestUserEmail);
            $rawCount = $deploymentRawCount + $userRawCount;

            if ($rawCount >= $expectedRawCount) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $errors = [];
        $deploymentId = $this->resolveDeploymentId($projectId, $deployment);

        if ($deploymentId === null) {
            throw new RuntimeException("Unable to resolve self-test deployment [{$deployment}] for project [{$projectId}].");
        }

        $summary = DB::table('nw_raw_events')
            ->select('event_type', DB::raw('count(*) as aggregate'))
            ->where('project_id', $projectId)
            ->where('deployment_id', $deploymentId)
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type')
            ->map(fn ($count) => (int) $count)
            ->all();
        $summary['user'] = $this->resolveSelfTestUserRawCount($projectId, $selfTestUserEmail);

        $expectedSummary = [
            'cache-event' => 6,
            'command' => 4,
            'exception' => 3,
            'job-attempt' => 4,
            'log' => 1,
            'mail' => 1,
            'notification' => 1,
            'outgoing-request' => 1,
            'query' => 1,
            'queued-job' => 3,
            'request' => 6,
            'scheduled-task' => 3,
            'user' => 1,
        ];

        foreach ($expectedSummary as $eventType => $count) {
            if (($summary[$eventType] ?? 0) !== $count) {
                $errors[] = "Expected [{$count}] {$eventType} events, found [".($summary[$eventType] ?? 0).'].';
            }
        }

        $userCount = DB::table('nw_users')
            ->where('project_id', $projectId)
            ->where('username', $selfTestUserEmail)
            ->count();

        if ($userCount !== 1) {
            $errors[] = "Expected exactly one self-test user row for [{$selfTestUserEmail}], found [{$userCount}].";
        }

        $requestStates = DB::table('nw_request_details as details')
            ->join('nw_executions as executions', 'executions.id', '=', 'details.execution_row_id')
            ->where('executions.project_id', $projectId)
            ->where('executions.deployment_id', $deploymentId)
            ->pluck('details.request_payload_state', 'details.route_name')
            ->all();

        $expectedRequestStates = [
            'nightwatch.self-test.exercise' => 'absent',
            'nightwatch.self-test.payload.absent' => 'absent',
            'nightwatch.self-test.payload.present' => 'present',
            'nightwatch.self-test.payload.unsupported' => 'unsupported_content_type',
            'nightwatch.self-test.payload.not-enabled' => 'not_enabled',
            'nightwatch.self-test.exception.unhandled' => 'absent',
        ];

        foreach ($expectedRequestStates as $routeName => $state) {
            if (($requestStates[$routeName] ?? null) !== $state) {
                $errors[] = "Expected route [{$routeName}] to store request payload state [{$state}].";
            }
        }

        $commandExitCodes = DB::table('nw_command_details as details')
            ->join('nw_executions as executions', 'executions.id', '=', 'details.execution_row_id')
            ->where('executions.project_id', $projectId)
            ->where('executions.deployment_id', $deploymentId)
            ->where('details.name', 'nightwatch:self-test:command')
            ->pluck('details.exit_code')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (! $this->countsMatch($commandExitCodes, [0 => 1, 1 => 1])) {
            $errors[] = 'Expected exactly one successful and one failed command event.';
        }

        $scheduledCommandExitCodes = DB::table('nw_command_details as details')
            ->join('nw_executions as executions', 'executions.id', '=', 'details.execution_row_id')
            ->where('executions.project_id', $projectId)
            ->where('executions.deployment_id', $deploymentId)
            ->where('details.name', 'nightwatch:self-test:schedule')
            ->pluck('details.exit_code')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (! $this->countsMatch($scheduledCommandExitCodes, [0 => 1, 1 => 1])) {
            $errors[] = 'Expected scheduled helper commands to emit one success and one failure command event.';
        }

        $scheduledStatuses = DB::table('nw_scheduled_task_details as details')
            ->join('nw_executions as executions', 'executions.id', '=', 'details.execution_row_id')
            ->where('executions.project_id', $projectId)
            ->where('executions.deployment_id', $deploymentId)
            ->pluck('details.status')
            ->all();

        if (! $this->countsMatch($scheduledStatuses, ['failed' => 1, 'processed' => 1, 'skipped' => 1])) {
            $errors[] = 'Expected scheduled task statuses processed/skipped/failed exactly once.';
        }

        $jobAttemptStatuses = DB::table('nw_job_attempt_details as details')
            ->join('nw_executions as executions', 'executions.id', '=', 'details.execution_row_id')
            ->where('executions.project_id', $projectId)
            ->where('executions.deployment_id', $deploymentId)
            ->pluck('details.status')
            ->all();

        if (! $this->countsMatch($jobAttemptStatuses, ['failed' => 1, 'processed' => 1, 'released' => 1])) {
            $errors[] = 'Expected job-attempt statuses processed/released/failed exactly once.';
        }

        $cacheTypes = DB::table('nw_cache_events')
            ->where('project_id', $projectId)
            ->where('deployment_id', $deploymentId)
            ->pluck('cache_event_type')
            ->all();

        if (! $this->countsMatch($cacheTypes, [
            'delete' => 1,
            'delete-failure' => 1,
            'hit' => 1,
            'miss' => 1,
            'write' => 1,
            'write-failure' => 1,
        ])) {
            $errors[] = 'Expected all cache-event sub-types exactly once.';
        }

        $exceptions = DB::table('nw_exceptions')
            ->where('project_id', $projectId)
            ->where('deployment_id', $deploymentId)
            ->select('message', 'handled', 'execution_source')
            ->get()
            ->all();

        $handledRequestExceptions = 0;
        $unhandledRequestExceptions = 0;
        $scheduledExceptions = 0;

        foreach ($exceptions as $exception) {
            if ($exception->execution_source === 'request' && (bool) $exception->handled) {
                $handledRequestExceptions++;
            }

            if ($exception->execution_source === 'request' && ! (bool) $exception->handled) {
                $unhandledRequestExceptions++;
            }

            if ($exception->execution_source === 'schedule' && ! (bool) $exception->handled) {
                $scheduledExceptions++;
            }
        }

        if ($handledRequestExceptions !== 1 || $unhandledRequestExceptions !== 1 || $scheduledExceptions !== 1) {
            $errors[] = 'Expected handled request, unhandled request, and scheduled-task failure exceptions exactly once.';
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        return $summary;
    }

    private function resolveDeploymentId(int $projectId, string $deployment): ?int
    {
        $id = DB::table('nw_deployments')
            ->where('project_id', $projectId)
            ->where('name', $deployment)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function resolveSelfTestUserRawCount(int $projectId, string $selfTestUserEmail): int
    {
        return DB::table('nw_raw_events')
            ->where('project_id', $projectId)
            ->where('event_type', 'user')
            ->where('payload', 'like', '%'.$selfTestUserEmail.'%')
            ->count();
    }

    /**
     * @param  list<int>  $expectedExitCodes
     */
    private function runHelperCommand(array $arguments, array $env, array $expectedExitCodes): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            base_path(),
            $env,
        );

        $process->setTimeout(null);
        $process->run();

        if (! in_array($process->getExitCode(), $expectedExitCodes, true)) {
            throw new ProcessFailedException($process);
        }
    }

    private function helperEnvironment(string $secret, string $ingestUri, string $deployment, string $serverSuffix, string $runId, string $secondaryBaseUrl): array
    {
        return array_merge($this->databaseEnvironment(), [
            'APP_ENV' => 'local',
            'NIGHTWATCH_ENABLED' => 'true',
            'NIGHTWATCH_TOKEN' => $secret,
            'NIGHTWATCH_INGEST_URI' => $ingestUri,
            'NIGHTWATCH_DEPLOY' => $deployment,
            'NIGHTWATCH_SERVER' => "nightwatch-self-test-{$serverSuffix}",
            'NIGHTWATCH_REQUEST_SAMPLE_RATE' => '0',
            'NIGHTWATCH_COMMAND_SAMPLE_RATE' => '0',
            'NIGHTWATCH_EXCEPTION_SAMPLE_RATE' => '0',
            'NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE' => '0',
            'NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD' => 'true',
            'NIGHTWATCH_INGEST_EVENT_BUFFER' => '1',
            'QUEUE_CONNECTION' => 'database',
            'QUEUE_FAILED_DRIVER' => 'null',
            'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'OVERWATCH_SELF_TEST_ENABLED' => '1',
            'OVERWATCH_SELF_TEST_RUN_ID' => $runId,
            'OVERWATCH_SELF_TEST_STUB_BASE_URL' => $secondaryBaseUrl,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        $defaultConnection = (string) config('database.default');
        $connection = config("database.connections.{$defaultConnection}");

        if (! is_array($connection)) {
            throw new RuntimeException("Database connection [{$defaultConnection}] is not configured.");
        }

        if ($defaultConnection === 'sqlite' && ($connection['database'] ?? null) === ':memory:') {
            throw new RuntimeException('The Nightwatch self-test harness requires a file-backed database connection.');
        }

        return array_filter([
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => $defaultConnection,
            'DB_DATABASE' => (string) ($connection['database'] ?? ''),
            'DB_HOST' => (string) ($connection['host'] ?? ''),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ], static fn ($value) => $value !== '');
    }

    private function waitForTcpReady(string $host, int $port, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;

        do {
            $socket = @fsockopen($host, $port);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            $this->assertBackgroundProcessesStillRunning();
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Timed out waiting for TCP listener on [{$host}:{$port}].");
    }

    private function waitForHttpReady(string $url, int $timeout): void
    {
        $deadline = microtime(true) + $timeout;

        do {
            try {
                $response = Http::timeout(1)->get($url);
            } catch (\Throwable) {
                $this->assertBackgroundProcessesStillRunning();
                usleep(100_000);

                continue;
            }

            if ($response->successful()) {
                return;
            }

            $this->assertBackgroundProcessesStillRunning();
            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Timed out waiting for HTTP server [{$url}].");
    }

    private function assertBackgroundProcessesStillRunning(): void
    {
        foreach ($this->backgroundProcesses as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException("Background process exited unexpectedly:\n".$process->getOutput().$process->getErrorOutput());
            }
        }
    }

    private function stopBackgroundProcesses(): void
    {
        foreach ($this->backgroundProcesses as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        $this->backgroundProcesses = [];
    }

    private function availablePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException("Unable to allocate a TCP port: {$errorMessage}");
        }

        $address = stream_socket_get_name($server, false);
        fclose($server);

        return (int) Str::afterLast((string) $address, ':');
    }

    /**
     * @param  list<int>  $expectedStatusCodes
     */
    private function assertHttp(\Illuminate\Http\Client\Response $response, array $expectedStatusCodes): void
    {
        if (! in_array($response->status(), $expectedStatusCodes, true)) {
            throw new RuntimeException("Unexpected HTTP status [{$response->status()}] returned by self-test route.");
        }
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int|string, int>  $expected
     */
    private function countsMatch(array $values, array $expected): bool
    {
        $normalized = array_map(
            static fn ($value) => is_bool($value) ? (int) $value : $value,
            $values,
        );

        $actual = array_count_values($normalized);
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }
}
