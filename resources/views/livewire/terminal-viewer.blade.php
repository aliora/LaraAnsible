<div @if(! $isFinished) wire:poll.2s @endif x-data="{ copied: false }" class="bg-gray-900 rounded-xl p-4 ring-1 ring-white/10 shadow-xl">
    <div class="flex justify-between items-center mb-3 border-b border-gray-700/70 pb-3">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-mono font-medium ring-1 ring-inset {{ $statusColor }}">
                @unless($isFinished)
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-75"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-current"></span>
                    </span>
                @endunless
                {{ __('laraansible::laraansible.status') }}: {{ $status }}
            </span>
            <span class="text-xs font-mono text-gray-500">{{ __('laraansible::laraansible.last_update') }}: {{ now()->format('H:i:s') }}</span>
        </div>

        <button
            type="button"
            x-on:click="navigator.clipboard.writeText($root.querySelector('pre').textContent); copied = true; setTimeout(() => copied = false, 1500)"
            class="inline-flex items-center gap-1.5 rounded-md bg-white/5 px-2.5 py-1 text-xs font-medium text-gray-300 ring-1 ring-inset ring-white/10 transition hover:bg-white/10 hover:text-white"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-3.5 w-3.5">
                <path stroke-linecap="round" stroke-linejoin="round" x-show="!copied" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                <path stroke-linecap="round" stroke-linejoin="round" x-show="copied" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <span x-text="copied ? '{{ __('laraansible::laraansible.copied') }}' : '{{ __('laraansible::laraansible.copy') }}'"></span>
        </button>
    </div>

    {{-- Fixed-height, scrollable log area: the modal stays put, only this div scrolls. --}}
    <div
        x-data
        x-init="
            const scroll = () => { $el.scrollTop = $el.scrollHeight; };
            scroll();
            new MutationObserver(scroll).observe($el, { childList: true, subtree: true, characterData: true });
        "
        style="height: 65vh; max-height: 65vh; overflow-y: auto; overflow-x: auto;"
    >
        <pre class="text-xs font-mono leading-relaxed whitespace-pre-wrap break-words font-fire-code" style="margin: 0;">{!! $output !!}</pre>
    </div>
</div>
