<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandAudit;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    public function status(string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        return response()->json([
            'status'        => $audit->status,
            'overall_score' => $audit->overall_score,
            'pillar_scores' => collect((array) $audit->pillar_scores)
                ->mapWithKeys(fn ($data, $slug) => [
                    $slug => is_array($data) ? ($data['score'] ?? null) : null,
                ])
                ->toArray(),
        ]);
    }
}
