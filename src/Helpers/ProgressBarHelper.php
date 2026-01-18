<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Support\Facades\FilamentColor;

/**
 * Helper class for progress bar rendering in Filament columns
 * 
 * Provides methods to calculate progress, determine color schemes,
 * and resolve theme-aware colors for progress indicators.
 */
class ProgressBarHelper
{
    /**
     * Calculate progress percentage from processed and total values
     * 
     * @param int $processed Number of completed items
     * @param int $total Total number of items
     * @param int|null $storedProgress Pre-calculated progress to use if available
     * @return float Progress percentage (0-100)
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
     * 
     * @param float $progressPercent Progress percentage (0-100)
     * @return string Color name (success, info, warning, gray)
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
     * 
     * Attempts to retrieve the color from the Filament color palette.
     * Falls back to hardcoded hex values if theme colors are unavailable.
     * 
     * @param string $colorName Semantic color name
     * @return string CSS color value (hex or rgb)
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
     * 
     * @param string $colorName Semantic color name
     * @return string Tailwind CSS class
     */
    public static function getBgClass(string $colorName): string
    {
        return "bg-{$colorName}-600";
    }
}
