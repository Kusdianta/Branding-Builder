<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BrandAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB146 — truth table for BrandAudit::instagramAuditIsTerminal(), the
 * single source of truth the dashboard reveal gate relies on. `pending`
 * and `scraped` are the only non-terminal states; everything else (and
 * an unknown/future value) is terminal so the gate fails safe.
 */
class BrandAuditInstagramTerminalTest extends TestCase
{
    public static function statusProvider(): array
    {
        return [
            'null'                      => [null, false],
            'pending'                   => ['pending', false],
            'scraped'                   => ['scraped', false],
            'done'                      => ['done', true],
            'no_instagram_url_provided' => ['no_instagram_url_provided', true],
            'credentials_stale'         => ['credentials_stale', true],
            'profile_not_found'         => ['profile_not_found', true],
            'audit_failed'              => ['audit_failed', true],
            'unknown_future_value'      => ['some_new_status', true],
        ];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function instagram_audit_is_terminal_truth_table(?string $status, bool $expected): void
    {
        $audit = new BrandAudit(['instagram_audit_status' => $status]);

        $this->assertSame($expected, $audit->instagramAuditIsTerminal());
    }
}
