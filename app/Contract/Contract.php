<?php

namespace App\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * The JSON Schemas copied from comitiva's `@comitiva/contract` at a pinned
 * tag (resources/contract, ADR 0016 in comitiva). Every request body is
 * validated against them; tests validate responses and broadcasts too.
 */
class Contract
{
    public const BASE_ID = 'https://comitiva.dev/schema/';

    private Validator $validator;

    public function __construct(private readonly string $directory)
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(5);
        $this->validator->resolver()->registerPrefix(self::BASE_ID, $directory);
    }

    public static function directory(): string
    {
        return resource_path('contract/schema');
    }

    /** The tag the schemas came from, as `contract:sync` recorded it. */
    public function version(): string
    {
        $file = dirname($this->directory).'/VERSION';
        if (! is_file($file)) {
            return 'unknown';
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && is_string($data['tag'] ?? null) ? $data['tag'] : 'unknown';
    }

    /**
     * Null when `$data` (decoded JSON: objects as stdClass) matches the named
     * schema, else a short description of the first problems.
     */
    public function errors(string $schema, mixed $data): ?string
    {
        if (! is_file($this->directory."/{$schema}.json")) {
            throw new RuntimeException("Unknown contract schema {$schema}");
        }
        $result = $this->validator->validate($data, self::BASE_ID.$schema.'.json');
        if ($result->isValid()) {
            return null;
        }

        return $this->describe($result->error());
    }

    /** Converts PHP arrays into the shape JSON decoding would give (objects as stdClass). */
    public static function toJson(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function describe(?ValidationError $error): string
    {
        if ($error === null) {
            return 'invalid';
        }
        $messages = [];
        foreach ((new ErrorFormatter)->format($error, false) as $path => $message) {
            $messages[] = ($path === '/' ? '' : $path.': ').$message;
            if (count($messages) === 3) {
                break;
            }
        }

        return implode('; ', $messages);
    }
}
