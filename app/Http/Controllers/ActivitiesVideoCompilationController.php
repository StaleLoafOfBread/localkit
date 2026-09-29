<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ActivitiesPage;
use App\Models\History;
use App\Models\Pet;
use App\Petkit\Storage\VideoReelGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Generates, caches, and streams a single unified MP4 video compilation of camera captures.
 */
class ActivitiesVideoCompilationController extends Controller
{
    public const CONTENT_TYPE_MP4 = 'video/mp4';
    public const CACHE_CONTROL_HEADER = 'no-cache, private';
    private const CACHE_KEY_MAP_PREFIX = 'reel_key_map_';
    private const CACHE_KEY_MAP_TTL_SECONDS = 3600;
    private const PROGRESS_PREFIX = 'reel_progress_';
    private const SSE_MAX_SECONDS = 1800;
    private const SSE_POLL_MICROSECONDS = 500000;

    public function __invoke(Request $request): BinaryFileResponse|Response
    {
        Log::info('ActivitiesVideoCompilationController::__invoke requested', [
            'query' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $histories = $this->resolveHistories($request);

        self::allowLongRunningRequest();

        Log::info('ActivitiesVideoCompilationController resolved histories', [
            'count' => $histories->count(),
            'history_ids' => $histories->pluck('id')->all(),
        ]);

        if ($histories->isEmpty()) {
            Log::warning('ActivitiesVideoCompilationController no video captures found matching filters');
            abort(Response::HTTP_NOT_FOUND, 'No video captures found matching the current filters.');
        }

        $cacheKey = VideoReelGenerator::cacheKey($histories);

        $filterHash = self::filterHash($request);
        self::rememberCacheKey($filterHash, $cacheKey);

        try {
            $localPath = VideoReelGenerator::ensureLocalFile($cacheKey, $histories);
        } catch (Throwable $e) {
            Log::error('Video reel compilation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
                'errorMessage' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $headers = self::videoResponseHeaders();

        if ($request->boolean('download', false)) {
            return response()->download(
                $localPath,
                VideoReelGenerator::OUTPUT_MP4_ATTACHMENT_NAME,
                $headers
            );
        }

        return response()->file($localPath, $headers);
    }

    /**
     * Returns the live compilation progress percentage and status for the requested video reel,
     * supporting Server-Sent Events (SSE) streaming or fast cached JSON polling.
     */
    public function progress(Request $request): JsonResponse|StreamedResponse
    {
        $filterHash = self::filterHash($request);
        $cacheKey = $request->query('key') ?: Cache::get(self::CACHE_KEY_MAP_PREFIX . $filterHash);

        if (! $cacheKey) {
            $histories = $this->resolveHistories($request);

            if ($histories->isEmpty()) {
                return response()->json([
                    'percent' => 0,
                    'title' => 'No Captures Found',
                    'subtitle' => 'No video captures matching current filters',
                    'current_clip' => 0,
                    'total_clips' => 0,
                    'step' => 'No video captures found',
                    'ready' => false,
                ]);
            }

            $cacheKey = VideoReelGenerator::cacheKey($histories);
            self::rememberCacheKey($filterHash, $cacheKey);
        }

        // Handle Server-Sent Events (SSE) streaming if requested
        if ($request->boolean('stream') || str_contains($request->header('Accept', ''), 'text/event-stream')) {
            return response()->stream(function () use ($cacheKey): void {
                $lastPercent = -1;
                $lastStep = null;
                $startTime = time();

                while (! connection_aborted() && (time() - $startTime) < self::SSE_MAX_SECONDS) {
                    $data = Cache::get(self::PROGRESS_PREFIX . $cacheKey);

                    if ($data !== null && is_array($data)) {
                        $percent = $data['percent'] ?? 0;
                        $step = $data['step'] ?? '';

                        if ($percent !== $lastPercent || $step !== $lastStep) {
                            self::emitSse($data);
                            $lastPercent = $percent;
                            $lastStep = $step;

                            if (! empty($data['ready']) || ! empty($data['errorMessage'])) {
                                break;
                            }
                        }
                    } elseif (VideoReelGenerator::isCachedAndValid($cacheKey)) {
                        self::emitSse(self::cachedReadyPayload());
                        break;
                    }

                    usleep(self::SSE_POLL_MICROSECONDS);
                }
            }, Response::HTTP_OK, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $progressData = Cache::get(self::PROGRESS_PREFIX . $cacheKey);
        if ($progressData !== null && is_array($progressData)) {
            return response()->json($progressData);
        }

        if (VideoReelGenerator::isCachedAndValid($cacheKey)) {
            return response()->json(self::cachedReadyPayload());
        }

        return response()->json([
            'percent' => 0,
            'title' => 'Initializing Assembly',
            'subtitle' => 'Spawning FFmpeg stream assembly worker...',
            'current_clip' => null,
            'total_clips' => null,
            'step' => 'Spawning FFmpeg stream assembly worker...',
            'ready' => false,
        ]);
    }

    private static function allowLongRunningRequest(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    private static function rememberCacheKey(string $filterHash, string $cacheKey): void
    {
        Cache::put(self::CACHE_KEY_MAP_PREFIX . $filterHash, $cacheKey, self::CACHE_KEY_MAP_TTL_SECONDS);
    }

    /** @return array{percent: int, title: string, subtitle: string, current_clip: int|null, total_clips: int|null, step: string, ready: bool, cached: bool} */
    private static function cachedReadyPayload(?int $totalClips = null): array
    {
        return [
            'percent' => 100,
            'title' => 'Ready',
            'subtitle' => 'Loaded from cache (S3 object hit)',
            'current_clip' => $totalClips,
            'total_clips' => $totalClips,
            'step' => 'Cached H.264 assembly loaded (S3 object hit)',
            'ready' => true,
            'cached' => true,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function emitSse(array $data): void
    {
        echo 'data: ' . json_encode($data) . "\n\n";
        @ob_flush();
        @flush();
    }

    /**
     * Computes a deterministic hash from the filter query parameters to bypass database re-queries.
     */
    private static function filterHash(Request $request): string
    {
        $filtered = array_filter(
            $request->except(['stream', 'key', 'download']),
            fn ($val): bool => $val !== null && $val !== '' && $val !== []
        );
        ksort($filtered);

        return md5(json_encode($filtered));
    }

    /**
     * Returns the shared HTTP headers for video download and inline file responses.
     *
     * @return array<string, string>
     */
    private static function videoResponseHeaders(): array
    {
        return [
            'Content-Type' => self::CONTENT_TYPE_MP4,
            'Cache-Control' => self::CACHE_CONTROL_HEADER,
        ];
    }

    /**
     * Resolves matching History records based on request filter parameters.
     *
     * @return Collection<int, History>
     */
    private function resolveHistories(Request $request): Collection
    {
        $deviceIds = (array) $request->query(ActivitiesPage::QUERY_PARAM_DEVICES, []);
        $petIds = (array) $request->query(ActivitiesPage::QUERY_PARAM_PETS, []);
        $types = (array) $request->query(ActivitiesPage::QUERY_PARAM_TYPES, []);
        $dateFrom = $request->query(ActivitiesPage::QUERY_PARAM_DATE_FROM);
        $dateTo = $request->query(ActivitiesPage::QUERY_PARAM_DATE_TO);
        $timeFrom = $request->query(ActivitiesPage::QUERY_PARAM_TIME_FROM);
        $timeTo = $request->query(ActivitiesPage::QUERY_PARAM_TIME_TO);
        $maxResults = $request->filled(ActivitiesPage::QUERY_PARAM_MAX_RESULTS)
            ? (int) $request->query(ActivitiesPage::QUERY_PARAM_MAX_RESULTS)
            : null;

        $query = VideoReelGenerator::videoCapturesQuery()
            ->with('media')
            ->latest();

        if (! empty($deviceIds)) {
            $query->whereIn('device_id', $deviceIds);
        }

        if (! empty($petIds)) {
            $hasUnknown = in_array(ActivitiesPage::UNKNOWN_PET, $petIds, true);
            $validPetIds = array_values(array_filter($petIds, fn (string|int $id): bool => (string) $id !== ActivitiesPage::UNKNOWN_PET));
            $existingPetIds = Pet::pluck('id')->toArray();

            $query->where(function ($subQuery) use ($hasUnknown, $validPetIds, $existingPetIds): void {
                if ($hasUnknown) {
                    $subQuery->whereNull('pet_id')
                        ->orWhere('pet_id', 0)
                        ->orWhereNotIn('pet_id', $existingPetIds);
                }

                if (! empty($validPetIds)) {
                    if ($hasUnknown) {
                        $subQuery->orWhereIn('pet_id', $validPetIds);
                    } else {
                        $subQuery->whereIn('pet_id', $validPetIds);
                    }
                }
            });
        }

        if (! empty($types)) {
            $query->whereIn('type', $types);
        }

        ActivitiesPage::applyDateFilter($query, $dateFrom, $dateTo);
        ActivitiesPage::applyTimeOfDayFilter($query, $timeFrom, $timeTo);

        if ($maxResults !== null && $maxResults > 0) {
            $query->limit($maxResults);
        }

        return $query->get()->reverse()->values();
    }
}
