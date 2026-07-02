<?php

namespace VisioSoft\LaraAnsible\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VisioSoft\LaraAnsible\Models\AnsibleTemplate;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\Keystore;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class PerformanceSeeder extends Seeder
{
    protected int $chunk = 2000;

    public function run(): void
    {
        $this->seed([
            'users' => (int) env('LARA_ANSIBLE_SEED_USERS', 50),
            'keystores' => (int) env('LARA_ANSIBLE_SEED_KEYSTORES', 100),
            'task_templates' => (int) env('LARA_ANSIBLE_SEED_TASK_TEMPLATES', 500),
            'ansible_templates' => (int) env('LARA_ANSIBLE_SEED_ANSIBLE_TEMPLATES', 300),
            'inventories' => (int) env('LARA_ANSIBLE_SEED_INVENTORIES', 20000),
            'deployments' => (int) env('LARA_ANSIBLE_SEED_DEPLOYMENTS', 100000),
        ]);
    }

    public function seed(array $counts): array
    {
        $done = [];
        $done['users'] = $this->seedUsers($counts['users'] ?? 0);
        $done['keystores'] = $this->seedKeystores($counts['keystores'] ?? 0);
        $done['task_templates'] = $this->seedTaskTemplates($counts['task_templates'] ?? 0);
        $done['ansible_templates'] = $this->seedAnsibleTemplates($counts['ansible_templates'] ?? 0);
        $done['inventories'] = $this->seedInventories($counts['inventories'] ?? 0, max(1, $done['keystores']));
        $done['deployments'] = $this->seedDeployments($counts['deployments'] ?? 0, [
            'users' => max(1, $done['users']),
            'task_templates' => max(1, $done['task_templates']),
            'inventories' => max(1, $done['inventories']),
        ]);

        return $done;
    }

    protected function seedUsers(int $n): int
    {
        if ($n <= 0 || ! Schema::hasTable('users')) {
            return 0;
        }

        $columns = Schema::getColumnListing('users');
        $now = now()->format('Y-m-d H:i:s');
        $hasPassword = in_array('password', $columns, true);

        return $this->stream('users', $n, function (int $i) use ($now, $hasPassword): array {
            $row = [
                'name' => 'Operator '.$i,
                'email' => 'operator'.$i.'@example.test',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasPassword) {
                $row['password'] = '$2y$10$abcdefghijklmnopqrstuv';
            }

            return $row;
        });
    }

    protected function seedKeystores(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        $table = (new Keystore)->getTable();
        $now = now()->format('Y-m-d H:i:s');

        return $this->stream($table, $n, fn (int $i): array => [
            'name' => 'Key '.$i,
            'description' => 'Benchmark SSH key '.$i,
            'type' => 'ssh',
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nbench-{$i}\n-----END OPENSSH PRIVATE KEY-----",
            'public_key' => 'ssh-ed25519 AAAAbench'.$i,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function seedTaskTemplates(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        $table = (new TaskTemplate)->getTable();
        $now = now()->format('Y-m-d H:i:s');

        return $this->stream($table, $n, fn (int $i): array => [
            'name' => 'Playbook '.$i,
            'description' => 'Benchmark job template '.$i,
            'playbook_content' => "---\n- name: Bench {$i}\n  hosts: all\n  tasks:\n    - name: Ping\n      ansible.builtin.ping:\n",
            'input_vars' => json_encode([]),
            'is_active' => ($i % 7) !== 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function seedAnsibleTemplates(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        $table = (new AnsibleTemplate)->getTable();
        $now = now()->format('Y-m-d H:i:s');
        $kinds = ['task', 'template'];

        return $this->stream($table, $n, fn (int $i): array => [
            'name' => ($i % 2 === 0 ? 'task_'.$i.'.yml' : 'tmpl_'.$i.'.j2'),
            'content' => "bench content {$i}",
            'kind' => $kinds[$i % 2],
            'is_active' => ($i % 9) !== 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function seedInventories(int $n, int $keystoreMax): int
    {
        if ($n <= 0) {
            return 0;
        }

        $table = (new Inventory)->getTable();
        $now = now()->format('Y-m-d H:i:s');

        return $this->stream($table, $n, function (int $i) use ($now, $keystoreMax): array {
            $hostCount = random_int(1, 4);
            $names = [];
            $ips = [];
            for ($h = 0; $h < $hostCount; $h++) {
                $names[] = 'host'.$i.'_'.$h;
                $ips[] = $this->ip();
            }

            $useScript = ($i % 10) === 0;
            $script = null;
            if ($useScript) {
                $lines = ['[bench_group]'];
                foreach ($names as $idx => $name) {
                    $lines[] = $name.' ansible_host='.$ips[$idx];
                }
                $script = implode("\n", $lines);
            }

            return [
                'name' => 'Inventory '.$i,
                'description' => 'Benchmark inventory '.$i,
                'hostname' => $ips[0],
                'port' => 22,
                'username' => ($i % 3 === 0) ? 'root' : 'deploy',
                'keystore_id' => random_int(1, $keystoreMax),
                'source_type' => ($i % 5 === 0) ? 'dynamic' : 'manual',
                'dynamic_child_id' => ($i % 5 === 0) ? random_int(1, 100000) : null,
                'name_list' => json_encode($names),
                'ip_list' => json_encode($ips),
                'script' => $script,
                'is_active' => ($i % 8) !== 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
    }

    protected function seedDeployments(int $n, array $ctx): int
    {
        if ($n <= 0) {
            return 0;
        }

        $table = (new Deployment)->getTable();
        $statuses = ['pending', 'running', 'success', 'success', 'success', 'failed', 'warning'];
        $base = now();

        return $this->stream($table, $n, function (int $i) use ($statuses, $base, $ctx): array {
            $status = $statuses[array_rand($statuses)];
            $created = $base->copy()->subMinutes(random_int(0, 525600));
            $done = in_array($status, ['success', 'failed', 'warning'], true);

            $invCount = random_int(1, 5);
            $invIds = [];
            for ($k = 0; $k < $invCount; $k++) {
                $invIds[] = random_int(1, $ctx['inventories']);
            }

            return [
                'task_template_id' => random_int(1, $ctx['task_templates']),
                'user_id' => random_int(1, $ctx['users']),
                'job_id' => (string) $i,
                'inventory_ids' => json_encode($invIds),
                'target_ip' => $this->ip(),
                'playbook_name' => 'Playbook '.random_int(1, $ctx['task_templates']),
                'status' => $status,
                'progress' => $status === 'success' ? 100 : random_int(0, 99),
                'total_hosts' => $invCount,
                'processed_hosts' => random_int(0, $invCount),
                'exit_code' => $status === 'failed' ? random_int(1, 255) : 0,
                'started_at' => $created->format('Y-m-d H:i:s'),
                'completed_at' => $done ? $created->copy()->addMinutes(random_int(1, 60))->format('Y-m-d H:i:s') : null,
                'created_at' => $created->format('Y-m-d H:i:s'),
                'updated_at' => $created->format('Y-m-d H:i:s'),
            ];
        });
    }

    protected function stream(string $table, int $n, callable $factory): int
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $buffer = [];
        $inserted = 0;

        for ($i = 1; $i <= $n; $i++) {
            $buffer[] = array_intersect_key($factory($i), $columns);

            if (count($buffer) >= $this->chunk) {
                DB::table($table)->insert($buffer);
                $inserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
            $inserted += count($buffer);
        }

        return $inserted;
    }

    protected function ip(): string
    {
        return random_int(10, 250).'.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
    }
}
