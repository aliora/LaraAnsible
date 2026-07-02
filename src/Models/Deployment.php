<?php

namespace VisioSoft\LaraAnsible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deployment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'task_template_id',
        'user_id',
        'job_id',
        'inventory_ids',
        'target_ip',
        'playbook_name',
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

    /**
     * Every deployment gets a job_id (names its log file); defaults to the row
     * id but stays a distinct, user-visible column.
     */
    protected static function booted(): void
    {
        static::created(function (Deployment $deployment): void {
            if (blank($deployment->job_id)) {
                $deployment->job_id = (string) $deployment->id;
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
     * Log file name for this run, e.g. JobID_25.log.
     */
    public function logFileName(): string
    {
        return 'JobID_'.($this->job_id ?: $this->id).'.log';
    }

    /**
     * Absolute path of this deployment's ansible log file:
     * storage/logs/ansible-playbook/JobID_<id>.log
     */
    public function logPath(): string
    {
        return storage_path('logs/ansible-playbook/'.$this->logFileName());
    }

    /**
     * Relative (storage-rooted) path of the log file, e.g.
     * logs/ansible-playbook/JobID_25.log. Derived from job_id so no extra column
     * is stored. Exposed as $deployment->log_file_path.
     */
    public function getLogFilePathAttribute(): string
    {
        return 'logs/ansible-playbook/'.$this->logFileName();
    }

    /**
     * Legacy log path used before the JobID_ prefix (storage/.../<id>.log).
     * Kept so old runs stay viewable after the rename.
     */
    protected function legacyLogPath(): string
    {
        return storage_path('logs/ansible-playbook/'.($this->job_id ?: $this->id).'.log');
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

    /** Read the full log file, falling back to the pre-rename name for old runs. */
    public function readLog(): string
    {
        $path = $this->logPath();
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        $legacy = $this->legacyLogPath();

        return is_file($legacy) ? (string) file_get_contents($legacy) : '';
    }
}
