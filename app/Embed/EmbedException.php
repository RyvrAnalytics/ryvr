<?php

namespace App\Embed;

use RuntimeException;

/**
 * A rejected embed request. The reason code is stable and safe to show to the embedding page;
 * the message never contains the token or any secret material.
 */
final class EmbedException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function unauthorized(string $reason, string $message = 'The embed token is not valid.'): self
    {
        return new self($reason, 401, $message);
    }

    public static function forbidden(string $reason, string $message): self
    {
        return new self($reason, 403, $message);
    }

    public static function badRequest(string $message): self
    {
        return new self('invalid_parameter', 400, $message);
    }
}
