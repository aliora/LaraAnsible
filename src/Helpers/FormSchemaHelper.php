<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Forms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class FormSchemaHelper
{
    /**
     * Get a select component for task templates.
     */
    public static function taskTemplateSelect(string $name = 'task_template_id'): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label('Görev')
            ->options(fn () => TaskTemplate::where('is_active', true)->pluck('name', 'id'))
            ->searchable()
            ->required()
            ->helperText('Çalıştırılacak Ansible görevini seçin');
    }

    /**
     * Get a select component for parent table (dynamic source).
     */
    public static function parentTableSelect(string $name = 'dynamic_parent_id'): Forms\Components\Select
    {
        $setting = AnsibleSetting::getActive();
        $options = $setting ? static::fetchParentOptions($setting) : [];

        return Forms\Components\Select::make($name)
            ->label('Dinamik Kaynak')
            ->options($options)
            ->searchable()
            ->helperText($setting ? "'{$setting->parent_table}' tablosundan seçim yapın" : 'Önce Ansible ayarlarını yapılandırın');
    }

    /**
     * Fetch parent table options with caching.
     */
    protected static function fetchParentOptions(AnsibleSetting $setting): array
    {
        if (! $setting->parent_table) {
            return [];
        }

        return Cache::remember("ansible_parent_options_{$setting->id}", 300, function () use ($setting) {
            try {
                $labelColumn = $setting->parent_label_column ?? 'name';
                $parents = DB::table($setting->parent_table)->get();

                $options = [];
                foreach ($parents as $parent) {
                    $options[$parent->id] = $parent->{$labelColumn} ?? "#{$parent->id}";
                }

                return $options;
            } catch (\Exception $e) {
                return [];
            }
        });
    }


    /**
     * Format a string for display as a label.
     * Converts "table_names" to "Table Name" and "column_names" to "Column Name".
     */
    public static function formatLabel(?string $text, string $default = ''): string
    {
        if (blank($text)) {
            return $default;
        }

        return \Illuminate\Support\Str::title(
            \Illuminate\Support\Str::replace('_', ' ',
                \Illuminate\Support\Str::singular($text)
            )
        );
    }
}
