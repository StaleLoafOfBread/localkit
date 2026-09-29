<?php

namespace App\Petkit\Storage\Concerns;

use App\Filament\Pages\MediaPage;
use App\Filament\Resources\DeviceResource;
use App\Models\History;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provides common helper methods for media compilation generators (timelapses, video reels)
 * including temp directory management, low-memory storage streaming, cache TTL validation,
 * and History capture payload formatting.
 */
trait InteractsWithMediaStorage
{
    /**
     * Formats a collection of History records into client payload metadata arrays.
     *
     * @param iterable<int, History> $histories
     * @param bool $isVideo True for video clips, false for still images.
     * @return array<int, array{history_id: int, url: string, title: string, message: string, pet: string, device: string, time: string, diff: string, duration: string|null, detail_url: string|null, parameters: array<string, mixed>|null}>
     */
    public static function formatHistoryCaptures(iterable $histories, bool $isVideo): array
    {
        $timezone = (string) config('app.timezone');
        $items = [];

        foreach ($histories as $history) {
            /** @var MediaFile|null $media */
            $media = $isVideo
                ? ($history->media->first(fn (MediaFile $clip): bool => $clip->isVideo() && $clip->module_type === 'CLOUD_STORAGE')
                    ?? $history->media->first(fn (MediaFile $clip): bool => $clip->isVideo()))
                : $history->media->first(fn (MediaFile $clip): bool => ! $clip->isVideo());

            if (! $media) {
                continue;
            }

            $detailUrl = $history->device_id
                ? DeviceResource::getUrl('activity', ['record' => $history->device_id, 'historyId' => $history->id])
                : null;

            $duration = ($isVideo && $media->duration > 0)
                ? number_format($media->duration / 1000, 2, '.', '') . 's'
                : ($history->eventDuration() > 0 ? $history->eventDuration() . 's' : null);

            $items[] = [
                'history_id' => $history->id,
                'url' => route('media.file', ['fileId' => $media->file_id]),
                'title' => $history->title(),
                'message' => strip_tags($history->message()),
                'pet' => $history->pet?->name ?? __('Unknown'),
                'device' => $history->device?->name ?? $history->device?->serial_number ?? __('Unknown device'),
                'time' => $history->created_at?->timezone($timezone)?->isoFormat(History::DATETIME_WITH_SECONDS_FORMAT) ?? '',
                'diff' => $history->created_at?->timezone($timezone)?->diffForHumans() ?? '',
                'duration' => $duration,
                'detail_url' => $detailUrl,
                'parameters' => $history->parameters,
            ];
        }

        return $items;
    }

    /**
     * Determines whether a compiled media object exists in object storage and is within the configured TTL.
     */
    public static function isMediaCachedAndValid(string $storageKey, ?int $retentionDays = null): bool
    {
        $disk = Storage::disk(MediaPage::DISK);

        if (! $disk->exists($storageKey)) {
            return false;
        }

        $configuredRetentionDays = $retentionDays ?? (int) config('localkit.retention.compilation_days');
        if ($configuredRetentionDays <= 0) {
            return true;
        }

        try {
            $lastModified = $disk->lastModified($storageKey);
            $isExpired = (now()->timestamp - $lastModified) > ($configuredRetentionDays * 86400);

            if ($isExpired) {
                $disk->delete($storageKey);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('Failed reading lastModified for media compilation', [
                'key' => $storageKey,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Creates and returns a unique temporary directory path.
     */
    protected static function makeTempDirectory(string $prefix, int $permissions = 0755): string
    {
        $tempDir = sys_get_temp_dir() . '/' . $prefix . Str::random(16);
        @mkdir($tempDir, $permissions, true);

        return $tempDir;
    }

    /**
     * Streams a file from object storage to local disk using low-memory stream buffers.
     */
    protected static function streamFileFromStorage(mixed $disk, string $storageKey, string $localPath): bool
    {
        try {
            $readStream = $disk->readStream($storageKey);
            if ($readStream) {
                $writeStream = fopen($localPath, 'wb');
                if ($writeStream) {
                    stream_copy_to_stream($readStream, $writeStream);
                    fclose($writeStream);
                }
                fclose($readStream);

                return true;
            }

            $bytes = $disk->get($storageKey);
            if ($bytes !== null) {
                file_put_contents($localPath, $bytes);

                return true;
            }
        } catch (Throwable $e) {
            Log::warning('Failed streaming file from storage', [
                'key' => $storageKey,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Recursively cleans up temporary files and the parent working directory.
     */
    protected static function cleanupTempDirectory(string $tempDir): void
    {
        $files = glob($tempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($tempDir);
    }
}
