<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\AnsibleTemplate;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;

class AnsibleService
{
    protected string $runDir = '';

    protected function runDir(Deployment $deployment): string
    {
        $dir = storage_path('app/ansible/runs/'.$deployment->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public function executeDeployment(Deployment $deployment): void
    {
        $deployment->refresh();
        if ($deployment->status === 'failed') {
            Log::info("Skipping deployment {$deployment->id}: already cancelled before pickup.");

            return;
        }

        $deployment->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->runDir = $this->runDir($deployment);

        try {
            Log::info("Starting deployment {$deployment->id}");

            $this->assertAnsibleBinaryAvailable();

            $inventoryData = $this->createInventoryFile($deployment);
            $inventoryPath = $inventoryData['path'];
            $totalHostsCount = $inventoryData['host_count'];
            Log::info("Created inventory file: {$inventoryPath} with {$totalHostsCount} hosts");

            $playbookPath = $this->createPlaybookFile($deployment);
            Log::info("Created playbook file: {$playbookPath}");

            $this->createTemplateFiles($deployment, $playbookPath);
            Log::info('Created template files');

            $this->createSharedLibraryFiles($playbookPath);
            $this->createDbLibraryFiles($playbookPath);

            $command = $this->buildAnsibleCommand($deployment, $inventoryPath, $playbookPath);
            Log::info("Command to execute: {$command}");

            $totalTasks = max(1, $this->getTotalTasks($deployment, $inventoryPath, $playbookPath));
            Log::info("Total playbook tasks: {$totalTasks}");

            $commandInput = "=== Command ===\n";
            $commandInput .= $command."\n\n";
            $commandInput .= "=== Inventory File ({$inventoryPath}) ===\n";
            $commandInput .= file_get_contents($inventoryPath)."\n\n";
            $commandInput .= "=== Playbook File ({$playbookPath}) ===\n";
            $commandInput .= file_get_contents($playbookPath);

            $deployment->writeLog($commandInput."\n\n=== Ansible Output ===\n");

            $deployment->update([
                'command_input' => $commandInput,
                'total_hosts' => $totalTasks,
                'processed_hosts' => 0,
            ]);

            $outputBuffer = '';

            $result = Process::forever()
                ->env([
                    'PYTHONUNBUFFERED' => '1',
                    'ANSIBLE_STDOUT_CALLBACK' => 'default',
                    'ANSIBLE_HOST_KEY_CHECKING' => config('laraansible.host_key_checking') ? 'True' : 'False',
                    'ANSIBLE_PIPELINING' => 'True',
                ])
                ->run($command, function ($type, $output) use (&$outputBuffer, $totalTasks, $deployment) {
                    $outputBuffer .= $output;

                    $deployment->appendLog($output);

                    $cleanOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $outputBuffer);
                    $completedTasks = 0;
                    if (preg_match_all('/^\s*TASK \[.*\]/m', $cleanOutput, $matches)) {
                        $completedTasks = count($matches[0]);
                    }

                    $processedTasks = min($completedTasks, $totalTasks);
                    $progress = min(100, (int) round(($processedTasks / $totalTasks) * 100));

                    if (str_contains($cleanOutput, 'PLAY RECAP')) {
                        $progress = 100;
                        $processedTasks = $totalTasks;
                    }

                    $deployment->update([
                        'progress' => $progress,
                        'processed_hosts' => $processedTasks,
                    ]);
                });

            Log::info("Command executed with exit code: {$result->exitCode()}");

            $deployment->refresh();
            if ($deployment->status === 'failed') {
                return;
            }

            $deployment->update([
                'status' => $this->resolveDeploymentStatus($result->output(), $result->exitCode()),
                'exit_code' => $result->exitCode(),
                'completed_at' => now(),
                'progress' => 100,
            ]);

            if (! $result->successful()) {
                Log::error("Deployment {$deployment->id} failed with exit code {$result->exitCode()}", [
                    'output' => $result->output(),
                    'error_output' => $result->errorOutput(),
                ]);
            }

            Log::info("Deployment {$deployment->id} completed");
        } catch (\Exception $e) {
            Log::error("Deployment {$deployment->id} exception: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            $deployment->appendLog("\n\n=== ERROR ===\n".$e->getMessage()."\n");
            $deployment->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            throw $e;
        } finally {
            $this->cleanup($this->runDir);
        }
    }

    protected function assertAnsibleBinaryAvailable(): void
    {
        $binary = (string) config('laraansible.ansible_binary', 'ansible-playbook');

        $resolved = str_contains($binary, '/')
            ? (is_executable($binary) ? $binary : null)
            : (new ExecutableFinder)->find($binary);

        if (! $resolved) {
            throw new \RuntimeException(
                "ansible binary '{$binary}' not found. Install ansible on the worker host ".
                'or set laraansible.ansible_binary (LARA_ANSIBLE_BINARY) to its absolute path.'
            );
        }
    }

    /**
     * Resolve a user-supplied inventory_file to a real path, returning null for
     * missing files or anything outside storage/app/ansible (path-traversal guard).
     */
    protected function resolveAllowedInventoryPath(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        $root = realpath(storage_path('app/ansible')) ?: storage_path('app/ansible');

        return str_starts_with($real, rtrim($root, '/').'/') ? $real : null;
    }

    /**
     * @return array{path: string, host_count: int}
     */
    protected function createInventoryFile(Deployment $deployment): array
    {
        if ($deployment->inventory_file) {
            $safePath = $this->resolveAllowedInventoryPath($deployment->inventory_file);
            if ($safePath === null) {
                throw new \RuntimeException('Refusing inventory_file outside the ansible storage dir: '.$deployment->inventory_file);
            }

            $content = file_get_contents($safePath);
            $count = substr_count($content, 'ansible_host=');

            return [
                'path' => $safePath,
                'host_count' => max(1, $count),
            ];
        }

        $inventoryIds = $deployment->inventory_ids ?? [];
        $staticInventoryIds = [];
        $dynamicInventoryIds = [];

        foreach ($inventoryIds as $id) {
            if ($id === 'all') {
                $staticInventoryIds = ['all'];
                break;
            }

            if (is_string($id) && str_starts_with($id, 'dynamic_')) {
                $dynamicInventoryIds[] = $id;
            } else {
                $staticInventoryIds[] = $id;
            }
        }

        $inventories = collect();

        if (! empty($staticInventoryIds)) {
            if (in_array('all', $staticInventoryIds)) {
                $inventories = Inventory::where('is_active', true)->get();
            } else {
                $inventories = Inventory::whereIn('id', $staticInventoryIds)->get();
            }
        }

        if (! empty($dynamicInventoryIds)) {
            $setting = AnsibleSetting::getActive();
            if ($setting && $setting->child_table) {
                foreach ($dynamicInventoryIds as $dynamicId) {
                    $parts = explode('_', $dynamicId);
                    if (count($parts) < 3) {
                        continue;
                    }

                    $child = $setting->findChild($parts[1]);
                    if (! $child) {
                        continue;
                    }

                    $inventory = new Inventory;
                    $inventory->hostname = $child->{$setting->child_hostname_column} ?? null;
                    $inventory->port = $setting->ssh_port ?? 22;
                    $inventory->username = $setting->ssh_username ?? 'root';

                    if ($inventory->hostname) {
                        $inventories->push($inventory);
                    }
                }
            }
        }

        $content = '';
        $setting = AnsibleSetting::getInstance();

        $allHosts = [];
        $groupedInventories = [];
        $ungroupedInventories = [];

        foreach ($inventories as $inventory) {
            if ($inventory->source_type !== 'dynamic' || ! $inventory->dynamic_child_id || ! $setting || ! $setting->child_table) {
                $ungroupedInventories[] = $inventory;

                continue;
            }

            $child = $setting->findChild($inventory->dynamic_child_id);
            $foreignKey = $setting->childForeignKey();

            if ($child && isset($child->{$foreignKey}) && $setting->parent_table) {
                $groupName = $setting->parentLabelFor($child->{$foreignKey}) ?? 'unknown';
                $groupName = strtolower(Inventory::ansibleGroupName($groupName));

                $groupedInventories[$groupName][] = $inventory;
            } else {
                $ungroupedInventories[] = $inventory;
            }
        }

        foreach ($groupedInventories as $groupName => $groupInventories) {
            $content .= "[{$groupName}]\n";

            $groupKeystores = [];
            $groupUsers = [];
            $groupPorts = [];

            foreach ($groupInventories as $inventory) {
                $hostsEntry = Inventory::normalizeHostsEntry($inventory->hosts_entry ?? []);
                if (empty($hostsEntry) && ! empty($inventory->hostname)) {
                    $hostsEntry = [$inventory->name ?? $inventory->hostname => $inventory->hostname];
                }

                if (empty($hostsEntry)) {
                    continue;
                }

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
                    $allHosts[] = $hostLine;
                    $content .= $hostLine."\n";
                }
            }

            $groupVarsContent = '';

            if (count($groupUsers) === 1) {
                $groupVarsContent .= 'ansible_user='.array_key_first($groupUsers)."\n";
            }

            if (count($groupPorts) === 1) {
                $groupVarsContent .= 'ansible_port='.array_key_first($groupPorts)."\n";
            }

            if (count($groupKeystores) === 1) {
                $keystorePath = $this->createTempKeyFile(reset($groupKeystores));
                $groupVarsContent .= "ansible_ssh_private_key_file={$keystorePath}\n";
            }

            if ($groupVarsContent !== '') {
                $content .= "[{$groupName}:vars]\n";
                $content .= $groupVarsContent;
            }

            $content .= "\n";
        }

        if (! empty($ungroupedInventories)) {
            $content .= "[ungrouped]\n";

            foreach ($ungroupedInventories as $inventory) {
                if (! empty($inventory->script) && $inventory->source_type !== 'dynamic') {
                    continue;
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

                    $allHosts[] = $line;
                    $content .= $line."\n";
                }
            }

            $content .= "\n";
        }

        foreach ($inventories as $inventory) {
            if (! empty($inventory->script) && $inventory->source_type !== 'dynamic') {
                $content .= $inventory->script."\n";

                foreach ($this->extractHostLinesFromInventoryScript($inventory->script) as $hostLine) {
                    $allHosts[] = $hostLine;
                }
            }
        }

        if (! empty($allHosts)) {
            $allHosts = array_unique($allHosts);

            $gateServerContent = "[gate_server]\n";
            foreach ($allHosts as $hostLine) {
                $gateServerContent .= $hostLine."\n";
            }
            $gateServerContent .= "\n";

            $allGroupContent = "[all]\n";
            foreach ($allHosts as $hostLine) {
                $allGroupContent .= $hostLine."\n";
            }
            $allGroupContent .= "\n";

            $content = $gateServerContent.$allGroupContent.$content;
        }

        $path = $this->runDir.'/inventory.ini';
        file_put_contents($path, $content);

        return [
            'path' => $path,
            'host_count' => count($allHosts),
        ];
    }

    /**
     * @return array<int, string>
     */
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

            $hostLines[] = str_contains($line, 'ansible_host=') ? $line : $first;
        }

