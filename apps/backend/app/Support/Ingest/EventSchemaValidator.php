<?php

namespace App\Support\Ingest;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

class EventSchemaValidator
{
    public const SCHEMA_ID = 'https://watchdog.example.invalid/contracts/event/1.0';

    private ?Validator $validator = null;

    public static function schemaPath(): string
    {
        return resource_path('contracts/event.schema.json');
    }

    public function isValid(mixed $event): bool
    {
        return $this->firstError($event) === null;
    }

    public function firstError(mixed $event): ?ValidationError
    {
        return $this->validator()->validate($event, self::SCHEMA_ID)->error();
    }

    private function validator(): Validator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        $validator = new Validator;
        $validator->setMaxErrors(1);
        $validator->parser()->setOption('defaultDraft', '2020-12');
        $validator->resolver()->registerFile(self::SCHEMA_ID, self::schemaPath());

        return $this->validator = $validator;
    }
}
