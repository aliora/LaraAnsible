@php
    $record = $getRecord();
    $processed = $record->processed_hosts ?? 0;
    $total = $record->total_hosts ?? 0;

    $progressPercent = $record->progress ?? 0;
    if ($progressPercent == 0 && $total > 0 && $processed > 0) {
        $progressPercent = ($processed / $total) * 100;
    }

    $progressPercent = round(max(0, min(100, $progressPercent)), 1);

    $colorName = match (true) {
        $progressPercent >= 100 => 'success',
        $progressPercent >= 50 => 'info',
        $progressPercent > 0 => 'warning',
        default => 'gray',
    };

    $colorVariable = null;
    $bgClass = "bg-{$colorName}-600";

    try {
        $colors = \Filament\Support\Facades\FilamentColor::getColors();
        if (isset($colors[$colorName])) {
            $colorValue = $colors[$colorName];
            if (is_array($colorValue)) {
                $shade = $colorValue[600] ?? $colorValue[500] ?? $colorValue['DEFAULT'] ?? null;
            } else {
                $shade = $colorValue;
            }

            if ($shade) {
                if (str_contains($shade, '(') || str_starts_with(trim($shade), '#')) {
                    $colorVariable = $shade;
                } else {
                    $colorVariable = "rgb({$shade})";
                }
            }
        }
    } catch (\Throwable $e) {
    }

    if (! $colorVariable) {
        $colorVariable = match ($colorName) {
            'success' => '#22c55e',
            'info' => '#3b82f6',
            'warning' => '#f59e0b',
            'gray' => '#6b7280',
            default => '#d1d5db',
        };
    }
@endphp

<div class="flex items-center gap-2 px-4 py-3" style="min-width: 150px;">
    <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden"
         style="height: 0.5rem; border-radius: 9999px; overflow: hidden;">
        <div class="h-full {{ $bgClass }} rounded-full transition-all duration-300"
             style="width: {{ $progressPercent }}%; background-color: {{ $colorVariable }}; height: 100%; border-radius: 9999px;"></div>
    </div>
    <span class="text-xs font-medium text-gray-600 dark:text-gray-400 whitespace-nowrap">
        {{ $processed }}/{{ $total }}
    </span>
</div>
