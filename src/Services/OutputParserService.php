<?php

namespace VisioSoft\LaraAnsible\Services;

/**
 * Service for parsing Ansible command output
 * 
 * Handles parsing of Ansible playbook execution output including:
 * - Task progress tracking
 * - PLAY RECAP parsing for deployment status
 * - ANSI code stripping
 * - Status resolution (success/failed/warning)
 */
class OutputParserService
{
    /**
     * Parse task progress from Ansible output
     */
    public function parseTaskProgress(string $outputBuffer, int $totalTasks): array
    {
        $cleanOutput = $this->stripAnsiCodes($outputBuffer);
        
        // Count completed tasks
        $completedTasks = 0;
        if (preg_match_all('/^\s*TASK \[.*\]/m', $cleanOutput, $matches)) {
            $completedTasks = count($matches[0]);
        }

        $processedTasks = min($completedTasks, $totalTasks);

        // Calculate progress percentage
        $progress = 0;
        if ($totalTasks > 0) {
            $progress = min(100, (int) round(($processedTasks / $totalTasks) * 100));
        }

        // If we see PLAY RECAP, we're done
        if (str_contains($cleanOutput, 'PLAY RECAP')) {
            $progress = 100;
            $processedTasks = $totalTasks;
        }

        return [
            'completed_tasks' => $completedTasks,
            'processed_tasks' => $processedTasks,
            'progress' => $progress,
        ];
    }

    /**
     * Resolve deployment status from output and exit code
     */
    public function resolveDeploymentStatus(string $output, int $exitCode): string
    {
        $recap = $this->parsePlayRecap($output);

        if ($recap !== null) {
            $hostsWithFailure = $recap['hosts_with_failure'];
            $hostsWithSuccess = $recap['hosts_with_success'];

            // Mixed results: some hosts succeeded, some failed
            if ($hostsWithFailure > 0 && $hostsWithSuccess > 0) {
                return 'warning';
            }

            // All hosts failed
            if ($hostsWithFailure > 0) {
                return 'failed';
            }

            // All hosts succeeded
            if ($hostsWithSuccess > 0) {
                return 'success';
            }
        }

        // Fallback to exit code
        return $exitCode === 0 ? 'success' : 'failed';
    }

    /**
     * Parse Ansible PLAY RECAP lines and return summary counts
     */
    public function parsePlayRecap(string $output): ?array
    {
        $output = $this->stripAnsiCodes($output);

        if (!str_contains($output, 'PLAY RECAP')) {
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

            if (!$inRecap) {
                continue;
            }

            $trim = trim($line);
            if ($trim === '') {
                if ($totalHosts > 0) {
                    break;
                }
                continue;
            }

            // Parse host recap line: hostname : ok=X changed=X unreachable=X failed=X ...
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

                // Only count as success if no failures
                if ($hasSuccess && !$hasFailure) {
                    $hostsWithSuccess++;
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
     * Strip ANSI color codes from output
     */
    public function stripAnsiCodes(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    /**
     * Format output for display (strip ANSI codes)
     */
    public function formatOutput(string $output): string
    {
        return $this->stripAnsiCodes($output);
    }
}
