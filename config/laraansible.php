<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Filament navigation group
    |--------------------------------------------------------------------------
    */
    'navigation_group' => 'Ansible Management',

    /*
    |--------------------------------------------------------------------------
    | Access gate
    |--------------------------------------------------------------------------
    | Gate ability that guards every LaraAnsible page/resource (running playbooks,
    | editing templates, viewing SSH keystores). Enforced ONLY when the ability is
    | actually defined in the host app (via Gate::define / a permission package),
    | so existing installs are not locked out. Set to null to disable the check.
    */
    'access_gate' => env('LARA_ANSIBLE_ACCESS_GATE', 'run-ansible'),

    /*
    |--------------------------------------------------------------------------
    | Park model
    |--------------------------------------------------------------------------
    | Eloquent model class backing the Inventory "park" relation. Set to null
    | (or an absent class) in host apps without parks; the form field and the
    | relation are then skipped automatically.
    */
    'park_model' => env('LARA_ANSIBLE_PARK_MODEL', 'Modules\\Parking\\Models\\Park'),

    /*
    |--------------------------------------------------------------------------
    | Ansible binary
    |--------------------------------------------------------------------------
    | Path to the ansible-playbook executable. Leave as the bare name to resolve
    | via PATH, or set an absolute path (e.g. /usr/bin/ansible-playbook) in prod.
    */
    'ansible_binary' => env('LARA_ANSIBLE_BINARY', 'ansible-playbook'),

    /*
    |--------------------------------------------------------------------------
    | Host key checking
    |--------------------------------------------------------------------------
    | When false, ansible does not prompt/fail on unknown SSH host keys. Required
    | for unattended runs against brand-new hosts (not yet in known_hosts).
    */
    'host_key_checking' => env('LARA_ANSIBLE_HOST_KEY_CHECKING', false),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Connection + queue the ExecuteAnsibleDeployment job runs on. Point these at
    | a dedicated queue whose `retry_after` is LONGER than the longest playbook,
    | so a long run is never re-reserved and executed twice. Null = default.
    */
    'queue_connection' => env('LARA_ANSIBLE_QUEUE_CONNECTION', 'ansible'),
    'queue' => env('LARA_ANSIBLE_QUEUE', 'ansible'),

    /*
    |--------------------------------------------------------------------------
    | Log retention (days)
    |--------------------------------------------------------------------------
    | ansible:prune-logs deletes JobID_*.log files older than this. 0 disables.
    */
    'log_retention_days' => (int) env('LARA_ANSIBLE_LOG_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Verbose diagnostics
    |--------------------------------------------------------------------------
    | When true, the runner logs verbose inventory/command diagnostics. Keep OFF
    | in production — inventory content includes hostnames/SSH details.
    */
    'debug' => (bool) env('LARA_ANSIBLE_DEBUG', false),
];
