<?php

namespace Nisalatp\DynamicReportGenerator\Providers;

use Illuminate\Support\ServiceProvider;
use Nisalatp\DynamicReportGenerator\ReportMaker;

class DynamicReportGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/dynamicreportgenerator.php', 'dynamicreportgenerator'
        );

        $this->app->singleton(ReportMaker::class, function ($app) {
            return new ReportMaker(
                $app['config']->get('dynamicreportgenerator.reportable_models', [])
            );
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/dynamicreportgenerator.php' => config_path('dynamicreportgenerator.php'),
            ], 'dynamicreportgenerator-config');
        }
    }
}
