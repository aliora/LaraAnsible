<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Forms;
use Filament\Schemas\Components\Group;
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
     * Task select (live) + a reactive group that renders the selected job's declared
     * inputs (TaskTemplate.input_vars). Collected under the `vars` key and passed to the
     * playbook as --extra-vars. Use in any launch modal.
     */
    public static function jobInputsSchema(string $taskField = 'task_template_id'): array
    {
        return [
            static::taskTemplateSelect($taskField)->live(),
            Group::make()
                ->key('job_inputs_group')
                ->schema(fn ($get) => static::inputFieldsFor($get($taskField)))
                ->columns(1)
                ->columnSpanFull(),
        ];
    }

    /**
     * Build form fields from a job's declared input definitions. Field names are
     * "vars.<name>" so submitted values arrive under $data['vars'].
     */
    public static function inputFieldsFor($taskTemplateId): array
    {
        if (blank($taskTemplateId)) {
            return [];
        }

        $defs = TaskTemplate::find($taskTemplateId)?->input_vars ?? [];
        if (! is_array($defs)) {
            return [];
        }

        $fields = [];
        foreach ($defs as $def) {
            $name = $def['name'] ?? null;
            if (blank($name)) {
                continue;
            }

            // Flat field name (the raw var name) — a nested "vars.<name>" statePath does
            // not persist reliably from a reactive closure, so the value never reaches
            // the server and ->required() wrongly fails. A stable ->key() keeps the
            // field's state across Livewire morphs. Collected via collectInputVars().
            // ->live() is essential: the field is rebuilt on every render of the reactive
            // container, so its value must be committed to the server on change (a plain,
            // non-live select only syncs on submit and the value is lost to the re-render,
            // arriving as null). Native select (no Choices.js) binds reliably here.
            $field = Forms\Components\Select::make($name)
                ->key('jobinput_'.$name)
                ->options(is_array($def['options'] ?? null) ? $def['options'] : [])
                ->live()
                ->label($def['label'] ?? $name)
                ->required((bool) ($def['required'] ?? false));

            if (isset($def['default']) && $def['default'] !== '') {
                $field->default($def['default']);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Collect launch-modal input values into a flat variables map for --extra-vars.
     * Every submitted key except the reserved ones (task select / target hosts) is
     * treated as a job input. Also folds a legacy nested `vars` array if present.
     */
    public static function collectInputVars(array $data, array $except = ['task_template_id', 'inventory_ids']): array
    {
        $vars = is_array($data['vars'] ?? null) ? $data['vars'] : [];

        foreach ($data as $key => $value) {
            if ($key === 'vars' || in_array($key, $except, true)) {
                continue;
            }
            $vars[$key] = $value;
        }

        $vars = array_filter($vars, fn ($v) => $v !== null && $v !== '' && $v !== []);

        // Ansible CLI/vars_prompt always yields strings; PHP turns numeric-string select
        // keys into ints, which then mismatch string-keyed dicts (be_backends["1"] vs [1]).
        // Cast scalars to string so --extra-vars matches what the playbook expects.
        return array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $vars);
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
