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
            $inventoryPath = $this->createInventoryFile($deployment);
            Log::info("Created inventory file: {$inventoryPath}");

            $playbookPath = $this->createPlaybookFile($deployment);
            Log::info("Created playbook file: {$playbookPath}");

            // Create templates directory and files
            $this->createTemplateFiles($deployment, $playbookPath);
            Log::info('Created template files');

            // Build ansible-playbook command
            $commandData = $this->buildAnsibleCommand($deployment, $inventoryPath, $playbookPath);
            Log::info("Command to execute: {$commandData['display_command']}");

            // Get total tasks count
            $totalTasks = $this->getTotalTasks($deployment, $inventoryPath, $playbookPath);
            Log::info("Total tasks to execute: {$totalTasks}");

            // Store command input before execution
            $commandInput = "=== Command ===\n";
            $commandInput .= $commandData['display_command']."\n\n";
            $commandInput .= "=== Inventory File ({$inventoryPath}) ===\n";
            $commandInput .= file_get_contents($inventoryPath)."\n\n";
            $commandInput .= "=== Playbook File ({$playbookPath}) ===\n";
            $commandInput .= file_get_contents($playbookPath);

            $deployment->update([
                'command_input' => $commandInput,
                'total_hosts' => $totalTasks, // Storing total tasks in total_hosts temporary or use a new column?
                // The user request implies we want progress based on tasks.
                // The DB column `total_hosts` is currently used for total hosts.
                // If I repurpose it, the UI label "Hosts" will be wrong.
                // However, the `Deployment` model has `total_hosts` and `processed_hosts`.
                // If I want to show task progress, I should probably calculate percentage myself and store in `progress`.
                // Let's keep `total_hosts` as actual host count if possible, effectively calculated from inventory.
                // But the user specifically asked for progress based on tasks.
                // Let's rely on `progress` column for the percentage.
                // Usage of `processed_hosts` vs `total_hosts` in UI:
                // UI shows: $processed.'/'.$total.
                // If I put task counts there, it will task X/Y.
                // The code below keeps `processed_hosts` as host count but updates `progress` based on tasks.
                // Or I can just track tasks in a local variable and update `progress`.
            ]);

            // Execute the command with streaming output
            $outputBuffer = '';
            $processedHosts = 0;
            $completedTasks = 0;
            $totalHosts = $deployment->total_hosts ?: 1;

            // Use an unlimited timeout to allow long-running Ansible playbooks
            $result = Process::forever()->run($commandData['wrapped_command'], function ($type, $output) use (&$outputBuffer, &$processedHosts, &$completedTasks, $totalHosts, $totalTasks, $deployment) {
                $outputBuffer .= $output;

                // Parse Ansible output to track progress

                // Track completed tasks
                if (preg_match_all('/TASK \[.*\]/i', $output, $matches)) {
                    $completedTasks += count($matches[0]);
                }

                // Track processed hosts (for "processed_hosts" counter, separate from progress %)
                // Look for patterns like "ok: [hostname]" or "changed: [hostname]" or "PLAY RECAP"
                if (preg_match_all('/(?:ok|changed|failed|unreachable):\s*\[([^\]]+)\]/', $output, $matches)) {
                    $processedHosts += count(array_unique($matches[1]));
                }

                // Calculate progress percentage based on TASKS
                $progress = 0;
                if ($totalTasks > 0) {
                    $progress = min(100, (int) (($completedTasks / $totalTasks) * 100));
                }

                // If we see PLAY RECAP, we're likely done or close to it.
                if (str_contains($output, 'PLAY RECAP')) {
                    $progress = 100;
                }

                // Update deployment with partial output and progress for real-time viewing
                $deployment->update([
                    'command_output' => $outputBuffer,
                    'progress' => $progress,
                    'processed_hosts' => min($processedHosts, $totalHosts), // Keep tracking hosts for the label X/Y hosts if needed, even if progress bar is task based.
                ]);
            });

            Log::info("Command executed with exit code: {$result->exitCode()}");

            // Final update with complete output and status
            $deployment->update([
                'status' => $result->successful() ? 'success' : 'failed',
                'command_output' => $result->output(),
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
     */
    protected function createInventoryFile(Deployment $deployment): string
    {
        // If an inventory file is specified, use it directly
        if ($deployment->inventory_file && file_exists($deployment->inventory_file)) {
            return $deployment->inventory_file;
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
        foreach ($groupedInventories as $groupName => $groupInventories) {
            $content .= "[{$groupName}]\n";

            $groupVars = [];
            $groupKeystores = [];
            $groupUsers = [];
            $groupPorts = [];

            foreach ($groupInventories as $inventory) {
                if (empty($inventory->hostname)) {
                    continue;
                }

                $hostLine = $inventory->name ?? $inventory->hostname;
                $hostLine .= " ansible_host={$inventory->hostname}";

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

                $content .= $hostLine."\n";
            }

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
        if (! empty($ungroupedInventories)) {
            $content .= "[ungrouped]\n";

            foreach ($ungroupedInventories as $inventory) {
                if (! empty($inventory->script)) {
                    continue; // Skip script-based inventories for now
                }

                if (! empty($inventory->hostname)) {
                    $line = $inventory->name ?? $inventory->hostname;
                    $line .= " ansible_host={$inventory->hostname}";

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

                    $content .= $line."\n";
                }
            }

            $content .= "\n";
        }

        // Append script-based inventories at the end
        foreach ($inventories as $inventory) {
            if (! empty($inventory->script)) {
                $content .= $inventory->script."\n";
            }
        }

        $path = storage_path('app/ansible/inventory_'.$deployment->id.'.ini');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
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
