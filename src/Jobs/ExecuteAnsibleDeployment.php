<?php

namespace VisioSoft\LaraAnsible\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Services\AnsibleService;

class ExecuteAnsibleDeployment implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    /**
     * Single attempt — an ansible run must never be silently retried.
     */
    public int $tries = 1;

    public function __construct(
        public Deployment $deployment
    ) {
        $this->onConnection(config('laraansible.queue_connection'));
        $this->onQueue(config('laraansible.queue'));
    }

    /**
     * Long playbooks outlive the queue connection's retry_after, so the worker
     * would re-reserve the still-running job and fail it with "attempted too
     * many times" mid-run. A time-based ceiling replaces the attempt-count
     * check; the run is left alone until it exceeds this window.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(AnsibleService $ansibleService): void
    {
        $ansibleService->executeDeployment($this->deployment);
    }

    public function failed(\Throwable $exception): void
    {
        $this->deployment->appendLog("\n\n=== ERROR ===\n".$exception->getMessage()."\n");
        $this->deployment->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        app(AnsibleService::class)->cleanup(
            storage_path('app/ansible/runs/'.$this->deployment->id)
        );
    }
}