        return $hostLines;
    }

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

        $path = $this->runDir.'/playbook.yml';
        file_put_contents($path, $content);

        return $path;
    }

    protected function createTemplateFiles(Deployment $deployment, string $playbookPath): void
    {
        $templates = $deployment->taskTemplate->templates ?? [];

        if (empty($templates)) {
            return;
        }

        $templatesDir = dirname($playbookPath).'/templates';

        if (! is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }

        foreach ($templates as $template) {
            $path = $templatesDir.'/'.basename($template['name']);
            file_put_contents($path, $template['content']);
        }
    }

    /**
     * Copy storage/app/ansible/library/ into the run directory, routed by
     * extension, so any job can reference shared tasks/templates by name.
     */
    protected function createSharedLibraryFiles(string $playbookPath): void
    {
        $libraryDir = storage_path('app/ansible/library');

        if (! is_dir($libraryDir)) {
            return;
        }

        $files = glob($libraryDir.'/*');
        if (empty($files)) {
            return;
        }

        $base = dirname($playbookPath);
        $reserved = ['playbook.yml', 'inventory.ini'];

        $this->ensureLibraryDirs($base);

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $this->writeLibraryFile($base, basename($file), file_get_contents($file), $reserved);
        }
    }

    /**
     * Materialize active AnsibleTemplate records into the run directory, routed
     * by extension exactly like the filesystem library.
     */
    protected function createDbLibraryFiles(string $playbookPath): void
    {
        $templates = AnsibleTemplate::query()
            ->where('is_active', true)
            ->get(['name', 'content']);

        if ($templates->isEmpty()) {
            return;
        }

        $base = dirname($playbookPath);
        $reserved = ['playbook.yml', 'inventory.ini'];

        $this->ensureLibraryDirs($base);

        foreach ($templates as $template) {
            if (blank($template->name)) {
                continue;
            }

            $this->writeLibraryFile($base, basename($template->name), (string) $template->content, $reserved);
        }
    }

    protected function ensureLibraryDirs(string $base): void
    {
        foreach (['tasks', 'templates', 'files'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                mkdir($base.'/'.$dir, 0755, true);
            }
        }
    }

    /**
     * Route one library file into the run dir: .yml/.yaml to tasks/, everything
     * else to templates/ and files/, plus a flat root copy for bare references.
     */
    protected function writeLibraryFile(string $base, string $name, string $content, array $reserved): void
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($name, $reserved, true)) {
            file_put_contents($base.'/'.$name, $content);
        }

        if (in_array($ext, ['yml', 'yaml'], true)) {
            file_put_contents($base.'/tasks/'.$name, $content);
        } else {
            file_put_contents($base.'/templates/'.$name, $content);
            file_put_contents($base.'/files/'.$name, $content);
        }
    }

    protected function createTempKeyFile(Keystore $keystore): string
    {
        $path = $this->runDir.'/keys/key_'.$keystore->id;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, $keystore->private_key);
        chmod($path, 0600);

        return $path;
    }

    /**
     * Merge the job's default extra_vars with the per-run values collected at
     * launch; per-run values win.
     */
    protected function resolveExtraVars(Deployment $deployment): array
    {
        $vars = $deployment->taskTemplate->extra_vars ?? [];
        $vars = is_array($vars) ? $vars : [];

        $runVars = $deployment->extra_vars ?? [];
        if (is_array($runVars)) {
            $vars = array_merge($vars, $runVars);
        }

        return $vars;
    }

    protected function buildAnsibleCommand(Deployment $deployment, string $inventoryPath, string $playbookPath): string
    {
        $binary = config('laraansible.ansible_binary', 'ansible-playbook');
        $command = escapeshellcmd($binary).' -i '.escapeshellarg($inventoryPath).' '.escapeshellarg($playbookPath);

        $extraVars = $this->resolveExtraVars($deployment);
        if (! empty($extraVars)) {
            $command .= ' --extra-vars '.escapeshellarg(json_encode($extraVars));
        }

        if ($deployment->cli_flags && is_array($deployment->cli_flags)) {
            foreach ($deployment->cli_flags as $flag) {
                if (filled($flag)) {
                    $command .= ' '.escapeshellarg((string) $flag);
                }
            }
        }

        if ($deployment->limit_hosts) {
            $command .= ' --limit '.escapeshellarg($deployment->limit_hosts);
        }

        if ($deployment->tags) {
            $command .= ' --tags '.escapeshellarg($deployment->tags);
        }

        if ($deployment->skip_tags) {
            $command .= ' --skip-tags '.escapeshellarg($deployment->skip_tags);
        }

        if ($deployment->forks) {
            $command .= ' --forks '.intval($deployment->forks);
        }

        if ($deployment->start_at_task) {
            $command .= ' --start-at-task '.escapeshellarg($deployment->start_at_task);
        }

        if ($deployment->remote_user) {
            $command .= ' --user '.escapeshellarg($deployment->remote_user);
        }

        if ($deployment->extra_args) {
            $extraArgs = trim($deployment->extra_args);
            if (preg_match('/[;&|`$><\r\n]|\$\(/', $extraArgs)) {
                throw new \RuntimeException('extra_args contains disallowed shell metacharacters.');
            }
            $command .= ' '.$extraArgs;
        }

        return $command;
    }

    /**
     * Estimate the task count via `ansible-playbook --list-tasks`. Heuristic:
     * counts indented lines, so dynamic includes may be underrepresented.
     */
    protected function getTotalTasks(Deployment $deployment, string $inventoryPath, string $playbookPath): int
    {
        $binary = config('laraansible.ansible_binary', 'ansible-playbook');
        $command = escapeshellcmd($binary).' -i '.escapeshellarg($inventoryPath).' '.escapeshellarg($playbookPath).' --list-tasks';

        $extraVars = $this->resolveExtraVars($deployment);
        if (! empty($extraVars)) {
            $command .= ' --extra-vars '.escapeshellarg(json_encode($extraVars));
        }

        if ($deployment->limit_hosts) {
            $command .= ' --limit '.escapeshellarg($deployment->limit_hosts);
        }

        if ($deployment->tags) {
            $command .= ' --tags '.escapeshellarg($deployment->tags);
        }

        if ($deployment->skip_tags) {
            $command .= ' --skip-tags '.escapeshellarg($deployment->skip_tags);
        }

        try {
            $result = Process::run($command);

            if ($result->successful()) {
                $taskCount = 0;

                foreach (explode("\n", $result->output()) as $line) {
                    if (preg_match('/^\s{4,}/', $line) && ! str_contains($line, 'tasks:') && ! str_contains($line, 'play:')) {
                        $taskCount++;
                    }
                }

                return max(1, $taskCount);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to count tasks: '.$e->getMessage());
        }

        return 1;
    }

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
     * Parse Ansible PLAY RECAP lines. A host counts as successful only when it
     * had no failed/unreachable tasks at all.
     *
     * @return array{total_hosts: int, hosts_with_success: int, hosts_with_failure: int}|null
     */
    protected function parsePlayRecap(string $output): ?array
    {
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

                if ($hasSuccess && ! $hasFailure) {
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
     * Remove a deployment's entire run directory. Confined to the ansible runs
     * root so it can never delete an unrelated path.
     */
    public function cleanup(string $runDir): void
    {
        if ($runDir === '' || ! is_dir($runDir)) {
            return;
        }

        $runsRoot = storage_path('app/ansible/runs');
        if (! str_starts_with($runDir, $runsRoot)) {
            Log::warning("Refusing to clean up path outside runs dir: {$runDir}");

            return;
        }

        File::deleteDirectory($runDir);
    }
}
