<?php

namespace Ahertl\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;


class LaravelScaffoldCommand extends Command
{
    protected $signature = 'laravel:scaffold {model} {--module=} {--table=} {--mass_upload} {--routes}';
    protected $description = 'Scaffold CRUD (Service, Repository, Controller, Import, and Routes) for a given model, with optional module support';
    protected $excludeCols = ["id","created_at","updated_at"];
    protected $excludeColsUpl = ["user_id","owner_id"];

    public function handle()
    {
        $model = $this->argument('model');
        $module = $this->option('module') ?: "";
        $table = $this->option('table') ?: Str::snake(Str::plural($model));
        $massUpload = $this->option('mass_upload');
        $generateRoutes = $this->option('routes');

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

        $this->createModel($basePath, $model, $columns, $isModular, $module);
        $this->createService($basePath, $model, $isModular,$module);
        $this->createRepository($basePath, $model, $isModular,$module);
        $this->createController($basePath, $model, $massUpload, $columns, $isModular,$module);

        if ($massUpload) {
            $this->createImportClass($basePath, $model, $columns, $isModular,$module);
        }

        // If the --routes option is provided, generate routes
        if ($generateRoutes) {
            $this->generateRoutes($model, $isModular, $module);
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

    protected function createModel($basePath, $model, $columns, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Models" : "App\\Models";
        $modelStub = ($model == "User") ? $this->getStub('UserModel.stub') : $this->getStub('Model.stub');
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
        $columns = array_filter($columns, fn($item) => !in_array($item, $this->excludeCols));
        return implode(",\n        ", array_map(fn($col) => "'$col'", $columns));
    }

    protected function createService($basePath, $model, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Services" : "App\\Services";
        $serviceImports = $isModular ? "use App\\Modules\\{$module}\\Repositories\\{$model}Repository;" : "use App\\Repositories\\{$model}Repository;";
        $serviceImports .= $isModular ? "\nuse App\\Modules\\{$module}\\Imports\\{$model}Import;" : "\nuse App\\Imports\\{$model}Import;";
        $serviceStub = ($model == "User") ? $this->getStub('UserService.stub') : $this->getStub('Service.stub');
        $serviceContent = str_replace(['{{modelName}}', '{{namespace}}','{{serviceImports}}'], [$model, $namespace, $serviceImports], $serviceStub);
        $servicePath = $isModular ? "{$basePath}/Services/{$model}Service.php" : app_path("Services/{$model}Service.php");
        File::put($servicePath, $serviceContent);
    }

    protected function createRepository($basePath, $model, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Repositories" : "App\\Repositories";
        $modelImports = $isModular ? "use App\\Modules\\{$module}\Models\\{$model};" : "use App\\Models\\{$model};";
        $repositoryStub = $this->getStub('Repository.stub');
        $repositoryContent = str_replace(['{{modelName}}', '{{namespace}}','{{modelImports}}'], [$model, $namespace,$modelImports], $repositoryStub);
        $repositoryPath = $isModular ? "{$basePath}/Repositories/{$model}Repository.php" : app_path("Repositories/{$model}Repository.php");
        File::put($repositoryPath, $repositoryContent);
    }

    protected function createController($basePath, $model, $massUpload, $columns, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Http\\Controllers" : "App\\Http\\Controllers";
        $importService = $isModular ? "use App\\Modules\\{$module}\\Services\\{$model}Service;" : "use App\\Services\\{$model}Service;";
        $controllerStub = ($model == "User") ? $this->getStub('UserController.stub') : $this->getStub('Controller.stub');
        $controllerContent = str_replace(['{{modelName}}','{{namespace}}','{{importService}}'], [$model, $namespace, $importService], $controllerStub);

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

    protected function createImportClass($basePath, $model, $columns, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Imports" : "App\\Imports";
        $modelImport = $isModular ? "use App\\Modules\\{$module}\\Models\\{$model};" : "use App\\Models\\{$model};";
        $importStub = ($model == "User") ? $this->getStub('UserImport.stub') : $this->getStub('Import.stub'); 
        $importContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{columnMappings}}','{{modelImport}}'],
            [$model, $namespace, $this->generateColumnMappings($columns),$modelImport],
            $importStub
        );
        $importPath = "{$basePath}/Imports";
        $importFile = "{$importPath}/{$model}Import.php"; 
        
        if (!is_dir($importPath)) {
            mkdir($importPath, 0755, true);
        } 

        File::put($importFile, $importContent);
    }

    protected function generateRoutes($model, $isModular, $module)
    {
        $routePath = $isModular
            ? app_path("Modules/{$module}/routes")
            : base_path("routes");

        $classImport = $isModular ? "use App\\Modules\\{$module}\\Http\\Controllers\\{$model}Controller;\n" : "use App\\Http\\Controllers\\{$model}Controller;\n";
        $routeFile = $routePath ."/api.php";
        // Check if routes/api.php exists
        if (!File::exists($routePath)) {
            mkdir($routePath,0777, true);
            touch($routeFile);
        }

        // Load the route file contents
        $routeContents = File::get($routeFile);

        // Define the API routes for the model
        $routePref =str_replace('_','-', Str::snake(Str::plural($model)));
        $modelRoutes = "Route::prefix('{$routePref}')->group(function () {\n    Route::get('/', [{$model}Controller::class, 'index']);\n    Route::post('/', [{$model}Controller::class, 'store']);\n    Route::get('{id}', [{$model}Controller::class, 'show']);\n    Route::put('{id}', [{$model}Controller::class, 'update']);\n    Route::delete('{id}', [{$model}Controller::class, 'destroy']);\n});\n\n";

        if(!empty($routeContents)) {
            $parts = explode("\n// routes\n", $routeContents);
            $parts[0] .= $classImport;
            $routeContents = implode("\n// routes\n", $parts);
            $routeContents .= $modelRoutes;
        } else {
            // Append the model routes to the route file
            $routeContents = "<?php\n    \nuse Illuminate\Support\Facades\Route;\n";
            $routeContents .= $classImport;
            $routeContents .= "\n// routes\n" . $modelRoutes;
        }

        // Save the updated route file
        File::put($routeFile, $routeContents);

        $this->info("API routes for {$model} have been added to {$routeFile}");
    }


    protected function generateColumnMappings($columns)
    {
        $columns = array_filter($columns, fn($item) => !in_array($item, array_merge($this->excludeCols, $this->excludeColsUpl)));
        return implode(",\n            ", array_map(function ($col) {
            return "'$col' => \$row['$col']";
        }, $columns));
    }

    protected function getStub($stubName)
    {
        $stubPath = base_path("stubs/vendor/laravel-scaffold/{$stubName}");

        if (File::exists($stubPath)) {
            //Use the user's customized stub
            return File::get($stubPath);
        }

        //Use the default stub from the package
        return File::get(__DIR__ . "/../stubs/{$stubName}");
    }

}