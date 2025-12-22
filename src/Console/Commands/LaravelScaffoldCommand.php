<?php

namespace Ahertl\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ahertl\LaravelScaffold\Spec\SimpleSpecParser;



class LaravelScaffoldCommand extends Command
{
    protected $signature = 'laravel:scaffold {model?} {--module=} {--table=} {--spec=} {--mass_upload} {--routes} {--dry-run} {--migration} {--migration-only} {--force} {-F}';
    protected $description = 'Scaffold CRUD (Service, Repository, Controller, Import, and Routes) for a given model, with optional module support';
    protected $exemptTable = ["migrations","sessions","migration","session","jobs","job","cache",
        "cache_locks","personal_access_tokens","password_reset_tokens","failed_jobs"];
    protected $exemptColumn = ["id","owner_id","user_id","accountable_id","accountable_type",
        'api_token','remember_token','email_verified_at','created_at','updated_at'];
    protected array $sensitiveColumns = [
        'password', 'token', 'api_token',
        'secret', 'remember_token'
    ];
    protected $currentTable = null;
    protected array $schemaCache = [];
    protected array $stubCache = [];

    protected function sanitizeIdentifier(string $value) : string {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $value);
    }

    protected function sanitizeClass(string $value) : string {
        return Str::studly($this->sanitizeIdentifier($value));
    }

    protected function sanitizeTable(string $value) : string {
        return Str::snake($this->sanitizeIdentifier($value));
    }

    protected function writeFile(string $path, string $content) : void {
        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would create: {$path}");
            return;
        }

        if(File::exists($path) && !($this->option('force') || $this->option('F'))) {
            $this->warn("Skipped existing file: {$path}");
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->info("Created: {$path}");
    }

    protected function loadStub(string $name) : string {
        return $this->stubCache[$name] ??= $this->getStub($name);
    }

    protected function generateRequestRules(array $fields): string {
        return collect($fields)->map(fn ($f) =>
            "'{$f['name']}' => ['" . implode("','", $this->mapValidationRules($f)) . "']"
        )->implode(",\n        ");
    }


    protected function mapValidationRules(array $field): array {
        $rules = [];

        $rules[] = match ($field['type']) {
            'string' => 'string',
            'number', 'int', 'integer' => 'integer',
            'boolean', 'bool' => 'boolean',
            default => 'string',
        };

        if (in_array('required', $field['mods'])) {
            $rules[] = 'required';
        }

        if (in_array('unique', $field['mods'])) {
            $rules[] = "unique:{$this->currentTable},{$field['name']}";
        }

        return $rules;
    }

    protected function createFormRequests(string $basePath, string $model, array $specFields) : void {
        $rules = $this->generateRequestRules($specFields);

        foreach (['Store', 'Update'] as $type) {
            $stub = $this->loadStub('FormRequest.stub');

            $content = str_replace(
                ['{{requestName}}', '{{rules}}'],
                ["{$type}{$model}Request", $rules],
                $stub
            );

            $path = "{$basePath}/Http/Requests/{$type}{$model}Request.php";
            $this->writeFile($path, $content);
        }
    }


    protected function buildMigrationLine(array $field) : string {
        $line = match ($field['type']) {
            'string' => "\$table->string('{$field['name']}')",
            'number', 'int', 'integer' => "\$table->integer('{$field['name']}')",
            'boolean', 'bool' => "\$table->boolean('{$field['name']}')",
            'date' => "\$table->date('{$field['name']}')",
            'datetime' => "\$table->dateTime('{$field['name']}')",
            default => "\$table->string('{$field['name']}')",
        };

        // 👇 THIS IS WHERE unique BELONGS
        if (in_array('unique', $field['mods'])) {
            $line .= "->unique()";
        }

        if (in_array('nullable', $field['mods'])) {
            $line .= "->nullable()";
        }

        return $line . ";";
    }

    protected function generateMigrationFromSpec( string $model, string $table, array $fields) : void {
        $timestamp = date('Y_m_d_His');
        $className = "Create" . Str::studly($table) . "Table";
        $fileName = "{$timestamp}_create_{$table}_table.php";
        $path = database_path("migrations/{$fileName}");

        $columns = collect($fields)
            ->map(fn ($field) => "            " . $this->buildMigrationLine($field))
            ->implode("\n");

        $stub = $this->loadStub('Migration.stub');

        $migration = str_replace(
            ['{{table}}','{{columns}}'],
            [$table, $columns],
            $stub
        );

        $this->writeFile($path, $migration);
    }


    protected function handleSpecMode(string $specPath) : void {
        $parser = new SimpleSpecParser();
        $entities = $parser->parse($specPath);

        foreach($entities as $model => $fields) {
            $table = Str::plural(Str::snake($model));
            $this->currentTable = $table;

            if ($this->option('migration') || $this->option('migration-only')) {
                $this->generateMigrationFromSpec($model, $table, $fields);
            }

            if ($this->option('migration-only')) {
                continue;
            }

            $this->createFormRequests(app_path(), $model, $fields);

            $columns = array_map(fn ($f) => $f['name'], $fields);

            $this->createModel(
                app_path(),
                $model,
                $columns,
                false,
                null,
                $fields
            );

            $this->createService(app_path(), $model, false, null);
            $this->createRepository(app_path(), $model, false, $columns, null);
            $this->createController(app_path(), $model, false, false, null);
        }

        $this->info("Scaffolding from spec file completed.");
    }

    public function handle()
    {
        if ($specPath = $this->option('spec')) {
            $this->handleSpecMode($specPath);
            return Command::SUCCESS;
        }

        $model = $this->sanitizeClass($this->argument('model')); //$this->argument('model');
        $module = $this->option('module');
        $table = $this->sanitizeTable(
            $this->option('table') ?? Str::plural(Str::snake($model))
        ); 
        $this->currentTable = $table;
        $massUpload = $this->option('mass_upload');
        $generateRoutes = $this->option('routes');

        if (in_array($table, $this->exemptTable)) return;

        $isModular = $module !== null;

        $basePath = $isModular ? app_path("Modules/{$module}") : app_path();

        if ($isModular && !is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        $this->createDirectories($basePath, $isModular);
        
        if (!Schema::hasTable($table)) {
            $this->error("Table {$table} does not exist.");
            return Command::FAILURE;
        }

        $columns = $this->getTableColumns($table);

        $this->createModel($basePath, $model, $columns, $isModular, $module);
        $this->createService($basePath, $model, $isModular, $module);
        $this->createRepository($basePath, $model, $isModular, $columns, $module);
        $this->createController($basePath, $model, $massUpload, $isModular, $module);

        if ($massUpload) {
            $this->createImportClass($basePath, $model, $columns, $isModular, $module);
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

    protected function getTableColumns(string $table) : array
    {
        return $this->schemaCache[$table] ??= Schema::getColumnListing($table);
    }

    protected function getRefTable($model, $col) {
        $tab = Str::snake(Str::plural($model));
        $refTable = DB::select("
        SELECT REFERENCED_TABLE_NAME AS referenced_table
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        AND REFERENCED_TABLE_NAME IS NOT NULL;", [env('DB_DATABASE'), $tab, $col]);

        return $refTable[0]->referenced_table;
    }
    protected function makefnName($model, $str) {
        return Str::singular($this->getRefTable($model, $str));
    }

    protected function makePascalName($model, $str) {
        return Str::studly(Str::singular($this->getRefTable($model, $str)));
    }

    protected function makeClassName($model, $str) {
        $str = str_replace("owner", "user", $str);
        $className = $this->makePascalName($model, $str) . "::class";
        return [$className, "'".$str."'"];
    }

    protected function mapSpecType(string $type) : string {
        return match ($type) {
            'string' => 'string',
            'number', 'int', 'integer' => 'integer',
            'bool', 'boolean' => 'boolean',
            'date', 'datetime' => 'datetime',
            default => 'string',
        };
    }


    protected function createModel($basePath, $model, $cols, $isModular, $module, $specFields = null)
    {
        $relationships = "";
        $fkFields = array_map(fn($col) => $col->COLUMN_NAME, array_filter($cols, fn($col) => $col->COLUMN_KEY == "MUL"));
        if (sizeof($fkFields) > 0) {
            $relationships .= implode("\n", array_map(fn($item)=>"
    public function ".$this->makefnName($model, $item)."() {
        return \$this->belongsTo(".implode(", ", $this->makeClassName($model, $item)).");
    }
            ", $fkFields));
        }
        $columns = $specFields ? array_column($specFields, 'name') : array_filter(array_map(fn($item) => $item->COLUMN_NAME, $cols), fn($item) => !in_array($item, $this->exemptColumn));
        $namespace = $isModular ? "App\\Modules\\{$module}\\Models" : "App\\Models";
        $modelStub = ($model == "User") ? $this->loadStub('UserModel.stub') : $this->loadStub('Model.stub');
        $fillable = $this->generateFillable($columns);
        $hidden = [];
        if ($specFields) {
            foreach ($specFields as $field) {
                if (in_array('hidden', $field['mods'])) {
                    $hidden[] = $field['name'];
                }
            }
        }

        $modelContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{fillable}}','{{hidden}}','{{relationships}}'],
            [$model, $namespace, $fillable, $hidden, $relationships],
            $modelStub
        );

        $modelPath = $isModular ? "{$basePath}/Models/{$model}.php" : app_path("Models/{$model}.php");
        
        $this->writeFile($modelPath, $modelContent);
    }

    protected function generateFillable($columns)
    {
        $fillable = array_diff($columns, $this->sensitiveColumns);
        return implode(",\n        ", array_map(fn($col) => "'$col'", $fillable));
    }

    protected function createService($basePath, $model, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Services" : "App\\Services";
        $serviceImports = $isModular ? "use App\\Modules\\{$module}\\Repositories\\{$model}Repository;" : "use App\\Repositories\\{$model}Repository;";
        $serviceImports .= $isModular ? "\nuse App\\Modules\\{$module}\\Imports\\{$model}Import;" : "\nuse App\\Imports\\{$model}Import;";
        $serviceStub = ($model == "User") ? $this->loadStub('UserService.stub') : $this->loadStub('Service.stub');
        $serviceContent = str_replace(['{{modelName}}', '{{namespace}}','{{serviceImports}}'], [$model, $namespace, $serviceImports], $serviceStub);
        $servicePath = $isModular ? "{$basePath}/Services/{$model}Service.php" : app_path("Services/{$model}Service.php");
        $this->writeFile($servicePath, $serviceContent);
    }

    protected function createRepository($basePath, $model, $isModular, $columns, $module)
    {
        $fetchStr = "";
        $fetchSingleStr = "";
        $fkFields = array_map(fn($col) => $col->COLUMN_NAME, array_filter($columns, fn($col) => $col->COLUMN_KEY == "MUL"));
        if(sizeof($fkFields) > 0) {
            $fetchStr .= " $model::with([".implode(", ", array_map(fn($item) => "'".$this->makefnName($model, $item)."'", $fkFields))."])->get();";
            $fetchSingleStr .= " $model::with([".implode(", ", array_map(fn($item) => "'".$this->makefnName($model,$item)."'", $fkFields))."])->findOrFail(\$id);";
        } else {
            $fetchStr .= " $model::all();";
            $fetchSingleStr .= " $model::findOrFail(\$id);";
        }
        $namespace = $isModular ? "App\\Modules\\{$module}\\Repositories" : "App\\Repositories";
        $modelImports = $isModular ? "use App\\Modules\\{$module}\Models\\{$model};" : "use App\\Models\\{$model};";
        $repositoryStub = $this->loadStub('Repository.stub');
        $repositoryContent = str_replace(['{{modelName}}', '{{namespace}}', '{{modelImports}}','{{fetchStr}}',"{{fetchSingleStr}}"], [$model, $namespace, $modelImports, $fetchStr, $fetchSingleStr], $repositoryStub);
        $repositoryPath = $isModular ? "{$basePath}/Repositories/{$model}Repository.php" : app_path("Repositories/{$model}Repository.php");
        $this->writeFile($repositoryPath, $repositoryContent);
    }

    protected function createController($basePath, $model, $massUpload, $isModular, $module)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Http\\Controllers" : "App\\Http\\Controllers";
        $importService = $isModular ? "use App\\Modules\\{$module}\\Services\\{$model}Service;" : "use App\\Services\\{$model}Service;";
        $controllerStub = ($model == "User") ? $this->loadStub('UserController.stub') : $this->loadStub('Controller.stub');
        $controllerContent = str_replace(['{{modelName}}','{{namespace}}','{{importService}}'], [$model, $namespace, $importService], $controllerStub); 

        if ($massUpload) {
            $massUploadFunction = $this->loadStub('MassUploadFunction.stub');
            $controllerContent = str_replace('{{massUploadFunction}}', $massUploadFunction, $controllerContent);
        } else {
            $controllerContent = str_replace('{{massUploadFunction}}', '', $controllerContent);
        }

        $controllerPath = $isModular
            ? "{$basePath}/Http/Controllers/{$model}Controller.php"
            : app_path("Http/Controllers/{$model}Controller.php");

        $this->writeFile($controllerPath, $controllerContent);
    }

    protected function createImportClass($basePath, $model, $columns, $isModular, $module)
    {
        $namespace = $isModular ? "{{App\\Modules\\{$module}}}\\Imports" : "App\\Imports";
        $modelImport = $isModular ? "use App\\Modules\\{$module}\\Models\\{$model};" : "use App\\Models\\{$model};";
        $importStub = ($model == "User") ? $this->loadStub('UserImport.stub') : $this->loadStub('Import.stub'); 
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

        $this->writeFile($importFile, $importContent);        
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
        $routePref = str_replace('_','-', Str::snake(Str::plural($model)));
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
        $this->writeFile($routeFile, $routeContents);

        $this->info("API routes for {$model} have been added to {$routeFile}");
    }


    protected function generateColumnMappings($cols)
    {
        $columns = array_map(fn($item) => $item->COLUMN_NAME, $cols);
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