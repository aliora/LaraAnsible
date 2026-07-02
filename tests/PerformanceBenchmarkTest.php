<?php

namespace VisioSoft\LaraAnsible\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VisioSoft\LaraAnsible\Database\Seeders\PerformanceSeeder;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class BenchUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

class PerformanceBenchmarkTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('LARA_ANSIBLE_BENCH_HOST', '127.0.0.1'),
            'port' => env('LARA_ANSIBLE_BENCH_PORT', '5432'),
            'database' => env('LARA_ANSIBLE_BENCH_DB', 'laraansible_bench'),
            'username' => env('LARA_ANSIBLE_BENCH_USER', get_current_user()),
            'password' => env('LARA_ANSIBLE_BENCH_PASS', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('auth.providers.users.model', BenchUser::class);
        $app['config']->set('laraansible.park_model', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('LARA_ANSIBLE_BENCH') !== '1') {
            $this->markTestSkipped('Set LARA_ANSIBLE_BENCH=1 (Postgres bench DB required) to run this benchmark.');
        }

        DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
        DB::statement('CREATE SCHEMA public');

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_high_volume_query_performance(): void
    {
        $counts = [
            'users' => (int) env('LARA_ANSIBLE_BENCH_USERS', 50),
            'keystores' => (int) env('LARA_ANSIBLE_BENCH_KEYSTORES', 100),
            'task_templates' => (int) env('LARA_ANSIBLE_BENCH_TASK_TEMPLATES', 500),
            'ansible_templates' => (int) env('LARA_ANSIBLE_BENCH_ANSIBLE_TEMPLATES', 300),
            'inventories' => (int) env('LARA_ANSIBLE_BENCH_INVENTORIES', 20000),
            'deployments' => (int) env('LARA_ANSIBLE_BENCH_DEPLOYMENTS', 100000),
        ];

        $seedStart = microtime(true);
        $seeded = (new PerformanceSeeder)->seed($counts);
        $seedMs = (microtime(true) - $seedStart) * 1000;

        $this->line('');
        $this->line('=== SEED (PostgreSQL) ===');
        foreach ($seeded as $table => $n) {
            $this->line(sprintf('  %-20s %s rows', $table, number_format($n)));
        }
        $this->line(sprintf('  %-20s %s ms', 'seed_time', number_format($seedMs, 0)));

        $newList = $this->measure('list_render_NEW (50 rows, fixed)', function () {
            $rows = Deployment::query()
                ->with(['taskTemplate', 'user'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            $sink = [];
            foreach ($rows as $deployment) {
                $sink[] = [
                    $deployment->inventoryNames(),
                    $deployment->hostCount(),
                    $deployment->statusLabel(),
                    $deployment->statusColor(),
                    $deployment->statusIcon(),
                ];
            }

            return count($sink);
        });

        $oldList = $this->measure('list_render_OLD (50 rows, pre-fix)', function () {
            $rows = Deployment::query()
                ->with(['taskTemplate', 'user'])
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            foreach ($rows as $deployment) {
                $ids = $deployment->inventory_ids ?? [];
                Inventory::whereIn('id', $ids)->pluck('name')->join(', ');
                Inventory::whereIn('id', $ids)->pluck('name')->join(', ');
                $inventories = Inventory::whereIn('id', $ids)->get();
                $total = 0;
                foreach ($inventories as $inventory) {
                    if (! empty($inventory->script)) {
                        preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', $inventory->script, $matches);
                        $total += count($matches[1] ?? []);
                    } elseif (! empty($inventory->hosts_entry)) {
                        $total += count($inventory->hosts_entry);
                    }
                }
            }

            return count($rows);
        });

        $stats = $this->measure('stats_widget (5 aggregates)', function () {
            return [
                Inventory::where('is_active', true)->count(),
                TaskTemplate::where('is_active', true)->count(),
                Deployment::count(),
                Deployment::where('status', 'success')->count(),
                Deployment::where('status', 'running')->count(),
            ];
        });

        $filter = $this->measure('status_filter (running, 25 rows)', function () {
            return Deployment::query()
                ->where('status', 'running')
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
                ->count();
        });

        $this->line('');
        $this->line('=== HOT PATHS ===');
        $this->line(sprintf('  %-38s %10s %8s', 'path', 'time(ms)', 'queries'));
        foreach ([$newList, $oldList, $stats, $filter] as $m) {
            $this->line(sprintf('  %-38s %10s %8d', $m['label'], number_format($m['ms'], 2), $m['queries']));
        }

        $explain = DB::select(
            'EXPLAIN SELECT * FROM '.(new Deployment)->getTable()." WHERE status = 'running' ORDER BY created_at DESC LIMIT 25"
        );
        $this->line('');
        $this->line('=== EXPLAIN status_filter ===');
        foreach ($explain as $row) {
            $this->line('  '.reset($row));
        }
        $this->line('');

        $this->assertSame($counts['deployments'], $seeded['deployments']);
        $this->assertLessThan($oldList['queries'], $newList['queries']);
        $this->assertLessThanOrEqual(60, $newList['queries']);
    }

    protected function measure(string $label, callable $fn): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $start = microtime(true);
        $fn();
        $ms = (microtime(true) - $start) * 1000;

        $queries = count($connection->getQueryLog());
        $connection->disableQueryLog();

        return ['label' => $label, 'ms' => $ms, 'queries' => $queries];
    }

    protected function line(string $text): void
    {
        fwrite(STDERR, $text.PHP_EOL);
    }
}
