<?php

namespace App\Petkit\Storage;

use App\Filament\Pages\ActivitiesPage;
use App\Filament\Pages\MediaPage;
use App\Models\History;
use App\Models\MediaFile;
use App\Petkit\Storage\Concerns\InteractsWithMediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encapsulates video reel query scoping, clip payload formatting, and FFmpeg video concatenation.
 */
class VideoReelGenerator
{
    use InteractsWithMediaStorage;

    public const TEMP_DIRECTORY_PREFIX = 'petkit-video-reel-';
    public const CACHE_DIRECTORY_PREFIX = 'petkit-compilations';
    public const CONCAT_FILENAME = 'clips.txt';
    public const OUTPUT_MP4_FILENAME = 'compilation.mp4';
    public const OUTPUT_MP4_ATTACHMENT_NAME = 'activities-video-compilation.mp4';
    public const SOURCE_FILENAME_PATTERN = 'source_%05d.%s';
    public const CLIP_TS_FILENAME_PATTERN = 'clip_%05d.ts';
    public const CACHE_PREFIX = 'reel';
    public const STORAGE_PREFIX = 'compilations/reels';
    private const PROGRESS_PREFIX = 'reel_progress_';
    public const SECONDS_PER_DAY = 86400;
    public const DIRECTORY_PERMISSIONS = 0755;
    public const MIN_TIMEOUT_SECONDS = 45;
    public const BASE_OVERHEAD_SECONDS = 20;
    public const ESTIMATED_SECONDS_PER_CLIP = 0.6;

    /**
     * Dynamically calculates execution, lock, and cache TTL timeouts based on the number of clips.
     *
     * @param iterable<int, History> $histories
     */
    public static function calculateTimeoutSeconds(iterable $histories): int
    {
        $count = is_countable($histories) ? count($histories) : count(iterator_to_array($histories));

        return max(
            self::MIN_TIMEOUT_SECONDS,
            (int) ceil(self::BASE_OVERHEAD_SECONDS + ($count * self::ESTIMATED_SECONDS_PER_CLIP))
        );
    }

    /**
     * Builds the base Eloquent query for History records that have video captures.
     *
     * @return Builder<History>
     */
    public static function videoCapturesQuery(): Builder
    {
        return History::query()
            ->whereHas('media', function (Builder $mediaQuery): void {
                $mediaQuery->where('file_id', 'like', '%.ts')
                    ->orWhere('file_type', 'like', '%video%');
            });
    }

    /**
     * Formats a collection of History records into clip metadata arrays for the video reel player.
     *
     * @param iterable<int, History> $histories
     * @return array<int, array{history_id: int, url: string, title: string, message: string, pet: string, device: string, time: string, diff: string, duration: string|null, detail_url: string|null, parameters: array<string, mixed>|null}>
     */
    public static function formatClips(iterable $histories): array
    {
        return self::formatHistoryCaptures($histories, true);
    }

    /**
     * Constructs the URL for streaming or downloading the compiled MP4 video compilation.
     *
     * @param array<int|string> $deviceIds
     * @param array<int|string> $petIds
     * @param array<string> $types
     * @param bool $download
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int|null $maxResults
     * @param string|null $timeFrom
     * @param string|null $timeTo
     */
    public static function downloadUrl(
        array $deviceIds = [],
        array $petIds = [],
        array $types = [],
        bool $download = false,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $maxResults = null,
        ?string $timeFrom = null,
        ?string $timeTo = null,
    ): string {
        $params = self::buildFilterParams($deviceIds, $petIds, $types, $dateFrom, $dateTo, $maxResults, $timeFrom, $timeTo);

        if ($download) {
            $params['download'] = 1;
        }

        return route('activities.video-compilation', $params);
    }

    /**
     * Constructs the URL for polling the real-time compilation progress of the video reel.
     *
     * @param array<int|string> $deviceIds
     * @param array<int|string> $petIds
     * @param array<string> $types
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int|null $maxResults
     * @param string|null $timeFrom
     * @param string|null $timeTo
     */
    public static function progressUrl(
        array $deviceIds = [],
        array $petIds = [],
        array $types = [],
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $maxResults = null,
        ?string $timeFrom = null,
        ?string $timeTo = null,
        ?string $cacheKey = null,
    ): string {
        $params = self::buildFilterParams($deviceIds, $petIds, $types, $dateFrom, $dateTo, $maxResults, $timeFrom, $timeTo);

        if ($cacheKey !== null) {
            $params['key'] = $cacheKey;
        }

        return route('activities.video-compilation.progress', $params);
    }

