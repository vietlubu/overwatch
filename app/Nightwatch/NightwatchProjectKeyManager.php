<?php

namespace App\Nightwatch;

use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function array_map;
use function array_values;
use function bin2hex;
use function hash;
use function is_numeric;
use function is_string;
use function random_bytes;
use function substr;
use function trim;

final class NightwatchProjectKeyManager
{
    /**
     * @param  list<string>  $tags
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     is_active: bool,
     *     tags: list<string>,
     *     token_hash: string,
     *     secret_fingerprint: string,
     *     secret_last_four: string,
     *     secret: string,
     * }
     */
    public function createProject(string $slug, ?string $name = null, array $tags = []): array
    {
        $slug = Str::slug($slug);
        $name ??= Str::headline($slug);
        $tags = $this->normalizeTags($tags);
        $secret = $this->generateUniqueSecret();
        $tokenHash = self::tokenHashForSecret($secret);
        $secretSha256 = self::secretSha256($secret);
        $secretFingerprint = self::secretFingerprint($secretSha256);
        $secretLastFour = substr($secret, -4);

        $projectId = (int) DB::table('nw_projects')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'token_hash' => $tokenHash,
            'secret_sha256' => $secretSha256,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
            'last_seen_at' => null,
            'tags' => $this->jsonValue($tags),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $projectId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'tags' => $tags,
            'token_hash' => $tokenHash,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
            'secret' => $secret,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     is_active: bool,
     *     tags: list<string>,
     *     token_hash: string,
     *     secret_fingerprint: string,
     *     secret_last_four: string,
     *     last_seen_at: ?string,
     * }|null
     */
    public function findProject(string $identifier): ?array
    {
        $query = DB::table('nw_projects')->select([
            'id',
            'name',
            'slug',
            'is_active',
            'tags',
            'token_hash',
            'secret_fingerprint',
            'secret_last_four',
            'last_seen_at',
        ]);

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
            'tags' => $this->decodeTags($project->tags),
            'token_hash' => (string) $project->token_hash,
            'secret_fingerprint' => (string) $project->secret_fingerprint,
            'secret_last_four' => (string) $project->secret_last_four,
            'last_seen_at' => $this->normalizeTimestamp($project->last_seen_at),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     is_active: bool,
     *     tags: list<string>,
     *     token_hash: string,
     *     secret: string,
     *     secret_fingerprint: string,
     *     secret_last_four: string,
     * }
     */
    public function rotateKey(array $project): array
    {
        $secret = $this->generateUniqueSecret();
        $tokenHash = self::tokenHashForSecret($secret);
        $secretSha256 = self::secretSha256($secret);
        $secretFingerprint = self::secretFingerprint($secretSha256);
        $secretLastFour = substr($secret, -4);

        DB::table('nw_projects')
            ->where('id', $project['id'])
            ->update([
            'token_hash' => $tokenHash,
            'secret_sha256' => $secretSha256,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
            'updated_at' => now(),
        ]);

        return [
            'id' => $project['id'],
            'name' => $project['name'],
            'slug' => $project['slug'],
            'is_active' => $project['is_active'],
            'tags' => $project['tags'],
            'token_hash' => $tokenHash,
            'secret' => $secret,
            'secret_fingerprint' => $secretFingerprint,
            'secret_last_four' => $secretLastFour,
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     is_active: bool,
     *     tags: list<string>,
     *     token_hash: string,
     *     secret_fingerprint: string,
     *     secret_last_four: string,
     *     last_seen_at: ?string,
     * }
     */
    public function updateProject(array $project, ?string $name = null, ?array $tags = null): array
    {
        $name = $name !== null ? trim($name) : $project['name'];
        $name = $name !== '' ? $name : $project['name'];
        $normalizedTags = $tags !== null ? $this->normalizeTags($tags) : $project['tags'];

        DB::table('nw_projects')
            ->where('id', $project['id'])
            ->update([
                'name' => $name,
                'tags' => $this->jsonValue($normalizedTags),
                'updated_at' => now(),
            ]);

        return [
            'id' => $project['id'],
            'name' => $name,
            'slug' => $project['slug'],
            'is_active' => $project['is_active'],
            'tags' => $normalizedTags,
            'token_hash' => $project['token_hash'],
            'secret_fingerprint' => $project['secret_fingerprint'],
            'secret_last_four' => $project['secret_last_four'],
            'last_seen_at' => $project['last_seen_at'],
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

            $exists = DB::table('nw_projects')
                ->where('token_hash', $tokenHash)
                ->exists();
        } while ($exists);

        return $secret;
    }

    /**
     * @param  list<string>  $tags
     */
    private function jsonValue(array $tags): string
    {
        return (string) json_encode($tags, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    public function normalizeTags(array $tags): array
    {
        return array_values(array_filter(array_map(function (mixed $tag): string {
            return trim((string) $tag);
        }, $tags), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @return list<string>
     */
    private function decodeTags(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeTags(Arr::wrap($decoded));
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : Carbon::parse($value)->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
