<?php

namespace App\Services\LanguageApp\Validators;

use App\Support\LiteValidationException;

/**
 * Lightweight JSON "schema-like" validator for our subset:
 * - supports: type: object|array|string|number|integer
 * - supports: required, additionalProperties=false, enum
 * - recursively validates nested objects/arrays
 *
 * NOTE: json_decode(..., true) maps {} -> [].
 * We therefore treat an empty array as a valid empty "object" when type=object.
 */
final class AiJsonValidator
{
    public static function validate(array $schema, mixed $data, string $path = '$'): void
    {
        $type = $schema['type'] ?? null;

        if ($type === 'object') {
            if (! is_array($data) || (array_is_list($data) && count($data) > 0)) {
                throw new LiteValidationException([], "{$path}: expected object");
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                $known = array_keys($schema['properties'] ?? []);
                foreach (array_keys($data) as $k) {
                    if (! in_array($k, $known, true)) {
                        throw new LiteValidationException([], "{$path}: unknown property '{$k}'");
                    }
                }
            }

            foreach (($schema['required'] ?? []) as $req) {
                if (! array_key_exists($req, $data)) {
                    throw new LiteValidationException([], "{$path}: missing required '{$req}'");
                }
            }

            foreach (($schema['properties'] ?? []) as $k => $sub) {
                if (array_key_exists($k, $data)) {
                    self::validate($sub, $data[$k], "{$path}.{$k}");
                }
            }

        } elseif ($type === 'array') {
            if (! is_array($data) || ! array_is_list($data)) {
                throw new LiteValidationException([], "{$path}: expected array");
            }
            $min = $schema['minItems'] ?? null;
            if ($min !== null && count($data) < $min) {
                throw new LiteValidationException([], "{$path}: minItems {$min} violated");
            }
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) {
                    self::validate($schema['items'], $item, "{$path}[{$i}]");
                }
            }

        } elseif ($type === 'string') {
            if (! is_string($data)) {
                throw new LiteValidationException([], "{$path}: expected string");
            }
            if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
                throw new LiteValidationException([], "{$path}: value '{$data}' not in enum");
            }
        } elseif ($type === 'number') {
            if (! is_numeric($data)) {
                throw new LiteValidationException([], "{$path}: expected number");
            }
        } elseif ($type === 'integer') {
            if (! is_int($data)) {
                throw new LiteValidationException([], "{$path}: expected integer");
            }
        } else {
            // pass-through for unknown (string|number) combos etc.
        }
    }
}