    /**
     * Computes the deterministic cache key for a collection of video reel History records.
     *
     * @param Collection<int, History> $histories
     */
    public static function cacheKey(Collection $histories): string
    {
        return CompilationCache::key(self::CACHE_PREFIX, $histories);
    }

    /**
     * Returns the relative object storage key where a cached compiled video reel should be stored.
     */
    public static function storageKey(string $cacheKey): string
    {
        return self::STORAGE_PREFIX . '/' . $cacheKey . '.mp4';
    }

    /**
     * Returns the absolute path where a cached compiled video reel should be stored on the local disk.
     */
    public static function localCachePath(string $cacheKey): string
    {
        $dir = sys_get_temp_dir() . '/' . self::CACHE_DIRECTORY_PREFIX;
        if (! is_dir($dir)) {
            @mkdir($dir, self::DIRECTORY_PERMISSIONS, true);
        }

        return $dir . '/' . $cacheKey . '.mp4';
    }

    /**
     * Ensures the compiled video reel exists locally for byte-range streaming,
     * compiling and uploading to S3 if missing or downloading from S3 if cached.
     *
     * @param string $cacheKey
     * @param iterable<int, History> $histories
     * @return string Absolute path to the verified local MP4 file.
     */
    public static function ensureLocalFile(string $cacheKey, iterable $histories): string
    {
        $localPath = self::localCachePath($cacheKey);
        $s3Key = self::storageKey($cacheKey);
        $disk = Storage::disk(MediaPage::DISK);

        Log::info('VideoReelGenerator::ensureLocalFile requested', [
            'cacheKey' => $cacheKey,
            'localPath' => $localPath,
            's3Key' => $s3Key,
            'localExists' => file_exists($localPath),
            'localSize' => file_exists($localPath) ? filesize($localPath) : 0,
            'isCachedAndValid' => self::isCachedAndValid($cacheKey),
        ]);

        $totalCount = is_countable($histories) ? count($histories) : count(iterator_to_array($histories));

        if (self::isValidLocalCache($localPath, $cacheKey)) {
            Log::info('VideoReelGenerator::ensureLocalFile returning existing valid local file', ['path' => $localPath]);
            self::publishReadyProgress($cacheKey, 'Ready', 'Loaded from cache', $totalCount);
            return $localPath;
        }

        $timeout = self::calculateTimeoutSeconds($histories);
        $lock = Cache::lock("reel_compile_lock_{$cacheKey}", $timeout);

        $result = $lock->block((int) ($timeout * 0.8), function () use ($cacheKey, $histories, $localPath, $s3Key, $disk, $timeout, $totalCount): string {
            if (self::isValidLocalCache($localPath, $cacheKey)) {
                Log::info('VideoReelGenerator::ensureLocalFile acquired lock and returning cached file', ['path' => $localPath]);
                self::publishReadyProgress($cacheKey, 'Ready', 'Loaded from cache', $totalCount);
                return $localPath;
            }

            if (self::isCachedAndValid($cacheKey)) {
                Log::info('VideoReelGenerator::ensureLocalFile streaming cached compilation from S3 to local', ['s3Key' => $s3Key, 'localPath' => $localPath]);
                self::streamFileFromStorage($disk, $s3Key, $localPath);
                if (self::isNonEmptyFile($localPath)) {
                    Log::info('VideoReelGenerator::ensureLocalFile successfully downloaded cached compilation', ['path' => $localPath, 'size' => filesize($localPath)]);
                    self::publishReadyProgress($cacheKey, 'Ready', 'Loaded from cloud storage', $totalCount);
                    return $localPath;
                }
                Log::warning('VideoReelGenerator::ensureLocalFile failed to download cached file from S3, recompiling', ['s3Key' => $s3Key]);
            }

            self::buildCompilation($histories, $cacheKey, $localPath, $timeout);

            return $localPath;
        });

        if (! is_string($result) || ! file_exists($result) || filesize($result) === 0) {
            $lastProgress = Cache::get(self::progressKey($cacheKey));
            $errorMessage = ($lastProgress && is_array($lastProgress) && ! empty($lastProgress['errorMessage']))
                ? $lastProgress['errorMessage']
                : 'Compilation failed or lock could not be acquired.';

            throw new RuntimeException($errorMessage);
        }

        return $result;
    }

