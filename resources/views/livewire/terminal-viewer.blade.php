<div wire:poll.2s class="bg-gray-900 rounded-lg p-4 max-h-[500px] overflow-y-auto">
    <div class="flex justify-between items-center mb-2 border-b border-gray-700 pb-2">
        <span class="text-xs font-mono text-gray-400">Durum: {{ $status }}</span>
        <span class="text-xs font-mono text-gray-500">Son Güncelleme: {{ now()->format('H:i:s') }}</span>
    </div>
    <pre class="text-green-400 text-xs font-mono whitespace-pre-wrap break-words font-fire-code">{{ $output }}</pre>
</div>
