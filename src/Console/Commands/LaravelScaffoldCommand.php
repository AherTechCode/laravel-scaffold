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
    protected $signature = 'laravel:scaffold {model?} {--module=} {--table=} {--spec=} {--mass_upload} {--routes} {--dry-run} {--migration} {--migration-only} {--force} {--F}';
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

    protected function writeRouteFile(string $path, string $content): void {
        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would update routes: {$path}");
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->info("Updated routes: {$path}");
    }

    protected function ensureApiRoutesRegistered(bool $isModular): void {
        if ($isModular) {
            return;
        }

        $bootstrapFile = base_path('bootstrap/app.php');

        if (! File::exists($bootstrapFile)) {
            return;
        }

        $contents = File::get($bootstrapFile);

        if (! str_contains($contents, '->withRouting(')
            || preg_match('/->withRouting\s*\([^;]*\bapi\s*:/s', $contents)
        ) {
            return;
        }

        $updated = preg_replace_callback(
            '/^(\s*web:\s*__DIR__\s*\.\s*[\'"]\/\.\.\/routes\/web\.php[\'"],\s*)$/m',
            function (array $matches): string {
                preg_match('/^(\s*)/', $matches[1], $indent);

                return rtrim($matches[1]) . "\n" . ($indent[1] ?? '        ') . "api: __DIR__.'/../routes/api.php',";
            },
            $contents,
            1,
            $count
        );

        if ($count === 0 || $updated === null) {
            $this->warn('Could not automatically register routes/api.php in bootstrap/app.php. Run php artisan install:api or add api routing manually.');
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would register routes/api.php in {$bootstrapFile}");
            return;
        }

        File::put($bootstrapFile, $updated);
        $this->info("Registered API routes in {$bootstrapFile}");
    }

    protected function ensureModuleRoutesRegistered(?string $module): void {
        if (! $module) {
            return;
        }

        $rootRouteFile = base_path('routes/api.php');
        $includeLine = "require base_path('app/Modules/{$module}/routes/api.php');";

        if ($this->option('dry-run')) {
            $this->line("[dry-run] Would register module routes for {$module} in {$rootRouteFile}");
            $this->ensureApiRoutesRegistered(false);
            return;
        }

        File::ensureDirectoryExists(dirname($rootRouteFile));

        if (! File::exists($rootRouteFile)) {
            File::put($rootRouteFile, "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n// module routes\n");
        }

        $contents = File::get($rootRouteFile);

        if (! str_contains($contents, 'use Illuminate\Support\Facades\Route;')) {
            $contents = preg_replace('/^<\?php\s*/', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n", $contents, 1) ?? $contents;
        }

        if (! str_contains($contents, $includeLine)) {
            if (! str_contains($contents, '// module routes')) {
                $contents = rtrim($contents) . "\n\n// module routes\n";
            }

            $contents = rtrim($contents) . "\n{$includeLine}\n";
            $this->writeRouteFile($rootRouteFile, $contents);
        }

        $this->ensureApiRoutesRegistered(false);
    }

    protected function loadStub(string $name) : string {
        return $this->stubCache[$name] ??= $this->getStub($name);
    }

    protected function generateRequestRules(array $fields): string {
        return collect($fields)->map(fn ($f) =>
            "'{$f['name']}' => ['" . implode("','", $this->mapValidationRules($f)) . "']"
        )->implode(",\n        ");
    }

    protected function hasFieldOption(array $field, string $option): bool {
        return array_key_exists($option, $field['options'] ?? [])
            || in_array($option, $field['mods'] ?? [], true);
    }

    protected function fieldOption(array $field, string $option, mixed $default = null): mixed {
        return $field['options'][$option] ?? $default;
    }

    protected function entityOption(array $entity, string $option, mixed $default = null): mixed {
        return $entity['options'][$option] ?? $default;
    }

    protected function directiveRows(array $entity, string $name): array {
        return $entity['directives'][$name] ?? [];
    }

    protected function directiveValues(array $entity, string $name): array {
        return collect($this->directiveRows($entity, $name))->flatten()->values()->all();
    }

    protected function hasDirective(array $entity, string $name): bool {
        return ! empty($entity['directives'][$name] ?? []);
    }

    protected function hasUpload(array $entity = [], bool $forced = false): bool {
        return $forced || $this->hasDirective($entity, 'upload');
    }

    protected function uploadConfig(array $entity = []): array {
        $config = [
            'field' => 'file',
            'mimes' => 'csv,xls,xlsx',
            'max' => '2048',
        ];

        foreach ($this->directiveValues($entity, 'upload') as $token) {
            if (! is_string($token) || ! str_contains($token, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $token, 2);
            $key = Str::camel($key);

            if (array_key_exists($key, $config)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    protected function phpValue(mixed $value): string {
        if ($value === true || $value === 'true') {
            return 'true';
        }

        if ($value === false || $value === 'false') {
            return 'false';
        }

        if ($value === null || $value === 'null') {
            return 'null';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }

    protected function mapValidationRules(array $field): array {
        $rules = [];

        $rules[] = match ($field['type']) {
            'string', 'text', 'mediumtext', 'longtext', 'uuid', 'ulid', 'enum' => 'string',
            'number', 'int', 'integer' => 'integer',
            'decimal', 'float', 'double' => 'numeric',
            'boolean', 'bool' => 'boolean',
            'date', 'datetime', 'timestamp' => 'date',
            'json' => 'array',
            default => $field['type'] === 'foreignid' ? 'integer' : 'string',
        };

        if ($this->hasFieldOption($field, 'required')) {
            $rules[] = 'required';
        }

        if ($this->hasFieldOption($field, 'nullable')) {
            $rules[] = 'nullable';
        }

        if ($field['type'] === 'string' && isset($field['args'][0]) && is_numeric($field['args'][0])) {
            $rules[] = "max:{$field['args'][0]}";
        }

        if ($field['type'] === 'enum' && ! empty($field['args'])) {
            $rules[] = 'in:' . implode(',', $field['args']);
        }

        if ($this->hasFieldOption($field, 'unique')) {
            $unique = $this->fieldOption($field, 'unique', true);
            $rules[] = $unique === true
                ? "unique:{$this->currentTable},{$field['name']}"
                : "unique:{$unique}";
        }

        return $rules;
    }

    protected function createFormRequests(string $basePath, string $model, array $specFields, bool $isModular = false, ?string $module = null) : void {
        $rules = $this->generateRequestRules($specFields);
        $namespace = $isModular ? "App\\Modules\\{$module}\\Http\\Requests" : "App\\Http\\Requests";

        foreach (['Store', 'Update'] as $type) {
            $stub = $this->loadStub('FormRequest.stub');

            $content = str_replace(
                ['{{namespace}}', '{{requestName}}', '{{rules}}'],
                [$namespace, "{$type}{$model}Request", $rules],
                $stub
            );

            $path = "{$basePath}/Http/Requests/{$type}{$model}Request.php";
            $this->writeFile($path, $content);
        }
    }


    protected function buildMigrationLine(array $field) : string {
        $args = $field['args'] ?? [];

        $line = match ($field['type']) {
            'string' => isset($args[0]) && is_numeric($args[0])
                ? "\$table->string('{$field['name']}', {$args[0]})"
                : "\$table->string('{$field['name']}')",
            'text' => "\$table->text('{$field['name']}')",
            'mediumtext' => "\$table->mediumText('{$field['name']}')",
            'longtext' => "\$table->longText('{$field['name']}')",
            'number', 'int', 'integer' => "\$table->integer('{$field['name']}')",
            'biginteger' => "\$table->bigInteger('{$field['name']}')",
            'unsignedbiginteger' => "\$table->unsignedBigInteger('{$field['name']}')",
            'foreignid' => "\$table->foreignId('{$field['name']}')",
            'boolean', 'bool' => "\$table->boolean('{$field['name']}')",
            'date' => "\$table->date('{$field['name']}')",
            'datetime' => "\$table->dateTime('{$field['name']}')",
            'timestamp' => "\$table->timestamp('{$field['name']}')",
            'time' => "\$table->time('{$field['name']}')",
            'decimal' => "\$table->decimal('{$field['name']}', " . ($args[0] ?? 8) . ", " . ($args[1] ?? 2) . ")",
            'float' => "\$table->float('{$field['name']}')",
            'double' => "\$table->double('{$field['name']}')",
            'json' => "\$table->json('{$field['name']}')",
            'uuid' => "\$table->uuid('{$field['name']}')",
            'ulid' => "\$table->ulid('{$field['name']}')",
            'enum' => $this->hasFieldOption($field, 'nativeEnum')
                ? "\$table->enum('{$field['name']}', [" . collect($args)->map(fn ($value) => $this->phpValue($value))->implode(', ') . "])"
                : "\$table->string('{$field['name']}')",
            default => "\$table->string('{$field['name']}')",
        };

        if ($field['type'] === 'foreignid') {
            $reference = $this->fieldOption($field, 'references')
                ?? $this->fieldOption($field, 'constrained')
                ?? (($field['args'] ?? [])[0] ?? null);

            if ($reference === true) {
                $line .= "->constrained()";
            } elseif ($reference) {
                $line .= "->constrained('{$reference}')";
            }
        }

        if ($this->hasFieldOption($field, 'unsigned') && ! str_contains($line, 'unsigned')) {
            $line .= "->unsigned()";
        }

        if ($this->hasFieldOption($field, 'unique')) {
            $line .= "->unique()";
        }

        if ($this->hasFieldOption($field, 'index')) {
            $line .= "->index()";
        }

        if ($this->hasFieldOption($field, 'nullable')) {
            $line .= "->nullable()";
        }

        if ($this->hasFieldOption($field, 'default')) {
            $line .= "->default(" . $this->phpValue($this->fieldOption($field, 'default')) . ")";
        }

        foreach (['cascadeOnDelete', 'nullOnDelete', 'restrictOnDelete', 'cascadeOnUpdate'] as $foreignModifier) {
            if ($this->hasFieldOption($field, $foreignModifier)) {
                $line .= "->{$foreignModifier}()";
            }
        }

        return $line . ";";
    }

    protected function generateMigrationFromSpec( string $model, string $table, array $fields, array $entity = []) : void {
        $timestamp = date('Y_m_d_His');
        $className = "Create" . Str::studly($table) . "Table";
        $fileName = "{$timestamp}_create_{$table}_table.php";
        $path = database_path("migrations/{$fileName}");

        $columns = collect($fields)
            ->map(fn ($field) => "            " . $this->buildMigrationLine($field))
            ->implode("\n");

        if ($this->entityOption($entity, 'softDeletes', false)) {
            $columns .= "\n            \$table->softDeletes();";
        }

        $stub = $this->loadStub('Migration.stub');

        $migration = str_replace(
            ['{{table}}','{{columns}}'],
            [$table, $columns],
            $stub
        );

        $this->writeFile($path, $migration);
    }

    protected function moduleForEntity(array $entity): ?string {
        $module = ($this->directiveValues($entity, 'module')[0] ?? null)
            ?: $this->entityOption($entity, 'module')
            ?: $this->option('module');

        return $module ? $this->sanitizeClass($module) : null;
    }

    protected function handleSpecMode(string $specPath) : void {
        $parser = new SimpleSpecParser();
        $entities = $parser->parse($specPath);

        foreach($entities as $model => $entity) {
            $module = $this->moduleForEntity($entity);
            $isModular = $module !== null;
            $architecture = strtolower((string) $this->entityOption($entity, 'architecture', 'standard'));

            if ($architecture === 'modular' && ! $isModular) {
                throw new \RuntimeException("Entity {$model} uses modular architecture but no module was defined.");
            }

            $basePath = $isModular ? app_path("Modules/{$module}") : app_path();
            $this->createDirectories($basePath, $isModular);
            $fields = $entity['fields'];
            $table = $this->sanitizeTable(
                $this->entityOption($entity, 'table') ?: Str::plural(Str::snake($model))
            );
            $this->currentTable = $table;

            if ($this->option('migration') || $this->option('migration-only')) {
                $this->generateMigrationFromSpec($model, $table, $fields, $entity);
            }

            if ($this->option('migration-only')) {
                continue;
            }

            $uploadEnabled = $this->hasUpload($entity);
            $this->createFormRequests($basePath, $model, $fields, $isModular, $module);

            $columns = array_map(fn ($f) => $f['name'], $fields);

            $this->createModel(
                $basePath,
                $model,
                $columns,
                $isModular,
                $module,
                $fields,
                $table
            );

            $this->createService($basePath, $model, $isModular, $module, $entity, $uploadEnabled);
            $this->createRepository($basePath, $model, $isModular, $columns, $module, $entity);
            $this->createController($basePath, $model, $uploadEnabled, $isModular, $module, $entity);

            if ($uploadEnabled) {
                $this->createImportClass($basePath, $model, $columns, $isModular, $module);
            }

            if ($this->option('routes')) {
                $this->generateRoutes($model, $isModular, $module, $entity, $uploadEnabled);
            }
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

        $this->createModel($basePath, $model, $columns, $isModular, $module, null, $table);
        $this->createService($basePath, $model, $isModular, $module, [], $massUpload);
        $this->createRepository($basePath, $model, $isModular, $columns, $module);
        $this->createController($basePath, $model, $massUpload, $isModular, $module);

        if ($massUpload) {
            $this->createImportClass($basePath, $model, $columns, $isModular, $module);
        }

        // If the --routes option is provided, generate routes
        if ($generateRoutes) {
            $this->generateRoutes($model, $isModular, $module, [], $massUpload);
        }

        $this->info("CRUD scaffolding complete for {$model}" . ($isModular ? " in module {$module}" : '') . '.');
    }

    protected function createDirectories($basePath, $isModular)
    {
        $directories = $isModular
            ? ['Http/Controllers', 'Http/Requests', 'Models', 'Repositories', 'Services', 'Imports', 'routes']
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

    protected function isSchemaColumnList(array $columns): bool {
        return ! empty($columns) && is_object($columns[0]) && property_exists($columns[0], 'COLUMN_NAME');
    }

    protected function columnNames(array $columns): array {
        if ($this->isSchemaColumnList($columns)) {
            return array_map(fn ($item) => $item->COLUMN_NAME, $columns);
        }

        return $columns;
    }

    protected function buildSpecRelationships(array $specFields): string {
        return collect($specFields)
            ->filter(fn ($field) => $field['type'] === 'foreignid')
            ->map(function ($field) {
                $reference = $this->fieldOption($field, 'references')
                    ?? $this->fieldOption($field, 'constrained')
                    ?? (($field['args'] ?? [])[0] ?? null);

                $relation = Str::camel(Str::beforeLast($field['name'], '_id'));
                $className = $reference
                    ? Str::studly(Str::singular($reference))
                    : Str::studly(Str::beforeLast($field['name'], '_id'));

                return "
    public function {$relation}() {
        return \$this->belongsTo({$className}::class, '{$field['name']}');
    }
            ";
            })
            ->implode("\n");
    }

    protected function generateCasts(array $fields): string {
        return collect($fields)
            ->map(function ($field) {
                $cast = match ($field['type']) {
                    'boolean', 'bool' => 'boolean',
                    'number', 'int', 'integer', 'biginteger', 'unsignedbiginteger', 'foreignid' => 'integer',
                    'decimal' => isset($field['args'][1]) ? "decimal:{$field['args'][1]}" : 'decimal:2',
                    'float', 'double' => 'float',
                    'date' => 'date',
                    'datetime', 'timestamp' => 'datetime',
                    'json' => 'array',
                    default => null,
                };

                return $cast ? "'{$field['name']}' => '{$cast}'" : null;
            })
            ->filter()
            ->implode(",\n        ");
    }


    protected function createModel($basePath, $model, $cols, $isModular, $module, $specFields = null, ?string $table = null)
    {
        $relationships = "";
        if ($specFields) {
            $relationships = $this->buildSpecRelationships($specFields);
        } elseif ($this->isSchemaColumnList($cols)) {
            $fkFields = array_map(fn($col) => $col->COLUMN_NAME, array_filter($cols, fn($col) => $col->COLUMN_KEY == "MUL"));
            if (sizeof($fkFields) > 0) {
            $relationships .= implode("\n", array_map(fn($item)=>"
    public function ".$this->makefnName($model, $item)."() {
        return \$this->belongsTo(".implode(", ", $this->makeClassName($model, $item)).");
    }
            ", $fkFields));
            }
        }
        $columns = $specFields
            ? array_column($specFields, 'name')
            : array_filter($this->columnNames($cols), fn($item) => !in_array($item, $this->exemptColumn));
        $namespace = $isModular ? "App\\Modules\\{$module}\\Models" : "App\\Models";
        $modelStub = ($model == "User") ? $this->loadStub('UserModel.stub') : $this->loadStub('Model.stub');
        $fillable = $this->generateFillable($columns);
        $defaultTable = Str::plural(Str::snake($model));
        $tableDeclaration = $table && $table !== $defaultTable ? "protected \$table = '{$table}';" : '';
        $hidden = [];
        if ($specFields) {
            foreach ($specFields as $field) {
                if (in_array('hidden', $field['mods'])) {
                    $hidden[] = $field['name'];
                }
            }
        }
        $hidden = implode(", ", array_map(fn ($field) => "'{$field}'", $hidden));
        $casts = $specFields ? $this->generateCasts($specFields) : '';

        $modelContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{tableDeclaration}}', '{{fillable}}','{{hidden}}','{{casts}}','{{relationships}}'],
            [$model, $namespace, $tableDeclaration, $fillable, $hidden, $casts, $relationships],
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

    protected function specCommands(array $entity): array {
        $commands = $this->directiveValues($entity, 'commands');

        foreach ($this->directiveRows($entity, 'transition') as $transition) {
            $parsed = $this->parseTransitionDirective($transition);
            if ($parsed['command']) {
                $commands[] = $parsed['command'];
            }
        }

        return collect($commands)
            ->filter()
            ->map(fn ($command) => Str::studly($command))
            ->unique()
            ->values()
            ->all();
    }

    protected function parseTransitionDirective(array $values): array {
        $from = $values[0] ?? null;
        $to = $values[1] ?? null;

        if ($from && str_contains($from, '->')) {
            [$from, $to] = array_map('trim', explode('->', $from, 2));
        } elseif ($from && str_contains($from, '-')) {
            [$from, $to] = array_map('trim', explode('-', $from, 2));
        }

        return [
            'from' => $from ? Str::studly($from) : null,
            'to' => $to ? Str::studly($to) : null,
            'command' => isset($values[2]) ? Str::studly($values[2]) : null,
            'event' => isset($values[3]) ? Str::studly($values[3]) : null,
        ];
    }

    protected function transitionForCommand(array $entity, string $command): ?array {
        foreach ($this->directiveRows($entity, 'transition') as $transition) {
            $parsed = $this->parseTransitionDirective($transition);
            if (($parsed['command'] ?? null) === $command) {
                return $parsed;
            }
        }

        return null;
    }

    protected function eventForCommand(array $entity, string $command, int $index): string {
        $transition = $this->transitionForCommand($entity, $command);
        if ($transition && $transition['event']) {
            return $transition['event'];
        }

        $events = $this->directiveValues($entity, 'events');
        return isset($events[$index]) ? Str::studly($events[$index]) : $command;
    }

    protected function stateField(array $fields): ?string {
        foreach (['status', 'state'] as $candidate) {
            foreach ($fields as $field) {
                if ($field['name'] === $candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    protected function generateBusinessRuleChecks(array $entity): string {
        $rules = [];

        foreach ($this->directiveRows($entity, 'rule') as $rule) {
            if (count($rule) < 3 || strtolower($rule[1]) !== 'requires') {
                continue;
            }

            $rules[Str::studly($rule[0])] = array_map(fn ($field) => Str::snake($field), array_slice($rule, 2));
        }

        if (empty($rules)) {
            return '// Add custom business rule checks here.';
        }

        $exported = var_export($rules, true);

        return "\$rules = {$exported};

        foreach ((\$rules[\$action] ?? []) as \$field) {
            \$hasData = array_key_exists(\$field, \$data) && \$data[\$field] !== null && \$data[\$field] !== '';
            \$hasRecord = \$record && isset(\$record->{\$field}) && \$record->{\$field} !== null && \$record->{\$field} !== '';

            if (! \$hasData && ! \$hasRecord) {
                throw new \\DomainException(\"{\$action} requires {\$field}.\");
            }
        }";
    }

    protected function generateCommandMethods(string $model, array $entity): string {
        $commands = $this->specCommands($entity);
        if (empty($commands)) {
            return '// Add custom command methods here.';
        }

        $stateField = $this->stateField($entity['fields'] ?? []);

        return collect($commands)->map(function ($command, $index) use ($entity, $stateField) {
            $method = Str::camel($command);
            $transition = $this->transitionForCommand($entity, $command);
            $event = $this->eventForCommand($entity, $command, $index);
            $transitionBlock = '';

            if ($transition && $stateField && $transition['from'] && $transition['to']) {
                $transitionBlock = "
        if ((string) \$record->{$stateField} !== '{$transition['from']}') {
            throw new \\DomainException('{$command} can only transition {$stateField} from {$transition['from']} to {$transition['to']}.');
        }

        \$record = \$this->repository->update(\$id, array_merge(\$data, ['{$stateField}' => '{$transition['to']}']));";
            }

            if ($transitionBlock === '') {
                $transitionBlock = "
        // Implement {$command} domain behavior here.";
            }

            return "public function {$method}(\$id, array \$data = [])
    {
        \$record = \$this->repository->getById(\$id);
        \$this->assertBusinessRules('{$command}', \$data, \$record);{$transitionBlock}
        \$this->dispatchDomainEvent('{$event}', ['record' => \$record, 'data' => \$data]);

        return \$record;
    }";
        })->implode("\n\n    ");
    }

    protected function generateMassUploadMethod(string $model, bool $uploadEnabled): string {
        if (! $uploadEnabled) {
            return '';
        }

        return "public function massUpload(\$file)
    {
        try {
            Excel::import(new {$model}Import, \$file);
            return true;
        } catch (\\Throwable \$e) {
            return \$e->getMessage();
        }
    }";
    }

    protected function createService($basePath, $model, $isModular, $module, array $entity = [], bool $uploadEnabled = false)
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Services" : "App\\Services";
        $serviceImports = $isModular ? "use App\\Modules\\{$module}\\Repositories\\{$model}Repository;" : "use App\\Repositories\\{$model}Repository;";
        if ($uploadEnabled) {
            $serviceImports .= $isModular ? "\nuse App\\Modules\\{$module}\\Imports\\{$model}Import;" : "\nuse App\\Imports\\{$model}Import;";
        }
        $serviceStub = ($model == "User") ? $this->loadStub('UserService.stub') : $this->loadStub('Service.stub');
        $serviceContent = str_replace(
            ['{{modelName}}', '{{namespace}}','{{excelImport}}','{{serviceImports}}', '{{businessRuleChecks}}', '{{commandMethods}}', '{{massUploadMethod}}'],
            [
                $model,
                $namespace,
                $uploadEnabled ? 'use Maatwebsite\Excel\Facades\Excel;' : '',
                $serviceImports,
                $this->generateBusinessRuleChecks($entity),
                $this->generateCommandMethods($model, $entity),
                $this->generateMassUploadMethod($model, $uploadEnabled)
            ],
            $serviceStub
        );
        $servicePath = $isModular ? "{$basePath}/Services/{$model}Service.php" : app_path("Services/{$model}Service.php");
        $this->writeFile($servicePath, $serviceContent);
    }

    protected function searchableColumns(array $columns, array $entity = []): array {
        $search = array_map(fn ($field) => Str::snake($field), $this->directiveValues($entity, 'search'));

        if (! empty($search)) {
            return $search;
        }

        return array_values(array_filter(
            $this->columnNames($columns),
            fn ($column) => ! in_array($column, $this->exemptColumn, true)
        ));
    }

    protected function createRepository($basePath, $model, $isModular, $columns, $module, array $entity = [])
    {
        $fetchStr = "";
        $fetchSingleStr = "";
        $fkFields = $this->isSchemaColumnList($columns)
            ? array_map(fn($col) => $col->COLUMN_NAME, array_filter($columns, fn($col) => $col->COLUMN_KEY == "MUL"))
            : [];
        if(sizeof($fkFields) > 0) {
            $fetchStr .= " $model::with([".implode(", ", array_map(fn($item) => "'".$this->makefnName($model, $item)."'", $fkFields))."])->get();";
            $fetchSingleStr .= " $model::with([".implode(", ", array_map(fn($item) => "'".$this->makefnName($model,$item)."'", $fkFields))."])->findOrFail(\$id);";
        } else {
            $fetchStr .= " $model::all();";
            $fetchSingleStr .= " $model::findOrFail(\$id);";
        }
        $namespace = $isModular ? "App\\Modules\\{$module}\\Repositories" : "App\\Repositories";
        $modelImports = $isModular ? "use App\\Modules\\{$module}\\Models\\{$model};" : "use App\\Models\\{$model};";
        $repositoryStub = $this->loadStub('Repository.stub');
        $searchableColumns = implode(', ', array_map(fn ($column) => "'{$column}'", $this->searchableColumns($columns, $entity)));
        $repositoryContent = str_replace(
            ['{{modelName}}', '{{namespace}}', '{{modelImports}}','{{fetchStr}}',"{{fetchSingleStr}}", '{{searchableColumns}}'],
            [$model, $namespace, $modelImports, $fetchStr, $fetchSingleStr, $searchableColumns],
            $repositoryStub
        );
        $repositoryPath = $isModular ? "{$basePath}/Repositories/{$model}Repository.php" : app_path("Repositories/{$model}Repository.php");
        $this->writeFile($repositoryPath, $repositoryContent);
    }

    protected function generateControllerCommandActions(array $entity): string {
        $commands = $this->specCommands($entity);
        if (empty($commands)) {
            return '';
        }

        return collect($commands)->map(function ($command) {
            $method = Str::camel($command);

            return "public function {$method}(Request \$request, \$id)
    {
        return response()->json(\$this->service->{$method}(\$id, \$request->all()));
    }";
        })->implode("\n\n    ");
    }

    protected function createController($basePath, $model, $massUpload, $isModular, $module, array $entity = [])
    {
        $namespace = $isModular ? "App\\Modules\\{$module}\\Http\\Controllers" : "App\\Http\\Controllers";
        $importService = $isModular ? "use App\\Modules\\{$module}\\Services\\{$model}Service;" : "use App\\Services\\{$model}Service;";
        $controllerStub = ($model == "User") ? $this->loadStub('UserController.stub') : $this->loadStub('Controller.stub');
        $usesSpecRequests = ! empty($entity);
        $requestImports = '';
        $storeRequest = 'Request';
        $updateRequest = 'Request';

        if ($usesSpecRequests) {
            $requestImports = $isModular
                ? "use App\\Modules\\{$module}\\Http\\Requests\\Store{$model}Request;\nuse App\\Modules\\{$module}\\Http\\Requests\\Update{$model}Request;"
                : "use App\\Http\\Requests\\Store{$model}Request;\nuse App\\Http\\Requests\\Update{$model}Request;";
            $storeRequest = "Store{$model}Request";
            $updateRequest = "Update{$model}Request";
        }

        $controllerContent = str_replace(
            ['{{modelName}}','{{namespace}}','{{importService}}','{{requestImports}}','{{storeRequest}}','{{updateRequest}}','{{storeData}}','{{updateData}}','{{commandActions}}'],
            [
                $model,
                $namespace,
                $importService,
                $requestImports,
                $storeRequest,
                $updateRequest,
                $usesSpecRequests ? '$request->validated()' : '$request->all()',
                $usesSpecRequests ? '$request->validated()' : '$request->all()',
                $this->generateControllerCommandActions($entity)
            ],
            $controllerStub
        );

        if ($massUpload) {
            $massUploadFunction = $this->loadStub('MassUploadFunction.stub');
            $uploadConfig = $this->uploadConfig($entity);
            $massUploadFunction = str_replace(
                ['{{modelName}}', '{{uploadField}}', '{{uploadMimes}}', '{{uploadMax}}'],
                [$model, $uploadConfig['field'], $uploadConfig['mimes'], $uploadConfig['max']],
                $massUploadFunction
            );
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
        $namespace = $isModular ? "App\\Modules\\{$module}\\Imports" : "App\\Imports";
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

    protected function routePrefix(string $model, array $entity = []): string {
        $route = $this->directiveValues($entity, 'route')[0] ?? null;

        return $route ?: str_replace('_','-', Str::snake(Str::plural($model)));
    }

    protected function generateCommandRoutes(string $model, array $entity = []): string {
        return collect($this->specCommands($entity))->map(function ($command) use ($model) {
            $method = Str::camel($command);
            $uri = Str::kebab($command);

            return "    Route::post('{id}/{$uri}', [{$model}Controller::class, '{$method}']);";
        })->implode("\n");
    }

    protected function normalizeRouteImports(string $routeContents): string {
        $seen = [];

        return collect(explode("\n", $routeContents))->filter(function ($line) use (&$seen) {
            if (preg_match('/^\s*use\s+[^;]+;\s*$/', $line) !== 1) {
                return true;
            }

            $key = trim($line);

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;
            return true;
        })->implode("\n");
    }

    protected function prepareRouteContents(string $routeContents, string $classImport): string {
        if (trim($routeContents) === '') {
            return "<?php\n\nuse Illuminate\Support\Facades\Route;\n{$classImport}\n// routes\n";
        }

        if (! str_contains($routeContents, 'use Illuminate\Support\Facades\Route;')) {
            $routeContents = preg_replace('/^<\?php\s*/', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n", $routeContents, 1) ?? $routeContents;
        }

        if (! str_contains($routeContents, trim($classImport))) {
            if (str_contains($routeContents, "\n// routes")) {
                $routeContents = str_replace("\n// routes", $classImport . "\n// routes", $routeContents);
            } else {
                $routeContents = rtrim($routeContents) . "\n" . $classImport;
            }
        }

        $routeContents = $this->normalizeRouteImports($routeContents);

        if (! str_contains($routeContents, '// routes')) {
            $routeContents = rtrim($routeContents) . "\n\n// routes\n";
        }

        return $routeContents;
    }

    protected function removeExistingRouteBlock(string $routeContents, string $routePrefix): string {
        $prefix = preg_quote($routePrefix, '/');

        return preg_replace(
            "/Route::prefix\\('{$prefix}'\\)->group\\(function \\(\\) \\{.*?\\}\\);\\s*/s",
            '',
            $routeContents
        ) ?? $routeContents;
    }

    protected function generateRoutes($model, $isModular, $module, array $entity = [], bool $uploadEnabled = false)
    {
        $routePath = $isModular
            ? app_path("Modules/{$module}/routes")
            : base_path("routes");

        $classImport = $isModular ? "use App\\Modules\\{$module}\\Http\\Controllers\\{$model}Controller;\n" : "use App\\Http\\Controllers\\{$model}Controller;\n";
        $routeFile = $routePath ."/api.php";
        // Check if routes/api.php exists
        if (!File::exists($routePath)) {
            mkdir($routePath,0777, true);
        }

        if (! File::exists($routeFile)) {
            touch($routeFile);
        }

        // Load the route file contents
        $routeContents = File::get($routeFile);

        // Define the API routes for the model
        $routePref = $this->routePrefix($model, $entity);
        $commandRoutes = $this->generateCommandRoutes($model, $entity);
        $commandRoutes = $commandRoutes ? "\n{$commandRoutes}" : "";
        $uploadRoute = $uploadEnabled ? "\n    Route::post('upload', [{$model}Controller::class, 'massUpload']);" : "";
        $modelRoutes = "Route::prefix('{$routePref}')->group(function () {\n    Route::get('/', [{$model}Controller::class, 'index']);\n    Route::post('/', [{$model}Controller::class, 'store']);{$uploadRoute}\n    Route::get('{id}', [{$model}Controller::class, 'show']);\n    Route::put('{id}', [{$model}Controller::class, 'update']);\n    Route::delete('{id}', [{$model}Controller::class, 'destroy']);{$commandRoutes}\n});\n\n";
        $routeContents = $this->prepareRouteContents($routeContents, $classImport);
        $routeContents = $this->removeExistingRouteBlock($routeContents, $routePref);
        $routeContents = rtrim($routeContents) . "\n" . $modelRoutes;

        // Save the updated route file
        $this->writeRouteFile($routeFile, $routeContents);

        if ($isModular) {
            $this->ensureModuleRoutesRegistered($module);
        } else {
            $this->ensureApiRoutesRegistered(false);
        }

        $this->info("API routes for {$model} have been added to {$routeFile}");
    }


    protected function generateColumnMappings($cols)
    {
        $columns = $this->columnNames($cols);
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
        return File::get(__DIR__ . "/../../stubs/{$stubName}");
    }

}