    /**
     * Determines whether the compiled video reel exists in object storage and is within the configured TTL.
     */
    public static function isCachedAndValid(string $cacheKey): bool
    {
        return self::isMediaCachedAndValid(self::storageKey($cacheKey));
    }

    /**
     * Builds the common filter query parameters shared by downloadUrl() and progressUrl().
     *
     * @return array<string, mixed>
     */
    private static function buildFilterParams(
        array $deviceIds,
        array $petIds,
        array $types,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $maxResults,
        ?string $timeFrom,
        ?string $timeTo,
    ): array {
        return array_filter([
            ActivitiesPage::QUERY_PARAM_DEVICES => $deviceIds,
            ActivitiesPage::QUERY_PARAM_PETS => $petIds,
            ActivitiesPage::QUERY_PARAM_TYPES => $types,
            ActivitiesPage::QUERY_PARAM_DATE_FROM => $dateFrom,
            ActivitiesPage::QUERY_PARAM_DATE_TO => $dateTo,
            ActivitiesPage::QUERY_PARAM_MAX_RESULTS => $maxResults,
            ActivitiesPage::QUERY_PARAM_TIME_FROM => $timeFrom,
            ActivitiesPage::QUERY_PARAM_TIME_TO => $timeTo,
        ], fn ($val): bool => $val !== null && $val !== [] && $val !== '');
    }

    private static function progressKey(string $cacheKey): string
    {
        return self::PROGRESS_PREFIX . $cacheKey;
    }

    private static function isNonEmptyFile(string $path): bool
    {
        return file_exists($path) && filesize($path) > 0;
    }

    private static function isValidLocalCache(string $localPath, string $cacheKey): bool
    {
        return self::isNonEmptyFile($localPath) && self::isCachedAndValid($cacheKey);
    }

