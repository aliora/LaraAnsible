<?php

namespace VisioSoft\LaraAnsible\Filament\Concerns;

use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Services\DeploymentService;

/**
 * Guards against launching a job while another pending/running job already
 * targets the same inventory: the launch is intercepted and a resolution modal
 * is shown instead. Any Livewire component hosting a job-launch action must
 * `use` this trait so `resolveInventoryConflict` resolves via its
 * `<name>Action()` method.
 */
trait HasInventoryConflictGuard
{
    /**
     * Returns true (caller must stop) when the launch collides with an active
     * job and the resolution modal was mounted. replaceMountedAction() is
     * required: we run inside another action's callback, and a nested
     * mountAction() would fail to resolve.
     *
     * @param  array<int|string>  $inventoryIds
     * @param  array<string, mixed>  $extraVars
     */
    public function guardInventoryConflict(array $inventoryIds, int $taskTemplateId, array $extraVars): bool
    {
        $conflicts = app(DeploymentService::class)->runningConflicts($inventoryIds);

        if ($conflicts->isEmpty()) {
            return false;
        }

        $this->replaceMountedAction('resolveInventoryConflict', [
            'inventory_ids' => array_values($inventoryIds),
            'task_template_id' => $taskTemplateId,
            'extra_vars' => $extraVars,
            'conflict_ids' => $conflicts->pluck('id')->all(),
        ]);

        return true;
    }

    public function resolveInventoryConflictAction(): Action
    {
        return Action::make('resolveInventoryConflict')
            ->requiresConfirmation()
            ->color('danger')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalHeading(__('laraansible::laraansible.conflict_heading'))
            ->modalDescription(function (Action $action): HtmlString {
                $conflictIds = $action->getArguments()['conflict_ids'] ?? [];
                $rows = Deployment::with(['taskTemplate', 'user'])
                    ->whereIn('id', $conflictIds)
                    ->get()
                    ->map(function (Deployment $d): string {
                        $name = e($d->taskTemplate?->name ?? __('laraansible::laraansible.job_number', ['id' => $d->id]));
                        $by = e($d->user?->name ?? '-');
                        $when = $d->started_at?->format('d.m.Y H:i:s') ?? __('laraansible::laraansible.queued');

                        return "• {$name} — {$by} — {$when}";
                    })
                    ->implode('<br>');

                return new HtmlString(
                    __('laraansible::laraansible.conflict_body_intro').'<br>'.$rows.
                    '<br><br>'.__('laraansible::laraansible.conflict_body_question')
                );
            })
            ->modalSubmitActionLabel(__('laraansible::laraansible.yes_stop_and_run'))
            ->modalCancelActionLabel(__('laraansible::laraansible.cancel'))
            ->action(function (Action $action): void {
                $args = $action->getArguments();
                $svc = app(DeploymentService::class);

                Deployment::whereIn('id', $args['conflict_ids'] ?? [])
                    ->get()
                    ->each(fn (Deployment $d) => $svc->cancel($d));

                $svc->createWithInventoryIds(
                    $args['inventory_ids'],
                    (int) $args['task_template_id'],
                    extraVars: $args['extra_vars'] ?? [],
                );
            });
    }
}
