<?php

namespace App\Petkit\Storage;

use App\Filament\Pages\ActivitiesPage;
use App\Filament\Pages\MediaPage;
use App\Models\History;
use App\Petkit\Storage\Concerns\InteractsWithMediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Compiles a sequence of still capture images into an animated GIF or video timelapse,
 * and formats history records into timelapse frame payloads.
 */
class TimelapseGenerator
{
    use InteractsWithMediaStorage;

    public const DEFAULT_FPS = 1;
    public const MIN_FPS = 0.25;
    public const MAX_FPS = 10.0;
    public const MAX_IMAGES_LIMIT = 150;
    public const DEFAULT_MAX_WIDTH = 640;
    public const PROCESS_TIMEOUT_SECONDS = 120;
    public const TEMP_DIRECTORY_PREFIX = 'petkit-timelapse-';
    public const CACHE_PREFIX = 'timelapse';
    public const STORAGE_PREFIX = 'compilations/timelapses';
    public const SECONDS_PER_DAY = 86400;
    public const CONCAT_FILENAME = 'frames.txt';
    public const OUTPUT_GIF_FILENAME = 'output.gif';
    public const OUTPUT_GIF_ATTACHMENT_NAME = 'activities-timelapse.gif';
    public const FRAME_FILENAME_PATTERN = 'frame_%05d.jpg';
    public const DIRECTORY_PERMISSIONS = 0755;
    public const MIN_SAFE_FRAME_INTERVAL = 0.1;

    /**
     * Builds the base Eloquent query for History records that have still screenshot captures.
     *
     * @return Builder<History>
     */
    public static function imageCapturesQuery(): Builder
    {
        return History::query()
            ->whereHas('media', function (Builder $mediaQuery): void {
                $mediaQuery->where('file_id', 'not like', '%.ts')
                    ->where(function (Builder $typeQuery): void {
                        $typeQuery->whereNull('file_type')
                            ->orWhere('file_type', 'not like', '%video%');
                    });
            });
    }

    /**
     * Formats a collection of History records into frame metadata arrays for the timelapse player.
     *
     * @param iterable<int, History> $histories
     * @return array<int, array{history_id: int, url: string, title: string, message: string, pet: string, device: string, time: string, diff: string, duration: string|null, detail_url: string|null, parameters: array<string, mixed>|null}>
     */
    public static function formatFrames(iterable $histories): array
    {
        return self::formatHistoryCaptures($histories, false);
    }

    /**
     * Constructs the URL for downloading the compiled GIF timelapse with current filter parameters.
     *
     * @param array<int|string> $deviceIds
     * @param array<int|string> $petIds
     * @param array<string> $types
     * @param float|int $fps
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
        float|int $fps = self::DEFAULT_FPS,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $maxResults = null,
        ?string $timeFrom = null,
        ?string $timeTo = null,
    ): string {
        return route('activities.timelapse', array_filter([
            ActivitiesPage::QUERY_PARAM_DEVICES => $deviceIds,
            ActivitiesPage::QUERY_PARAM_PETS => $petIds,
            ActivitiesPage::QUERY_PARAM_TYPES => $types,
            ActivitiesPage::QUERY_PARAM_DATE_FROM => $dateFrom,
            ActivitiesPage::QUERY_PARAM_DATE_TO => $dateTo,
            ActivitiesPage::QUERY_PARAM_MAX_RESULTS => $maxResults,
            ActivitiesPage::QUERY_PARAM_TIME_FROM => $timeFrom,
            ActivitiesPage::QUERY_PARAM_TIME_TO => $timeTo,
            'fps' => $fps,
        ], fn ($val): bool => $val !== null && $val !== [] && $val !== ''));
    }

    /**
     * Computes the deterministic cache key for a collection of timelapse History records and FPS value.
     *
     * @param Collection<int, History> $histories
     * @param float|int $fps
     */
    public static function cacheKey(Collection $histories, float|int $fps = self::DEFAULT_FPS): string
    {
        return CompilationCache::key(self::CACHE_PREFIX, $histories, ['fps' => (float) $fps]);
    }

    /**
     * Returns the relative object storage key where a cached compiled timelapse GIF should be stored.
     */
    public static function storageKey(string $cacheKey): string
    {
        return self::STORAGE_PREFIX . '/' . $cacheKey . '.gif';
    }

