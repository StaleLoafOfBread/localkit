@props([
    'frames' => [],
    'downloadUrl' => null,
])

<div
    {{ $attributes }}
    x-data="petkitTimelapsePlayer({
        frames: @js($frames),
        downloadUrl: @js($downloadUrl),
    })"
    x-on:keydown.space.window="if (open) { $event.preventDefault(); togglePlay(); }"
    x-on:keydown.arrow-left.window="if (open) { $event.preventDefault(); prevFrame(); }"
    x-on:keydown.arrow-right.window="if (open) { $event.preventDefault(); nextFrame(); }"
>
    <x-filament::button
        color="primary"
        size="sm"
        icon="heroicon-m-film"
        icon-position="before"
        x-on:click="open = true;"
        :disabled="empty($frames)"
    >
        {{ __('Timelapse') }}
        <span class="petkit-player-btn-badge">
            {{ count($frames) }}
        </span>
    </x-filament::button>

    {{-- Timelapse Modal --}}
    <x-petkit-player-modal
        :title="__('Activity Timelapse')"
        icon="heroicon-m-film"
        :count="count($frames)"
        count-label="frames"
        :download-url="$downloadUrl"
        download-href="getDownloadUrl()"
        :download-label="__('Download GIF')"
        :download-title="__('Download as animated GIF')"
    >
        {{-- Player Viewport (Canvas + HUD) --}}
        <div class="petkit-player-viewport">
            <canvas
                x-ref="canvas"
                width="1280"
                height="720"
                class="petkit-player-media"
            ></canvas>

            {{-- HUD Overlay (Timestamp, Pet, Device badges) --}}
            <template x-if="frames.length > 0">
                <div class="petkit-player-hud">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span class="petkit-player-hud__badge" x-text="currentFrame().date ? (currentFrame().date + ' ' + currentFrame().time) : currentFrame().time"></span>
                        <template x-if="currentFrame().pet">
                            <span class="petkit-player-hud__pet" x-text="currentFrame().pet"></span>
                        </template>
                        <template x-if="currentFrame().device">
                            <span class="petkit-player-hud__device" x-text="currentFrame().device"></span>
                        </template>
                    </div>

                    <div class="petkit-player-hud__counter">
                        <span x-text="currentIndex + 1"></span> / <span x-text="frames.length"></span>
                    </div>
                </div>
            </template>

            <template x-if="frames.length === 0">
                <div class="petkit-player-empty-state">
                    <x-filament::icon icon="heroicon-o-photo" style="width:3rem;height:3rem;opacity:0.5;" />
                    <p style="font-size:0.875rem;">{{ __('No image snapshots recorded.') }}</p>
                </div>
            </template>
        </div>

        {{-- Playback Controls Bar --}}
        <x-slot:controls>
            {{-- Timeline Scrubber Range Slider --}}
            <div class="petkit-player-scrubber-row">
                <input
                    type="range"
                    min="0"
                    :max="frames.length > 0 ? frames.length - 1 : 0"
                    step="1"
                    :value="currentIndex"
                    x-on:input="seek(parseInt($event.target.value))"
                    :disabled="frames.length <= 1"
                    class="petkit-player-scrubber"
                />
                <span class="petkit-player-scrubber-time text-gray-700 dark:text-gray-300">
                    <span x-text="currentIndex + 1"></span> / <span x-text="frames.length"></span>
                </span>
            </div>

            {{-- Buttons & Speed Selector --}}
            <div class="petkit-player-buttons-row">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    {{-- Step Previous --}}
                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="heroicon-m-backward"
                        x-on:click="prevFrame()"
                        x-bind:disabled="frames.length <= 1"
                        :disabled="empty($frames)"
                        title="{{ __('Previous frame') }}"
                    />

                    {{-- Play / Pause --}}
                    <x-filament::button
                        color="primary"
                        size="sm"
                        x-on:click="togglePlay()"
                        x-bind:disabled="frames.length <= 1"
                        :disabled="empty($frames)"
                    >
                        <span style="display:inline-flex;align-items:center;gap:0.25rem;">
                            <x-filament::icon x-show="!isPlaying" icon="heroicon-m-play" style="width:1rem;height:1rem;" />
                            <x-filament::icon x-show="isPlaying" x-cloak icon="heroicon-m-pause" style="width:1rem;height:1rem;" />
                            <span x-text="isPlaying ? '{{ __('Pause') }}' : '{{ __('Play') }}'"></span>
                        </span>
                    </x-filament::button>

                    {{-- Step Next --}}
                    <x-filament::button
                        color="gray"
                        size="sm"
                        icon="heroicon-m-forward"
                        x-on:click="nextFrame()"
                        x-bind:disabled="frames.length <= 1"
                        :disabled="empty($frames)"
                        title="{{ __('Next frame') }}"
                    />
                </div>

                {{-- Speed Selector --}}
                <div class="petkit-player-speed-group">
                    <span class="petkit-player-speed-label">Speed:</span>
                    <div class="petkit-player-speed-pill">
                        <template x-for="speedFps in [0.25, 0.5, 1, 2, 4, 8]" :key="speedFps">
                            <button
                                type="button"
                                x-on:click="setFps(speedFps)"
                                :disabled="frames.length <= 1"
                                class="petkit-player-speed-btn disabled:opacity-50 disabled:cursor-not-allowed"
                                :class="{ 'petkit-player-speed-btn--active': fps === speedFps }"
                                x-text="speedFps + 'x'"
                            ></button>
                        </template>
                    </div>
                </div>
            </div>
        </x-slot:controls>

        {{-- Active Frame Event Data Section --}}
        <x-slot:footer>
            <template x-if="frames.length > 0">
                <x-petkit-player-event-details
                    item-accessor="currentFrame()"
                    index-accessor="currentIndex + 1"
                    total-accessor="frames.length"
                    :item-label="__('Frame')"
                />
            </template>
        </x-slot:footer>
    </x-petkit-player-modal>
</div>
