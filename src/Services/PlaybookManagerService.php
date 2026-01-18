<?php

namespace VisioSoft\LaraAnsible\Services;

use VisioSoft\LaraAnsible\Models\Deployment;

class PlaybookManagerService
{
    /**
     * Create playbook file from deployment
     */
    public function createPlaybookFile(Deployment $deployment): string
    {
        $taskTemplate = $deployment->taskTemplate;
        $content = $this->getPlaybookContent($taskTemplate);

        if (!$content) {
            throw new \Exception('No playbook content or valid playbook path found');
        }

        return $this->writePlaybookFile($deployment->id, $content);
    }

    /**
     * Get playbook content from task template
     */
    protected function getPlaybookContent($taskTemplate): ?string
    {
        $content = $taskTemplate->playbook_content;

        if (!$content && $taskTemplate->playbook_path && file_exists($taskTemplate->playbook_path)) {
            $content = file_get_contents($taskTemplate->playbook_path);
        }

        return $content;
    }

    /**
     * Write playbook content to file
     */
    protected function writePlaybookFile(int $deploymentId, string $content): string
    {
        $path = storage_path("app/ansible/playbook_{$deploymentId}.yml");
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Create template files from deployment
     */
    public function createTemplateFiles(Deployment $deployment, string $playbookPath): void
    {
        $templates = $deployment->taskTemplate->templates ?? [];

        if (empty($templates)) {
            return;
        }

        $playbookDir = dirname($playbookPath);
        $templatesDir = $playbookDir . '/templates';

        if (!is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }

        foreach ($templates as $template) {
            $path = $templatesDir . '/' . $template['name'];
            file_put_contents($path, $template['content']);
        }
    }
}
