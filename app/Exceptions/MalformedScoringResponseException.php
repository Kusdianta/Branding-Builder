<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MalformedScoringResponseException extends RuntimeException
{
    public static function fromReason(string $pillarSlug, string $reason): self
    {
        return new self(sprintf('Respons rubric %s tidak valid: %s', $pillarSlug, $reason));
    }
}
