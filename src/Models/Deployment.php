<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deployment extends Model
{
    protected $fillable = [
        'task_template_id',
        'user_id',
        'log_id',
        'inventory_ids',
        'inventory_file',
        'status',
        'command_input',
        'extra_args',
        'cli_flags',
        'extra_vars',
        'limit_hosts',
        'tags',
        'skip_tags',
        'forks',
        'start_at_task',
        'remote_user',
        'started_at',
        'completed_at',
        'exit_code',
        'progress',
        'total_hosts',
        'processed_hosts',
    ];

    protected $casts = [
        'inventory_ids' => 'array',
        'cli_flags' => 'array',
        'extra_vars' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'exit_code' => 'integer',
        'forks' => 'integer',
        'progress' => 'integer',
        'total_hosts' => 'integer',
        'processed_hosts' => 'integer',
    ];

    protected static function booted(): void
    {
        // Give every deployment a log id (used to name its log file). Defaults to
        // the row id, but is a distinct column so it can be surfaced/toggled in the UI.
        static::created(function (Deployment $deployment): void {
            if (blank($deployment->log_id)) {
                $deployment->log_id = (string) $deployment->id;
                $deployment->saveQuietly();
            }
        });
    }

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\Models\User'));
    }

    /**
     * Get the inventory items count for display.
     */
    public function getInventoryCountAttribute(): int
    {
        return is_array($this->inventory_ids) ? count($this->inventory_ids) : 0;
    }

    /**
     * Absolute path of this deployment's ansible log file:
     * storage/logs/ansible-playbook/<id>.log
     */
    public function logPath(): string
    {
        $name = $this->log_id ?: $this->id;

        return storage_path('logs/ansible-playbook/'.$name.'.log');
    }

    protected function ensureLogDir(): void
    {
        $dir = dirname($this->logPath());
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /** Overwrite the log file (used for the header at run start). */
    public function writeLog(string $text): void
    {
        $this->ensureLogDir();
        file_put_contents($this->logPath(), $text, LOCK_EX);
    }

    /** Append to the log file (used for streamed output). */
    public function appendLog(string $text): void
    {
        $this->ensureLogDir();
        file_put_contents($this->logPath(), $text, FILE_APPEND | LOCK_EX);
    }

    /** Read the full log file (empty string if none yet). */
    public function readLog(): string
    {
        $path = $this->logPath();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
