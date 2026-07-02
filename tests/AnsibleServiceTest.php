<?php

namespace VisioSoft\LaraAnsible\Tests;

use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Services\AnsibleService;

class AnsibleServiceTest extends TestCase
{
    protected function buildCommand(Deployment $deployment): array
    {
        $method = new \ReflectionMethod(AnsibleService::class, 'buildAnsibleCommand');

        return $method->invoke(new AnsibleService, $deployment, '/runs/1/inventory.ini', '/runs/1/playbook.yml');
    }

    public function test_builds_base_command_with_escaped_paths(): void
    {
        $command = $this->buildCommand(new Deployment)['display_command'];

        $this->assertStringStartsWith('ansible-playbook -i ', $command);
        $this->assertStringContainsString("'/runs/1/inventory.ini'", $command);
        $this->assertStringContainsString("'/runs/1/playbook.yml'", $command);
    }

    public function test_escapes_cli_options_as_single_arguments(): void
    {
        $deployment = new Deployment([
            'limit_hosts' => "web'; rm -rf /",
            'tags' => 'setup,deploy',
            'forks' => 10,
            'remote_user' => 'root',
        ]);

        $command = $this->buildCommand($deployment)['display_command'];

        $this->assertStringContainsString("--limit 'web'\''; rm -rf /'", $command);
        $this->assertStringContainsString("--tags 'setup,deploy'", $command);
        $this->assertStringContainsString('--forks 10', $command);
        $this->assertStringContainsString("--user 'root'", $command);
    }

    public function test_escapes_each_cli_flag(): void
    {
        $deployment = new Deployment(['cli_flags' => ['--check', '--diff; whoami']]);

        $command = $this->buildCommand($deployment)['display_command'];

        $this->assertStringContainsString("'--check'", $command);
        $this->assertStringContainsString("'--diff; whoami'", $command);
    }

    public function test_rejects_extra_args_with_shell_metacharacters(): void
    {
        foreach (['a; b', 'a | b', 'a `b`', 'a $(b)', 'a > b', "a\nb"] as $malicious) {
            try {
                $this->buildCommand(new Deployment(['extra_args' => $malicious]));
                $this->fail("extra_args '{$malicious}' should have been rejected");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('disallowed shell metacharacters', $e->getMessage());
            }
        }
    }

    public function test_allows_safe_extra_args(): void
    {
        $command = $this->buildCommand(new Deployment(['extra_args' => '-vvv --timeout 30']))['display_command'];

        $this->assertStringContainsString('-vvv --timeout 30', $command);
    }
}
