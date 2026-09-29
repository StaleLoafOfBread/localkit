<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasActivityFilters;
use App\Models\History;
use App\Petkit\Storage\TimelapseGenerator;
use App\Petkit\Storage\VideoReelGenerator;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Dedicated "Activities" page that renders a chronological timeline of all
 * logged events across every device and pet.
 *
 * This is a standalone Filament Page component backed by Livewire, allowing
 * interactive multi-select filtering, date range scoping, time-of-day filtering, max event capping, and pagination without full browser reloads.
 */
class ActivitiesPage extends Page
{
    use WithPagination;
    use HasActivityFilters;

    public const DEFAULT_PER_PAGE = 15;
    public const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100, 'all'];

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bolt';
    protected string $view = 'filament.pages.activities-page';
    protected static ?string $slug = 'activities';
    protected static string | \UnitEnum | null $navigationGroup = 'Activities';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Activities';
    protected static ?string $title = 'Activities';

    /**
     * Items per page for pagination (e.g. 10, 15, 25, 50, 100, or 'all').
     * Synced with the browser URL (omitted when set to the default).
     */
    #[Url(except: self::DEFAULT_PER_PAGE)]
    public int|string $perPage = self::DEFAULT_PER_PAGE;

    /**
     * Livewire lifecycle hook: triggered whenever any component property is updated.
     * Resets pagination back to page 1 so results aren't out-of-bounds.
     */
    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * Queries the database for activity history records matching active filters.
     *
     * @return LengthAwarePaginator<int, History>
     */
    public function getHistories(): LengthAwarePaginator
    {
        $query = History::query()
            ->with(['pet', 'device', 'media'])
            ->latest();

        $this->applyFilters($query);

        $totalCount = $query->count();
        if ($this->maxResults > 0) {
            $totalCount = min($this->maxResults, $totalCount);
        }

        $pageSize = ($this->perPage === 'all' || (string) $this->perPage === 'all')
            ? max(1, $totalCount)
            : max(1, (int) $this->perPage);

        if ($this->maxResults > 0) {
            $offset = ($this->getPage() - 1) * $pageSize;
            $remaining = max(0, $totalCount - $offset);
            $items = $query->offset($offset)->limit(min($pageSize, $remaining))->get();

            return new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $totalCount,
                $pageSize,
                $this->getPage(),
                ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
            );
        }

        return $query->paginate($pageSize);
    }

    /**
     * Retrieves all screenshot capture frames matching the active filters in chronological order.
     *
     * @return array<int, array{history_id: int, url: string, title: string, message: string, pet: string, device: string, time: string, diff: string, duration: string|null, detail_url: string|null, parameters: array<string, mixed>|null}>
     */
    public function getFilteredScreenshotMedia(): array
    {
        $query = TimelapseGenerator::imageCapturesQuery()
            ->with(['pet', 'device', 'media'])
            ->latest();

        $this->applyFilters($query);

        if ($this->maxResults > 0) {
            $query->limit($this->maxResults);
        }

        $histories = $query->get()->reverse()->values();

        return TimelapseGenerator::formatFrames($histories);
    }

    /**
     * Retrieves all video capture clips matching the active filters in chronological order.
     *
     * @return array<int, array{history_id: int, url: string, title: string, message: string, pet: string, device: string, time: string, diff: string, duration: string|null, detail_url: string|null, parameters: array<string, mixed>|null}>
     */
    public function getFilteredVideoMedia(): array
    {
        $query = VideoReelGenerator::videoCapturesQuery()
            ->with(['pet', 'device', 'media'])
            ->latest();

        $this->applyFilters($query);

        if ($this->maxResults > 0) {
            $query->limit($this->maxResults);
        }

        $histories = $query->get()->reverse()->values();

        return VideoReelGenerator::formatClips($histories);
    }

    /**
     * Generates the URL for exporting and downloading the server-side animated GIF.
     */
    public function timelapseUrl(float|int $fps = TimelapseGenerator::DEFAULT_FPS): string
    {
        return TimelapseGenerator::downloadUrl(
            deviceIds: $this->deviceIds,
            petIds: $this->petIds,
            types: $this->types,
            fps: $fps,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            maxResults: $this->maxResults > 0 ? $this->maxResults : null,
            timeFrom: $this->timeFrom,
            timeTo: $this->timeTo,
        );
    }

    /**
     * Generates the URL for streaming or downloading the server-side concatenated MP4 video reel.
     */
    public function videoCompilationUrl(bool $download = false): string
    {
        return VideoReelGenerator::downloadUrl(
            deviceIds: $this->deviceIds,
            petIds: $this->petIds,
            types: $this->types,
            download: $download,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            maxResults: $this->maxResults > 0 ? $this->maxResults : null,
            timeFrom: $this->timeFrom,
            timeTo: $this->timeTo,
        );
    }

    /**
     * Generates the URL for polling real-time compilation progress of the server-side video reel.
     */
    public function videoCompilationProgressUrl(): string
    {
        return VideoReelGenerator::progressUrl(
            deviceIds: $this->deviceIds,
            petIds: $this->petIds,
            types: $this->types,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            maxResults: $this->maxResults > 0 ? $this->maxResults : null,
            timeFrom: $this->timeFrom,
            timeTo: $this->timeTo,
        );
    }

    /**
     * Builds the target URL to navigate to the Timeline Scrubber page preserving all active filters.
     */
    public function getTimelinePageUrl(): string
    {
        return TimelinePage::getUrl($this->getActivityFilterQueryParams());
    }
}
