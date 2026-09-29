<x-filament-panels::page>
    @php($timelineEvents = $this->getTimelineEvents())

    @once
        {!! loadInlineStylesheet('css/petkit-timeline-scrubber.css') !!}
        {!! loadInlineScript('js/petkit-timeline-scrubber.js') !!}
    @endonce

    <x-filament::section>
        <x-slot name="heading">
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <x-filament::icon icon="heroicon-o-film" style="width:1.5rem;height:1.5rem;color:var(--primary-500);" />
                <span>{{ __('Timeline Scrubber') }}</span>
                <span class="petkit-player-btn-badge" style="background:var(--primary-500);color:#ffffff;font-size:0.75rem;padding:0.125rem 0.5rem;border-radius:9999px;">
                    {{ count($timelineEvents) }} {{ __('events') }}
                </span>
            </div>
        </x-slot>

        {{-- Shared Activity Filter Toolbar --}}
        @component('filament.pages.activities.filter-toolbar')
            {{-- Open in Activities Page Button --}}
            <a
                href="{{ $this->getActivitiesPageUrl() }}"
                style="margin-left:auto;"
            >
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-m-arrow-top-right-on-square"
                    icon-position="after"
                >
                    {{ __('Open in Activities') }}
                    <span class="petkit-filter-btn-badge">
                        {{ count($timelineEvents) }}
                    </span>
                </x-filament::button>
            </a>
        @endcomponent

        {{-- Active Filter Chips --}}
        @if ($this->hasActiveFilters())
            <div style="display:flex;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;padding-top:0.25rem;">
                <span style="font-size:0.75rem;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.04em;">
                    {{ __('Active filters:') }}
                </span>
                @include('filament.pages.activities.filter-chips')
            </div>
        @endif

        {{-- Security Camera / NVR Horizontal Timeline Scrubber Stage --}}
        @php($filterHash = md5(($this->dateFrom ?? '') . '_' . ($this->dateTo ?? '') . '_' . implode(',', $this->deviceIds) . '_' . implode(',', $this->petIds) . '_' . implode(',', $this->types) . '_' . implode(',', $this->mediaTypes) . '_' . ($this->maxResults > 0 ? $this->maxResults : 'all') . '_' . count($timelineEvents)))
        <div
            wire:key="timeline-stage-{{ $filterHash }}"
            x-data="petkitTimelineScrubber({
                events: @js($timelineEvents),
            })"
            x-ref="stagePanel"
            class="petkit-scrubber-stage"
            x-on:keydown.space.window="if (!document.activeElement || document.activeElement.tagName !== 'INPUT') { $event.preventDefault(); togglePlay(); }"
            x-on:keydown.arrow-left.window="if (!document.activeElement || document.activeElement.tagName !== 'INPUT') { $event.preventDefault(); prevEvent(); }"
            x-on:keydown.arrow-right.window="if (!document.activeElement || document.activeElement.tagName !== 'INPUT') { $event.preventDefault(); nextEvent(); }"
            x-on:keydown.home.window="if (!document.activeElement || document.activeElement.tagName !== 'INPUT') { $event.preventDefault(); firstEvent(); }"
            x-on:keydown.end.window="if (!document.activeElement || document.activeElement.tagName !== 'INPUT') { $event.preventDefault(); lastEvent(); }"
        >
            {{-- Stage Top Header Bar (Unobstructed Metadata & Actions above the Viewport) --}}
            <div class="petkit-scrubber-stage-header">
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;flex:1;min-width:0;">
                    {{-- Latest badge --}}
                    <span
                        x-show="events.length > 0 && currentIndex === events.length - 1"
                        class="petkit-scrubber-badge petkit-scrubber-badge--live"
                    >
                        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#ffffff;"></span>
                        <span>{{ __('LATEST') }}</span>
                    </span>

                    {{-- Timestamp & Diff OSD --}}
                    <div class="petkit-scrubber-header-datetime" x-show="events.length > 0">
                        <span x-text="formatFullDateTime(currentTimestamp) || currentEvent().datetime"></span>
                        <span x-show="currentEvent().diff" style="display:inline-flex;align-items:center;gap:0.375rem;">
                            <span style="opacity:0.6;">&middot;</span>
                            <span x-text="currentEvent().diff"></span>
                        </span>
                        <span x-show="currentEvent().duration" style="display:inline-flex;align-items:center;gap:0.375rem;">
                            <span style="opacity:0.6;">&middot;</span>
                            <span x-text="currentEvent().duration"></span>
                        </span>
                    </div>

                    {{-- Device Badge --}}
                    <span x-show="currentEvent().device_name" class="petkit-scrubber-badge">
                        <x-filament::icon icon="heroicon-m-rectangle-stack" style="width:0.875rem;height:0.875rem;opacity:0.8;" />
                        <span x-text="currentEvent().device_name"></span>
                    </span>

                    {{-- Pet Badge --}}
                    <span x-show="currentEvent().pet_name" class="petkit-scrubber-badge" style="border-color:rgba(236,72,153,0.4);color:#f472b6;">
                        <x-filament::icon icon="heroicon-m-heart" style="width:0.875rem;height:0.875rem;" />
                        <span x-text="currentEvent().pet_name"></span>
                    </span>

                    {{-- Event Type Badge --}}
                    <span
                        x-show="events.length > 0 && currentEvent().type_title"
                        class="petkit-scrubber-badge"
                        :style="'border-color:' + getEventColorHex(currentEvent().color) + ';color:' + getEventColorHex(currentEvent().color)"
                    >
                        <span x-text="currentEvent().type_title"></span>
                    </span>

                    {{-- Media Type Badge --}}
                    <span
                        x-show="currentEvent().media_type === 'video'"
                        class="petkit-scrubber-badge"
                        style="background:rgba(37,99,235,0.8);border-color:#3b82f6;color:#ffffff;"
                    >
                        <x-filament::icon icon="heroicon-m-video-camera" style="width:0.875rem;height:0.875rem;" />
                        <span>{{ __('VIDEO') }}</span>
                    </span>

                    <span
                        x-show="currentEvent().media_type === 'image'"
                        class="petkit-scrubber-badge"
                        style="background:rgba(16,185,129,0.8);border-color:#10b981;color:#ffffff;"
                    >
                        <x-filament::icon icon="heroicon-m-photo" style="width:0.875rem;height:0.875rem;" />
                        <span>{{ __('SNAPSHOT') }}</span>
                    </span>

                    <span
                        x-show="events.length > 0 && !currentEvent().media_type"
                        class="petkit-scrubber-badge"
                        style="background:rgba(75,85,99,0.8);border-color:#6b7280;color:#ffffff;"
                    >
                        <x-filament::icon icon="heroicon-m-bolt" style="width:0.875rem;height:0.875rem;" />
                        <span>{{ __('EVENT') }}</span>
                    </span>
                </div>

                {{-- Right Actions (Counter, Detail Link, Fullscreen) --}}
                <div style="display:flex;align-items:center;gap:0.5rem;margin-left:auto;">
                    <span class="petkit-scrubber-badge">
                        <span x-text="events.length > 0 ? (currentIndex + 1) : 0"></span> / <span x-text="events.length"></span>
                    </span>

                    <a
                        x-show="currentEvent().detail_url"
                        :href="currentEvent().detail_url || '#'"
                        target="_blank"
                        class="petkit-scrubber-hud-btn"
                        title="{{ __('Open full event details page in a new tab') }}"
                    >
                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" />
                    </a>

                    <button
                        type="button"
                        class="petkit-scrubber-hud-btn"
                        x-on:click="toggleFullscreen()"
                        title="{{ __('Toggle Fullscreen') }}"
                    >
                        <x-filament::icon icon="heroicon-m-arrows-pointing-out" x-show="!isFullscreen" />
                        <x-filament::icon icon="heroicon-m-arrows-pointing-in" x-show="isFullscreen" x-cloak />
                    </button>
                </div>
            </div>

            {{-- Main Viewport (100% Unobstructed Media Feed) --}}
            <div class="petkit-scrubber-viewport">
                {{-- Viewport Media & Info Display --}}
                <div x-show="events.length > 0" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                    {{-- 1. Video Player (No loop, automatically advances on ended) --}}
                    <div x-show="currentEvent().media_type === 'video'" style="width:100%;height:100%;">
                        <video
                            x-ref="videoPlayer"
                            :src="currentEvent().video_url || ''"
                            :poster="currentEvent().image_url || ''"
                            class="petkit-scrubber-media"
                            playsinline
                            preload="metadata"
                            x-on:ended="onVideoEnded()"
                            x-on:timeupdate="onVideoTimeUpdate()"
                            x-on:click="togglePlay()"
                        ></video>
                    </div>

                    {{-- 2. Image Capture --}}
                    <div x-show="currentEvent().media_type === 'image'" style="width:100%;height:100%;">
                        <img
                            :src="currentEvent().image_url || ''"
                            :alt="currentEvent().title || ''"
                            class="petkit-scrubber-media"
                            loading="eager"
                            decoding="async"
                        />
                    </div>

                    {{-- 3. Non-Media Event Info Bubble / Card --}}
                    <div x-show="!currentEvent().media_type" class="petkit-scrubber-infocard-wrapper">
                        <div
                            class="petkit-scrubber-infocard"
                            :style="'--event-color:' + getEventColorHex(currentEvent().color) + ';--event-bg:' + getEventColorHex(currentEvent().color) + '25;--event-glow:' + getEventColorHex(currentEvent().color) + '40;'"
                        >
                            <div class="petkit-scrubber-infocard__header">
                                <div class="petkit-scrubber-infocard__icon">
                                    <template x-if="currentEvent().icon === 'heroicon-m-exclamation-triangle'">
                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-arrow-path-rounded-square'">
                                        <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-wrench-screwdriver'">
                                        <x-filament::icon icon="heroicon-m-wrench-screwdriver" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-exclamation-circle'">
                                        <x-filament::icon icon="heroicon-m-exclamation-circle" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-cake'">
                                        <x-filament::icon icon="heroicon-m-cake" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-beaker'">
                                        <x-filament::icon icon="heroicon-m-beaker" />
                                    </template>
                                    <template x-if="currentEvent().icon === 'heroicon-m-camera'">
                                        <x-filament::icon icon="heroicon-m-camera" />
                                    </template>
                                    <template x-if="!['heroicon-m-exclamation-triangle', 'heroicon-m-arrow-path-rounded-square', 'heroicon-m-wrench-screwdriver', 'heroicon-m-exclamation-circle', 'heroicon-m-cake', 'heroicon-m-beaker', 'heroicon-m-camera'].includes(currentEvent().icon)">
                                        <x-filament::icon icon="heroicon-m-bolt" />
                                    </template>
                                </div>

                                <div class="petkit-scrubber-infocard__titles">
                                    <div class="petkit-scrubber-infocard__type-pill" x-text="currentEvent().type_title"></div>
                                    <h3 class="petkit-scrubber-infocard__title" x-text="currentEvent().title"></h3>
                                </div>
                            </div>

                            <div class="petkit-scrubber-infocard__tags">
                                <span x-show="currentEvent().device_name" class="petkit-scrubber-infocard__tag">
                                    <x-filament::icon icon="heroicon-m-rectangle-stack" />
                                    <span x-text="currentEvent().device_name"></span>
                                </span>

                                <span x-show="currentEvent().pet_name" class="petkit-scrubber-infocard__tag" style="color:#f472b6;">
                                    <x-filament::icon icon="heroicon-m-heart" />
                                    <span x-text="currentEvent().pet_name"></span>
                                </span>

                                <span x-show="currentEvent().duration" class="petkit-scrubber-infocard__tag">
                                    <x-filament::icon icon="heroicon-m-clock" />
                                    <span x-text="currentEvent().duration"></span>
                                </span>
                            </div>

                            <div x-show="currentEvent().message" class="petkit-scrubber-infocard__message" x-html="currentEvent().message"></div>

                            <div class="petkit-scrubber-infocard__footer">
                                <span x-text="currentEvent().datetime"></span>
                                <span x-text="currentEvent().diff"></span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Empty State --}}
                <div x-show="events.length === 0" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;padding:3rem;color:#94a3b8;text-align:center;">
                    <x-filament::icon icon="heroicon-o-calendar" style="width:3.5rem;height:3.5rem;opacity:0.4;" />
                    <div>
                        <h4 style="font-size:1.125rem;font-weight:600;color:#f1f5f9;margin-bottom:0.25rem;">{{ __('No activity events found') }}</h4>
                        <p style="font-size:0.875rem;">{{ __('Try adjusting your device, pet, type, or date filters.') }}</p>
                    </div>
                </div>
            </div>

            {{-- Transport Controls Bar --}}
            <div class="petkit-scrubber-controls">
                {{-- Left: Event Navigation & Playback Controls --}}
                <div class="petkit-scrubber-controls-group">
                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        x-on:click="firstEvent()"
                        :disabled="currentIndex === 0 || events.length === 0"
                        title="{{ __('Jump to Oldest Event (Home)') }}"
                    >
                        <x-filament::icon icon="heroicon-m-backward" />
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        x-on:click="prevEvent()"
                        :disabled="currentIndex === 0 || events.length === 0"
                        title="{{ __('Previous Event (Left Arrow)') }}"
                    >
                        <x-filament::icon icon="heroicon-m-chevron-left" />
                        <span>{{ __('Prev') }}</span>
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn petkit-scrubber-btn--primary"
                        x-on:click="togglePlay()"
                        :disabled="events.length === 0"
                        title="{{ __('Play / Pause (Space)') }}"
                    >
                        <x-filament::icon icon="heroicon-m-play" x-show="!isPlaying" />
                        <x-filament::icon icon="heroicon-m-pause" x-show="isPlaying" x-cloak />
                        <span x-text="isPlaying ? '{{ __('Pause') }}' : '{{ __('Play') }}'"></span>
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        x-on:click="nextEvent()"
                        :disabled="currentIndex >= events.length - 1 || events.length === 0"
                        title="{{ __('Next Event (Right Arrow)') }}"
                    >
                        <span>{{ __('Next') }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" />
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        x-on:click="lastEvent()"
                        :disabled="currentIndex >= events.length - 1 || events.length === 0"
                        title="{{ __('Jump to Latest Event (End)') }}"
                    >
                        <x-filament::icon icon="heroicon-m-forward" />
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        :class="isMuted ? 'petkit-scrubber-btn--active' : ''"
                        x-on:click="toggleMute()"
                        :title="isMuted ? '{{ __('Unmute Audio') }}' : '{{ __('Mute Audio') }}'"
                    >
                        <x-filament::icon icon="heroicon-m-speaker-x-mark" x-show="isMuted" />
                        <x-filament::icon icon="heroicon-m-speaker-wave" x-show="!isMuted" />
                    </button>
                </div>

                {{-- Current Playhead Date & Time Display --}}
                <div
                    class="petkit-scrubber-transport-time"
                    :title="'{{ __('Current Playhead Date & Time: ') }}' + formatFullDateTime(currentTimestamp)"
                >
                    <span class="petkit-scrubber-transport-time__dot"></span>
                    <span x-text="formatFullDateTime(currentTimestamp)"></span>
                </div>

                {{-- Center: Playback Speed --}}
                <div class="petkit-scrubber-controls-group">
                    <div class="petkit-scrubber-segmented">
                        <button
                            type="button"
                            :class="playbackSpeed === 0.5 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(0.5)"
                        >
                            0.5x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 1 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(1)"
                        >
                            1x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 2 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(2)"
                        >
                            2x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 4 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(4)"
                        >
                            4x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 8 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(8)"
                        >
                            8x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 16 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(16)"
                        >
                            16x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 32 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(32)"
                        >
                            32x
                        </button>
                        <button
                            type="button"
                            :class="playbackSpeed === 64 ? 'petkit-scrubber-segmented-btn petkit-scrubber-segmented-btn--active' : 'petkit-scrubber-segmented-btn'"
                            x-on:click="setSpeed(64)"
                        >
                            64x
                        </button>
                    </div>
                </div>

                {{-- Right: Zoom Levels & Toggles --}}
                <div class="petkit-scrubber-controls-group">
                    <div style="display:flex;align-items:center;gap:0.25rem;">
                        <span style="font-size:0.75rem;color:#94a3b8;">{{ __('Scale:') }}</span>
                        <select
                            x-model="zoom"
                            style="font-size:0.75rem;background:#090d16;color:#e2e8f0;border:1px solid rgba(255,255,255,0.15);border-radius:0.375rem;padding:0.25rem 0.5rem;"
                        >
                            <option value="auto">{{ __('Auto Scale') }}</option>
                            <option value="1h">{{ __('1 Hour') }}</option>
                            <option value="6h">{{ __('6 Hours') }}</option>
                            <option value="12h">{{ __('12 Hours') }}</option>
                            <option value="24h">{{ __('24 Hours') }}</option>
                            <option value="7d">{{ __('7 Days') }}</option>
                            <option value="all">{{ __('Fit All') }}</option>
                        </select>
                    </div>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        :class="showFilmstrip ? 'petkit-scrubber-btn--active' : ''"
                        x-on:click="showFilmstrip = !showFilmstrip"
                        title="{{ __('Toggle Event Filmstrip') }}"
                    >
                        <x-filament::icon icon="heroicon-m-film" />
                    </button>

                    <button
                        type="button"
                        class="petkit-scrubber-btn"
                        :class="showData ? 'petkit-scrubber-btn--active' : ''"
                        x-on:click="showData = !showData"
                        title="{{ __('Toggle Raw JSON Payload') }}"
                    >
                        <x-filament::icon icon="heroicon-m-code-bracket" />
                    </button>
                </div>
            </div>

            {{-- Horizontal Timeline Scrubber Tape --}}
            <div
                x-ref="tapeContainer"
                class="petkit-scrubber-tape-container"
                :class="isDragging ? 'petkit-scrubber-tape-container--dragging' : ''"
                x-on:pointerdown="onTapePointerDown($event)"
                x-on:pointermove="onTapePointerMove($event)"
                x-on:pointerup="onTapePointerUp($event)"
                x-on:pointercancel="onTapePointerCancel($event)"
                x-on:click="onTapeClick($event)"
                x-on:wheel="onTapeWheel($event)"
            >
                <div
                    x-ref="tape"
                    class="petkit-scrubber-tape"
                    :style="'width:' + tapeWidth + 'px; margin: 0 45vw;'"
                >
                    {{-- Date Boundary Dividers --}}
                    <template x-for="boundary in dateBoundaries" :key="'boundary-' + boundary.left">
                        <div
                            class="petkit-scrubber-date-boundary"
                            :style="'left:' + boundary.left + 'px;'"
                            x-text="boundary.label"
                        ></div>
                    </template>

                    {{-- Graduation Ticks & Time Labels --}}
                    <template x-for="tick in rulerTicks" :key="'tick-' + tick.left">
                        <div>
                            <div
                                class="petkit-scrubber-tick"
                                :class="tick.isMajor ? 'petkit-scrubber-tick--major' : 'petkit-scrubber-tick--minor'"
                                :style="'left:' + tick.left + 'px;'"
                            ></div>
                            <template x-if="tick.isMajor && tick.label">
                                <div
                                    class="petkit-scrubber-tick-label"
                                    :style="'left:' + tick.left + 'px;'"
                                    x-text="tick.label"
                                ></div>
                            </template>
                        </div>
                    </template>

                    {{-- Event Marker Pins along the Tape (Staggered Alternating Above and Below Center Axis) --}}
                    <template x-for="(event, idx) in events" :key="'event-marker-' + event.id">
                        <div
                            class="petkit-scrubber-event-marker"
                            :class="[
                                idx === currentIndex ? 'petkit-scrubber-event-marker--active' : '',
                                idx % 2 === 0 ? 'petkit-scrubber-event-marker--top' : 'petkit-scrubber-event-marker--bottom'
                            ]"
                            :style="'left:' + timeToPixels(event.timestamp) + 'px;'"
                            x-on:click.stop="seekToEvent(idx)"
                            x-on:mouseenter="setHoverEvent(event, $event)"
                            x-on:mouseleave="clearHoverEvent()"
                        >
                            {{-- Event Pin --}}
                            <div
                                class="petkit-scrubber-event-pin"
                                :style="'background:' + getEventColorHex(event.color) + ';'"
                            >
                                <template x-if="event.icon === 'heroicon-m-exclamation-triangle'">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-arrow-path-rounded-square'">
                                    <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-wrench-screwdriver'">
                                    <x-filament::icon icon="heroicon-m-wrench-screwdriver" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-exclamation-circle'">
                                    <x-filament::icon icon="heroicon-m-exclamation-circle" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-cake'">
                                    <x-filament::icon icon="heroicon-m-cake" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-beaker'">
                                    <x-filament::icon icon="heroicon-m-beaker" />
                                </template>
                                <template x-if="event.icon === 'heroicon-m-camera'">
                                    <x-filament::icon icon="heroicon-m-camera" />
                                </template>
                                <template x-if="!['heroicon-m-exclamation-triangle', 'heroicon-m-arrow-path-rounded-square', 'heroicon-m-wrench-screwdriver', 'heroicon-m-exclamation-circle', 'heroicon-m-cake', 'heroicon-m-beaker', 'heroicon-m-camera'].includes(event.icon)">
                                    <x-filament::icon icon="heroicon-m-bolt" />
                                </template>

                                {{-- Media indicator badge --}}
                                <template x-if="event.has_media">
                                    <div
                                        class="petkit-scrubber-event-pin__media-badge"
                                        :style="event.media_type === 'video' ? 'background: #3b82f6;' : 'background: #10b981;'"
                                        :title="event.media_type === 'video' ? '{{ __('Video Clip') }}' : '{{ __('Snapshot Photo') }}'"
                                    ></div>
                                </template>
                            </div>

                            <div class="petkit-scrubber-event-marker__stem"></div>
                        </div>
                    </template>

                    {{-- Playhead (Vertical Red Needle) --}}
                    <div
                        class="petkit-scrubber-playhead"
                        :style="'left:' + timeToPixels(currentTimestamp) + 'px;'"
                    ></div>
                </div>
            </div>

            {{-- Floating Hover Event Preview Card (Anchored above the timeline tape, unobstructed by viewport, video, or scroll clipping) --}}
            <template x-if="hoverEvent">
                <div
                    class="petkit-scrubber-hover-preview"
                    :style="'left:' + hoverScreenX + 'px; top:' + (hoverScreenY - 12) + 'px;'"
                >
                    <template x-if="hoverEvent.thumbnail_url">
                        <img :src="hoverEvent.thumbnail_url" alt="Preview" class="petkit-scrubber-hover-preview__img" loading="lazy" decoding="async" />
                    </template>
                    <div class="petkit-scrubber-hover-preview__body">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                            <span
                                class="petkit-scrubber-hover-preview__type"
                                :style="'color:' + getEventColorHex(hoverEvent.color)"
                                x-text="hoverEvent.type_title"
                            ></span>
                            <span class="petkit-scrubber-hover-preview__time" x-text="formatTimeOnly(hoverEvent.timestamp) || hoverEvent.time"></span>
                        </div>
                        <div class="petkit-scrubber-hover-preview__title" x-text="hoverEvent.title"></div>
                        <div class="petkit-scrubber-hover-preview__meta">
                            <template x-if="hoverEvent.media_type === 'video'">
                                <span style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.6875rem;padding:0.1rem 0.35rem;border-radius:0.25rem;background:rgba(37,99,235,0.25);color:#60a5fa;border:1px solid rgba(96,165,250,0.3);">
                                    <x-filament::icon icon="heroicon-m-video-camera" style="width:0.75rem;height:0.75rem;" />
                                    <span>{{ __('Video') }}</span>
                                </span>
                            </template>
                            <template x-if="hoverEvent.media_type === 'image'">
                                <span style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.6875rem;padding:0.1rem 0.35rem;border-radius:0.25rem;background:rgba(16,185,129,0.25);color:#34d399;border:1px solid rgba(52,211,153,0.3);">
                                    <x-filament::icon icon="heroicon-m-photo" style="width:0.75rem;height:0.75rem;" />
                                    <span>{{ __('Photo') }}</span>
                                </span>
                            </template>
                            <template x-if="!hoverEvent.media_type">
                                <span style="display:inline-flex;align-items:center;gap:0.25rem;font-size:0.6875rem;padding:0.1rem 0.35rem;border-radius:0.25rem;background:rgba(107,114,128,0.25);color:#9ca3af;border:1px solid rgba(156,163,175,0.3);">
                                    <x-filament::icon icon="heroicon-m-bolt" style="width:0.75rem;height:0.75rem;" />
                                    <span>{{ __('Info') }}</span>
                                </span>
                            </template>

                            <template x-if="hoverEvent.pet_name">
                                <span style="color:#f472b6;" x-text="hoverEvent.pet_name"></span>
                            </template>
                            <template x-if="hoverEvent.device_name">
                                <span style="color:#94a3b8;" x-text="hoverEvent.device_name"></span>
                            </template>
                        </div>
                    </div>
                    <div class="petkit-scrubber-hover-preview__arrow"></div>
                </div>
            </template>

            {{-- Event Filmstrip / Mini Cards Carousel --}}
            <div
                x-show="showFilmstrip && events.length > 0"
                x-cloak
                x-transition
                class="petkit-scrubber-filmstrip"
            >
                <template x-for="(event, idx) in events" :key="'filmstrip-' + event.id">
                    <div
                        class="petkit-scrubber-filmstrip-card"
                        :class="idx === currentIndex ? 'petkit-scrubber-filmstrip-card--active' : ''"
                        :style="'--card-color:' + getEventColorHex(event.color) + ';'"
                        x-on:click="seekToEvent(idx)"
                    >
                        <template x-if="event.thumbnail_url">
                            <img :src="event.thumbnail_url" alt="Capture" class="petkit-scrubber-filmstrip-card__img" loading="lazy" decoding="async" />
                        </template>

                        <div class="petkit-scrubber-filmstrip-card__content">
                            <div class="petkit-scrubber-filmstrip-card__top">
                                <div class="petkit-scrubber-filmstrip-card__icon">
                                    <template x-if="event.icon === 'heroicon-m-exclamation-triangle'">
                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-arrow-path-rounded-square'">
                                        <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-wrench-screwdriver'">
                                        <x-filament::icon icon="heroicon-m-wrench-screwdriver" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-exclamation-circle'">
                                        <x-filament::icon icon="heroicon-m-exclamation-circle" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-cake'">
                                        <x-filament::icon icon="heroicon-m-cake" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-beaker'">
                                        <x-filament::icon icon="heroicon-m-beaker" />
                                    </template>
                                    <template x-if="event.icon === 'heroicon-m-camera'">
                                        <x-filament::icon icon="heroicon-m-camera" />
                                    </template>
                                    <template x-if="!['heroicon-m-exclamation-triangle', 'heroicon-m-arrow-path-rounded-square', 'heroicon-m-wrench-screwdriver', 'heroicon-m-exclamation-circle', 'heroicon-m-cake', 'heroicon-m-beaker', 'heroicon-m-camera'].includes(event.icon)">
                                        <x-filament::icon icon="heroicon-m-bolt" />
                                    </template>
                                </div>
                                <span class="petkit-scrubber-filmstrip-card__time" x-text="formatTimeOnly(event.timestamp) || event.time"></span>
                            </div>

                            <div class="petkit-scrubber-filmstrip-card__bottom">
                                <span x-text="event.title"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Collapsible Raw JSON Parameters Drawer --}}
            <div
                x-show="showData && currentEvent() && currentEvent().parameters"
                x-cloak
                x-transition
                class="petkit-scrubber-inspector"
            >
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
                    <span style="font-weight:600;color:#f8fafc;">{{ __('Event Payload & Diagnostic Parameters') }}</span>
                    <span style="font-size:0.75rem;color:#94a3b8;" x-text="'Event ID: #' + (currentEvent() ? currentEvent().id : '')"></span>
                </div>
                <pre x-text="JSON.stringify(currentEvent() ? currentEvent().parameters : {}, null, 2)"></pre>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
