<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ActivitiesPage;
use App\Filament\Pages\MediaPage;
use App\Models\History;
use App\Models\MediaFile;
use App\Models\Pet;
use App\Petkit\Storage\TimelapseGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Generates, caches, and streams an animated GIF timelapse of camera captures matching the current filters.
 */
class ActivitiesTimelapseController extends Controller
{
    public const CONTENT_TYPE_GIF = 'image/gif';
    public const CACHE_CONTROL_HEADER = 'no-cache, private';

    public function __invoke(Request $request): StreamedResponse|Response
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
        $fps = (float) $request->query('fps', TimelapseGenerator::DEFAULT_FPS);

        $fps = max(TimelapseGenerator::MIN_FPS, min(TimelapseGenerator::MAX_FPS, $fps));

        $query = TimelapseGenerator::imageCapturesQuery()
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

        $histories = $query->get()->reverse()->values();

        if ($histories->isEmpty()) {
            abort(Response::HTTP_NOT_FOUND, 'No screenshot images found matching the current filters.');
        }

        $cacheKey = TimelapseGenerator::cacheKey($histories, $fps);
        $s3Key = TimelapseGenerator::storageKey($cacheKey);
        $disk = Storage::disk(MediaPage::DISK);

        if (! TimelapseGenerator::isCachedAndValid($cacheKey)) {
            $imageBytesList = [];

            foreach ($histories as $history) {
                /** @var MediaFile|null $imageMedia */
                $imageMedia = $history->media->first(fn (MediaFile $clip): bool => ! $clip->isVideo());

                if ($imageMedia && $disk->exists($imageMedia->object_key)) {
                    $imageBytesList[] = $disk->get($imageMedia->object_key);
                }
            }

            if (empty($imageBytesList)) {
                abort(Response::HTTP_NOT_FOUND, 'No screenshot images found matching the current filters.');
            }

            try {
                $gif = TimelapseGenerator::createGifFromImages($imageBytesList, $fps);
                TimelapseGenerator::storeGif($gif, $cacheKey);
            } catch (Throwable $e) {
                abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Failed to generate timelapse GIF: ' . $e->getMessage());
            }
        }

        return $disk->response($s3Key, TimelapseGenerator::OUTPUT_GIF_ATTACHMENT_NAME, [
            'Content-Type' => self::CONTENT_TYPE_GIF,
            'Cache-Control' => self::CACHE_CONTROL_HEADER,
        ], 'attachment');
    }
}
