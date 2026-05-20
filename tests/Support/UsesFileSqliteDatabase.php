<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait UsesFileSqliteDatabase
{
    protected string $sqliteDatabasePath;

    protected function useFileSqliteDatabase(): void
    {
        $directory = storage_path('framework/testing');

        File::ensureDirectoryExists($directory);

        $this->sqliteDatabasePath = $directory.'/'.Str::uuid().'.sqlite';

        File::put($this->sqliteDatabasePath, '');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->sqliteDatabasePath,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'database',
            'mail.default' => 'array',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Artisan::call('migrate:fresh', [
            '--database' => 'sqlite',
            '--force' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function sqliteEnvironmentOverrides(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->sqliteDatabasePath,
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'database',
            'QUEUE_FAILED_DRIVER' => 'null',
            'SESSION_DRIVER' => 'array',
        ];
    }

    protected function cleanupFileSqliteDatabase(): void
    {
        DB::disconnect('sqlite');

        if (isset($this->sqliteDatabasePath) && File::exists($this->sqliteDatabasePath)) {
            File::delete($this->sqliteDatabasePath);
        }
    }
}
