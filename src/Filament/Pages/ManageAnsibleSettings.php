<?php

namespace VisioSoft\LaraAnsible\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use VisioSoft\LaraAnsible\Models\AnsibleSetting;

class ManageAnsibleSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Ansible';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'Ayarlar';

    protected static ?string $title = 'Ansible Ayarları';

    protected static ?string $slug = 'ansible/settings';

    protected string $view = 'laraansible::pages.manage-ansible-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $setting = AnsibleSetting::getInstance();

        $this->form->fill($setting->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    Wizard\Step::make('parent')
                        ->label('1. Grup/Park Tablosu')
                        ->description('Ana gruplandırma tablosunu seçin')
                        ->icon('heroicon-o-folder')
                        ->schema([
                            Section::make('Ana Tablo (Parent/Group)')
                                ->description('Cihazların gruplandırılacağı ana tablo. Örneğin: parks, locations, branches')
                                ->schema([
                                    Forms\Components\Select::make('parent_table')
                                        ->label('Tablo Adı')
                                        ->options(fn () => AnsibleSetting::getAvailableTables())
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('parent_label_column', null))
                                        ->helperText('Veritabanınızdaki grup/park tablosunu seçin'),

                                    Forms\Components\Select::make('parent_label_column')
                                        ->label('Görüntüleme Kolonu')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('parent_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Listede gösterilecek isim kolonu (örn: name, title)'),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('child')
                        ->label('2. Cihaz Tablosu')
                        ->description('Ansible ile yönetilecek cihazları seçin')
                        ->icon('heroicon-o-server-stack')
                        ->schema([
                            Section::make('Cihaz Tablosu (Child/Devices)')
                                ->description('Ansible\'ın yöneteceği cihazların bulunduğu tablo. Örneğin: devices, kiosks, servers')
                                ->schema([
                                    Forms\Components\Select::make('child_table')
                                        ->label('Tablo Adı')
                                        ->options(fn () => AnsibleSetting::getAvailableTables())
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set) {
                                            $set('child_label_column', null);
                                            $set('child_parent_foreign_key', null);
                                            $set('version_column', null);
                                            $set('child_hostname_column', null);
                                            $set('child_port_column', null);
                                            $set('child_username_column', null);
                                        })
                                        ->helperText('Cihazlarınızın bulunduğu tabloyu seçin'),

                                    Forms\Components\Select::make('child_parent_foreign_key')
                                        ->label('Grup İlişki Kolonu (Foreign Key)')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Ana tablo ile ilişkiyi sağlayan kolon (örn: park_id)'),
                                ])
                                ->columns(2),

                            Fieldset::make('Cihaz Bilgileri')
                                ->schema([
                                    Forms\Components\Select::make('child_label_column')
                                        ->label('Cihaz Adı Kolonu')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Cihazın görüntüleme adı'),

                                    Forms\Components\Select::make('version_column')
                                        ->label('Versiyon Kolonu')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->placeholder('Opsiyonel')
                                        ->helperText('Yazılım sürümü tutuluyorsa'),
                                ])
                                ->columns(2),
                        ]),

                    Wizard\Step::make('connection')
                        ->label('3. Bağlantı Ayarları')
                        ->description('SSH bağlantı bilgilerini eşleştirin')
                        ->icon('heroicon-o-link')
                        ->schema([
                            Section::make('SSH Bağlantı Ayarları')
                                ->description('Ansible\'ın cihazlara bağlanmak için kullanacağı bilgiler')
                                ->schema([
                                    Forms\Components\Select::make('child_hostname_column')
                                        ->label('IP/Hostname Kolonu')
                                        ->options(fn (Get $get) => AnsibleSetting::getColumnsForTable($get('child_table')))
                                        ->searchable()
                                        ->required()
                                        ->helperText('Cihazın IP adresi veya hostname\'i içeren kolon'),

                                    Forms\Components\TextInput::make('ssh_port')
                                        ->label('SSH Port')
                                        ->default('22')
                                        ->placeholder('22')
                                        ->helperText('Tüm cihazlar için kullanılacak SSH portu'),

                                    Forms\Components\TextInput::make('ssh_username')
                                        ->label('SSH Kullanıcı Adı')
                                        ->default('root')
                                        ->placeholder('root')
                                        ->helperText('Tüm cihazlar için kullanılacak SSH kullanıcısı'),
                                ])
                                ->columns(3),
                        ]),
                ])
                    ->submitAction($this->getFormActions()[0]),
            ])
            ->statePath('data');
    }

    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Ayarları Kaydet')
                ->icon('heroicon-o-check')
                ->color('success')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $setting = AnsibleSetting::getInstance();
        $setting->update($data);

        Notification::make()
            ->title('Ayarlar Kaydedildi')
            ->body('Ansible entegrasyon ayarları başarıyla güncellendi.')
            ->success()
            ->send();
    }
}
