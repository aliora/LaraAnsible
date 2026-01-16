<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'task_template_id',
        'user_id',
        'inventory_ids',
        'inventory_file',
        'status',
        'command_input',
        'extra_args',
        'cli_flags',
        'limit_hosts',
        'tags',
        'skip_tags',
        'forks',
        'start_at_task',
        'remote_user',
        'command_output',
        'started_at',
        'completed_at',
        'exit_code',
    ];

    protected $casts = [
        'inventory_ids' => 'array',
        'cli_flags' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'exit_code' => 'integer',
        'forks' => 'integer',
    ];

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'));
    }
}
