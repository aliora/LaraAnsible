<?php

namespace VisioSoft\LaraAnsible;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use VisioSoft\LaraAnsible\Console\PruneAnsibleLogs;
use VisioSoft\LaraAnsible\Livewire\TerminalViewer;

class LaraAnsibleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laraansible.php',
            'laraansible'
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laraansible');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'laraansible');

        FilamentAsset::register([
            Css::make('laraansible-styles', __DIR__.'/../resources/css/laraansible.css'),
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

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/laraansible'),
        ], 'laraansible-translations');

        Livewire::component('terminal-viewer', TerminalViewer::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneAnsibleLogs::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('ansible:prune-logs')->daily();
            });
        }
    }
}
