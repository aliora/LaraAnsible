<?php

namespace VisioSoft\LaraAnsible\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use VisioSoft\LaraAnsible\Models\Deployment;

class AnsibleService
{
    protected InventoryBuilderService $inventoryBuilder;
    protected PlaybookManagerService $playbookManager;
    protected CommandBuilderService $commandBuilder;
    protected OutputParserService $outputParser;

    public function __construct(
        InventoryBuilderService $inventoryBuilder,
        PlaybookManagerService $playbookManager,
        CommandBuilderService $commandBuilder,
        OutputParserService $outputParser
    ) {
        $this->inventoryBuilder = $inventoryBuilder;
        $this->playbookManager = $playbookManager;
        $this->commandBuilder = $commandBuilder;
        $this->outputParser = $outputParser;
    }

    /**
     * Execute an Ansible deployment
     */
    public function executeDeployment(Deployment $deployment): void
    {
        $deployment->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            Log::info("Starting deployment {$deployment->id}");

            // Create temporary files using specialized services
            $inventoryData = $this->inventoryBuilder->createInventoryFile($deployment);
            $inventoryPath = $inventoryData['path'];
            $totalHostsCount = $inventoryData['host_count'];
            Log::info("Created inventory file: {$inventoryPath} with {$totalHostsCount} hosts");

            $playbookPath = $this->playbookManager->createPlaybookFile($deployment);
            Log::info("Created playbook file: {$playbookPath}");

            // Create templates directory and files
            $this->playbookManager->createTemplateFiles($deployment, $playbookPath);
            Log::info('Created template files');

            // Build ansible-playbook command
            $commandData = $this->commandBuilder->buildAnsibleCommand($deployment, $inventoryPath, $playbookPath);
            Log::info("Command to execute: {$commandData['display_command']}");

            // Get total tasks count
            $totalPlaybookTasks = $this->commandBuilder->getTotalTasks($deployment, $inventoryPath, $playbookPath);
            $totalTasks = max(1, $totalPlaybookTasks);
            Log::info("Total playbook tasks: {$totalTasks}");

            // Store command input before execution
            $commandInput = $this->commandBuilder->buildCommandInput($commandData, $inventoryPath, $playbookPath);

            $deployment->update([
                'command_input' => $commandInput,
                'total_hosts' => $totalTasks,
                'processed_hosts' => 0,
            ]);

            // Execute the command with streaming output
            $outputBuffer = '';

            $result = Process::forever()
                ->env([
                    'PYTHONUNBUFFERED' => '1',
                    'ANSIBLE_STDOUT_CALLBACK' => 'default',
                ])
                ->run($commandData['wrapped_command'], function ($type, $output) use (&$outputBuffer, $totalTasks, $deployment) {
                    $outputBuffer .= $output;

                    // Parse progress using OutputParserService
                    $progressData = $this->outputParser->parseTaskProgress($outputBuffer, $totalTasks);

                    // Update deployment with partial output and progress
                    $deployment->update([
                        'command_output' => $outputBuffer,
                        'progress' => $progressData['progress'],
                        'processed_hosts' => $progressData['processed_tasks'],
                    ]);
                });

            Log::info("Command executed with exit code: {$result->exitCode()}");

            // Final update with complete output and status
            $finalOutput = $result->output();
            $status = $this->outputParser->resolveDeploymentStatus($finalOutput, $result->exitCode());

            $deployment->update([
                'status' => $status,
                'command_output' => $finalOutput,
                'exit_code' => $result->exitCode(),
                'completed_at' => now(),
                'progress' => 100,
            ]);

            if (!$result->successful()) {
                Log::error("Deployment {$deployment->id} failed with exit code {$result->exitCode()}", [
                    'output' => $result->output(),
                    'error_output' => $result->errorOutput(),
                ]);
            }

            // Clean up temporary files
            $this->cleanup($inventoryPath, $playbookPath);
            Log::info("Deployment {$deployment->id} completed");

        } catch (\Exception $e) {
            Log::error("Deployment {$deployment->id} exception: {$e->getMessage()}", [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            $deployment->update([
                'status' => 'failed',
                'command_output' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Clean up temporary files
     */
    protected function cleanup(string $inventoryPath, string $playbookPath): void
    {
        // Remove inventory file
        if (file_exists($inventoryPath) && str_starts_with($inventoryPath, storage_path('app/ansible/'))) {
            unlink($inventoryPath);
        }

        // Remove playbook and templates directory
        if (file_exists($playbookPath) && str_starts_with($playbookPath, storage_path('app/ansible/'))) {
            $playbookDir = dirname($playbookPath);
            $templatesDir = $playbookDir . '/templates';

            // Remove templates directory if exists
            if (is_dir($templatesDir)) {
                $files = glob($templatesDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($templatesDir);
            }

            unlink($playbookPath);
        }
    }
}
