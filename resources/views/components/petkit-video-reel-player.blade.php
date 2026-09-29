@props([
    'clips' => [],
    'videoUrl' => null,
    'downloadUrl' => null,
    'progressUrl' => null,
])

<div
    {{ $attributes }}
    x-data="petkitVideoReelPlayer({
        clips: @js($clips),
        videoUrl: @js($videoUrl),
        downloadUrl: @js($downloadUrl),
        progressUrl: @js($progressUrl),
        initialLoadingTitle: @js(__('Initializing Assembly')),
        initialLoadingSubtitle: @js(__('Preparing stream pipeline...')),
        initialLoadingStep: @js(__('Loading video reel...')),
    })"
    x-on:keydown.space.window="if (open && isVideoReady) { $event.preventDefault(); togglePlay(); }"
    x-on:keydown.arrow-left.window="if (open) { $event.preventDefault(); seekBy(-5); }"
    x-on:keydown.arrow-right.window="if (open) { $event.preventDefault(); seekBy(5); }"
>
    <x-filament::button
        color="primary"
        size="sm"
        icon="heroicon-m-video-camera"
        icon-position="before"
        x-on:click="open = true;"
        :disabled="empty($clips)"
    >
        {{ __('Video Reel') }}
        <span class="petkit-player-btn-badge">
            {{ count($clips) }}
        </span>
    </x-filament::button>

    {{-- Video Reel Modal --}}
    <x-petkit-player-modal
        :title="__('Combined Video Reel')"
        icon="heroicon-m-video-camera"
        :count="count($clips)"
        count-label="video events"
        :download-url="$downloadUrl"
        :download-label="__('Download Video')"
        :download-title="__('Download the full combined MP4 video')"
    >
        {{-- Player Viewport --}}
        <div class="petkit-player-viewport">
            <video
                x-ref="videoPlayer"
                x-show="clips.length > 0"
                :src="open ? videoUrl : ''"
                playsinline
                preload="auto"
                x-on:timeupdate="handleTimeUpdate()"
                x-on:loadedmetadata="handleLoadedMetadata()"
                x-on:progress="handleProgress()"
                x-on:canplay="handleCanPlay()"
                x-on:playing="isPlaying = true; finishLoading()"
                x-on:play="isPlaying = true"
                x-on:pause="isPlaying = false"
                x-on:waiting="handleWaiting()"
                x-on:ended="handleEnded()"
                x-on:error="if (open) handleVideoError($event)"
                class="petkit-player-media"
            ></video>

            {{-- Real Progress Bar Overlay --}}
            <div
                x-show="isLoading"
                x-cloak
                class="petkit-player-loading-overlay"
            >
                <div class="petkit-player-loading-content" style="max-width:28rem;">
                    {{-- Row 1 (Above Bar): Pulsing Dot + Title (left) & Current Clip Count (right) --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:0.75rem;">
                        <span style="display:inline-flex;align-items:center;gap:0.5rem;font-size:0.9375rem;font-weight:700;color:#ffffff;line-height:1.2;flex:1;min-width:0;">
                            <span style="width:0.5rem;height:0.5rem;border-radius:9999px;background:#ea580c;display:inline-block;box-shadow:0 0 8px #f97316;flex-shrink:0;"></span>
                            <span x-text="loadingTitle || loadingStep" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                        </span>
                        <template x-if="loadingCurrentClip && loadingTotalClips">
                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-variant-numeric:tabular-nums;font-weight:600;font-size:0.8125rem;white-space:nowrap;padding:0.125rem 0.5rem;border-radius:0.25rem;background:rgba(255,255,255,0.08);color:var(--gray-200);flex-shrink:0;">
                                Clip <span x-text="loadingCurrentClip"></span> of <span x-text="loadingTotalClips"></span>
                            </span>
                        </template>
                        <template x-if="!loadingCurrentClip && loadingTotalClips">
                            <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-variant-numeric:tabular-nums;font-size:0.8125rem;color:var(--gray-400);white-space:nowrap;flex-shrink:0;">
                                <span x-text="loadingTotalClips"></span> clips
                            </span>
                        </template>
                    </div>

                    {{-- Row 2 (Bar Row): Real Progress Bar Track with Percentage after the bar --}}
                    <div style="display:flex;align-items:center;width:100%;gap:0.75rem;">
                        <div class="petkit-player-progress-track" style="flex:1;">
                            <div
                                class="petkit-player-progress-fill"
                                :style="'width: ' + Math.min(100, Math.max(3, Math.round(loadingProgress))) + '%;'"
                            ></div>
                        </div>
                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-variant-numeric:tabular-nums;color:#f97316;font-weight:700;font-size:0.9375rem;flex-shrink:0;min-width:2.75rem;text-align:right;line-height:1;" x-text="Math.round(loadingProgress) + '%'"></span>
                    </div>

                    {{-- Row 3 (Below Bar): Subtitle (left) & ETA Countdown (right) --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:0.5rem;font-size:0.75rem;color:var(--gray-400);">
                        <span style="color:var(--gray-400);line-height:1.3;flex:1;min-width:0;word-break:break-word;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="loadingSubtitle"></span>
                        <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-variant-numeric:tabular-nums;white-space:nowrap;flex-shrink:0;" :title="'Elapsed: ' + (loadingElapsedSeconds || 0).toFixed(1) + 's'" x-text="loadingEtaText"></span>
                    </div>
                </div>
            </div>

            {{-- Error Overlay --}}
            <div
                x-show="hasError"
                x-cloak
                class="petkit-player-error-overlay"
            >
                <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:2.5rem;height:2.5rem;color:var(--danger-500);" />
                <p style="font-size:0.875rem;color:var(--danger-300, #fca5a5);max-width:28rem;word-break:break-word;text-align:center;line-height:1.4;" x-text="errorMessage || '{{ __('Unable to load the combined video reel.') }}'"></p>
                <button
                    type="button"
                    x-on:click="startLoading(); if ($refs.videoPlayer) $refs.videoPlayer.load();"
                    style="display:inline-flex;align-items:center;gap:0.375rem;padding:0.375rem 0.75rem;font-size:0.8125rem;font-weight:600;border-radius:0.375rem;background:var(--primary-500);color:white;border:none;cursor:pointer;margin-top:0.25rem;"
                >
                    <x-filament::icon icon="heroicon-m-arrow-path" style="width:1rem;height:1rem;" />
                    <span>{{ __('Try Again') }}</span>
                </button>
            </div>

            <template x-if="clips.length === 0">
                <div class="petkit-player-empty-state">
                    <x-filament::icon icon="heroicon-o-video-camera" style="width:3rem;height:3rem;opacity:0.5;" />
                    <p style="font-size:0.875rem;">{{ __('No video captures recorded.') }}</p>
                </div>
            </template>
        </div>

        {{-- Unified Playback Controls Bar --}}
        <x-slot:controls>
            {{-- Timeline Scrubber Range Slider across ALL concatenated videos --}}
            <div class="petkit-player-scrubber-row">
                <input
                    type="range"
                    min="0"
                    :max="duration || 1"
                    step="0.1"
                    :value="currentTime"
                    x-on:input="seekTo(parseFloat($event.target.value))"
                    :disabled="duration <= 0"
                    class="petkit-player-scrubber"
                />
                <span class="petkit-player-scrubber-time text-gray-700 dark:text-gray-300" style="min-width:6rem;">
                    <span x-text="formatSeconds(currentTime)"></span> / <span x-text="formatSeconds(duration)"></span>
                </span>
            </div>

            {{-- Primary Control Buttons --}}
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                {{-- Left: Play/Pause, Rewind, Fast Forward --}}
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <x-filament::button
                        color="primary"
                        size="sm"
                        x-on:click="togglePlay()"
                        :disabled="empty($clips)"
                    >
                        <span style="display:inline-flex;align-items:center;gap:0.25rem;">
                            <x-filament::icon x-show="!isPlaying" icon="heroicon-m-play" style="width:1rem;height:1rem;" />
                            <x-filament::icon x-show="isPlaying" x-cloak icon="heroicon-m-pause" style="width:1rem;height:1rem;" />
                            <span x-text="isPlaying ? '{{ __('Pause') }}' : '{{ __('Play') }}'"></span>
                        </span>
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="heroicon-m-backward"
                        x-on:click="seekBy(-5)"
                        :disabled="empty($clips)"
                        title="{{ __('Rewind 5 seconds') }}"
                    />

                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="heroicon-m-forward"
                        x-on:click="seekBy(5)"
                        :disabled="empty($clips)"
                        title="{{ __('Forward 5 seconds') }}"
                    />
                </div>

                {{-- Right: Playback Speed Selector --}}
                <div class="petkit-player-speed-group">
                    <span class="petkit-player-speed-label">Speed:</span>
                    <div class="petkit-player-speed-pill">
                        @foreach ([0.5, 1.0, 2.0, 4.0, 8.0] as $speed)
                            <button
                                type="button"
                                x-on:click="setSpeed({{ $speed }})"
                                class="petkit-player-speed-btn"
                                :class="{ 'petkit-player-speed-btn--active': playbackRate === {{ $speed }} }"
                            >
                                {{ $speed }}x
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:controls>

        {{-- Synchronized Active Clip Metadata Panel --}}
        <x-slot:footer>
            <template x-if="clips.length > 0">
                <x-petkit-player-event-details
                    item-accessor="currentClip()"
                    index-accessor="currentClipIndex() + 1"
                    total-accessor="clips.length"
                    :item-label="__('Clip')"
                />
            </template>
        </x-slot:footer>
    </x-petkit-player-modal>
</div>
