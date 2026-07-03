<?php

namespace VisioSoft\LaraAnsible\Livewire;

use Illuminate\Support\HtmlString;
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
        $output = $output !== '' ? $output : __('laraansible::laraansible.waiting_for_log');

        return view('laraansible::livewire.terminal-viewer', [
            'output' => $this->colorize($output),
            'status' => $status,
            'statusColor' => $this->statusColor($status),
            'isFinished' => $isFinished,
        ]);
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'success' => 'bg-green-500/15 text-green-400 ring-green-500/30',
            'failed' => 'bg-red-500/15 text-red-400 ring-red-500/30',
            'warning' => 'bg-amber-500/15 text-amber-400 ring-amber-500/30',
            'running', 'pending' => 'bg-blue-500/15 text-blue-400 ring-blue-500/30',
            default => 'bg-gray-500/15 text-gray-400 ring-gray-500/30',
        };
    }

    protected function colorize(string $output): HtmlString
    {
        $lines = preg_split('/\r\n|\r|\n/', $output);

        $html = array_map(function (string $line): string {
            $class = $this->lineColor($line);
            $escaped = e($line);

            return $escaped === '' ? '' : '<span class="'.$class.'">'.$escaped.'</span>';
        }, $lines);

        return new HtmlString(implode("\n", $html));
    }

    protected function lineColor(string $line): string
    {
        $trimmed = ltrim($line);

        return match (true) {
            str_starts_with($trimmed, '===') => 'text-cyan-300 font-semibold',
            str_starts_with($trimmed, 'PLAY') || str_starts_with($trimmed, 'TASK') || str_starts_with($trimmed, 'PLAY RECAP') => 'text-sky-300 font-semibold',
            preg_match('/^(fatal|failed|\[ERROR\])|unreachable|Task failed|error=[1-9]|failed=[1-9]/i', $trimmed) === 1 => 'text-red-400',
            preg_match('/^\[WARNING\]|warning|changed=[1-9]/i', $trimmed) === 1 => 'text-amber-300',
            str_starts_with($trimmed, 'ok:') || str_starts_with($trimmed, 'changed:') => 'text-green-400',
            str_starts_with($trimmed, 'skipping:') || str_starts_with($trimmed, 'ignoring:') => 'text-gray-500',
            default => 'text-gray-300',
        };
    }
}
