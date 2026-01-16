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
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
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
                                $files = glob($directory.'/*.ini') ?: [];
                                $files = array_merge($files, glob($directory.'/*.yml') ?: []);
                                $files = array_merge($files, glob($directory.'/*.yaml') ?: []);
                                $options = [];
                                foreach ($files as $file) {
                                    $basename = basename($file);
                                    $options[$file] = $basename;
                                }

                                return $options;
                            })
                            ->searchable()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Inventory dosyasından seç (dosya seçilirse, sunucu seçimi devre dışı kalır)'),
                        Forms\Components\Select::make('inventory_ids')
                            ->label('Sunucular')
                            ->multiple()
                            ->searchable()
                            ->options(function () {
                                $options = ['all' => '🔥 Tümünü Seç'];

                                // Get static inventories from database
                                $staticInventories = Inventory::where('is_active', true)->get();
                                if ($staticInventories->isNotEmpty()) {
                                    $staticOptions = [];
                                    foreach ($staticInventories as $inventory) {
                                        $staticOptions[$inventory->id] = "{$inventory->name} ({$inventory->hostname})";
                                    }
                                    $options['Kayıtlı Sunucular'] = $staticOptions;
                                }

                                // Get dynamic inventories from settings
                                $setting = AnsibleSetting::getActive();
                                if ($setting) {
                                    $dynamicOptions = $setting->getGroupedInventoryOptions();
                                    foreach ($dynamicOptions as $group => $items) {
                                        $options[$group] = $items;
                                    }
                                }

                                return $options;
                            })
                            ->columnSpanFull()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Sunucuları seçin veya "Tümünü Seç" ile hepsini ekleyin'),
                        Forms\Components\Select::make('cli_flags')
                            ->label('CLI Seçenekleri')
                            ->multiple()
                            ->searchable()
                            ->options([
                                'Kontrol ve Test' => [
                                    '--syntax-check' => 'YAML yazım hatalarını kontrol et',
                                    '--check' => 'Simülasyon (dry-run) - değişiklik yapmadan göster',
                                    '--diff' => 'Yapılacak değişikliklerin farklarını göster',
                                    '--list-tasks' => 'Playbook görevlerini listele',
                                ],
                                'Yetki ve Bağlantı' => [
                                    '--become' => 'Root/sudo olarak çalıştır',
                                    '--ask-become-pass' => 'Sudo parolasını sor',
                                ],
                                'Detay Seviyesi' => [
                                    '-v' => 'Detaylı çıktı',
                                    '-vv' => 'Daha detaylı çıktı',
                                    '-vvv' => 'En detaylı çıktı (debug)',
                                ],
                            ])
                            ->columnSpanFull()
                            ->disabled(fn ($livewire) => $livewire instanceof Pages\EditDeployment)
                            ->helperText('Kategoriden seçenek seçin'),
                    ])
                    ->columns(2)
                    ->compact(),
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
