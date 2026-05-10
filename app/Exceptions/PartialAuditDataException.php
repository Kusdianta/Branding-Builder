<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class PartialAuditDataException extends RuntimeException
{
    public function __construct(
        public readonly string $pillarSlug,
        public readonly string $errorCode,
    ) {
        parent::__construct(sprintf(
            'Pilar %s tidak dapat diberi skor: %s. Aggregator harus menandai audit sebagai partial.',
            $pillarSlug,
            $errorCode,
        ));
    }
}
