@props([
    'title' => '',
    'icon' => 'heroicon-m-film',
    'count' => 0,
    'countLabel' => 'items',
    'downloadUrl' => null,
    'downloadHref' => null,
    'downloadLabel' => __('Download'),
    'downloadTitle' => __('Download media'),
    'showFilterChips' => true,
])

{{-- Player Modal (Teleported to body for true viewport centering) --}}
<template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open = false"
        class="petkit-player-overlay"
    >
        {{-- Dark Backdrop --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="petkit-player-backdrop"
            x-on:click="open = false"
        ></div>

        {{-- Modal Dialog Panel --}}
        <div
            x-ref="modalPanel"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="petkit-player-modal rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        >
            {{-- Modal Header --}}
            <div class="petkit-player-header">
                <div class="petkit-player-header__title-group">
                    {{-- Title row: icon, title, count badge --}}
                    <div class="petkit-player-header__title-row">
                        <x-filament::icon :icon="$icon" class="petkit-player-header__icon" />
                        <h3 class="petkit-player-header__title text-gray-950 dark:text-white">
                            {{ $title }}
                        </h3>
                        <span class="petkit-player-header__badge">
                            {{ $count }} {{ $countLabel }}
                        </span>
                    </div>

                    {{-- Active filter chips row --}}
                    @if ($showFilterChips)
                        @include('filament.pages.activities.filter-chips')
                    @endif
                </div>

                {{-- Header Actions --}}
                <div class="petkit-player-header__actions">
                    @if ($downloadUrl || $downloadHref)
                        <a
                            @if ($downloadHref)
                                :href="{{ $downloadHref }}"
                            @else
                                href="{{ $downloadUrl }}"
                            @endif
                            target="_blank"
                            class="petkit-player-header__download-btn"
                            title="{{ $downloadTitle }}"
                        >
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" style="width:1rem;height:1rem;" />
                            <span>{{ $downloadLabel }}</span>
                        </a>
                    @endif

                    <button
                        type="button"
                        x-on:click="toggleFullscreen()"
                        class="petkit-player-header__icon-btn"
                        :title="isFullscreen ? '{{ __('Exit Fullscreen') }}' : '{{ __('Fullscreen') }}'"
                    >
                        <x-filament::icon x-show="!isFullscreen" icon="heroicon-m-arrows-pointing-out" style="width:1.25rem;height:1.25rem;" />
                        <x-filament::icon x-show="isFullscreen" x-cloak icon="heroicon-m-arrows-pointing-in" style="width:1.25rem;height:1.25rem;" />
                    </button>

                    <button
                        type="button"
                        x-on:click="open = false"
                        class="petkit-player-header__icon-btn"
                        title="{{ __('Close') }}"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" style="width:1.25rem;height:1.25rem;" />
                    </button>
                </div>
            </div>

            {{-- Viewport Area --}}
            {{ $slot }}

            {{-- Playback Controls Toolbar --}}
            @if (isset($controls))
                <div class="petkit-player-controls">
                    {{ $controls }}
                </div>
            @endif

            {{-- Active Event Metadata Footer --}}
            @if (isset($footer))
                {{ $footer }}
            @endif
        </div>
    </div>
</template>
