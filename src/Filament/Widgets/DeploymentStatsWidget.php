<?php

namespace VisioSoft\LaraAnsible\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Models\TaskTemplate;

class DeploymentStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make(__('laraansible::laraansible.total_servers'), Inventory::where('is_active', true)->count())
                ->description(__('laraansible::laraansible.total_servers_description'))
                ->descriptionIcon('heroicon-o-server')
                ->color('success'),
            Stat::make(__('laraansible::laraansible.task_templates'), TaskTemplate::where('is_active', true)->count())
                ->description(__('laraansible::laraansible.task_templates_description'))
                ->descriptionIcon('heroicon-o-document-text')
                ->color('primary'),
            Stat::make(__('laraansible::laraansible.total_deployments'), Deployment::count())
                ->description(__('laraansible::laraansible.successful_count', ['count' => Deployment::where('status', 'success')->count()]))
                ->descriptionIcon('heroicon-o-rocket-launch')
                ->color('info'),
            Stat::make(__('laraansible::laraansible.running_deployments'), Deployment::where('status', 'running')->count())
                ->description(__('laraansible::laraansible.currently_executing'))
                ->descriptionIcon('heroicon-o-play')
                ->color('warning'),
        ];
    }
}
