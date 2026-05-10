<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MissingAnthropicKeyException extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'Anthropic API key belum terdeteksi. Tambahkan ANTHROPIC_API_KEY di vault/branding-builder.json '
            .'(atau ANTHROPIC_API_KEY di .env saat development).'
        );
    }
}
