<?php 
    namespace Ahertl\LaravelScaffold\Spec;

    use Illuminate\Support\Str;


    class SimpleSpecParser {
        public function parse(string $path) : array {
            if (! file_exists($path)) {
                throw new \InvalidArgumentException("Spec file not found: {$path}");
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $entities = [];
            $current = null;

            foreach ($lines as $index => $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                // entity
                if (str_ends_with($line, ':')) {
                    $current = Str::studly(rtrim($line, ':'));
                    $entities[$current] = [];
                    continue;
                }

                // field type [modifiers]
                if (str_starts_with($line, '-')) {
                    if (! $current) {
                        throw new \RuntimeException("Field defined before entity at line ".$index+1);
                    }

                    $parts = preg_split('/\s+/', ltrim($line, '-'));
                    if (count($parts) < 2) {
                        throw new \RuntimeException(
                            "Invalid field definition at line ".($index + 1).
                            ". Expected: - name type"
                        );
                    }

                    [$name, $type] = $parts;
                    $mods = array_slice($parts, 2);

                    if (isset($entities[$current][$name])) {
                        throw new \RuntimeException(
                            "Duplicate field '{$name}' in {$current} at line ".($index + 1)
                        );
                    }

                    $entities[$current][] = [
                        'name' => Str::snake($name),
                        'type' => strtolower($type),
                        'mods' => $mods,
                    ];
                }
            }

            return $entities;
        }
    }