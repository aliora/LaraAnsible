<?php

namespace VisioSoft\LaraAnsible\Tests;

use Filament\Support\SupportServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use VisioSoft\LaraAnsible\LaraAnsibleServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            LaraAnsibleServiceProvider::class,
        ];
    }
}
