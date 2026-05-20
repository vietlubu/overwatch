<?php

namespace Tests\Feature;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NightwatchProjectCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_create_command_creates_a_project(): void
    {
        $this->artisan('nightwatch:project:create', [
            'slug' => 'acme',
            '--name' => 'Acme',
        ])->assertSuccessful();

        $this->assertDatabaseHas('nw_projects', [
            'slug' => 'acme',
            'name' => 'Acme',
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('nw_ingest_tokens', 0);
    }

    public function test_key_create_command_generates_a_key_and_prints_the_secret_once(): void
    {
        DB::table('nw_projects')->insert([
            'id' => 10,
            'name' => 'Acme',
            'slug' => 'acme',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('nightwatch:key:create', [
            'project' => 'acme',
            '--environment' => 'production',
            '--name' => 'primary',
        ])
            ->expectsOutputToContain('Store this secret now. It will not be shown again.')
            ->expectsOutputToContain('NIGHTWATCH_TOKEN=')
            ->expectsOutputToContain('NIGHTWATCH_INGEST_URI=')
            ->assertSuccessful();

        $token = DB::table('nw_ingest_tokens')
            ->where('project_id', 10)
            ->where('environment', 'production')
            ->where('key_name', 'primary')
            ->first();

        $this->assertNotNull($token);
        $this->assertTrue((bool) $token->is_active);
        $this->assertNotEmpty($token->secret_sha256);
        $this->assertNotEmpty($token->secret_fingerprint);
        $this->assertNotEmpty($token->secret_last_four);
        $this->assertSame(
            NightwatchProjectKeyManager::secretFingerprint((string) $token->secret_sha256),
            $token->secret_fingerprint,
        );
    }

    public function test_key_create_command_supports_multiple_environments_for_the_same_project(): void
    {
        DB::table('nw_projects')->insert([
            'id' => 11,
            'name' => 'Acme',
            'slug' => 'acme',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('nightwatch:key:create', [
            'project' => 'acme',
            '--environment' => 'production',
            '--name' => 'primary',
        ])->assertSuccessful();

        $this->artisan('nightwatch:key:create', [
            'project' => 'acme',
            '--environment' => 'staging',
            '--name' => 'primary',
        ])->assertSuccessful();

        $this->assertDatabaseCount('nw_ingest_tokens', 2);
        $this->assertDatabaseHas('nw_ingest_tokens', [
            'project_id' => 11,
            'environment' => 'production',
            'key_name' => 'primary',
        ]);
        $this->assertDatabaseHas('nw_ingest_tokens', [
            'project_id' => 11,
            'environment' => 'staging',
            'key_name' => 'primary',
        ]);
    }
}
