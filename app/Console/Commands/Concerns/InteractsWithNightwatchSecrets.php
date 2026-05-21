<?php

namespace App\Console\Commands\Concerns;

trait InteractsWithNightwatchSecrets
{
    /**
     * @param  array{slug: string, token_hash: string, secret_fingerprint: string, secret: string}  $project
     */
    protected function renderProjectSecret(array $project): void
    {
        $advertiseUri = sprintf(
            '%s:%d',
            config('overwatch.tcp.host', '127.0.0.1'),
            (int) config('overwatch.tcp.port', 2407),
        );

        $this->warn('Store this secret now. It will not be shown again.');
        $this->newLine();
        $this->table(
            ['Project', 'Token Hash', 'Fingerprint'],
            [[
                $project['slug'],
                $project['token_hash'],
                $project['secret_fingerprint'],
            ]],
        );
        $this->line('Secret: '.$project['secret']);
        $this->newLine();
        $this->line('Environment snippet:');
        $this->line('NIGHTWATCH_TOKEN='.$project['secret']);
        $this->line('NIGHTWATCH_INGEST_URI='.$advertiseUri);
        $this->line('NIGHTWATCH_DEPLOY=your-deploy-name');
        $this->line('NIGHTWATCH_SERVER=your-server-name');
    }
}
