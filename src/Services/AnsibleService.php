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

            // Build ansible-playbook command
            $commandData = $this->buildAnsibleCommand($deployment, $inventoryPath, $playbookPath);
            Log::info("Command to execute: {$commandData['display_command']}");

            // Store command input before execution
            $commandInput = "=== Command ===\n";
            $commandInput .= $commandData['display_command']."\n\n";
            $commandInput .= "=== Inventory File ({$inventoryPath}) ===\n";
            $commandInput .= file_get_contents($inventoryPath)."\n\n";
            $commandInput .= "=== Playbook File ({$playbookPath}) ===\n";
            $commandInput .= file_get_contents($playbookPath);

            $deployment->update([
                'command_input' => $commandInput,
            ]);

            // Execute the command with streaming output
            $outputBuffer = '';
            // Use an unlimited timeout to allow long-running Ansible playbooks
            $result = Process::forever()->run($commandData['wrapped_command'], function ($type, $output) use (&$outputBuffer, $deployment) {
                $outputBuffer .= $output;
                // Update deployment with partial output for real-time viewing
                $deployment->update([
                    'command_output' => $outputBuffer,
                ]);
            });

            Log::info("Command executed with exit code: {$result->exitCode()}");

            // Final update with complete output and status
            $deployment->update([
                'status' => $result->successful() ? 'success' : 'failed',
                'command_output' => $result->output(),
                'exit_code' => $result->exitCode(),
                'completed_at' => now(),
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
                                // Use mapped columns or fallback to defaults
                                $inventory->port = $setting->child_port_column ? ($child->{$setting->child_port_column} ?? 22) : 22;
                                $inventory->username = $setting->child_username_column ? ($child->{$setting->child_username_column} ?? null) : null;

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

        $content = "[all]\n";
        foreach ($inventories as $inventory) {
            $line = "{$inventory->hostname} ansible_port={$inventory->port} ansible_user={$inventory->username}";

            if ($inventory->keystore) {
                $keyPath = $this->createTempKeyFile($inventory->keystore);
                $line .= " ansible_ssh_private_key_file={$keyPath}";
            }

            $content .= $line."\n";
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
     * Clean up temporary files
     */
    protected function cleanup(string $inventoryPath, string $playbookPath): void
    {
        if (file_exists($inventoryPath)) {
            unlink($inventoryPath);
        }

        if (file_exists($playbookPath)) {
            unlink($playbookPath);
        }
    }
}
