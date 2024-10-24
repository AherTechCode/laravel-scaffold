<?php

namespace Ahertl\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LaravelScaffoldCommand extends Command
{
    protected $signature = 'laravel:scaffold {model} {--module=} {--table=} {--mass_upload}';
    protected $description = 'Scaffold CRUD (Service, Repository, Controller, Import) for a given model, with optional module support';

    public function handle()
    {
        $model = $this->argument('model');
        $module = $this->option('module');
        $table = $this->option('table') ?: Str::snake(Str::plural($model));
        $massUpload = $this->option('mass_upload');

        $isModular = $module !== null;

        $basePath = $isModular ? app_path("Modules/{$module}") : app_path();

        if ($isModular && !is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        $this->createDirectories($basePath, $isModular);
        
        if (!Schema::hasTable($table)) {
            $this->error("Table {$table} does not exist.");
            return;
        }

        $columns = $this->getTableColumns($table);

        $this->createModel($basePath, $model, $columns, $isModular);
        $this->createService($basePath, $model, $isModular);
        $this->createRepository($basePath, $model, $isModular);
        $this->createController($basePath, $model, $massUpload, $columns, $isModular);

        if ($massUpload) {
            $this->createImportClass($basePath, $model, $columns, $isModular);
        }

        $this->info("CRUD scaffolding complete for {$model}" . ($isModular ? " in module {$module}" : '') . '.');
    }

    protected function createDirectories($basePath, $isModular)
    {
        $directories = $isModular
            ? ['Http/Controllers', 'Models', 'Repositories', 'Services', 'Imports']
            : ['Http/Controllers', 'Models', 'Repositories', 'Services'];

        foreach ($directories as $dir) {
            if (!is_dir("{$basePath}/{$dir}")) {
                mkdir("{$basePath}/{$dir}", 0755, true);
            }
        }
    }

    protected function getTableColumns($table)
    {
        return Schema::getColumnListing($table);
    }

    protected function createModel($basePath, $model, $columns, $isModular)
    {
        $namespace = $isModular ? "App\\Modules\\{{module}}\\Models" : "App\\Models";
        $modelStub = $this->getStub('Model.stub');
        $fillable = $this->generateFillable($columns);
        $modelContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{fillable}}'],
            [$model, $namespace, $fillable],
            $modelStub
        );
        $modelPath = $isModular ? "{$basePath}/Models/{$model}.php" : app_path("Models/{$model}.php");
        File::put($modelPath, $modelContent);
    }

    protected function generateFillable($columns)
    {
        return implode(",\n        ", array_map(fn($col) => "'$col'", $columns));
    }

    protected function createService($basePath, $model, $isModular)
    {
        $namespace = $isModular ? "App\\Modules\\{{module}}\\Services" : "App\\Services";
        $serviceStub = $this->getStub('Service.stub');
        $serviceContent = str_replace(['{{modelName}}', '{{namespace}}'], [$model, $namespace], $serviceStub);
        $servicePath = $isModular ? "{$basePath}/Services/{$model}Service.php" : app_path("Services/{$model}Service.php");
        File::put($servicePath, $serviceContent);
    }

    protected function createRepository($basePath, $model, $isModular)
    {
        $namespace = $isModular ? "App\\Modules\\{{module}}\\Repositories" : "App\\Repositories";
        $repositoryStub = $this->getStub('Repository.stub');
        $repositoryContent = str_replace(['{{modelName}}', '{{namespace}}'], [$model, $namespace], $repositoryStub);
        $repositoryPath = $isModular ? "{$basePath}/Repositories/{$model}Repository.php" : app_path("Repositories/{$model}Repository.php");
        File::put($repositoryPath, $repositoryContent);
    }

    protected function createController($basePath, $model, $massUpload, $columns, $isModular)
    {
        $namespace = $isModular ? "App\\Modules\\{{module}}\\Http\\Controllers" : "App\\Http\\Controllers";
        $controllerStub = $this->getStub('Controller.stub');
        $controllerContent = str_replace('{{modelName}}', $model, $controllerStub);

        if ($massUpload) {
            $massUploadFunction = $this->getStub('MassUploadFunction.stub');
            $controllerContent = str_replace('{{massUploadFunction}}', $massUploadFunction, $controllerContent);
        } else {
            $controllerContent = str_replace('{{massUploadFunction}}', '', $controllerContent);
        }

        $controllerPath = $isModular
            ? "{$basePath}/Http/Controllers/{$model}Controller.php"
            : app_path("Http/Controllers/{$model}Controller.php");

        File::put($controllerPath, $controllerContent);
    }

    protected function createImportClass($basePath, $model, $columns, $isModular)
    {
        $namespace = $isModular ? "{{App\\Modules\\{{module}}}}\\Imports" : "App\\Imports";
        $importStub = $this->getStub('Import.stub'); 
        $importContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{columnMappings}}'],
            [$model, $namespace, $this->generateColumnMappings($columns)],
            $importStub
        );
        $importPath = "{$basePath}/Imports";
        $importFile = "{$importPath}/{$model}Import.php"; 
        
        if (!is_dir($importPath)) {
            mkdir($importPath, 0755, true);
        } 

        File::put($importFile, $importContent);
    }

    protected function generateColumnMappings($columns)
    {
        return implode(",\n            ", array_map(function ($col) {
            return "'$col' => \$row['$col']";
        }, $columns));
    }

    protected function getStub($stubName)
    {
        $stubPath = base_path("stubs/vendor/laravel-scaffold/{$stubName}");

        if (File::exists($stubPath)) {
            // Use the user's customized stub
            return File::get($stubPath);
        }

        // Use the default stub from the package
        return File::get(__DIR__ . "/../stubs/{$stubName}");
    }

}
