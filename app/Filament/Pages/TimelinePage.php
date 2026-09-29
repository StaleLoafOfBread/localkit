<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasActivityFilters;
use App\Filament\Resources\DeviceResource;
use App\Filament\Resources\DeviceResource\Pages\PetkitActivities;
use App\Models\History;
use Filament\Pages\Page;

/**
 * Dedicated "Timeline Scrubber" page that renders a security camera-style
 * horizontal timeline scrubber for all device and pet activity events.
 */
class TimelinePage extends Page
{
    use HasActivityFilters;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-film';
    protected string $view = 'filament.pages.timeline-page';
    protected static ?string $slug = 'timeline';
    protected static string | \UnitEnum | null $navigationGroup = 'Activities';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Timeline Scrubber';
    protected static ?string $title = 'Timeline Scrubber';

    /**
     * Formats all matching activity history records into a chronologically ordered array for the frontend scrubber.
     *
     * @return array<int, array{
     *     id: int,
     *     timestamp: int,
     *     timestamp_ms: int,
     *     iso: string,
     *     datetime: string,
     *     date: string,
     *     time: string,
     *     diff: string,
     *     type: ?string,
     *     type_title: string,
     *     icon: string,
     *     color: string,
     *     title: string,
     *     message: string,
     *     pet_name: ?string,
     *     pet_id: int|string|null,
     *     device_name: ?string,
     *     device_id: int|string|null,
     *     duration: ?string,
     *     duration_seconds: int,
     *     detail_url: ?string,
     *     parameters: ?array<string, mixed>,
     *     has_media: bool,
     *     media_type: 'video'|'image'|null,
     *     image_url: ?string,
     *     video_url: ?string,
     *     thumbnail_url: ?string,
     * }>
     */
    public function getTimelineEvents(): array
    {
        $query = History::query()
            ->with(['pet', 'device', 'media'])
            ->latest();

        $this->applyFilters($query);

        if ($this->maxResults > 0) {
            $query->limit($this->maxResults);
        }

        // Reverse so that the sequence is strictly chronological (oldest to newest, left-to-right)
        $histories = $query->get()->reverse()->values();
        $timezone = (string) config('app.timezone');

        $events = $histories->map(function (History $history) use ($timezone): array {
            $meta = PetkitActivities::typeMeta($history->type);
            $listingMedia = PetkitActivities::mediaForListing($history->media);
            $eventDate = $history->created_at?->timezone($timezone);

            $imageUrl = $listingMedia['image']
                ? route('media.file', ['fileId' => $listingMedia['image']->file_id])
                : null;

            $videoUrl = $listingMedia['video']
                ? route('media.file', ['fileId' => $listingMedia['video']->file_id])
                : null;

            $mediaType = match (true) {
                $videoUrl !== null => 'video',
                $imageUrl !== null => 'image',
                default => null,
            };

            $petName = $history->isPetActivity()
                ? ($history->pet?->name ?? __('petkit.unknown'))
                : null;

            $deviceName = $history->device
                ? ($history->device->name ?? $history->device->serial_number)
                : null;

            $detailUrl = ($history->device_id && config('app.debug'))
                ? DeviceResource::getUrl('activity', ['record' => $history->device_id, 'historyId' => $history->id])
                : null;

            $eventDuration = $history->eventDuration();

            return [
                'id' => (int) $history->id,
                'timestamp' => (int) ($eventDate?->timestamp ?? 0),
                'timestamp_ms' => (int) (($eventDate?->timestamp ?? 0) * 1000),
                'iso' => (string) ($eventDate?->toIso8601String() ?? ''),
                'datetime' => (string) ($eventDate?->isoFormat(History::DATETIME_FORMAT) ?? ''),
                'date' => (string) ($eventDate?->isoFormat(History::DATE_FORMAT) ?? ''),
                'time' => (string) ($eventDate?->isoFormat(History::TIME_WITH_SECONDS_FORMAT) ?? ''),
                'diff' => (string) ($eventDate?->diffForHumans() ?? ''),
                'type' => $history->type,
                'type_title' => $history->title(),
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'title' => $history->title(),
                'message' => $history->message(),
                'pet_name' => $petName,
                'pet_id' => $history->pet_id,
                'device_name' => $deviceName,
                'device_id' => $history->device_id,
                'duration' => $eventDuration > 0 ? $history->eventHumanDuration() : null,
                'duration_seconds' => $eventDuration,
                'detail_url' => $detailUrl,
                'parameters' => is_array($history->parameters) ? $history->parameters : null,
                'has_media' => $mediaType !== null,
                'media_type' => $mediaType,
                'image_url' => $imageUrl,
                'video_url' => $videoUrl,
                'thumbnail_url' => $imageUrl,
            ];
        });

        return $events->all();
    }

    /**
     * Builds the target URL to navigate to the Activities page preserving all active filters.
     */
    public function getActivitiesPageUrl(): string
    {
        return ActivitiesPage::getUrl($this->getActivityFilterQueryParams());
    }
}
