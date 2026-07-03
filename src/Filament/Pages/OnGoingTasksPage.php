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
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Filament\Concerns\HasInventoryConflictGuard;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;
use VisioSoft\LaraAnsible\Services\DeploymentService;

class OnGoingTasksPage extends Page implements HasTable
{
    use AuthorizesAnsibleAccess;
    use HasInventoryConflictGuard;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'ansible/on-going-tasks';

    protected string $view = 'laraansible::pages.on-going-tasks';

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.activity_log');
    }

    public function getTitle(): string
    {
        return __('laraansible::laraansible.activity_log');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Deployment::query()
                    ->with(['taskTemplate', 'user'])
                    ->orderByDesc('created_at')
            )
            ->poll('3s')
            ->recordAction('watch_terminal')
            ->columns([
                Tables\Columns\TextColumn::make('taskTemplate.name')
                    ->label(__('laraansible::laraansible.task'))
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-command-line'),
                Tables\Columns\TextColumn::make('inventory_names')
                    ->label(__('laraansible::laraansible.inventory'))
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-server')
                    ->limit(40)
                    ->state(fn (Deployment $record): string => $record->inventoryNames())
                    ->tooltip(fn (Deployment $record): ?string => $record->resolvedInventories()->pluck('name')->join(', ') ?: null),
                Tables\Columns\TextColumn::make('total_hosts')
                    ->label(__('laraansible::laraansible.hosts'))
                    ->state(fn (Deployment $record): int => $record->hostCount())
                    ->badge()
                    ->color('info')
                    ->suffix(' '.__('laraansible::laraansible.hosts_suffix')),
                Tables\Columns\ViewColumn::make('progress')
                    ->label(__('laraansible::laraansible.progress'))
                    ->view('laraansible::filament.columns.progress-bar'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('laraansible::laraansible.status'))
                    ->badge()
                    ->color(fn (Deployment $record): string => $record->statusColor())
                    ->icon(fn (Deployment $record): string => $record->statusIcon())
                    ->formatStateUsing(fn (Deployment $record): string => $record->statusLabel()),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('laraansible::laraansible.started_by'))
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('job_id')
                    ->label(__('laraansible::laraansible.job_id'))
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage(__('laraansible::laraansible.job_id_copied'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('started_at')
                    ->label(__('laraansible::laraansible.started_at'))
                    ->dateTime('d.m.Y H:i:s')
                    ->description(fn (Deployment $record): ?string => $record->completed_at
                        ? __('laraansible::laraansible.ended_at', ['time' => $record->completed_at->format('d.m.Y H:i:s')])
                        : null)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laraansible::laraansible.status'))
                    ->options(Deployment::statusOptions()),
            ])
            ->actions(TableHelper::actionGroup([
                Actions\Action::make('watch_terminal')
                    ->label(__('laraansible::laraansible.view_log'))
                    ->icon('heroicon-o-computer-desktop')
                    ->color('info')
                    ->modalHeading(fn (Deployment $record): string => __('laraansible::laraansible.terminal_heading', ['name' => $record->taskTemplate?->name]))
                    ->modalContent(function (Deployment $record): HtmlString {
                        return new HtmlString(Blade::render(
                            '<livewire:terminal-viewer :deployment-id="$id" />',
                            ['id' => $record->id]
                        ));
                    })
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false),
                Actions\Action::make('repeat_job')
                    ->label(__('laraansible::laraansible.repeat'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('laraansible::laraansible.repeat_job'))
                    ->modalDescription(fn (Deployment $record): string => __('laraansible::laraansible.repeat_job_confirm', ['name' => $record->taskTemplate?->name]))
                    ->modalSubmitActionLabel(__('laraansible::laraansible.yes_repeat'))
                    ->action(function (Deployment $record): void {
                        $inv = $record->inventory_ids ?? [];
                        $vars = $record->extra_vars ?? [];
                        if ($this->guardInventoryConflict($inv, (int) $record->task_template_id, $vars)) {
                            return;
                        }
                        app(DeploymentService::class)->createWithInventoryIds(
                            $inv,
                            $record->task_template_id,
                            extraVars: $vars,
                        );
                    })
                    ->successNotificationTitle(__('laraansible::laraansible.job_repeated')),
                Actions\Action::make('cancel_job')
                    ->label(__('laraansible::laraansible.stop'))
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->visible(fn (Deployment $record): bool => in_array($record->status, ['running', 'pending'], true))
                    ->requiresConfirmation()
                    ->modalHeading(__('laraansible::laraansible.stop_job'))
                    ->modalDescription(fn (Deployment $record): string => __('laraansible::laraansible.stop_job_confirm', ['name' => $record->taskTemplate?->name]))
                    ->modalSubmitActionLabel(__('laraansible::laraansible.yes_stop'))
                    ->action(function (Deployment $record): void {
                        app(DeploymentService::class)->cancel($record);
                    })
                    ->successNotificationTitle(__('laraansible::laraansible.job_stopped')),
            ]), RecordActionsPosition::BeforeColumns)
            ->headerActions([
                Actions\Action::make('create_new_job')
                    ->label(__('laraansible::laraansible.new_job'))
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading(__('laraansible::laraansible.start_new_ansible_job'))
                    ->form([
                        Forms\Components\Select::make('inventory_ids')
                            ->label(__('laraansible::laraansible.target_hosts'))
                            ->options(Inventory::pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                        ...FormSchemaHelper::jobInputsSchema(),
                    ])
                    ->action(function (array $data): void {
                        $inv = $data['inventory_ids'];
                        $tpl = (int) $data['task_template_id'];
                        $vars = FormSchemaHelper::collectInputVars($data);
                        if ($this->guardInventoryConflict($inv, $tpl, $vars)) {
                            return;
                        }
                        app(DeploymentService::class)->createWithInventoryIds($inv, $tpl, extraVars: $vars);
                    }),
            ])
            ->emptyStateHeading(__('laraansible::laraansible.no_activities'))
            ->emptyStateDescription(__('laraansible::laraansible.no_activities_description'))
            ->emptyStateIcon('heroicon-o-play-circle');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
