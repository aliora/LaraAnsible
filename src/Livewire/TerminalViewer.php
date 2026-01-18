<?php

namespace VisioSoft\LaraAnsible\Livewire;

use Livewire\Component;
use VisioSoft\LaraAnsible\Models\Deployment;

class TerminalViewer extends Component
{
    public int $deploymentId;

    public function mount(int $deploymentId)
    {
        $this->deploymentId = $deploymentId;
    }

    public function render()
    {
        $deployment = Deployment::find($this->deploymentId);
        $status = $deployment?->status ?? 'unknown';

        // Define terminal statuses
        $isFinished = in_array($status, ['success', 'failed', 'warning']);

        return view('laraansible::livewire.terminal-viewer', [
            'output' => $deployment?->command_output ?? 'Yükleniyor...',
            'status' => $status,
            'isFinished' => $isFinished,
        ]);
    }
}
