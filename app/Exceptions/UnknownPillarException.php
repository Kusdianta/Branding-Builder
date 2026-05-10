<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class UnknownPillarException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self(sprintf('Tidak ada scoring rubric aktif untuk pilar "%s".', $slug));
    }
}
