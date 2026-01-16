<?php

namespace VisioSoft\LaraAnsible\Services;

use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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

        // If 'all' is selected, get all active inventories
        if (in_array('all', $inventoryIds)) {
            $inventories = Inventory::where('is_active', true)->get();
        } else {
            $inventories = Inventory::whereIn('id', $inventoryIds)->get();
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

        // Add check/test flags (--syntax-check, --check, --diff, --list-tasks)
        if ($deployment->cli_check_flags) {
            foreach ($deployment->cli_check_flags as $flag) {
                $command .= ' ' . $flag;
            }
        }

        // Add target flags (--become)
        if ($deployment->cli_target_flags) {
            foreach ($deployment->cli_target_flags as $flag) {
                $command .= ' ' . $flag;
            }
        }

        // Add --limit
        if ($deployment->cli_limit) {
            $command .= ' --limit ' . escapeshellarg($deployment->cli_limit);
        }

        // Add --tags
        if ($deployment->cli_tags) {
            $command .= ' --tags ' . escapeshellarg($deployment->cli_tags);
        }

        // Add --skip-tags
        if ($deployment->cli_skip_tags) {
            $command .= ' --skip-tags ' . escapeshellarg($deployment->cli_skip_tags);
        }

        // Add --start-at-task
        if ($deployment->cli_start_at_task) {
            $command .= ' --start-at-task ' . escapeshellarg($deployment->cli_start_at_task);
        }

        // Add --forks
        if ($deployment->cli_forks) {
            $command .= ' --forks ' . (int) $deployment->cli_forks;
        }

        // Add verbosity level (-v, -vv, -vvv, -vvvv)
        if ($deployment->cli_verbosity) {
            $command .= ' ' . $deployment->cli_verbosity;
        }

        // Add extra CLI arguments from deployment (freeform)
        if ($deployment->extra_args) {
            $command .= ' ' . trim($deployment->extra_args);
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
