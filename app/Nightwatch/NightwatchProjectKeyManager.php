<?php

namespace App\Nightwatch;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function bin2hex;
use function hash;
use function is_numeric;
use function random_bytes;
use function substr;

final class NightwatchProjectKeyManager
{
    /**
     * @return array{id: int, name: string, slug: string, is_active: bool}
     */
    public function createProject(string $slug, ?string $name = null): array
    {
        $slug = Str::slug($slug);
        $name ??= Str::headline($slug);

        $projectId = (int) DB::table('nw_projects')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $projectId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, is_active: bool}|null
     */
    public function findProject(string $identifier): ?array
    {
        $query = DB::table('nw_projects')->select(['id', 'name', 'slug', 'is_active']);

        $project = is_numeric($identifier)
            ? $query->where('id', (int) $identifier)->first()
            : $query->where('slug', $identifier)->first();

        if ($project === null) {
            return null;
        }

        return [
            'id' => (int) $project->id,
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'is_active' => (bool) $project->is_active,
        ];
    }

    /**
     * @param  array{id: int, name: string, slug: string, is_active: bool}  $project
     * @return array{
     *     id: int,
     *     project_id: int,
     *     environment: string,
     *     key_name: string,
     *     token_hash: string,
     *     secret: string,
     *     secret_fingerprint: string,
     *     secret_last_four: string,
     * }
     */
    public function createKey(array $project, string $environment, string $keyName = 'primary'): array
    {
        $environment = trim($environment);
        $keyName = trim($keyName);

        $secret = $this->generateUniqueSecret();
        $tokenHash = self::tokenHashForSecret($secret);
        $secretSha256 = self::secretSha256($secret);
        $secretFingerprint = self::secretFingerprint($secretSha256);
        $secretLastFour = substr($secret, -4);

        $id = (int) DB::table('nw_ingest_tokens')->insertGetId([
            'project_id' => $project['id'],
            'environment' => $environment,
            'token_hash' => $tokenHash,
            'key_name' => $keyName,
            'secret_sha256' => $secretSha256,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
            'is_active' => true,
            'revoked_at' => null,
            'last_seen_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'project_id' => $project['id'],
            'environment' => $environment,
            'key_name' => $keyName,
            'token_hash' => $tokenHash,
            'secret' => $secret,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
        ];
    }

    public static function tokenHashForSecret(string $secret): string
    {
        return substr(hash('xxh128', $secret), 0, 7);
    }

    public static function secretSha256(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function secretFingerprint(string $secretSha256): string
    {
        return substr($secretSha256, 0, 16);
    }

    private function generateUniqueSecret(): string
    {
        do {
            $secret = 'nw_'.bin2hex(random_bytes(24));
            $tokenHash = self::tokenHashForSecret($secret);

            $exists = DB::table('nw_ingest_tokens')
                ->where('token_hash', $tokenHash)
                ->exists();
        } while ($exists);

        return $secret;
    }
}
