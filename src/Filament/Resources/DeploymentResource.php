<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use VisioSoft\LaraAnsible\Filament\Resources\DeploymentResource\Pages;
use VisioSoft\LaraAnsible\Jobs\ExecuteAnsibleDeployment;
use VisioSoft\LaraAnsible\Models\Deployment;
use VisioSoft\LaraAnsible\Models\Inventory;

class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Deployment Configuration')
                    ->schema([
                        Forms\Components\Select::make('task_template_id')
                            ->relationship('taskTemplate', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment),
                        Forms\Components\Select::make('inventory_file')
                            ->label('Inventory File')
                            ->options(function () {
                                $directory = config('laraansible.inventory_directory', base_path('ansible'));
                                if (! is_dir($directory)) {
                                    return [];
                                }
                                $files = glob($directory . '/*.ini') ?: [];
                                $files = array_merge($files, glob($directory . '/*.yml') ?: []);
                                $files = array_merge($files, glob($directory . '/*.yaml') ?: []);
                                $options = [];
                                foreach ($files as $file) {
                                    $basename = basename($file);
                                    $options[$file] = $basename;
                                }
                                return $options;
                            })
                            ->searchable()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Select an inventory file from the configured directory (optional, overrides server selection)'),
                        Forms\Components\CheckboxList::make('inventory_ids')
                            ->label('Servers (Database)')
                            ->options(function () {
                                $options = ['all' => 'All Servers'];
                                $inventories = Inventory::where('is_active', true)->pluck('name', 'id')->toArray();

                                return $options + $inventories;
                            })
                            ->columns(2)
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (is_array($state) && in_array('all', $state)) {
                                    $set('inventory_ids', ['all']);
                                }
                            })
                            ->reactive()
                            ->helperText('Select from database inventories (used if no file selected)'),
                    ])
                    ->columns(2)
                    ->compact(),
                Section::make('CLI Arguments')
                    ->schema([
                        Forms\Components\CheckboxList::make('cli_check_flags')
                            ->label('Kontrol ve Test')
                            ->options([
                                '--syntax-check' => '--syntax-check (YAML syntax kontrolü)',
                                '--check' => '-C / --check (Dry-run simülasyon)',
                                '--diff' => '-D / --diff (Değişiklikleri göster)',
                                '--list-tasks' => '--list-tasks (Görevleri listele)',
                            ])
                            ->columns(2)
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment),
                        Forms\Components\CheckboxList::make('cli_target_flags')
                            ->label('Hedef ve Akış')
                            ->options([
                                '--become' => '-b / --become (Sudo ile çalıştır)',
                            ])
                            ->columns(2)
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment),
                        Forms\Components\TextInput::make('cli_limit')
                            ->label('-l / --limit')
                            ->placeholder('host1,host2 veya group_name')
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Belirli sunucu/grup ile sınırla'),
                        Forms\Components\TextInput::make('cli_tags')
                            ->label('-t / --tags')
                            ->placeholder('deploy,setup')
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Sadece bu etiketli görevleri çalıştır'),
                        Forms\Components\TextInput::make('cli_skip_tags')
                            ->label('--skip-tags')
                            ->placeholder('slow,optional')
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Bu etiketli görevleri atla'),
                        Forms\Components\TextInput::make('cli_start_at_task')
                            ->label('--start-at-task')
                            ->placeholder('Task Name')
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Bu görevden başla'),
                        Forms\Components\TextInput::make('cli_forks')
                            ->label('-f / --forks')
                            ->placeholder('5')
                            ->numeric()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Paralel sunucu sayısı'),
                        Forms\Components\Select::make('cli_verbosity')
                            ->label('Verbose Level')
                            ->options([
                                '-v' => '-v (Verbose)',
                                '-vv' => '-vv (More Verbose)',
                                '-vvv' => '-vvv (Debug)',
                                '-vvvv' => '-vvvv (Connection Debug)',
                            ])
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Çıktı detay seviyesi'),
                        Forms\Components\Textarea::make('extra_args')
                            ->label('Ek CLI Argümanları')
                            ->rows(2)
                            ->columnSpanFull()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Yukarıda olmayan ek argümanlar'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (?Deployment $record) => $record !== null),
                Section::make('Execution Details')
                    ->schema([
                        Forms\Components\Placeholder::make('status')
                            ->label('Status')
                            ->content(function (?Deployment $record) {
                                if (! $record) {
                                    return new HtmlString('<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-500/20 text-gray-700 ring-1 ring-gray-500">N/A</span>');
                                }

                                $status = $record->status ?? 'pending';
                                $styles = [
                                    'pending' => 'bg-gray-500/20 text-gray-700 ring-gray-500',
                                    'running' => 'bg-blue-500/20 text-blue-700 ring-blue-500 animate-pulse',
                                    'success' => 'bg-green-500/20 text-green-700 ring-green-500',
                                    'failed' => 'bg-red-500/20 text-red-700 ring-red-500',
                                ];

                                $class = $styles[$status] ?? 'bg-gray-500/20 text-gray-700 ring-gray-500';
                                $label = ucfirst($status);

                                return new HtmlString("<span class=\"inline-flex items-center px-2 py-1 rounded text-xs font-medium ring-1 {$class}\">{$label}</span>");
                            }),
                        Forms\Components\Placeholder::make('started_at')
                            ->content(fn (?Deployment $record) => $record?->started_at?->diffForHumans() ?? 'N/A'),
                        Forms\Components\Placeholder::make('duration')
                            ->label('Duration')
                            ->content(function (?Deployment $record) {
                                if (! $record?->started_at || ! $record?->completed_at) {
                                    return '—';
                                }
                                $seconds = $record->started_at->diffInSeconds($record->completed_at);
                                $h = intdiv($seconds, 3600);
                                $m = intdiv($seconds % 3600, 60);
                                $s = $seconds % 60;
                                $parts = [];
                                if ($h) {
                                    $parts[] = $h.'h';
                                }
                                if ($m) {
                                    $parts[] = $m.'m';
                                }
                                $parts[] = $s.'s';

                                return implode(' ', $parts);
                            }),
                        Forms\Components\Placeholder::make('exit_code')
                            ->content(fn (?Deployment $record) => $record?->exit_code ?? 'N/A'),
                    ])
                    ->columns(4)
                    ->compact()
                    ->hidden(fn (?Deployment $record) => $record === null),
                Section::make('Command Input')
                    ->schema([
                        Forms\Components\Textarea::make('command_input')
                            ->label('')
                            ->rows(10)
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->collapsed()
                    ->compact()
                    ->hidden(fn (?Deployment $record) => $record === null || empty($record->command_input)),
                Section::make('Command Output')
                    ->schema([
                        Forms\Components\Textarea::make('command_output')
                            ->label('')
                            ->rows(20)
                            ->disabled()
                            ->columnSpanFull()
                            ->live(onBlur: false),
                    ])
                    ->compact()
                    ->hidden(fn (?Deployment $record) => $record === null || empty($record->command_output)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('taskTemplate.name')
                    ->label('Task')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'running',
                        'success' => 'success',
                        'danger' => 'failed',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->state(function (Deployment $record) {
                        if (! $record->started_at || ! $record->completed_at) {
                            return '—';
                        }
                        $seconds = $record->started_at->diffInSeconds($record->completed_at);
                        $h = intdiv($seconds, 3600);
                        $m = intdiv($seconds % 3600, 60);
                        $s = $seconds % 60;
                        $parts = [];
                        if ($h) {
                            $parts[] = $h.'h';
                        }
                        if ($m) {
                            $parts[] = $m.'m';
                        }
                        $parts[] = $s.'s';

                        return implode(' ', $parts);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('task_template_id')
                    ->relationship('taskTemplate', 'name')
                    ->label('Task Template'),
            ])
            ->actions([
                Actions\Action::make('execute')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(function (Deployment $record) {
                        if ($record->status === 'pending') {
                            ExecuteAnsibleDeployment::dispatch($record);
                            $record->update(['status' => 'running']);
                        }
                    })
                    ->visible(fn (Deployment $record) => $record->status === 'pending')
                    ->requiresConfirmation(),
                Actions\ViewAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeployments::route('/'),
            'create' => Pages\CreateDeployment::route('/create'),
            'view' => Pages\ViewDeployment::route('/{record}'),
            'edit' => Pages\EditDeployment::route('/{record}/edit'),
        ];
    }
}
