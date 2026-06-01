<?php

namespace Ahertl\LaravelScaffold\Spec;

use Illuminate\Support\Str;

class SimpleSpecParser
{
    public function parse(string $path): array
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("Spec file not found: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $entities = [];
        $current = null;
        $currentModule = null;
        $appOptions = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '@')) {
                if (! $current) {
                    $directive = $this->parseDirective($line, $index + 1);

                    if ($directive['name'] === 'app') {
                        $appOptions = array_merge($appOptions, $this->parseOptions($directive['values']));
                        continue;
                    }

                    throw new \RuntimeException("Directive defined before entity at line ".($index + 1));
                }

                $directive = $this->parseDirective($line, $index + 1);
                $entities[$current]['directives'][$directive['name']][] = $directive['values'];
                continue;
            }

            if (preg_match('/^module\s+([a-zA-Z0-9_\-]+)\s*:$/i', $line, $matches)) {
                $currentModule = Str::studly($matches[1]);
                $current = null;
                continue;
            }

            if (in_array(strtolower($line), ['endmodule', 'end module'], true)) {
                $currentModule = null;
                $current = null;
                continue;
            }

            if (str_ends_with($line, ':') && ! str_starts_with($line, '-')) {
                [$current, $entity] = $this->parseEntity($line, $index + 1, $appOptions, $currentModule);
                $entities[$current] = $entity;
                continue;
            }

            if (str_starts_with($line, '-')) {
                if (! $current) {
                    throw new \RuntimeException("Field defined before entity at line ".($index + 1));
                }

                $field = $this->parseField($line, $index + 1);
                if (isset($entities[$current]['field_names'][$field['name']])) {
                    throw new \RuntimeException(
                        "Duplicate field '{$field['name']}' in {$current} at line ".($index + 1)
                    );
                }

                $entities[$current]['field_names'][$field['name']] = true;
                $entities[$current]['fields'][] = $field;
            }
        }

        foreach ($entities as &$entity) {
            unset($entity['field_names']);
        }

        return $entities;
    }

    protected function parseEntity(string $line, int $lineNumber, array $appOptions = [], ?string $currentModule = null): array
    {
        $definition = trim(rtrim($line, ':'));
        $parts = preg_split('/\s+/', $definition);

        if (! $parts || $parts[0] === '') {
            throw new \RuntimeException("Invalid entity definition at line {$lineNumber}.");
        }

        $model = Str::studly(array_shift($parts));
        $options = array_merge($appOptions, $this->parseOptions($parts));

        if ($currentModule && ! isset($options['module'])) {
            $options['module'] = $currentModule;
        }

        return [
            $model,
            [
                'options' => $options,
                'directives' => [],
                'fields' => [],
                'field_names' => [],
            ],
        ];
    }

    protected function parseDirective(string $line, int $lineNumber): array
    {
        $parts = preg_split('/\s+/', trim(ltrim($line, '@')));

        if (! $parts || $parts[0] === '') {
            throw new \RuntimeException("Invalid directive definition at line {$lineNumber}.");
        }

        return [
            'name' => Str::camel(array_shift($parts)),
            'values' => $parts,
        ];
    }

    protected function parseField(string $line, int $lineNumber): array
    {
        $parts = preg_split('/\s+/', trim(ltrim($line, '-')));
        if (count($parts) < 2) {
            throw new \RuntimeException(
                "Invalid field definition at line {$lineNumber}. Expected: - name type"
            );
        }

        [$name, $typeSpec] = array_slice($parts, 0, 2);
        $type = $this->parseType($typeSpec);
        $options = $this->parseOptions(array_slice($parts, 2));

        return [
            'name' => Str::snake($name),
            'source_name' => $name,
            'type' => $type['name'],
            'args' => $type['args'],
            'mods' => array_keys($options),
            'options' => $options,
            'raw_type' => $typeSpec,
        ];
    }

    protected function parseType(string $typeSpec): array
    {
        [$name, $rawArgs] = array_pad(explode(':', $typeSpec, 2), 2, '');

        return [
            'name' => strtolower($name),
            'args' => $rawArgs === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $rawArgs)), fn ($value) => $value !== '')),
        ];
    }

    protected function parseOptions(array $tokens): array
    {
        $options = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (str_contains($token, ':')) {
                [$key, $value] = explode(':', $token, 2);
            } elseif (str_contains($token, '=')) {
                [$key, $value] = explode('=', $token, 2);
            } else {
                $key = $token;
                $value = true;
            }

            $options[Str::camel($key)] = $value;
        }

        return $options;
    }
}
