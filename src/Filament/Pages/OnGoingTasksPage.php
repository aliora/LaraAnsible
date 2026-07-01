<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;

class OnGoingTasksPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $title = 'Activity Log';

    protected static ?string $slug = 'ansible/on-going-tasks';

    protected string $view = 'laraansible::pages.on-going-tasks';

    /**
     * Polling interval in seconds for refreshing deployment status.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Deployment::query()
                    ->with(['taskTemplate', 'user'])
                    // Show all deployments, including history
                    ->orderByDesc('created_at')
            )
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('taskTemplate.name')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-command-line'),
                Tables\Columns\TextColumn::make('total_hosts')
                    ->label('Hosts')
                    ->state(function (Deployment $record): int {
                        $inventoryIds = $record->inventory_ids ?? [];
                        if (empty($inventoryIds)) {
                            return 0;
                        }
                        $inventories = Inventory::whereIn('id', $inventoryIds)->get();
                        $totalHosts = 0;
                        foreach ($inventories as $inventory) {
                            if (! empty($inventory->script)) {
                                preg_match_all('/^([a-zA-Z0-9_.-]+)\s+ansible_host=/m', $inventory->script, $matches);
                                $totalHosts += count($matches[1] ?? []);
                            } elseif (! empty($inventory->hosts_entry)) {
                                $totalHosts += count($inventory->hosts_entry);
                            }
                        }

                        return $totalHosts;
                    })
                    ->badge()
                    ->color('info')
                    ->suffix(' hosts'),
                Tables\Columns\ViewColumn::make('progress')
                    ->label('Progress')
                    ->view('laraansible::filament.columns.progress-bar'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'warning' => 'warning',
                        'running' => 'info',
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'warning' => 'heroicon-o-exclamation-triangle',
                        'running' => 'heroicon-o-arrow-path',
                        'success' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-clock',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'warning' => 'Warning',
                        'running' => 'Running',
                        'success' => 'Successful',
                        'failed' => 'Failed',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Started By')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('log_id')
                    ->label('Log ID')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Log ID copied')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started At')
                    ->dateTime('d.m.Y H:i:s')
                    ->description(fn (Deployment $record): ?string => $record->completed_at ? 'Ended: '.$record->completed_at->format('d.m.Y H:i:s') : null)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'warning' => 'Warning',
                        'running' => 'Running',
                        'success' => 'Successful',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                // All row operations grouped under an "İşlemler" dropdown button.
                Actions\ActionGroup::make([
                    Actions\Action::make('watch_terminal')
                        ->label('Logs')
                        ->icon('heroicon-o-computer-desktop')
                        ->color('info')
                        ->modalHeading(fn (Deployment $record): string => "Terminal: {$record->taskTemplate?->name}")
                        ->modalContent(function (Deployment $record): HtmlString {
                            return new HtmlString(Blade::render(
                                '<livewire:terminal-viewer :deployment-id="$id" />',
                                ['id' => $record->id]
                            ));
                        })
                        ->modalWidth('4xl')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),
                    Actions\Action::make('repeat_job')
                        ->label('Repeat')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Repeat Job')
                        ->modalDescription(fn (Deployment $record): string => "Do you want to repeat the job '{$record->taskTemplate?->name}' with the same configuration?")
                        ->modalSubmitActionLabel('Yes, Repeat')
                        ->action(function (Deployment $record): void {
                            app(DeploymentService::class)->createWithInventoryIds(
                                $record->inventory_ids ?? [],
                                $record->task_template_id,
                                extraVars: $record->extra_vars ?? [],
                            );
                        })
                        ->successNotificationTitle('Job repeated successfully'),
                    Actions\Action::make('cancel_job')
                        ->label('Stop')
                        ->icon('heroicon-o-stop-circle')
                        ->color('danger')
                        ->visible(fn (Deployment $record): bool => in_array($record->status, ['running', 'pending'], true))
                        ->requiresConfirmation()
                        ->modalHeading('Stop Job')
                        ->modalDescription(fn (Deployment $record): string => "Stop the running job '{$record->taskTemplate?->name}'? This kills its ansible process on the controller.")
                        ->modalSubmitActionLabel('Yes, Stop')
                        ->action(function (Deployment $record): void {
                            app(DeploymentService::class)->cancel($record);
                        })
                        ->successNotificationTitle('Job stopped'),
                ])
                    ->label('İşlemler')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('primary')
                    ->button(),
            ], RecordActionsPosition::BeforeColumns)
            ->headerActions([
                Actions\Action::make('create_new_job')
                    ->label('New Job')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading('Start New Ansible Job')
                    ->form([
                        Forms\Components\Select::make('inventory_ids')
                            ->label('Target Hosts')
                            ->options(Inventory::pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                        ...FormSchemaHelper::jobInputsSchema(),
                    ])
                    ->action(function (array $data): void {
                        app(DeploymentService::class)->createWithInventoryIds(
                            $data['inventory_ids'],
                            (int) $data['task_template_id'],
                            extraVars: FormSchemaHelper::collectInputVars($data),
                        );
                    }),
            ])
            ->emptyStateHeading('No activities found')
            ->emptyStateDescription('Use the "New Job" button to start a deployment.')
            ->emptyStateIcon('heroicon-o-play-circle');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
