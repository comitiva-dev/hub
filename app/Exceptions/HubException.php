<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error with a stable code from the contract's ErrorCode. The API renders
 * it as `{ error: { code, message, retryable } }` with its HTTP status.
 */
class HubException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_request', $message, 422);
    }

    public static function forbidden(string $message = 'Your role does not allow that'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self('not_found', $message, 404);
    }

    /** @return array{code: string, message: string, retryable: bool} */
    public function shape(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage(), 'retryable' => $this->retryable];
    }
}
