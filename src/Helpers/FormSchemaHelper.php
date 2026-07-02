<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Filament\Forms;
use Filament\Schemas\Components\Group;
use Illuminate\Support\Str;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class FormSchemaHelper
{
    public static function taskTemplateSelect(string $name = 'task_template_id'): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label(__('laraansible::laraansible.task'))
            ->options(fn () => TaskTemplate::where('is_active', true)->pluck('name', 'id'))
            ->searchable()
            ->required()
            ->helperText(__('laraansible::laraansible.task_select_help'));
    }

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
     * Build form fields from a job's declared input definitions (TaskTemplate.input_vars).
     *
     * Fields must keep a flat name, a stable ->key() and ->live(): a nested
     * "vars.<name>" statePath or a non-live select loses its value across
     * Livewire morphs of the reactive container, arriving as null on submit.
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
     *
     * Scalars are cast to string: Ansible vars are always strings, while PHP turns
     * numeric-string select keys into ints that then mismatch string-keyed dicts.
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

        return array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $vars);
    }

    public static function parentTableSelect(string $name = 'dynamic_parent_id'): Forms\Components\Select
    {
        $setting = AnsibleSetting::getActive();

        return Forms\Components\Select::make($name)
            ->label(__('laraansible::laraansible.dynamic_source'))
            ->options($setting?->parentOptions() ?? [])
            ->searchable()
            ->helperText($setting && $setting->parent_table
                ? __('laraansible::laraansible.dynamic_source_help', ['table' => $setting->parent_table])
                : __('laraansible::laraansible.configure_settings_first'));
    }

    public static function formatLabel(?string $text, string $default = ''): string
    {
        if (blank($text)) {
            return $default;
        }

        return Str::title(
            Str::replace('_', ' ',
                Str::singular($text)
            )
        );
    }
}
