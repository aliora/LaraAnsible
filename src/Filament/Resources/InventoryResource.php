<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use VisioSoft\LaraAnsible\Filament\Concerns\AuthorizesAnsibleAccess;
use VisioSoft\LaraAnsible\Filament\Resources\InventoryResource\Pages;
use VisioSoft\LaraAnsible\Helpers\FormSchemaHelper;
use VisioSoft\LaraAnsible\Helpers\JobLauncher;
use VisioSoft\LaraAnsible\Helpers\TableHelper;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;
use VisioSoft\LaraAnsible\Models\Inventory;

class InventoryResource extends Resource
{
    use AuthorizesAnsibleAccess;

    protected static ?string $model = Inventory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('laraansible::laraansible.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('laraansible::laraansible.inventory');
    }

    public static function getModelLabel(): string
    {
        return __('laraansible::laraansible.host');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('laraansible::laraansible.general_information'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label(__('laraansible::laraansible.friendly_name'))
                            ->placeholder(__('laraansible::laraansible.friendly_name_placeholder')),

                        Forms\Components\Select::make('park_id')
                            ->label(__('laraansible::laraansible.assigned_park'))
                            ->relationship('park', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder(__('laraansible::laraansible.select_park_placeholder'))
                            ->visible(fn (): bool => class_exists((string) config('laraansible.park_model'))),

                        Forms\Components\Textarea::make('description')
                            ->label(__('laraansible::laraansible.description'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),

                Section::make(__('laraansible::laraansible.connection_details'))
                    ->schema([
                        Forms\Components\TextInput::make('port')
                            ->numeric()
                            ->default(22)
                            ->label(__('laraansible::laraansible.ssh_port')),

                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->default(function () {
                                return AnsibleSetting::getInstance()->ssh_username ?? 'root';
                            })
                            ->label(__('laraansible::laraansible.ssh_username')),

                        Forms\Components\Select::make('keystore_id')
                            ->label(__('laraansible::laraansible.ssh_key'))
                            ->relationship('keystore', 'name')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('laraansible::laraansible.name'))
                                    ->required(),
                                Forms\Components\Textarea::make('private_key')
                                    ->required()
                                    ->label(__('laraansible::laraansible.private_key_pem')),
                            ]),
                    ])
                    ->columnSpan(1),

                Section::make(__('laraansible::laraansible.host_management'))
                    ->schema([
                        Forms\Components\KeyValue::make('hosts_entry')
                            ->label(__('laraansible::laraansible.host_list'))
                            ->keyLabel(__('laraansible::laraansible.host_name_alias'))
                            ->valueLabel(__('laraansible::laraansible.ip_hostname'))
                            ->addActionLabel(__('laraansible::laraansible.add_host'))
                            ->reorderable()
                            ->columnSpanFull()
                            ->helperText(__('laraansible::laraansible.host_list_help')),
                    ])
                    ->columnSpanFull(),

                Section::make(__('laraansible::laraansible.advanced_configuration'))
                    ->collapsed()
                    ->description(__('laraansible::laraansible.advanced_configuration_description'))
                    ->schema([
                        Forms\Components\Textarea::make('script')
                            ->label(__('laraansible::laraansible.inventory_script'))
                            ->rows(8)
                            ->columnSpanFull()
                            ->disabled()
                            ->dehydrated()
                            ->placeholder("[gate_server]\n\npi5 ansible_host=100.88.196.89\npi5-3 ansible_host=100.89.209.23\n[gate_server:vars]\nansible_user=root\nansible_ssh_private_key_file=~/.ssh/id_ed25519")
                            ->helperText(__('laraansible::laraansible.inventory_script_help')),

                        Forms\Components\KeyValue::make('variables')
                            ->label(__('laraansible::laraansible.inventory_variables'))
                            ->keyLabel(__('laraansible::laraansible.variable_name'))
                            ->valueLabel(__('laraansible::laraansible.value')),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        $setting = AnsibleSetting::getInstance();

        $parentLabel = FormSchemaHelper::formatLabel($setting?->parent_table, __('laraansible::laraansible.group_parent'));
        $childLabel = FormSchemaHelper::formatLabel($setting?->child_table, __('laraansible::laraansible.host_name'));
        $hostnameLabel = FormSchemaHelper::formatLabel($setting?->child_hostname_column, __('laraansible::laraansible.ip_hostname'));

        return TableHelper::configure($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label($childLabel),
                Tables\Columns\TextColumn::make('hostname')
                    ->searchable()
                    ->sortable()
                    ->label($hostnameLabel),
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->label(__('laraansible::laraansible.ssh_user')),
                Tables\Columns\TextColumn::make('parent_name')
                    ->label($parentLabel)
                    ->state(function (Inventory $record) use ($setting) {
                        if (! $setting) {
                            return __('laraansible::laraansible.manual');
                        }

                        if ($setting->parent_table === 'parks' && $record->park_id) {
                            return $setting->parentLabelFor($record->park_id) ?? __('laraansible::laraansible.not_found');
                        }

                        if ($record->source_type === 'dynamic' && $setting->parent_table && $record->dynamic_child_id) {
                            $child = $setting->findChild($record->dynamic_child_id);
                            $foreignKey = $setting->childForeignKey();

                            if ($child && isset($child->{$foreignKey})) {
                                return $setting->parentLabelFor($child->{$foreignKey}) ?? __('laraansible::laraansible.not_found');
                            }
                        }

                        return __('laraansible::laraansible.manual');
                    }),
                Tables\Columns\TextColumn::make('host_count')
                    ->label(__('laraansible::laraansible.hosts'))
                    ->state(fn (Inventory $record): int => $record->hostCount()),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('parent_group')
                    ->label(__('laraansible::laraansible.parent_group'))
                    ->options(fn (): array => $setting?->parentOptions() ?? [])
                    ->query(function ($query, $data) use ($setting) {
                        if (! $data['value'] || ! $setting) {
                            return $query;
                        }

                        return $query->whereIn('dynamic_child_id', $setting->childIdsOf($data['value']));
                    }),
            ])
            ->headerActions([])
            ->actions([
                Actions\EditAction::make()
                    ->button(),
            ])
            ->bulkActions(TableHelper::bulkActions([
                JobLauncher::bulkAction('quick_run'),
            ]));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'view' => Pages\ViewInventory::route('/{record}'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}
