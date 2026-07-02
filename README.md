# LaraAnsible — Filament admin for Ansible

A Filament plugin for managing Ansible inventories, keystores, task templates, and queued deployments with live console output. Deployments run `ansible-playbook` on a queue worker and stream stdout/stderr to per-run log files.

## Requirements

**PHP / framework**

- PHP `^8.2`
- Laravel `^11 | ^12 | ^13`
- Filament `^4 | ^5`
- Laravel Horizon `^5.40` (Redis queue)

**System (on the queue-worker host)**

- `ansible` (>= 2.10) — `ansible-playbook` on PATH, or set `LARA_ANSIBLE_BINARY` to its absolute path
- `openssh-client`
- `python3` (>= 3.8)
- `redis-server` (>= 6.0)

## Install

```bash
composer require visio/laraansible

# publish config (optional) and run migrations
php artisan vendor:publish --tag=laraansible-config
php artisan migrate            # do NOT use --seed in production (seeds demo data only)
```

Register the plugin on your Filament panel:

```php
use VisioSoft\LaraAnsible\LaraAnsiblePlugin;

return $panel->plugin(LaraAnsiblePlugin::make());
```

Optional dashboard widgets:

```php
use VisioSoft\LaraAnsible\Filament\Widgets\DeploymentStatsWidget;
use VisioSoft\LaraAnsible\Filament\Widgets\LatestDeployments;

->widgets([
    DeploymentStatsWidget::class,
    LatestDeployments::class,
])
```

## Production setup

**1. Dedicated queue (prevents double execution).** Ansible runs are long; if a run
exceeds the queue `retry_after`, a second worker re-reserves and runs it again on the
same hosts. Route the job to a queue whose `retry_after` exceeds your longest playbook.

`config/queue.php` → add an `ansible` connection:

```php
'ansible' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('LARA_ANSIBLE_QUEUE', 'ansible'),
    'retry_after' => (int) env('ANSIBLE_QUEUE_RETRY_AFTER', 21600), // 6h
    'block_for' => null,
    'after_commit' => false,
],
```

`config/horizon.php` → add a serialised supervisor (and list it under each `environments` block):

```php
'supervisor-ansible' => [
    'connection' => 'ansible',
    'queue' => ['ansible'],
    'balance' => 'simple',
    'maxProcesses' => 1,   // serialise: never two runs on the same host at once
    'tries' => 1,
    'timeout' => 0,        // the job sets retryUntil() instead
],
```

The package job already targets `laraansible.queue_connection` / `laraansible.queue`
(default `ansible`). Without Horizon, run: `php artisan queue:work ansible --queue=ansible`.

**2. Authorization.** Every LaraAnsible page/resource is gated behind the
`laraansible.access_gate` ability (default `run-ansible`). It is enforced **only when the
ability is defined** in your app, so define it to restrict who can run playbooks / view
keystores:

```php
Gate::define('run-ansible', fn ($user) => $user->hasRole('ops'));
```

Running a playbook is effectively remote code execution — restrict panel access accordingly.

**3. Log retention.** Per-run logs live at `storage/logs/ansible-playbook/JobID_<id>.log`.
`ansible:prune-logs` runs daily (retention from `laraansible.log_retention_days`, default 30).

## Configuration

`config/laraansible.php` (env overrides in brackets):

- `ansible_binary` [`LARA_ANSIBLE_BINARY`] — `ansible-playbook` path
- `host_key_checking` [`LARA_ANSIBLE_HOST_KEY_CHECKING`] — default `false` (needed for new hosts)
- `queue_connection` / `queue` [`LARA_ANSIBLE_QUEUE_CONNECTION` / `LARA_ANSIBLE_QUEUE`]
- `access_gate` [`LARA_ANSIBLE_ACCESS_GATE`] — default `run-ansible`
- `park_model` [`LARA_ANSIBLE_PARK_MODEL`] — Eloquent class for the Inventory "park" relation; set `null` in apps without parks (the field hides itself)
- `log_retention_days` [`LARA_ANSIBLE_LOG_RETENTION_DAYS`] — default `30`
- `debug` [`LARA_ANSIBLE_DEBUG`] — verbose diagnostics (keep OFF in prod)

## Localization

All UI strings live in `resources/lang/{en,tr,ru,ky}/laraansible.php` (English is the
base language). Override by publishing: `php artisan vendor:publish --tag=laraansible-translations`.

## Data retention

All models use SoftDeletes — deleting from the panel marks records with `deleted_at`
instead of removing rows.

## Admin workflow

Create Keystores → Inventories → Task Templates, then launch and watch Deployments
(live console via the "View Log" action).

## Notes

- Dev tools: `composer format` (Pint), `composer test` (PHPUnit via Testbench)
- Keystore private keys are encrypted at rest (DB) and written `0600` only inside the
  per-run dir, which is removed after every run.

License: MIT