    /**
     * Determines whether the compiled timelapse GIF exists in object storage and is within the configured TTL.
     */
    public static function isCachedAndValid(string $cacheKey): bool
    {
        return self::isMediaCachedAndValid(self::storageKey($cacheKey));
    }

    /**
     * Persists the compiled GIF binary onto object storage (S3/Garage).
     */
    public static function storeGif(string $gifBinary, string $cacheKey): void
    {
        $disk = Storage::disk(MediaPage::DISK);
        $disk->put(self::storageKey($cacheKey), $gifBinary);
    }

    /**
     * Compiles an array of raw image binary strings into an animated GIF using ffmpeg.
     *
     * @param array<int, string> $imageBytesList Chronologically ordered list of image file contents.
     * @param float|int $fps Frames per second for the animation.
     * @param int $maxWidth Maximum width of the output GIF.
     * @return string The raw binary bytes of the compiled GIF file.
     * @throws RuntimeException If image sequence is empty or ffmpeg compilation fails.
     */
    public static function createGifFromImages(
        array $imageBytesList,
        float|int $fps = self::DEFAULT_FPS,
        int $maxWidth = self::DEFAULT_MAX_WIDTH,
    ): string {
        if (empty($imageBytesList)) {
            throw new RuntimeException('Cannot generate GIF from an empty list of images.');
        }

        $tempDir = self::makeTempDirectory(self::TEMP_DIRECTORY_PREFIX, self::DIRECTORY_PERMISSIONS);

        try {
            $concatFilePath = self::writeFramesAndManifest($tempDir, $imageBytesList, $fps);

            return self::executeFfmpegGifProcess($tempDir, $concatFilePath, $maxWidth);
        } finally {
            self::cleanupTempDirectory($tempDir);
        }
    }

    /**
     * Writes individual image frames and generates the ffmpeg concat demuxer manifest file.
     *
     * @param array<int, string> $imageBytesList
     */
    private static function writeFramesAndManifest(string $tempDir, array $imageBytesList, float|int $fps): string
    {
        $frameIndex = 0;
        $concatList = '';
        $duration = 1.0 / max(self::MIN_SAFE_FRAME_INTERVAL, (float) $fps);

        foreach ($imageBytesList as $imageBytes) {
            $frameFilename = sprintf(self::FRAME_FILENAME_PATTERN, $frameIndex);
            $filePath = $tempDir . '/' . $frameFilename;
            file_put_contents($filePath, $imageBytes);

            $concatList .= "file '{$frameFilename}'\nduration {$duration}\n";
            $frameIndex++;
        }

        // Append the last frame once more to prevent the ffmpeg concat demuxer from truncating the final frame
        if ($frameIndex > 0) {
            $lastFilename = sprintf(self::FRAME_FILENAME_PATTERN, $frameIndex - 1);
            $concatList .= "file '{$lastFilename}'\n";
        }

        $concatFilePath = $tempDir . '/' . self::CONCAT_FILENAME;
        file_put_contents($concatFilePath, $concatList);

        return $concatFilePath;
    }

    /**
     * Executes the ffmpeg process with two-pass palette optimization and returns the output GIF bytes.
     */
    private static function executeFfmpegGifProcess(string $tempDir, string $concatFilePath, int $maxWidth): string
    {
        $outputGifPath = $tempDir . '/' . self::OUTPUT_GIF_FILENAME;

        // High quality two-pass palette generation for sharp colors and clean dithering
        $filterComplex = sprintf(
            '[0:v] scale=%d:-1:flags=lanczos,split [a][b]; [a] palettegen=stats_mode=diff [p]; [b][p] paletteuse=dither=bayer:bayer_scale=3',
            $maxWidth,
        );

        $process = new Process([
            env('FFMPEG_BINARY', 'ffmpeg'),
            '-hide_banner',
            '-loglevel', 'error',
            '-f', 'concat',
            '-safe', '0',
            '-i', $concatFilePath,
            '-filter_complex', $filterComplex,
            '-y',
            $outputGifPath,
        ]);

        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($outputGifPath) || filesize($outputGifPath) === 0) {
            throw new RuntimeException('ffmpeg GIF generation failed: ' . $process->getErrorOutput());
        }

        return (string) file_get_contents($outputGifPath);
    }
}
