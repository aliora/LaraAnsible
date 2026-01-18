<?php

namespace VisioSoft\LaraAnsible;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class LaraAnsibleServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laraansible.php',
            'laraansible'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laraansible');

        FilamentAsset::register([
            Css::make('laraansible-styles', __DIR__ . '/../resources/css/laraansible.css'),
        ], 'visio/laraansible');

        $this->publishes([
            __DIR__.'/../config/laraansible.php' => config_path('laraansible.php'),
        ], 'laraansible-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'laraansible-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/laraansible'),
        ], 'laraansible-views');

        \Livewire\Livewire::component('terminal-viewer', \VisioSoft\LaraAnsible\Livewire\TerminalViewer::class);
    }
}
