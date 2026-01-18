@php
    use VisioSoft\LaraAnsible\Helpers\ProgressBarHelper;
    
    $record = $getRecord();
    $processed = $record->processed_hosts ?? 0;
    $total = $record->total_hosts ?? 0;
    
    $progressPercent = ProgressBarHelper::calculateProgress($processed, $total, $record->progress ?? null);
    $colorName = ProgressBarHelper::getColorName($progressPercent);
    $colorVariable = ProgressBarHelper::resolveColor($colorName);
    $bgClass = ProgressBarHelper::getBgClass($colorName);
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
