<div @if(! $isFinished) wire:poll.2s @endif class="bg-gray-900 rounded-lg p-4">
    <div class="flex justify-between items-center mb-2 border-b border-gray-700 pb-2">
        <span class="text-xs font-mono text-gray-400">Durum: {{ $status }}</span>
        <span class="text-xs font-mono text-gray-500">Son Güncelleme: {{ now()->format('H:i:s') }}</span>
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
        <pre class="text-green-400 text-xs font-mono whitespace-pre-wrap break-words font-fire-code" style="margin: 0;">{{ $output }}</pre>
    </div>
</div>
