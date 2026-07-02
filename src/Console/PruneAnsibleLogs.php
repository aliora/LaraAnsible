<?php

namespace VisioSoft\LaraAnsible\Console;

use Illuminate\Console\Command;

class PruneAnsibleLogs extends Command
{
    protected $signature = 'ansible:prune-logs {--days= : Delete JobID_*.log files older than N days (defaults to config laraansible.log_retention_days)}';

    protected $description = 'Delete old ansible-playbook log files (storage/logs/ansible-playbook).';

    public function handle(): int
    {
        $optDays = $this->option('days');
        $days = $optDays !== null ? (int) $optDays : (int) config('laraansible.log_retention_days', 30);

        if ($optDays === null && $days <= 0) {
            $this->info('Ansible log retention disabled (log_retention_days <= 0).');

            return self::SUCCESS;
        }

        $dir = storage_path('logs/ansible-playbook');
        if (! is_dir($dir)) {
            $this->info('No ansible log directory yet.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max(0, $days))->getTimestamp();
        $deleted = 0;

        foreach (glob($dir.'/*.log') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} ansible log file(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
