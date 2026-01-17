<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Services\DeploymentService;
use Filament\Forms;

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
            ->poll('12s')
            ->columns([
                Tables\Columns\TextColumn::make('taskTemplate.name')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-command-line'),
                Tables\Columns\TextColumn::make('total_hosts')
                    ->label('Hosts')
                    ->badge()
                    ->color('info')
                    ->suffix(' hosts'),
                Tables\Columns\TextColumn::make('progress')
                    ->label('Progress')
                    ->formatStateUsing(function ($state, Deployment $record): HtmlString {
                        $progress = $state ?? 0;
                        $processed = $record->processed_hosts ?? 0;
                        $total = $record->total_hosts ?? 0;

                        $colorClass = match (true) {
                            $progress >= 100 => 'bg-green-500',
                            $progress >= 50 => 'bg-blue-500',
                            $progress > 0 => 'bg-amber-500',
                            default => 'bg-gray-300',
                        };

                        return new HtmlString(
                            '<div class="flex items-center gap-2 min-w-[120px]">'.
                            '<div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">'.
                            '<div class="h-full '.$colorClass.' rounded-full transition-all duration-300" style="width: '.$progress.'%"></div>'.
                            '</div>'.
                            '<span class="text-xs font-medium text-gray-600 dark:text-gray-400 whitespace-nowrap">'.$processed.'/'.$total.'</span>'.
                            '</div>'
                        );
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'running',
                        'success' => 'success',
                        'danger' => 'failed',
                    ])
                    ->icons([
                        'heroicon-o-clock' => 'pending',
                        'heroicon-o-arrow-path' => 'running',
                        'heroicon-o-check-circle' => 'success',
                        'heroicon-o-x-circle' => 'failed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Successful',
                        'failed' => 'Failed',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Started By')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started At')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Successful',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Actions\Action::make('watch_terminal')
                    ->label('Watch Output')
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
            ])
            ->headerActions([
                Actions\Action::make('create_new_job')
                    ->label('New Job')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading('Start New Ansible Job')
                    ->form([
                        Forms\Components\Select::make('inventory_ids')
                            ->label('Target Hosts')
                            ->options(\VisioSoft\LaraAnsible\Models\Inventory::pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                        FormSchemaHelper::taskTemplateSelect(),
                    ])
                    ->action(function (array $data): void {
                        app(DeploymentService::class)->createWithInventoryIds(
                            $data['inventory_ids'],
                            $data['task_template_id']
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
