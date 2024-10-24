<?php

namespace Ahertl\LaravelScaffold\Providers;

use Illuminate\Support\ServiceProvider;
use Ahertl\LaravelScaffold\Console\Commands\LaravelScaffoldCommand;

class LaravelScaffoldServiceProvider extends ServiceProvider
{
    /**
     * Register the scaffold command.
     */
    public function register()
    {
        $this->commands([
            LaravelScaffoldCommand::class,
        ]);
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        // Publish the stub files so users can customize them
        $this->publishes([
            __DIR__ . '/../stubs' => base_path('stubs/vendor/laravel-scaffold'), // Adjust the path accordingly
        ], 'stubs');
    }

    // public function boot()
    // {
    //     // Path to your stub files in the package
    //     $stubPath = __DIR__ . '/stubs';

    //     // Publish the stub files to the Laravel project so they can be customized
    //     $this->publishes([
    //         $stubPath => base_path('stubs/vendor/laravel-scaffold'), // Destination path in the Laravel app
    //     ], 'stubs');

    //     // If you want to load stubs from the published directory first
    //     $this->loadStubOverrides($stubPath);
    // }

    /**
     * Load stub files from either the published stubs or fall back to default stubs.
     */
    protected function loadStubOverrides($stubPath)
    {
        $publishedStubPath = base_path('stubs/vendor/laravel-scaffold');

        if (file_exists($publishedStubPath)) {
            // Use the published stubs if they exist
            $this->stubPath = $publishedStubPath;
        } else {
            // Use default stubs from the package
            $this->stubPath = $stubPath;
        }
    }

    /**
     * Get the path to the stubs.
     * 
     * This method can be called from within your command.
     */
    public function getStubPath()
    {
        return $this->stubPath;
    }
}
