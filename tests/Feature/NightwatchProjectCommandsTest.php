<?php

namespace Tests\Feature;

use App\Nightwatch\NightwatchProjectKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NightwatchProjectCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_create_command_creates_a_project_with_key_and_tags(): void
    {
        $this->artisan('nightwatch:project:create', [
            'slug' => 'acme',
            '--name' => 'Acme',
            '--tags' => 'internal,billing',
        ])
            ->expectsOutputToContain('Store this secret now. It will not be shown again.')
            ->expectsOutputToContain('NIGHTWATCH_TOKEN=')
            ->expectsOutputToContain('NIGHTWATCH_INGEST_URI=')
            ->assertSuccessful();

        $project = DB::table('nw_projects')
            ->where('slug', 'acme')
            ->first();

        $this->assertNotNull($project);
        $this->assertTrue((bool) $project->is_active);
        $this->assertNotEmpty($project->token_hash);
        $this->assertNotEmpty($project->secret_sha256);
        $this->assertNotEmpty($project->secret_fingerprint);
        $this->assertNotEmpty($project->secret_last_four);
        $this->assertSame(['internal', 'billing'], json_decode((string) $project->tags, true));
    }

    public function test_project_update_command_updates_name_and_tags(): void
    {
        DB::table('nw_projects')->insert([
            'id' => 10,
            'name' => 'Acme',
            'slug' => 'acme',
            'is_active' => true,
            'token_hash' => 'abc1234',
            'secret_sha256' => str_repeat('a', 64),
            'secret_fingerprint' => str_repeat('b', 16),
            'secret_last_four' => '1234',
            'last_seen_at' => null,
            'tags' => json_encode(['internal'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('nightwatch:project:update', [
            'project' => 'acme',
            '--name' => 'Acme API',
            '--tags' => 'internal,api',
        ])->assertSuccessful();

        $this->assertDatabaseHas('nw_projects', [
            'id' => 10,
            'name' => 'Acme API',
            'slug' => 'acme',
        ]);
        $this->assertSame(
            ['internal', 'api'],
            json_decode((string) DB::table('nw_projects')->where('id', 10)->value('tags'), true),
        );
    }

    public function test_project_rotate_key_command_rotates_the_secret_material(): void
    {
        $secret = 'nw_existing_secret_1234567890';

        DB::table('nw_projects')->insert([
            'id' => 11,
            'name' => 'Acme',
            'slug' => 'acme',
            'is_active' => true,
            'token_hash' => NightwatchProjectKeyManager::tokenHashForSecret($secret),
            'secret_sha256' => NightwatchProjectKeyManager::secretSha256($secret),
            'secret_fingerprint' => NightwatchProjectKeyManager::secretFingerprint(NightwatchProjectKeyManager::secretSha256($secret)),
            'secret_last_four' => substr($secret, -4),
            'last_seen_at' => null,
            'tags' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = DB::table('nw_projects')->where('id', 11)->first();

        $this->artisan('nightwatch:project:rotate-key', [
            'project' => 'acme',
        ])
            ->expectsOutputToContain('Store this secret now. It will not be shown again.')
            ->expectsOutputToContain('NIGHTWATCH_TOKEN=')
            ->assertSuccessful();

        $after = DB::table('nw_projects')->where('id', 11)->first();

        $this->assertNotSame($before->token_hash, $after->token_hash);
        $this->assertNotSame($before->secret_sha256, $after->secret_sha256);
        $this->assertNotSame($before->secret_fingerprint, $after->secret_fingerprint);
    }

    public function test_find_project_normalizes_last_seen_at_from_database_strings(): void
    {
        $secret = 'nw_existing_secret_1234567890';
        $lastSeenAt = now()->subMinute()->format('Y-m-d H:i:s');

        DB::table('nw_projects')->insert([
            'id' => 12,
            'name' => 'Acme',
            'slug' => 'acme',
            'is_active' => true,
            'token_hash' => NightwatchProjectKeyManager::tokenHashForSecret($secret),
            'secret_sha256' => NightwatchProjectKeyManager::secretSha256($secret),
            'secret_fingerprint' => NightwatchProjectKeyManager::secretFingerprint(NightwatchProjectKeyManager::secretSha256($secret)),
            'secret_last_four' => substr($secret, -4),
            'last_seen_at' => $lastSeenAt,
            'tags' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project = app(NightwatchProjectKeyManager::class)->findProject('acme');

        $this->assertNotNull($project);
        $this->assertSame('acme', $project['slug']);
        $this->assertNotNull($project['last_seen_at']);
    }
}
