<?php

namespace Tests\Feature;

use App\Nightwatch\NightwatchProjectKeyManager;
use Symfony\Component\Process\Process;
use Tests\Support\UsesFileSqliteDatabase;
use Tests\TestCase;

use function fclose;
use function fsockopen;
use function fwrite;
use function json_encode;
use function stream_get_contents;
use function usleep;

class NightwatchListenCommandTest extends TestCase
{
    use UsesFileSqliteDatabase;

    private Process $listener;

    private string $tokenHash;

    private int $listenerPort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFileSqliteDatabase();

        $manager = app(NightwatchProjectKeyManager::class);
        $project = $manager->createProject('listener-test', 'Listener Test');

        $this->tokenHash = $project['token_hash'];
        $this->listenerPort = $this->availablePort();

        $this->listener = new Process(
            [
                PHP_BINARY,
                'artisan',
                'nightwatch:listen',
                '--host=127.0.0.1',
                '--port='.$this->listenerPort,
            ],
            base_path(),
            $this->sqliteEnvironmentOverrides() + [
                'NIGHTWATCH_ENABLED' => 'false',
                'OVERWATCH_TCP_HOST' => '127.0.0.1',
                'OVERWATCH_TCP_PORT' => (string) $this->listenerPort,
            ],
        );

        $this->listener->setTimeout(null);
        $this->listener->start();

        $this->waitForListener();
    }

    protected function tearDown(): void
    {
        if (isset($this->listener) && $this->listener->isRunning()) {
            $this->listener->stop(1);
        }

        $this->cleanupFileSqliteDatabase();

        parent::tearDown();
    }

    public function test_ping_returns_ack(): void
    {
        $response = $this->sendFrame($this->wirePayload('PING'));

        $this->assertSame('2:OK', $response);
        $this->assertDatabaseCount('nw_raw_events', 0);
        $this->assertDatabaseHas('nw_ingest_batches', [
            'ack_status' => 'accepted',
            'record_count' => 0,
        ]);
    }

    public function test_valid_batch_persists_and_acknowledges(): void
    {
        $response = $this->sendFrame($this->wirePayload([
            [
                'v' => 1,
                't' => 'request',
                'timestamp' => 1779282709.0,
                'deploy' => 'listener-test',
                'server' => 'listener-test',
                '_group' => 'listener-request',
                'trace_id' => 'trace-1',
                'method' => 'GET',
                'url' => 'http://listener.test/orders/1',
                'route_name' => 'listener.orders.show',
                'route_methods' => ['GET'],
                'route_domain' => 'listener.test',
                'route_path' => '/orders/{order}',
                'route_action' => 'ListenerController@show',
                'ip' => '127.0.0.1',
                'duration' => 1000,
                'status_code' => 200,
                'request_size' => 10,
                'response_size' => 20,
                'bootstrap' => 10,
                'before_middleware' => 10,
                'action' => 10,
                'render' => 10,
                'after_middleware' => 10,
                'sending' => 10,
                'terminating' => 10,
                'exceptions' => 0,
                'logs' => 0,
                'queries' => 0,
                'lazy_loads' => 0,
                'jobs_queued' => 0,
                'mail' => 0,
                'notifications' => 0,
                'outgoing_requests' => 0,
                'files_read' => 0,
                'files_written' => 0,
                'cache_events' => 0,
                'hydrated_models' => 0,
                'peak_memory_usage' => 1024,
                'exception_preview' => '',
                'context' => '{}',
                'headers' => '{}',
                'payload' => '',
            ],
        ]));

        $this->assertSame('2:OK', $response);
        $this->assertDatabaseCount('nw_raw_events', 1);
        $this->assertDatabaseHas('nw_request_details', [
            'route_name' => 'listener.orders.show',
            'request_payload_state' => 'absent',
        ]);
    }

    public function test_invalid_frame_rejects_without_partial_persistence(): void
    {
        $response = $this->sendRaw('4:bad');

        $this->assertSame('', $response);
        $this->assertDatabaseCount('nw_raw_events', 0);
    }

    public function test_multiple_frames_on_one_connection_are_processed(): void
    {
        $socket = fsockopen('127.0.0.1', $this->listenerPort);

        fwrite($socket, $this->wirePayload('PING').$this->wirePayload([
            [
                'v' => 1,
                't' => 'log',
                'timestamp' => 1779282709.0,
                'deploy' => 'listener-test',
                'server' => 'listener-test',
                '_group' => 'listener-log',
                'trace_id' => 'trace-log',
                'execution_source' => 'command',
                'execution_id' => 'command-1',
                'execution_preview' => 'listener-test',
                'execution_stage' => 'action',
                'level' => 'info',
                'message' => 'Listener test log',
                'context' => '{}',
                'extra' => '{}',
            ],
        ]));

        stream_socket_shutdown($socket, STREAM_SHUT_WR);

        $response = stream_get_contents($socket);
        fclose($socket);

        $this->assertSame('2:OK2:OK', $response);
        $this->assertDatabaseCount('nw_raw_events', 1);
        $this->assertDatabaseHas('nw_logs', [
            'message' => 'Listener test log',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|string  $payload
     */
    private function wirePayload(array|string $payload): string
    {
        $encoded = $payload === 'PING'
            ? 'PING'
            : json_encode($payload, JSON_THROW_ON_ERROR);

        $body = 'v1:'.$this->tokenHash.':'.$encoded;

        return strlen($body).':'.$body;
    }

    private function sendFrame(string $frame): string
    {
        $socket = fsockopen('127.0.0.1', $this->listenerPort);

        fwrite($socket, $frame);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);

        $response = stream_get_contents($socket);

        fclose($socket);

        return $response;
    }

    private function sendRaw(string $payload): string
    {
        $socket = fsockopen('127.0.0.1', $this->listenerPort);

        fwrite($socket, $payload);
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        usleep(200_000);

        $response = stream_get_contents($socket);

        fclose($socket);

        return $response;
    }

    private function waitForListener(): void
    {
        $deadline = microtime(true) + 5;

        do {
            $socket = @fsockopen('127.0.0.1', $this->listenerPort);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail('Listener did not start in time.');
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            $this->fail("Unable to allocate TCP port: {$errorMessage}");
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) str($address)->afterLast(':')->toString();
    }
}
