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

        $isFinished = in_array($status, ['success', 'failed', 'warning']);

        $output = $deployment ? $deployment->readLog() : '';

        return view('laraansible::livewire.terminal-viewer', [
            'output' => $output !== '' ? $output : __('laraansible::laraansible.waiting_for_log'),
            'status' => $status,
            'isFinished' => $isFinished,
        ]);
    }
}