    private static function allowLongRunningProcess(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    /**
     * Publishes a "ready" cache progress entry for the given cache key.
     */
    private static function publishReadyProgress(
        string $cacheKey,
        string $title = 'Ready',
        string $subtitle = 'Video reel ready to play',
        ?int $totalClips = null,
        int $ttl = 300
    ): void {
        Cache::put(self::progressKey($cacheKey), [
            'percent' => 100,
            'title' => $title,
            'subtitle' => $subtitle,
            'current_clip' => $totalClips,
            'total_clips' => $totalClips,
            'step' => "{$title} ({$subtitle})",
            'ready' => true,
            'cached' => true,
        ], $ttl);
    }

    /**
     * Streams video clips directly from object storage to disk, compiles them with FFmpeg,
     * and persists the resulting MP4 compilation onto object storage (S3/Garage).
     *
     * @param iterable<int, History> $histories
     * @param string $cacheKey
     * @param string|null $outputFilePath Optional explicit output path on local disk.
     * @param int|null $timeoutSeconds Optional dynamic timeout ceiling in seconds.
     * @return void
     * @throws RuntimeException If no readable clips exist or FFmpeg compilation fails.
     */
    public static function buildCompilation(
        iterable $histories,
        string $cacheKey,
        ?string $outputFilePath = null,
        ?int $timeoutSeconds = null
    ): void {
        $timeout = $timeoutSeconds ?? self::calculateTimeoutSeconds($histories);

        self::allowLongRunningProcess();

        $compilationStartTime = microtime(true);
        $tempDir = self::makeTempDirectory(self::TEMP_DIRECTORY_PREFIX, self::DIRECTORY_PERMISSIONS);
        $tempOutputFilePath = $tempDir . '/' . self::OUTPUT_MP4_FILENAME;

        Log::info('VideoReelGenerator::buildCompilation started', [
            'cacheKey' => $cacheKey,
            'tempDir' => $tempDir,
            'finalOutputFilePath' => $outputFilePath,
            'timeout' => $timeout,
        ]);

        $totalCount = is_countable($histories) ? count($histories) : count(iterator_to_array($histories));

        try {
            Cache::put(self::progressKey($cacheKey), [
                'percent' => 5,
                'title' => 'Initializing Assembly',
                'subtitle' => 'Preparing lossless stream pipeline',
                'current_clip' => null,
                'total_clips' => $totalCount,
                'step' => sprintf('Initializing lossless stream assembly (%d inputs)...', $totalCount),
                'ready' => false,
            ], $timeout);

            [$concatFilePath, $clipTimings] = self::downloadNormalizeAndBuildManifest($tempDir, $histories, $cacheKey, $timeout);

            Cache::put(self::progressKey($cacheKey), [
                'percent' => 85,
                'title' => 'Concatenating Streams',
                'subtitle' => 'FFmpeg demuxer with monotonic retiming',
                'current_clip' => $totalCount,
                'total_clips' => $totalCount,
                'step' => sprintf('Concatenating %d streams via FFmpeg demuxer (monotonic retiming)...', $totalCount),
                'ready' => false,
            ], $timeout);

            $stitchStartTime = microtime(true);
            VideoRemuxer::concatTsFilesToMp4($concatFilePath, $tempOutputFilePath, $timeout);
            $stitchDuration = microtime(true) - $stitchStartTime;

            Cache::put(self::progressKey($cacheKey), [
                'percent' => 92,
                'title' => 'Uploading to Storage',
                'subtitle' => 'Streaming faststart MP4 to S3/Garage storage',
                'current_clip' => $totalCount,
                'total_clips' => $totalCount,
                'step' => 'Muxing MP4 faststart container & streaming to S3/Garage storage...',
                'ready' => false,
            ], $timeout);

            // Upload the compiled MP4 file to object storage (S3/Garage)
            $uploadStartTime = microtime(true);
            $disk = Storage::disk(MediaPage::DISK);
            $s3Key = self::storageKey($cacheKey);
            $stream = fopen($tempOutputFilePath, 'rb');

            if ($stream !== false) {
                $uploaded = $disk->put($s3Key, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                Log::info('VideoReelGenerator::buildCompilation uploaded to S3', ['s3Key' => $s3Key, 'result' => $uploaded]);
            } else {
                Log::error('VideoReelGenerator::buildCompilation failed to open output file for upload', ['path' => $tempOutputFilePath]);
            }
            $uploadDuration = microtime(true) - $uploadStartTime;

            if ($outputFilePath !== null && $outputFilePath !== $tempOutputFilePath) {
                @copy($tempOutputFilePath, $outputFilePath);
            }

            Cache::put(self::progressKey($cacheKey), [
                'percent' => 98,
                'title' => 'Finalizing Index',
                'subtitle' => 'Caching stream manifest & moov atom index',
                'current_clip' => $totalCount,
                'total_clips' => $totalCount,
                'step' => 'Finalizing moov atom index & caching stream manifest...',
                'ready' => false,
            ], $timeout);

            $totalDuration = microtime(true) - $compilationStartTime;
            $totalClips = count($clipTimings);
            $avgClipDuration = $totalClips > 0 ? array_sum($clipTimings) / $totalClips : 0.0;
            $minClipDuration = $totalClips > 0 ? min($clipTimings) : 0.0;
            $maxClipDuration = $totalClips > 0 ? max($clipTimings) : 0.0;
            $formattedTotal = History::formatHumanDuration($totalDuration);

            $stats = [
                'total_clips' => $totalClips,
                'total_seconds' => round($totalDuration, 2),
                'formatted_total' => $formattedTotal,
                'avg_seconds_per_clip' => round($avgClipDuration, 2),
                'min_seconds_per_clip' => round($minClipDuration, 2),
                'max_seconds_per_clip' => round($maxClipDuration, 2),
                'stitch_seconds' => round($stitchDuration, 2),
                'upload_seconds' => round($uploadDuration, 2),
            ];

            Cache::put(self::progressKey($cacheKey), [
                'percent' => 100,
                'title' => 'Ready',
                'subtitle' => 'H.264 video reel compiled successfully',
                'current_clip' => $totalClips,
                'total_clips' => $totalClips,
                'step' => 'H.264 video reel compiled successfully',
                'ready' => true,
                'cached' => false,
                'stats' => $stats,
            ], $timeout);

            Log::info('VideoReelGenerator::buildCompilation completed timing benchmark', [
                'cacheKey' => $cacheKey,
                'outputFilePath' => $outputFilePath ?? $tempOutputFilePath,
                'size' => file_exists($outputFilePath ?? $tempOutputFilePath) ? filesize($outputFilePath ?? $tempOutputFilePath) : 0,
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            Log::error('VideoReelGenerator::buildCompilation failed with exception', [
                'cacheKey' => $cacheKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Cache::put(self::progressKey($cacheKey), [
                'percent' => 0,
                'title' => 'Compilation Failed',
                'subtitle' => $e->getMessage(),
                'current_clip' => null,
                'total_clips' => $totalCount,
                'step' => 'Compilation failed: ' . $e->getMessage(),
                'ready' => false,
                'error' => true,
                'errorMessage' => $e->getMessage(),
            ], $timeout);

            throw $e;
        } finally {
            self::cleanupTempDirectory($tempDir);
        }
    }

    /**
     * Downloads stored MP4 captures, prepares intermediate transport streams with Annex B parameter sets
     * using non-fatal stream mapping (-map 0:v? -map 0:a?), and builds the concat manifest in a single pass.
     *
     * @param iterable<int, History> $histories
     * @param string|null $cacheKey
     * @param int|null $progressTtl
     * @return array{0: string, 1: array<int, float>} Tuple of [manifestPath, clipDurations]
     */
    private static function downloadNormalizeAndBuildManifest(
        string $tempDir,
        iterable $histories,
        ?string $cacheKey = null,
        ?int $progressTtl = null
    ): array {
        $disk = Storage::disk(MediaPage::DISK);
        $histories = is_countable($histories) ? $histories : iterator_to_array($histories);
        $total = count($histories);
        $clipIndex = 0;
        $clipTimings = [];
        $manifest = '';
        $ttl = $progressTtl ?? self::calculateTimeoutSeconds($histories);

        foreach ($histories as $history) {
            /** @var MediaFile|null $videoMedia */
            $videoMedia = $history->media->first(fn (MediaFile $media): bool => $media->isVideo() && $media->module_type === 'CLOUD_STORAGE')
                ?? $history->media->first(fn (MediaFile $media): bool => $media->isVideo());

            if (! $videoMedia) {
                continue;
            }

            // Prefer the pre-existing remuxed MP4 when it exists. Otherwise use stored video or TS fallback.
            $sourceKey = null;
            foreach (array_unique([
                VideoRemuxer::mp4Key($videoMedia->object_key),
                $videoMedia->object_key,
                VideoRemuxer::tsKey($videoMedia->object_key),
            ]) as $key) {
                if ($disk->exists($key)) {
                    $sourceKey = $key;
                    break;
                }
            }

            if ($sourceKey === null) {
                throw new RuntimeException("Video source not found for history {$history->id}.");
            }

            if ($cacheKey !== null && $total > 0) {
                $percent = (int) min(80, max(5, 5 + round(($clipIndex / $total) * 75)));
                Cache::put(self::progressKey($cacheKey), [
                    'percent' => $percent,
                    'title' => 'Remuxing Clips',
                    'subtitle' => 'Lossless stream copy',
                    'current_clip' => $clipIndex + 1,
                    'total_clips' => $total,
                    'step' => sprintf('Remuxing clip %d/%d (lossless stream copy)...', $clipIndex + 1, $total),
                    'ready' => false,
                ], $ttl);
            }

            $extension = strtolower(pathinfo($sourceKey, PATHINFO_EXTENSION));
            $downloadPath = sprintf('%s/raw_%05d.%s', $tempDir, $clipIndex, $extension ?: 'mp4');
            $tsFilename = sprintf(self::CLIP_TS_FILENAME_PATTERN, $clipIndex);
            $tsPath = sprintf('%s/%s', $tempDir, $tsFilename);

            $clipStartTime = microtime(true);
            if (! self::streamFileFromStorage($disk, $sourceKey, $downloadPath)) {
                throw new RuntimeException("Failed to download video for history {$history->id}.");
            }

            // Stream-copy clip directly into intermediate TS with Annex B headers (no probing needed)
            VideoRemuxer::prepareIntermediateTsClip($downloadPath, $tsPath);

            @unlink($downloadPath);

            $clipDuration = microtime(true) - $clipStartTime;
            $clipTimings[] = $clipDuration;

            Log::info(sprintf(
                'VideoReelGenerator: Prepared clip %d/%d (history #%d) in %.3fs',
                $clipIndex + 1,
                $total,
                $history->id,
                $clipDuration,
            ));

            $manifest .= "file '{$tsFilename}'\n";
            $clipIndex++;
        }

        if ($clipIndex === 0) {
            throw new RuntimeException('No readable video captures found in storage to compile.');
        }

        $manifestPath = $tempDir . '/' . self::CONCAT_FILENAME;
        file_put_contents($manifestPath, $manifest);

        return [$manifestPath, $clipTimings];
    }
}
