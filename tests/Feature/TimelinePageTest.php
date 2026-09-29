<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivitiesPage;
use App\Filament\Pages\TimelinePage;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ActivityTestCase;

class TimelinePageTest extends ActivityTestCase
{
    public function test_newest_capped_events_are_returned_in_chronological_order(): void
    {
        for ($id = 1; $id <= 40; $id++) {
            $this->event($id, ['created_at' => sprintf('2026-09-01 12:00:%02d', $id)]);
        }

        $page = new TimelinePage;
        $events = $page->getTimelineEvents();
        $this->assertSame(range(16, 40), array_column($events, 'id'));
        $this->assertSame($events[0]['timestamp'] * 1000, $events[0]['timestamp_ms']);

        $page->maxResults = 0;
        $this->assertSame(range(1, 40), array_column($page->getTimelineEvents(), 'id'));
    }

    public function test_projection_distinguishes_video_with_poster_photo_and_info(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->event($id, ['created_at' => sprintf('2026-09-01 12:00:0%d', $id)]);
        }
        DB::table('media_files')->insert([
            ['event_id' => 'event-1', 'file_id' => 'clip.ts', 'file_type' => 'video/mp2t', 'module_type' => 'CLOUD_STORAGE'],
            ['event_id' => 'event-1', 'file_id' => 'poster.jpg', 'file_type' => 'image/jpeg', 'module_type' => 'EVENT_PREVIEW'],
            ['event_id' => 'event-2', 'file_id' => 'photo.jpg', 'file_type' => 'image/jpeg', 'module_type' => 'EVENT_PREVIEW'],
        ]);

        $events = (new TimelinePage)->getTimelineEvents();
        $this->assertSame(['video', 'image', null], array_column($events, 'media_type'));
        $this->assertStringContainsString('poster.jpg', $events[0]['thumbnail_url']);
        $this->assertFalse($events[2]['has_media']);
        $this->assertNull($events[2]['detail_url']);
    }

    public function test_both_navigation_directions_preserve_all_filters(): void
    {
        $properties = [
            'deviceIds' => ['7'], 'petIds' => ['unknown'], 'types' => ['DETECT'],
            'mediaTypes' => ['video'], 'dateFrom' => '1788220800', 'dateTo' => '1788393599',
            'timeFrom' => '22:00', 'timeTo' => '02:00', 'maxResults' => 0,
        ];
        $expected = [
            'devices' => ['7'], 'pets' => ['unknown'], 'types' => ['DETECT'],
            'media' => ['video'], 'date_from' => '1788220800', 'date_to' => '1788393599',
            'time_from' => '22:00', 'time_to' => '02:00', 'max_results' => 'all',
        ];

        foreach ([new ActivitiesPage, new TimelinePage] as $page) {
            foreach ($properties as $property => $value) {
                $page->{$property} = $value;
            }
            $url = $page instanceof TimelinePage ? $page->getActivitiesPageUrl() : $page->getTimelinePageUrl();
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($expected, $query);
        }
    }

    public function test_empty_and_filtered_timeline_views_render(): void
    {
        Livewire::test(TimelinePage::class)->assertSuccessful()->assertSee('Timeline Scrubber');

        $this->event(1);
        $component = Livewire::test(TimelinePage::class)->assertSuccessful();
        $this->assertCount(1, $component->instance()->getTimelineEvents());
        $component->set('types', ['ERROR'])->assertSuccessful();
        $this->assertSame([], $component->instance()->getTimelineEvents());
    }
}
