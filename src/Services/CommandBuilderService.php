<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\Process;
use VisioSoft\LaraAnsible\Models\Deployment;

class CommandBuilderService
{
    /**
     * Build ansible-playbook command
     */
    public function buildAnsibleCommand(Deployment $deployment, string $inventoryPath, string $playbookPath): array
    {
        // Build base ansible-playbook command
        $command = "ansible-playbook -i {$inventoryPath} {$playbookPath}";

        // Add extra vars from task template
        if ($deployment->taskTemplate->extra_vars) {
            $extraVars = json_encode($deployment->taskTemplate->extra_vars);
            $command .= ' --extra-vars ' . escapeshellarg($extraVars);
        }

        // Add CLI flags
        if ($deployment->cli_flags && is_array($deployment->cli_flags)) {
            foreach ($deployment->cli_flags as $flag) {
                $command .= ' ' . $flag;
            }
        }

        // Add limit hosts
        if ($deployment->limit_hosts) {
            $command .= ' --limit ' . escapeshellarg($deployment->limit_hosts);
        }

        // Add tags
        if ($deployment->tags) {
            $command .= ' --tags ' . escapeshellarg($deployment->tags);
        }

        // Add skip-tags
        if ($deployment->skip_tags) {
            $command .= ' --skip-tags ' . escapeshellarg($deployment->skip_tags);
        }

        // Add forks
        if ($deployment->forks) {
            $command .= ' --forks ' . intval($deployment->forks);
        }

        // Add start-at-task
        if ($deployment->start_at_task) {
            $command .= ' --start-at-task ' . escapeshellarg($deployment->start_at_task);
        }

        // Add remote user
        if ($deployment->remote_user) {
            $command .= ' --user ' . escapeshellarg($deployment->remote_user);
        }

        // Add extra CLI arguments
        if ($deployment->extra_args) {
            $command .= ' ' . trim($deployment->extra_args);
        }

        return [
            'display_command' => $command,
            'wrapped_command' => $command,
        ];
    }

    /**
     * Get total tasks count by executing ansible-playbook with --list-tasks
     */
    public function getTotalTasks(Deployment $deployment, string $inventoryPath, string $playbookPath): int
    {
        $command = "ansible-playbook -i {$inventoryPath} {$playbookPath} --list-tasks";

        // Add extra vars if needed
        if ($deployment->taskTemplate->extra_vars) {
            $extraVars = json_encode($deployment->taskTemplate->extra_vars);
            $command .= ' --extra-vars ' . escapeshellarg($extraVars);
        }

        // Add limit hosts
        if ($deployment->limit_hosts) {
            $command .= ' --limit ' . escapeshellarg($deployment->limit_hosts);
        }

        // Add tags
        if ($deployment->tags) {
            $command .= ' --tags ' . escapeshellarg($deployment->tags);
        }

        // Add skip-tags
        if ($deployment->skip_tags) {
            $command .= ' --skip-tags ' . escapeshellarg($deployment->skip_tags);
        }

        try {
            $result = Process::timeout(30)->run($command);
            $output = $result->output();

            // Count tasks in output
            preg_match_all('/^\s+\d+\s+tasks\s+in\s+total/m', $output, $totalMatches);
            if (!empty($totalMatches[0])) {
                // Extract number from line like "  25 tasks in total"
                if (preg_match('/(\d+)\s+tasks?\s+in\s+total/', $totalMatches[0][0], $numMatch)) {
                    return (int) $numMatch[1];
                }
            }

            // Fallback: count TASK lines
            preg_match_all('/^\s+\[.*\]/m', $output, $taskMatches);
            return count($taskMatches[0] ?? []);
        } catch (\Exception $e) {
            // If list-tasks fails, return 1 as fallback
            return 1;
        }
    }

    /**
     * Build command input string for storage
     */
    public function buildCommandInput(array $commandData, string $inventoryPath, string $playbookPath): string
    {
        $commandInput = "=== Command ===\n";
        $commandInput .= $commandData['display_command'] . "\n\n";
        $commandInput .= "=== Inventory File ({$inventoryPath}) ===\n";
        $commandInput .= file_get_contents($inventoryPath) . "\n\n";
        $commandInput .= "=== Playbook File ({$playbookPath}) ===\n";
        $commandInput .= file_get_contents($playbookPath);

        return $commandInput;
    }
}
