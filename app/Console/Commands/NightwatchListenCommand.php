<?php

namespace App\Console\Commands;

use App\Nightwatch\NightwatchEventIngestor;
use App\Nightwatch\NightwatchWireFrameBuffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Facades\Nightwatch;
use Throwable;

use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function sprintf;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;

class NightwatchListenCommand extends Command
{
    protected $signature = 'nightwatch:listen
        {--host= : TCP host to bind}
        {--port= : TCP port to bind}
        {--backlog= : Listen backlog}
        {--accept-timeout= : Accept timeout in seconds}
        {--read-timeout= : Per-connection read timeout in seconds}
        {--max-frame-bytes= : Maximum framed payload size in bytes}';

    protected $description = 'Listen for Nightwatch TCP payloads and ingest them into Overwatch.';

    private bool $running = true;

    public function handle(NightwatchEventIngestor $ingestor): int
    {
        Nightwatch::pause();
        Nightwatch::dontSample();

        $host = (string) ($this->option('host') ?: config('overwatch.tcp.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('overwatch.tcp.port', 2407));
        $backlog = (int) ($this->option('backlog') ?: config('overwatch.tcp.backlog', 128));
        $acceptTimeout = (float) ($this->option('accept-timeout') ?: config('overwatch.tcp.accept_timeout', 1.0));
        $readTimeout = (float) ($this->option('read-timeout') ?: config('overwatch.tcp.read_timeout', 1.0));
        $maxFrameBytes = (int) ($this->option('max-frame-bytes') ?: config('overwatch.tcp.max_frame_bytes', 10 * 1024 * 1024));
        $acknowledgment = (string) config('overwatch.tcp.acknowledgment', '2:OK');
        $logger = Log::channel((string) config('overwatch.logging.channel', 'stack'));

        $this->installSignalHandlers();

        $context = stream_context_create([
            'socket' => [
                'backlog' => $backlog,
            ],
        ]);

        $server = @stream_socket_server(
            sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            $this->error("Unable to start listener: {$errorMessage}");

            return self::FAILURE;
        }

        $logger->info('Nightwatch TCP listener started.', [
            'host' => $host,
            'port' => $port,
        ]);

        try {
            while ($this->running) {
                $client = @stream_socket_accept($server, $acceptTimeout);

                if ($client === false) {
                    continue;
                }

                $peer = (string) stream_socket_get_name($client, true);

                try {
                    $this->ingestConnection($client, $peer, $ingestor, $readTimeout, $maxFrameBytes, $acknowledgment);
                } catch (Throwable $e) {
                    $logger->warning('Nightwatch TCP payload rejected.', [
                        'peer' => $peer,
                        'message' => $e->getMessage(),
                    ]);
                } finally {
                    fclose($client);
                }
            }
        } finally {
            fclose($server);
            $logger->info('Nightwatch TCP listener stopped.', [
                'host' => $host,
                'port' => $port,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $client
     */
    private function ingestConnection($client, string $peer, NightwatchEventIngestor $ingestor, float $readTimeout, int $maxFrameBytes, string $acknowledgment): void
    {
        stream_set_timeout($client, (int) $readTimeout, (int) (($readTimeout - (int) $readTimeout) * 1_000_000));

        $frames = new NightwatchWireFrameBuffer($maxFrameBytes);

        while (! feof($client) && $this->running) {
            $chunk = fread($client, 8192);

            if ($chunk === false) {
                throw new \RuntimeException("Failed reading payload bytes from [{$peer}].");
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($client);

                if ($meta['timed_out'] ?? false) {
                    if ($frames->hasPendingBytes()) {
                        throw new \RuntimeException("Timed out waiting for the rest of the Nightwatch frame from [{$peer}].");
                    }

                    continue;
                }

                break;
            }

            $frames->append($chunk);

            while (($frame = $frames->nextFrame()) !== null) {
                $ingestor->ingestWirePayload($frame, 'tcp');
                fwrite($client, $acknowledgment);
            }
        }

        if ($frames->hasPendingBytes()) {
            throw new \RuntimeException("Connection [{$peer}] closed before a full Nightwatch frame was received.");
        }
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function (): void {
            $this->running = false;
        });

        pcntl_signal(SIGTERM, function (): void {
            $this->running = false;
        });
    }
}
