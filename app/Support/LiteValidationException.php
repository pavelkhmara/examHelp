<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Лёгкий вариант ValidationException, не использующий Facades/Container.
 * Хранит errors и задаёт message без вызова родительского конструктора.
 */
class LiteValidationException extends ValidationException
{
    /** @var array<string, array<int, string>> */
    protected array $liteErrors = [];

    public function __construct(array $errors = [], string $message = 'The given data was invalid.')
    {
        // Не вызываем parent::__construct(), чтобы не тянуть валидатор/переводы.
        $this->message = $message; // Exception::$message — protected
        $this->liteErrors = $errors;
    }

    /** Совместимость с API ValidationException::errors() */
    public function errors(): array
    {
        return $this->liteErrors;
    }
}
