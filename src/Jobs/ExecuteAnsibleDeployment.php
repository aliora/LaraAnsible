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

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 0; // No timeout

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Deployment $deployment
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AnsibleService $ansibleService): void
    {
        $ansibleService->executeDeployment($this->deployment);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->deployment->appendLog("\n\n=== ERROR ===\n".$exception->getMessage()."\n");
        $this->deployment->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        // Remove any leftover run artifacts from the failed deployment.
        app(AnsibleService::class)->cleanup(
            storage_path('app/ansible/runs/'.$this->deployment->id)
        );
    }
}
