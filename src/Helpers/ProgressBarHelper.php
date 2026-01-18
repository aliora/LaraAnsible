<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Support\Facades\FilamentColor;

class ProgressBarHelper
{
    /**
     * Calculate progress percentage from processed and total values
     */
    public static function calculateProgress(int $processed, int $total, ?int $storedProgress = null): float
    {
        // Use stored progress if available
        if ($storedProgress !== null && $storedProgress > 0) {
            return round(max(0, min(100, $storedProgress)), 1);
        }

        // Calculate from processed/total
        if ($total > 0 && $processed > 0) {
            $percent = ($processed / $total) * 100;
            return round(max(0, min(100, $percent)), 1);
        }

        return 0.0;
    }

    /**
     * Get semantic color name based on progress percentage
     */
    public static function getColorName(float $progressPercent): string
    {
        return match (true) {
            $progressPercent >= 100 => 'success',
            $progressPercent >= 50 => 'info',
            $progressPercent > 0 => 'warning',
            default => 'gray',
        };
    }

    /**
     * Resolve color value from Filament theme or use fallback
     */
    public static function resolveColor(string $colorName): string
    {
        try {
            $colors = FilamentColor::getColors();
            if (isset($colors[$colorName])) {
                $colorValue = $colors[$colorName];
                
                // If it's an array {50:..., 500:...}, pick 600 or 500
                if (is_array($colorValue)) {
                    $shade = $colorValue[600] ?? $colorValue[500] ?? $colorValue['DEFAULT'] ?? null;
                } else {
                    $shade = $colorValue;
                }

                if ($shade) {
                    // Check if it's already a valid CSS color
                    if (str_contains($shade, '(') || str_starts_with(trim($shade), '#')) {
                        return $shade;
                    }
                    // Assume it's a comma separated RGB list
                    return "rgb({$shade})";
                }
            }
        } catch (\Throwable $e) {
            // Facade might not be available or other error
        }

        // Fallback colors
        return match ($colorName) {
            'success' => '#22c55e', // green-500
            'info' => '#3b82f6',    // blue-500
            'warning' => '#f59e0b', // amber-500
            'gray' => '#6b7280',    // gray-500
            default => '#d1d5db',
        };
    }

    /**
     * Get Tailwind background class for color
     */
    public static function getBgClass(string $colorName): string
    {
        return "bg-{$colorName}-600";
    }
}
