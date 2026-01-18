<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;

class AnsibleService
{
    /**
     * Execute an Ansible deployment
     */
    public function executeDeployment(Deployment $deployment): void
    {
        $deployment->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            Log::info("Starting deployment {$deployment->id}");

            // Create temporary files
            $inventoryData = $this->createInventoryFile($deployment);
            $inventoryPath = $inventoryData['path'];
            $totalHostsCount = $inventoryData['host_count'];
            Log::info("Created inventory file: {$inventoryPath} with {$totalHostsCount} hosts");

            $playbookPath = $this->createPlaybookFile($deployment);
            Log::info("Created playbook file: {$playbookPath}");

            // Create templates directory and files
            $this->createTemplateFiles($deployment, $playbookPath);
            Log::info('Created template files');

            // Build ansible-playbook command
            $commandData = $this->buildAnsibleCommand($deployment, $inventoryPath, $playbookPath);
            Log::info("Command to execute: {$commandData['display_command']}");

            // Get total tasks count
            $totalPlaybookTasks = $this->getTotalTasks($deployment, $inventoryPath, $playbookPath);
            $totalTasks = max(1, $totalPlaybookTasks);
            Log::info("Total playbook tasks: {$totalTasks}");

            // Store command input before execution
            $commandInput = "=== Command ===\n";
            $commandInput .= $commandData['display_command']."\n\n";
            $commandInput .= "=== Inventory File ({$inventoryPath}) ===\n";
            $commandInput .= file_get_contents($inventoryPath)."\n\n";
            $commandInput .= "=== Playbook File ({$playbookPath}) ===\n";
            $commandInput .= file_get_contents($playbookPath);

            $deployment->update([
                'command_input' => $commandInput,
                // Use total_hosts/processed_hosts to track task progress (X/Y) in the UI.
                'total_hosts' => $totalTasks,
                'processed_hosts' => 0,
            ]);

            // Execute the command with streaming output
            $outputBuffer = '';
            $completedTasks = 0;
            $totalTasks = max(1, $totalTasks);

            // Use an unlimited timeout to allow long-running Ansible playbooks
            $result = Process::forever()
                ->env([
                    'PYTHONUNBUFFERED' => '1',
                    'ANSIBLE_STDOUT_CALLBACK' => 'default',
                ])
                ->run($commandData['wrapped_command'], function ($type, $output) use (&$outputBuffer, &$completedTasks, $totalTasks, $deployment) {
                $outputBuffer .= $output;

                // Parse Ansible output to track progress

                // Count tasks in the accumulated output buffer.
                // TASK lines are emitted once per task (not per host).
                $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $outputBuffer);
                $completedTasks = 0;
                if (preg_match_all('/^\s*TASK \[.*\]/m', $cleanOutput, $matches)) {
                    $completedTasks = count($matches[0]);
                }

                $processedTasks = min($completedTasks, $totalTasks);

                // Calculate progress percentage based on TASKS
                $progress = 0;
                if ($totalTasks > 0) {
                    $progress = min(100, (int) round(($processedTasks / $totalTasks) * 100));
                }

                // If we see PLAY RECAP, we're likely done or close to it.
                if (str_contains($cleanOutput, 'PLAY RECAP')) {
                    $progress = 100;
                    $processedTasks = $totalTasks;
                }

                // Update deployment with partial output and progress for real-time viewing
                $deployment->update([
                    'command_output' => $outputBuffer,
                    'progress' => $progress,
                    'processed_hosts' => $processedTasks,
                ]);
            });

            Log::info("Command executed with exit code: {$result->exitCode()}");

            // Final update with complete output and status
            $finalOutput = $result->output();
            $deployment->update([
                'status' => $this->resolveDeploymentStatus($finalOutput, $result->exitCode()),
                'command_output' => $finalOutput,
                'exit_code' => $result->exitCode(),
                'completed_at' => now(),
                'progress' => 100, // Ensure it's 100 on completion
            ]);

            if (! $result->successful()) {
                Log::error("Deployment {$deployment->id} failed with exit code {$result->exitCode()}", [
                    'output' => $result->output(),
                    'error_output' => $result->errorOutput(),
                ]);
            }

