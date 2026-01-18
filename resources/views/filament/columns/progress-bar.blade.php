@php
    $record = $getRecord();
    $processed = $record->processed_hosts ?? 0;
    $total = $record->total_hosts ?? 0;
    
    // Use the stored progress percentage if available, otherwise calculate fallback
    $progressPercent = $record->progress ?? 0;
    if ($progressPercent == 0 && $total > 0 && $processed > 0) {
        $progressPercent = ($processed / $total) * 100;
    }
    
    $progressPercent = round(max(0, min(100, $progressPercent)), 1);

    // Determine semantic color name based on progress
    $colorName = match (true) {
        $progressPercent >= 100 => 'success',
        $progressPercent >= 50 => 'info',
        $progressPercent > 0 => 'warning',
        default => 'gray',
    };

    // Try to get the color from Filament theme
    $colorVariable = null;
    $bgClass = "bg-{$colorName}-600"; // Darker shade for better visibility? Standard is 500 or 600.
    
    try {
        $colors = \Filament\Support\Facades\FilamentColor::getColors();
        if (isset($colors[$colorName])) {
            $colorValue = $colors[$colorName];
            // If it's an array {50:..., 500:...}, pick 600 (better contrast) or 500
            if (is_array($colorValue)) {
                $shade = $colorValue[600] ?? $colorValue[500] ?? $colorValue['DEFAULT'] ?? null;
            } else {
                $shade = $colorValue;
            }

            if ($shade) {
                // Check if it's already a valid CSS color (hex, rgb(), hsl(), oklch())
                // If it contains '(', it's likely a function like oklch(...) or rgb(...)
                // If it starts with #, it's hex.
                if (str_contains($shade, '(') || str_starts_with(trim($shade), '#')) {
                        $colorVariable = $shade;
                } else {
                    // Assume it's a comma separated RGB list (old Filament/Tailwind format)
                    $colorVariable = "rgb({$shade})";
                }
            }
        }
    } catch (\Throwable $e) {
        // Facade might not be available or other error
    }

    // Fallback if variable couldn't be resolved or just to be safe
    if (!$colorVariable) {
        $colorVariable = match ($colorName) {
            'success' => '#22c55e', // green-500
            'info' => '#3b82f6',    // blue-500
            'warning' => '#f59e0b', // amber-500
            'gray' => '#6b7280',    // gray-500
            default => '#d1d5db',
        };
    }
@endphp

<div class="flex items-center gap-2 px-4 py-3" style="min-width: 150px;">
    {{-- Track --}}
    <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden" 
         style="height: 0.5rem; border-radius: 9999px; overflow: hidden;">
        {{-- Bar --}}
        <div class="h-full {{ $bgClass }} rounded-full transition-all duration-300" 
             style="width: {{ $progressPercent }}%; background-color: {{ $colorVariable }}; height: 100%; border-radius: 9999px;"></div>
    </div>
    <span class="text-xs font-medium text-gray-600 dark:text-gray-400 whitespace-nowrap">
        {{ $processed }}/{{ $total }}
    </span>
</div>
