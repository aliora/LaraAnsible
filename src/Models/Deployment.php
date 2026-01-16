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
        'cli_check_flags',
        'cli_target_flags',
        'cli_limit',
        'cli_tags',
        'cli_skip_tags',
        'cli_start_at_task',
        'cli_forks',
        'cli_verbosity',
        'command_output',
        'started_at',
        'completed_at',
        'exit_code',
    ];

    protected $casts = [
        'inventory_ids' => 'array',
        'cli_check_flags' => 'array',
        'cli_target_flags' => 'array',
        'cli_forks' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'exit_code' => 'integer',
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
