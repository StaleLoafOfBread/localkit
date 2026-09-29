@props([
    'itemAccessor' => 'currentItem()',
    'indexAccessor' => 'currentIndex + 1',
    'totalAccessor' => 'frames.length',
    'itemLabel' => __('Frame'),
])

{{-- Active Event Metadata Panel & JSON Drawer --}}
<div class="petkit-player-details">
    <div class="petkit-player-details__row">
        {{-- Left: Item Counter, Pet, Device, Timestamp --}}
        <div class="petkit-player-details__chips">
            <span class="petkit-player-details__counter">
                {{ $itemLabel }} <span x-text="{{ $indexAccessor }}"></span> / <span x-text="{{ $totalAccessor }}"></span>
            </span>

            <template x-if="{{ $itemAccessor }}.pet">
                <span class="petkit-player-details__pet" x-text="{{ $itemAccessor }}.pet"></span>
            </template>

            <template x-if="{{ $itemAccessor }}.device">
                <span class="petkit-player-details__device" x-text="{{ $itemAccessor }}.device"></span>
            </template>

            <span class="petkit-player-details__time" x-text="{{ $itemAccessor }}.time"></span>
        </div>

        {{-- Right: Detail Page Link & JSON Toggle --}}
        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <template x-if="{{ $itemAccessor }}.detail_url">
                <a
                    :href="{{ $itemAccessor }}.detail_url"
                    target="_blank"
                    style="display:inline-flex;align-items:center;gap:0.375rem;padding:0.375rem 0.625rem;font-size:0.8125rem;font-weight:600;border-radius:0.375rem;text-decoration:none;"
                    class="border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700"
                    title="{{ __('Open full activity detail page in a new tab') }}"
                >
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" style="width:0.9375rem;height:0.9375rem;" />
                    <span>{{ __('View Event Details') }}</span>
                </a>
            </template>

            <template x-if="{{ $itemAccessor }}.parameters && Object.keys({{ $itemAccessor }}.parameters).length > 0">
                <button
                    type="button"
                    x-on:click="showData = !showData"
                    style="display:inline-flex;align-items:center;gap:0.375rem;padding:0.375rem 0.625rem;font-size:0.8125rem;font-weight:500;border-radius:0.375rem;cursor:pointer;"
                    class="border border-gray-300 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700"
                    title="{{ __('Toggle Event Details and Payload JSON') }}"
                >
                    <x-filament::icon icon="heroicon-m-code-bracket" style="width:0.9375rem;height:0.9375rem;" />
                    <span x-text="showData ? '{{ __('Hide Data') }}' : '{{ __('Raw Data') }}'"></span>
                </button>
            </template>
        </div>
    </div>

    {{-- Event Title & Message --}}
    <div>
        <h4 class="petkit-player-details__title text-gray-950 dark:text-white" x-text="{{ $itemAccessor }}.title || '{{ __('Event capture') }}'"></h4>
        <template x-if="{{ $itemAccessor }}.message">
            <p class="petkit-player-details__message" x-text="{{ $itemAccessor }}.message"></p>
        </template>
    </div>

    {{-- Collapsible Raw JSON Parameters Viewer --}}
    <div
        x-show="showData"
        x-cloak
        x-transition
        class="petkit-player-details__json"
    >
        <pre x-text="JSON.stringify({{ $itemAccessor }}.parameters || {{ $itemAccessor }}, null, 2)"></pre>
    </div>
</div>
