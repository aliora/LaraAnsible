<?php

namespace VisioSoft\LaraAnsible\Filament\Resources;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DBSchema;
use VisioSoft\LaraAnsible\Filament\Resources\AnsibleSettingResource\Pages;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;

class AnsibleSettingResource extends Resource
{
    protected static ?string $model = AnsibleSetting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Ayarlar';

    protected static ?string $modelLabel = 'Ayar';

    protected static ?string $pluralModelLabel = 'Ayarlar';

    public static function form(Schema $schema): Schema
    {
        $tables = self::getAvailableTables();

        return $schema
            ->schema([
                Section::make('Genel Ayarlar')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Ayar Adı')
                            ->required()
                            ->default('default')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Parent (Grup) Tablosu')
                    ->description('Sunucuların gruplandığı ana tablo (örn: parks, locations)')
                    ->schema([
                        Forms\Components\Select::make('parent_table')
                            ->label('Tablo')
                            ->options($tables)
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('parent_label_column', null)),
                        Forms\Components\Select::make('parent_label_column')
                            ->label('Etiket Kolonu')
                            ->options(fn (callable $get) => self::getTableColumns($get('parent_table')))
                            ->searchable()
                            ->helperText('Grup adını gösteren kolon (örn: name)'),
                    ])
                    ->columns(2),
                Section::make('Child (Cihaz) Tablosu')
                    ->description('Sunucu/cihaz bilgilerini içeren tablo (örn: devices, kiosks)')
                    ->schema([
                        Forms\Components\Select::make('child_table')
                            ->label('Tablo')
                            ->options($tables)
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('child_label_column', null)),
                        Forms\Components\Select::make('child_label_column')
                            ->label('Etiket Kolonu')
                            ->options(fn (callable $get) => self::getTableColumns($get('child_table')))
                            ->searchable()
                            ->helperText('Cihaz adını gösteren kolon'),
                        Forms\Components\Select::make('child_hostname_column')
                            ->label('Hostname/IP Kolonu')
                            ->options(fn (callable $get) => self::getTableColumns($get('child_table')))
                            ->searchable()
                            ->required()
                            ->helperText('IP adresi veya hostname içeren kolon'),
                        Forms\Components\Select::make('child_port_column')
                            ->label('Port Kolonu (Opsiyonel)')
                            ->options(fn (callable $get) => self::getTableColumns($get('child_table')))
                            ->searchable()
                            ->helperText('SSH port kolonu'),
                        Forms\Components\Select::make('child_username_column')
                            ->label('Kullanıcı Adı Kolonu (Opsiyonel)')
                            ->options(fn (callable $get) => self::getTableColumns($get('child_table')))
                            ->searchable()
                            ->helperText('SSH kullanıcı adı kolonu'),
                        Forms\Components\Select::make('child_parent_foreign_key')
                            ->label('Parent Foreign Key')
                            ->options(fn (callable $get) => self::getTableColumns($get('child_table')))
                            ->searchable()
                            ->helperText('Parent tablosuna bağlayan foreign key (örn: park_id)'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ayar Adı')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('parent_table')
                    ->label('Parent Tablo')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('child_table')
                    ->label('Child Tablo')
                    ->badge()
                    ->color('success'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Güncelleme')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Actions\EditAction::make(),
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
            'index' => Pages\ListAnsibleSettings::route('/'),
            'create' => Pages\CreateAnsibleSetting::route('/create'),
            'edit' => Pages\EditAnsibleSetting::route('/{record}/edit'),
        ];
    }

    /**
     * Get available database tables
     */
    protected static function getAvailableTables(): array
    {
        try {
            $tables = DBSchema::getTableListing();
            $options = [];

            foreach ($tables as $table) {
                // Remove schema prefix if present
                $tableName = str_contains($table, '.') ? explode('.', $table)[1] : $table;
                $options[$tableName] = $tableName;
            }

            return $options;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get columns for a specific table
     */
    protected static function getTableColumns(?string $table): array
    {
        if (! $table) {
            return [];
        }

        try {
            $columns = DBSchema::getColumnListing($table);

            return array_combine($columns, $columns);
        } catch (\Exception $e) {
            return [];
        }
    }
}