            // Clean up temporary files
            $this->cleanup($inventoryPath, $playbookPath);
            Log::info("Deployment {$deployment->id} completed");

        } catch (\Exception $e) {
            Log::error("Deployment {$deployment->id} exception: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            $deployment->update([
                'status' => 'failed',
                'command_output' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Create temporary inventory file or use existing inventory file
     * Returns array with 'path' and 'host_count'
     */
    protected function createInventoryFile(Deployment $deployment): array
    {
        // Debug: Log deployment info
        Log::info("Creating inventory for deployment {$deployment->id}");
        Log::info('Deployment inventory_ids: '.json_encode($deployment->inventory_ids));
        Log::info('Deployment inventory_file: '.($deployment->inventory_file ?? 'null'));

        // If an inventory file is specified, use it directly
        // If an inventory file is specified, use it directly (assume 1 host or we count later? Difficult without parsing)
        // For custom inventory files, we might just have to scan it or default to 1 count logic if not parsed.
        // Let's try to count lines with "ansible_host" as a heuristic for custom files too.
        if ($deployment->inventory_file && file_exists($deployment->inventory_file)) {
             $content = file_get_contents($deployment->inventory_file);
             $count = substr_count($content, 'ansible_host='); // Basic heuristic
             return [
                 'path' => $deployment->inventory_file,
                 'host_count' => max(1, $count)
             ];
        }

        $inventoryIds = $deployment->inventory_ids ?? [];
        $staticInventoryIds = [];
        $dynamicInventoryIds = [];

        // Separate static and dynamic IDs
        foreach ($inventoryIds as $id) {
            if ($id === 'all') {
                $staticInventoryIds = ['all'];
                // For dynamic, we need to fetch all from settings if 'all' is supported there,
                // but currently 'all' usually means all static servers.
                // Let's assume 'all' only applies to static for backward compatibility or handle it if needed.
                // For now, if 'all' is selected, we get all static.
                // If users want all dynamic, they can select all groups.
                break;
            }

            if (is_string($id) && str_starts_with($id, 'dynamic_')) {
                $dynamicInventoryIds[] = $id;
            } else {
                $staticInventoryIds[] = $id;
            }
        }

        $inventories = collect();

        // 1. Handle Static Inventories
        if (! empty($staticInventoryIds)) {
            if (in_array('all', $staticInventoryIds)) {
                $inventories = Inventory::where('is_active', true)->get();
            } else {
                $inventories = Inventory::whereIn('id', $staticInventoryIds)->get();
            }
        }

        // Debug: Log inventory query results
        Log::info('Static Inventory IDs: '.json_encode($staticInventoryIds));
        Log::info('Dynamic Inventory IDs: '.json_encode($dynamicInventoryIds));
        Log::info('Inventories found: '.$inventories->count());
        foreach ($inventories as $inv) {
            Log::info("Inventory: id={$inv->id}, name={$inv->name}, hostname={$inv->hostname}, is_active={$inv->is_active}");
        }

        // 2. Handle Dynamic Inventories
        if (! empty($dynamicInventoryIds)) {
            $setting = AnsibleSetting::getActive();
            if ($setting && $setting->child_table) {
                foreach ($dynamicInventoryIds as $dynamicId) {
                    // key format: dynamic_{child_id}_{hostname}
                    // We only need child_id to look up the record again to be safe and get fresh data
                    $parts = explode('_', $dynamicId);
                    if (count($parts) >= 3) {
                        $childId = $parts[1];

                        try {
                            $child = \DB::table($setting->child_table)->find($childId);
                            if ($child) {
                                // Create a temporary Inventory object or array structure
                                $inventory = new Inventory;
                                $inventory->hostname = $child->{$setting->child_hostname_column} ?? null;
                                // Use direct SSH settings
                                $inventory->port = $setting->ssh_port ?? 22;
                                $inventory->username = $setting->ssh_username ?? 'root';

                                // Dynamic items don't have a keystore relation unless we add logic for it.
                                // For now, we assume they rely on SSH agent, passwordless access, or provided arguments.

                                if ($inventory->hostname) {
                                    $inventories->push($inventory);
                                }
                            }
                        } catch (\Exception $e) {
                            Log::warning("AnsibleService: Failed to fetch dynamic inventory item {$dynamicId}: ".$e->getMessage());
                        }
                    }
                }
            }
        }

        $content = '';
        $setting = AnsibleSetting::getInstance();

        Log::info('CODE VERSION: 2025-01-18-18:00 - BEFORE GROUPING');

        // Track all hostnames for the [all] group
        $allHosts = [];

        // Group inventories by parent
        $groupedInventories = [];
        $ungroupedInventories = [];

        foreach ($inventories as $inventory) {
            if ($inventory->source_type === 'dynamic' && $inventory->dynamic_child_id && $setting && $setting->child_table) {
                try {
                    $foreignKey = $setting->child_parent_foreign_key ?? 'parent_id';
                    $child = \DB::table($setting->child_table)->find($inventory->dynamic_child_id);

                    if ($child && isset($child->{$foreignKey})) {
                        $parentId = $child->{$foreignKey};

                        if ($setting->parent_table) {
                            $parent = \DB::table($setting->parent_table)->find($parentId);
                            $groupName = $parent ? ($parent->{$setting->parent_label_column ?? 'name'} ?? 'unknown') : 'unknown';

                            // Sanitize group name for Ansible
                            $groupName = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($groupName));

                            if (! isset($groupedInventories[$groupName])) {
                                $groupedInventories[$groupName] = [];
                            }

                            $groupedInventories[$groupName][] = $inventory;
                        } else {
                            $ungroupedInventories[] = $inventory;
                        }
                    } else {
                        $ungroupedInventories[] = $inventory;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to group inventory {$inventory->id}: ".$e->getMessage());
                    $ungroupedInventories[] = $inventory;
                }
            } else {
                $ungroupedInventories[] = $inventory;
            }
        }

        // Build inventory content with groups
        Log::info('Building grouped inventories. Count: '.count($groupedInventories));
        foreach ($groupedInventories as $groupName => $groupInventories) {
            Log::info("Processing group: {$groupName}, inventories count: ".count($groupInventories));
            $content .= "[{$groupName}]\n";
            Log::info('Content after group header: '.json_encode($content));

            $groupVars = [];
            $groupKeystores = [];
            $groupUsers = [];
            $groupPorts = [];

            foreach ($groupInventories as $inventory) {
                Log::info("Processing inventory in group: id={$inventory->id}, hostname={$inventory->hostname}");
                $hostsEntry = Inventory::normalizeHostsEntry($inventory->hosts_entry ?? []);
                if (empty($hostsEntry) && ! empty($inventory->hostname)) {
                    $hostsEntry = [$inventory->name ?? $inventory->hostname => $inventory->hostname];
                }

                if (empty($hostsEntry)) {
                    Log::info('Skipping inventory with empty hostname');

                    continue;
                }

                // Collect all unique values for group vars
                if ($inventory->username) {
                    $groupUsers[$inventory->username] = true;
                }
                if ($inventory->port) {
                    $groupPorts[$inventory->port] = true;
                }
                if ($inventory->keystore) {
                    $groupKeystores[$inventory->keystore->id] = $inventory->keystore;
                }

                foreach ($hostsEntry as $hostName => $hostValue) {
                    $alias = trim((string) $hostName);
                    $hostIp = trim((string) $hostValue);

                    if ($alias === '' || $hostIp === '') {
                        continue;
                    }

                    $hostLine = "{$alias} ansible_host={$hostIp}";

                    // Track for [all] group
                    $allHosts[] = $hostLine;

                    $content .= $hostLine."\n";
                    Log::info('Content after adding host: '.json_encode(substr($content, -200)));
                }
            }

            Log::info("Finished processing group {$groupName}, content length: ".strlen($content));

            // Add group vars section
            $hasGroupVars = false;
            $groupVarsContent = '';

            // If all hosts use same user, set as group var
            if (count($groupUsers) === 1) {
                $groupVarsContent .= 'ansible_user='.array_key_first($groupUsers)."\n";
                $hasGroupVars = true;
            }

            // If all hosts use same port, set as group var
            if (count($groupPorts) === 1) {
                $groupVarsContent .= 'ansible_port='.array_key_first($groupPorts)."\n";
                $hasGroupVars = true;
            }

            // If all hosts use same keystore, set as group var
            if (count($groupKeystores) === 1) {
                $keystore = reset($groupKeystores);
                $keystorePath = $this->createTempKeyFile($keystore);
                $groupVarsContent .= "ansible_ssh_private_key_file={$keystorePath}\n";
                $hasGroupVars = true;
            }

            if ($hasGroupVars) {
                $content .= "[{$groupName}:vars]\n";
                $content .= $groupVarsContent;
            }

            $content .= "\n";
        }

        // Add ungrouped inventories
        Log::info('Building ungrouped inventories. Count: '.count($ungroupedInventories));
        if (! empty($ungroupedInventories)) {
            $content .= "[ungrouped]\n";

            foreach ($ungroupedInventories as $inventory) {
                Log::info("Processing ungrouped inventory: id={$inventory->id}, hostname={$inventory->hostname}, script=".(! empty($inventory->script) ? 'YES' : 'NO'));
                if (! empty($inventory->script) && $inventory->source_type !== 'dynamic') {
                    Log::info('Skipping script-based inventory');

                    continue; // Skip script-based inventories for now
                }

                $hostsEntry = Inventory::normalizeHostsEntry($inventory->hosts_entry ?? []);
                if (empty($hostsEntry) && ! empty($inventory->hostname)) {
                    $hostsEntry = [$inventory->name ?? $inventory->hostname => $inventory->hostname];
                }

                foreach ($hostsEntry as $hostName => $hostValue) {
                    $alias = trim((string) $hostName);
                    $hostIp = trim((string) $hostValue);

                    if ($alias === '' || $hostIp === '') {
                        continue;
                    }

                    $line = "{$alias} ansible_host={$hostIp}";

                    if ($inventory->port) {
                        $line .= " ansible_port={$inventory->port}";
                    }
                    if ($inventory->username) {
                        $line .= " ansible_user={$inventory->username}";
                    }

                    if ($inventory->keystore) {
                        $keyPath = $this->createTempKeyFile($inventory->keystore);
                        $line .= " ansible_ssh_private_key_file={$keyPath}";
                    }

                    // Track for [all] group
                    $allHosts[] = $line;

                    $content .= $line."\n";
                    Log::info('Added ungrouped host, content length now: '.strlen($content));
                }
            }

            $content .= "\n";
        }

        // Append script-based inventories at the end
        foreach ($inventories as $inventory) {
            if (! empty($inventory->script) && $inventory->source_type !== 'dynamic') {
                $content .= $inventory->script."\n";

                foreach ($this->extractHostLinesFromInventoryScript($inventory->script) as $hostLine) {
                    $allHosts[] = $hostLine;
                }
            }
        }

        // Prepend [gate_server] and [all] groups at the beginning if we have hosts
        if (! empty($allHosts)) {
            // Remove duplicates
            $allHosts = array_unique($allHosts);

            // Create [gate_server] group with all hosts directly
            $gateServerContent = "[gate_server]\n";
            foreach ($allHosts as $hostLine) {
                $gateServerContent .= $hostLine."\n";
            }
            $gateServerContent .= "\n";

            // Also create [all] group for compatibility
            $allGroupContent = "[all]\n";
            foreach ($allHosts as $hostLine) {
                $allGroupContent .= $hostLine."\n";
            }
            $allGroupContent .= "\n";

            $content = $gateServerContent.$allGroupContent.$content;
        }

        $path = storage_path('app/ansible/inventory_'.$deployment->id.'.ini');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);

        // Debug: Log inventory content
        Log::info("Inventory file created at: {$path}");
        Log::info("Inventory content:\n{$content}");
        Log::info('Total hosts collected: '.count($allHosts));

        return [
            'path' => $path,
            'host_count' => count($allHosts)
        ];
    }

    /**
     * Extract host entries from an inventory script.
     *
     * @return array<int, string>
     */
    protected function extractHostsFromInventoryScript(string $script): array
    {
        $hosts = [];
        $currentSection = 'hosts';
        $lines = preg_split("/\r\n|\n|\r/", $script) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
                $header = strtolower($matches[1]);
                if (str_contains($header, ':vars')) {
                    $currentSection = 'vars';
                } elseif (str_contains($header, ':children')) {
                    $currentSection = 'children';
                } else {
                    $currentSection = 'hosts';
                }

                continue;
            }

            if ($currentSection !== 'hosts') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            $first = $parts[0] ?? '';

            if ($first === '' || str_contains($first, '=')) {
                continue;
            }

            $hosts[] = $first;
        }

        return $hosts;
    }

    protected function extractHostLinesFromInventoryScript(string $script): array
    {
        $hostLines = [];
        $currentSection = 'hosts';
        $lines = preg_split("/\r\n|\n|\r/", $script) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
                $header = strtolower($matches[1]);
                if (str_contains($header, ':vars')) {
                    $currentSection = 'vars';
                } elseif (str_contains($header, ':children')) {
                    $currentSection = 'children';
                } else {
                    $currentSection = 'hosts';
                }

                continue;
            }

            if ($currentSection !== 'hosts') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            $first = $parts[0] ?? '';

            if ($first === '' || str_contains($first, '=')) {
                continue;
            }

            // Return the full line if it contains ansible_host
            if (str_contains($line, 'ansible_host=')) {
                $hostLines[] = $line;
            } else {
                $hostLines[] = $first;
            }
        }

        return $hostLines;
    }

    /**
     * Create temporary playbook file
     */
    protected function createPlaybookFile(Deployment $deployment): string
    {
        $taskTemplate = $deployment->taskTemplate;

        $content = $taskTemplate->playbook_content;

        if (! $content && $taskTemplate->playbook_path && file_exists($taskTemplate->playbook_path)) {
            $content = file_get_contents($taskTemplate->playbook_path);
        }

        if (! $content) {
            throw new \Exception('No playbook content or valid playbook path found');
        }

        $path = storage_path('app/ansible/playbook_'.$deployment->id.'.yml');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Create template files from AnsibleTemplate model
     */
    protected function createTemplateFiles(Deployment $deployment, string $playbookPath): void
    {
        $templates = $deployment->taskTemplate->templates ?? [];

        if (empty($templates)) {
            return;
        }

        $playbookDir = dirname($playbookPath);
        $templatesDir = $playbookDir.'/templates';

        if (! is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }

        foreach ($templates as $template) {
            $path = $templatesDir.'/'.$template['name'];
            file_put_contents($path, $template['content']);
        }
    }

    /**
     * Create temporary SSH key file
     */
    protected function createTempKeyFile(Keystore $keystore): string
    {
        $path = storage_path('app/ansible/keys/key_'.$keystore->id);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, $keystore->private_key);
        chmod($path, 0600);

        return $path;
    }

    /**
     * Build Ansible command
     */
    protected function buildAnsibleCommand(Deployment $deployment, string $inventoryPath, string $playbookPath): array
    {
        // Build base ansible-playbook command with correct order
        $command = "ansible-playbook -i {$inventoryPath} {$playbookPath}";

        // Add extra vars from task template
        if ($deployment->taskTemplate->extra_vars) {
            $extraVars = json_encode($deployment->taskTemplate->extra_vars);
            $command .= ' --extra-vars '.escapeshellarg($extraVars);
        }

        // Add CLI flags from checkboxes
        if ($deployment->cli_flags && is_array($deployment->cli_flags)) {
            foreach ($deployment->cli_flags as $flag) {
                $command .= ' '.$flag;
            }
        }

        // Add limit hosts
        if ($deployment->limit_hosts) {
            $command .= ' --limit '.escapeshellarg($deployment->limit_hosts);
        }

        // Add tags
        if ($deployment->tags) {
            $command .= ' --tags '.escapeshellarg($deployment->tags);
        }

        // Add skip-tags
        if ($deployment->skip_tags) {
            $command .= ' --skip-tags '.escapeshellarg($deployment->skip_tags);
        }

        // Add forks
        if ($deployment->forks) {
            $command .= ' --forks '.intval($deployment->forks);
        }

        // Add start-at-task
        if ($deployment->start_at_task) {
            $command .= ' --start-at-task '.escapeshellarg($deployment->start_at_task);
        }

        // Add remote user
        if ($deployment->remote_user) {
            $command .= ' --user '.escapeshellarg($deployment->remote_user);
        }

        // Add extra CLI arguments from deployment
        if ($deployment->extra_args) {
            $command .= ' '.trim($deployment->extra_args);
        }

        return [
            'display_command' => $command,
            'wrapped_command' => $command,
        ];
    }

    /**
     * Get total tasks count executing ansible-playbook with --list-tasks
     */
    protected function getTotalTasks(Deployment $deployment, string $inventoryPath, string $playbookPath): int
    {
        // Build base ansible-playbook command with correct order
        $command = "ansible-playbook -i {$inventoryPath} {$playbookPath} --list-tasks";

        // Add extra vars/args if needed to ensure valid execution context, but --list-tasks might skip them.
        // However, if variables are required for task conditional inclusion, we might need them.
        if ($deployment->taskTemplate->extra_vars) {
            $extraVars = json_encode($deployment->taskTemplate->extra_vars);
            $command .= ' --extra-vars '.escapeshellarg($extraVars);
        }

        // Add limit hosts
        if ($deployment->limit_hosts) {
            $command .= ' --limit '.escapeshellarg($deployment->limit_hosts);
        }

        // Add tags
        if ($deployment->tags) {
            $command .= ' --tags '.escapeshellarg($deployment->tags);
        }

        // Add skip-tags
        if ($deployment->skip_tags) {
            $command .= ' --skip-tags '.escapeshellarg($deployment->skip_tags);
        }

        try {
            $result = Process::run($command);

            if ($result->successful()) {
                $output = $result->output();
                // Count tasks in output.
                // Output format:
                //   playbook: ...
                //     play: ...
                //       tasks:
                //         Task Name
                //         Another Task

                // Or:
                // tasks:
                //   TAGS: []
                //   Task Name

                // Simple approach: count non-empty lines under "tasks:" ??
                // Better approach: count lines that look like task names.
                // Actually `ansible-playbook --list-tasks` output is:
                // playbook: playbook_1.yml
                //   play: all
                //     tasks:
                //       Task 1
                //       Task 2

                $lines = explode("\n", $output);
                $count = 0;
                $inTasks = false;

                // Regex to match task lines?
                // They are usually indented.
                // Let's count number of "TAGS:" lines? No, that is --list-tags.

                // Let's count lines that match indentation and are not structural.
                // A safer way: count "TASK" in standard execution log? No, we are pre-calculating.

                // Let's count non-empty lines that are indented under "tasks:".
                // But there could be multiple plays.

                foreach ($lines as $line) {
                    $trim = trim($line);
                    if (empty($trim)) {
                        continue;
                    }

                    // We count lines that seem to be tasks.
                    // It's heuristic.
                    // The reliable way is counting how many items are indented after "jobs:" or "tasks:".
                    // But multiple plays complicates it.

                    // Alternative: count 'TAGS' if we used --list-tags?
                    // No.

                    // Let's assume standard output.
                    // "    tasks:" starts a block.
                    // "    play:" starts a play.

                    // Actually, parsing this textually is fragile.
                    // But it's better than nothing.

                    // Let's try to count lines that have significant indentation and don't start with "play:" or "tasks:".
                    // Or, just count non-header lines.

                    // Let's use a simpler heuristic for now:
                    // Count lines that are NOT "playbook:", "play:", "tasks:".
                    // And maybe filter out file paths/headers.
                }

                // Re-think: `ansible-playbook --list-tasks` does not guarantee 1:1 mapping with execution steps if includes/imports are dynamic.
                // But it gives static list.

                // Let's try to count lines that are NOT indented with 2 spaces (plays) or 0 spaces (playbook).
                // Tasks are usually indented by 4 or 6 spaces.

                $taskCount = 0;
                $matches = [];
                // Look for lines that typically represent tasks in the list output
                // Example:
                //     tasks:
                //       Gathering Facts
                //       Install package

                // We count lines that are indented.
                // But wait, "Gathering Facts" is implicit unless turned off.
                // Deployment execution will show "TASK [Gathering Facts]".

                // How about we just returning a rough estimate?
                // Or maybe we can rely on `ansible-playbook` JSON output if available?
                // `ANSIBLE_STDOUT_CALLBACK=json` doesn't work well with --list-tasks usually.

                // Regular output counting:
                // Count lines that are not starting with "playbook:", "play:", "tasks:".
                // And are not empty.

                // Let's refine regex.
                // Only count typical task names? No names can be anything.

                // Let's count the lines that are indented.
                // Typically: "      Task Name"

                // Let's look for "  TAGS: " if we use `--list-tasks --list-tags`?
                // No.

                // Let's just blindly count lines that are significantly indented.
                $lines = explode("\n", $output);
                foreach ($lines as $line) {
                    // Check if line is a task
                    // Usually 6 spaces indentation for tasks under a play.
                    if (preg_match('/^\s{4,}/', $line) && ! str_contains($line, 'tasks:') && ! str_contains($line, 'play:')) {
                        $taskCount++;
                    }
                }

                return max(1, $taskCount);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to count tasks: '.$e->getMessage());
        }

        return 1; // Fallback
    }

    /**
     * Determine deployment status based on play recap output.
     */
    protected function resolveDeploymentStatus(string $output, int $exitCode): string
    {
        $recap = $this->parsePlayRecap($output);

        if ($recap !== null) {
            $hostsWithFailure = $recap['hosts_with_failure'];
            $hostsWithSuccess = $recap['hosts_with_success'];

            if ($hostsWithFailure > 0 && $hostsWithSuccess > 0) {
                return 'warning';
            }

            if ($hostsWithFailure > 0) {
                return 'failed';
            }

            if ($hostsWithSuccess > 0) {
                return 'success';
            }
        }

        return $exitCode === 0 ? 'success' : 'failed';
    }

    /**
     * Parse Ansible PLAY RECAP lines and return summary counts.
     */
    protected function parsePlayRecap(string $output): ?array
    {
        // Strip ANSI color codes
        $output = preg_replace('/\x1b[^m]*m/', '', $output);

        if (! str_contains($output, 'PLAY RECAP')) {
            return null;
        }

        $lines = preg_split("/\r?\n/", $output);
        $inRecap = false;
        $hostsWithSuccess = 0;
        $hostsWithFailure = 0;
        $totalHosts = 0;

        foreach ($lines as $line) {
            if (str_contains($line, 'PLAY RECAP')) {
                $inRecap = true;
                continue;
            }

            if (! $inRecap) {
                continue;
            }

            $trim = trim($line);
            if ($trim === '') {
                if ($totalHosts > 0) {
                    break;
                }
                continue;
            }

            // Regex to match host recap line
            // format: hostname : ok=X changed=X unreachable=X failed=X skipped=X rescued=X ignored=X
            if (preg_match('/^(\S+)\s*:\s*ok=(\d+)\s+changed=(\d+)\s+unreachable=(\d+)\s+failed=(\d+)/', $trim, $matches)) {
                $totalHosts++;
                $ok = (int) $matches[2];
                $changed = (int) $matches[3];
                $unreachable = (int) $matches[4];
                $failed = (int) $matches[5];

                $hasFailure = ($failed + $unreachable) > 0;
                $hasSuccess = ($ok + $changed) > 0;

                if ($hasFailure) {
                    $hostsWithFailure++;
                }
                
                // If a host has both failure and success (e.g. some tasks OK then failed), it counts as failure for the host status usually.
                // But for the global status, we checking if *any* host succeeded.
                
                if ($hasSuccess && ! $hasFailure) {
                     $hostsWithSuccess++;
                } elseif ($hasSuccess && $hasFailure) {
                    // Logic check: if a host partially succeeded but eventually failed, does it count towards "hostsWithSuccess"?
                    // The goal of "warning" is: "Some hosts failed, but AT LEAST ONE host was fully successful"?
                    // Or "Some hosts failed, but some operation succeeded"?
                    
                    // Usually "Warning" means: Mix of successful hosts and failed hosts.
                    // If Host A fails, Host B succeeds -> Warning.
                    // If Host A partially succeeds then fails -> Failed (for that host).
                    
                    // So we only count hostsWithSuccess if they strictly didn't fail?
                    // Let's stick to strict success for "hostsWithSuccess".
                }
            }
        }

        if ($totalHosts === 0) {
            return null;
        }

        return [
            'total_hosts' => $totalHosts,
            'hosts_with_success' => $hostsWithSuccess,
            'hosts_with_failure' => $hostsWithFailure,
        ];
    }

    /**
     * Clean up temporary files
     */
    protected function cleanup(string $inventoryPath, string $playbookPath): void
    {
        if (file_exists($inventoryPath)) {
            unlink($inventoryPath);
        }

        if (file_exists($playbookPath)) {
            $playbookDir = dirname($playbookPath);
            $templatesDir = $playbookDir.'/templates';

            // Clean up templates if they exist
            if (is_dir($templatesDir)) {
                $files = glob($templatesDir.'/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($templatesDir);
            }

            unlink($playbookPath);
        }
    }
}
