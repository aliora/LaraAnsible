<?php

namespace VisioSoft\LaraAnsible\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use VisioSoft\LaraAnsible\Models\Deployment;

class LatestDeployments extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Deployment::query()->latest()->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('laraansible::laraansible.id')),
                Tables\Columns\TextColumn::make('taskTemplate.name')
                    ->label(__('laraansible::laraansible.task')),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('laraansible::laraansible.user')),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'warning',
                        'info' => 'running',
                        'success' => 'success',
                        'danger' => 'failed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => __('laraansible::laraansible.status_pending'),
                        'warning' => __('laraansible::laraansible.status_warning'),
                        'running' => __('laraansible::laraansible.status_running'),
                        'success' => __('laraansible::laraansible.status_success'),
                        'failed' => __('laraansible::laraansible.status_failed'),
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->label(__('laraansible::laraansible.started')),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->label(__('laraansible::laraansible.completed')),
            ])
            ->heading(__('laraansible::laraansible.latest_deployments'));
    }
}
