<?php

namespace VisioSoft\LaraAnsible\Helpers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use VisioSoft\LaraAnsible\Models\AnsibleTemplate;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

/**
 * Auto-detecting importer for a mixed batch of Ansible files.
 *
 * Each uploaded file is classified by extension + content and routed:
 *   - playbook (.yml with hosts:/import_playbook:) -> TaskTemplate (a "Job"),
 *     with input_vars auto-detected from its vars_prompt blocks.
 *   - task (.yml without hosts:)                   -> AnsibleTemplate kind=task
 *   - template (.j2 or other config file)          -> AnsibleTemplate kind=template
 *   - inventory (.ini)                             -> skipped (separate feature)
 *
 * Names are idempotent keys (updateOrCreate): jobs keyed by filename WITHOUT
 * extension, library files keyed by filename WITH extension so the runtime can
 * resolve `import_tasks: tasks/<name>` / `template: src=templates/<name>`.
 */
class AnsibleImporter
{
    /**
     * Classify a single file. Returns one of: playbook | task | template | skip.
     */
    public static function classify(string $filename, string $content): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'j2') {
            return 'template';
        }

        if ($ext === 'ini') {
            return 'skip';
        }

        if (! in_array($ext, ['yml', 'yaml'], true)) {
            return 'template';
        }

        try {
            $parsed = Yaml::parse($content);

            if (is_array($parsed) && array_is_list($parsed)) {
                foreach ($parsed as $play) {
                    if (is_array($play) && (array_key_exists('hosts', $play) || array_key_exists('import_playbook', $play))) {
                        return 'playbook';
                    }
                }
            }

            return 'task';
        } catch (\Throwable $e) {
            if (preg_match('/^\s*-\s*(hosts|import_playbook)\s*:/m', $content)) {
                return 'playbook';
            }

            return 'task';
        }
    }

    /**
     * Import a batch of stored file paths (on the 'local' disk). Consumes the
     * files (deletes them after reading). Returns counts per kind.
     *
     * @param  array<int, string>  $storedPaths
     * @return array{jobs:int, tasks:int, templates:int, skipped:int}
     */
    public static function import(array $storedPaths): array
    {
        $counts = ['jobs' => 0, 'tasks' => 0, 'templates' => 0, 'skipped' => 0];

        foreach ($storedPaths as $path) {
            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                continue;
            }

            static::importOne(basename($path), Storage::disk('local')->get($path), $counts);

            Storage::disk('local')->delete($path);
        }

        return $counts;
    }

    /**
     * Classify one file and upsert it into the right model, tallying $counts.
     *
     * @param  array{jobs:int, tasks:int, templates:int, skipped:int}  $counts
     */
    protected static function importOne(string $filename, string $content, array &$counts): void
    {
        switch (static::classify($filename, $content)) {
            case 'playbook':
                TaskTemplate::updateOrCreate(
                    ['name' => pathinfo($filename, PATHINFO_FILENAME)],
                    [
                        'playbook_content' => $content,
                        'is_active' => true,
                        'input_vars' => static::detectInputVars($content),
                    ],
                );
                $counts['jobs']++;
                break;
            case 'task':
                AnsibleTemplate::updateOrCreate(
                    ['name' => $filename],
                    ['content' => $content, 'kind' => 'task', 'is_active' => true],
                );
                $counts['tasks']++;
                break;
            case 'template':
                AnsibleTemplate::updateOrCreate(
                    ['name' => $filename],
                    ['content' => $content, 'kind' => 'template', 'is_active' => true],
                );
                $counts['templates']++;
                break;
            default:
                $counts['skipped']++;
                break;
        }
    }

    public static function summarize(array $counts): string
    {
        $summary = __('laraansible::laraansible.import_result', [
            'jobs' => $counts['jobs'] ?? 0,
            'tasks' => $counts['tasks'] ?? 0,
            'templates' => $counts['templates'] ?? 0,
        ]);

        if (($counts['skipped'] ?? 0) > 0) {
            $summary .= __('laraansible::laraansible.import_result_skipped', ['skipped' => $counts['skipped']]);
        }

        return $summary;
    }

    /**
     * Parse Ansible `vars_prompt` blocks out of a playbook and map them to input
     * definitions (name/label/default/options/required). Choices aren't expressible
     * in vars_prompt, so selects still need their options set by hand afterwards.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function detectInputVars(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($parsed)) {
            return [];
        }

        $out = [];
        foreach ($parsed as $play) {
            if (! is_array($play) || empty($play['vars_prompt']) || ! is_array($play['vars_prompt'])) {
                continue;
            }

            foreach ($play['vars_prompt'] as $vp) {
                if (! is_array($vp) || blank($vp['name'] ?? null)) {
                    continue;
                }

                $label = trim(strtok((string) ($vp['prompt'] ?? ''), "\n"));

                $out[] = [
                    'name' => $vp['name'],
                    'label' => $label !== '' ? $label : $vp['name'],
                    'default' => (string) ($vp['default'] ?? ''),
                    'options' => [],
                    'required' => true,
                ];
            }
        }

        return $out;
    }
}
