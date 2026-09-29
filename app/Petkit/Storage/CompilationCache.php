<?php

namespace App\Petkit\Storage;

use App\Models\History;
use Illuminate\Support\Collection;

/**
 * Generates deterministic cache keys for media compilations (video reels, animated timelapses)
 * derived from History records.
 */
class CompilationCache
{
    // Bump this version whenever the compilation pipeline changes in a way that
    // requires all cached outputs to be discarded and recompiled (e.g. FFmpeg
    // command changes, output format changes, etc.).
    public const SCHEMA_VERSION = 1;

    /**
     * Computes a deterministic cache key for a collection of History records and optional parameters.
     *
     * @param string $prefix
     * @param Collection<int, History> $histories
     * @param array<string, mixed> $extra
     */
    public static function key(string $prefix, Collection $histories, array $extra = []): string
    {
        $keyPayload = array_merge([
            'version' => self::SCHEMA_VERSION,
            'histories' => $histories->map(fn (History $history): array => [
                'id' => $history->id,
                'updated_at' => $history->updated_at?->format('Y-m-d H:i:s.u'),
            ])->values()->all(),
        ], $extra);

        return sprintf('%s_%s', $prefix, md5((string) json_encode($keyPayload)));
    }
}
