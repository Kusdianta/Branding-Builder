<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateActivationKit;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function status(string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        // BB22: include per-step progress so external clients can build
        // their own live dashboards without scraping Livewire state. The
        // wizard view itself uses Livewire wire:poll, but this endpoint
        // gives a stable JSON contract for ops/monitoring.
        $steps = AuditStep::where('brand_audit_id', $audit->id)
            ->orderBy('order')
            ->get()
            ->map(fn ($s) => [
                'key'          => $s->step_key,
                'track'        => $s->track,
                'status'       => $s->status,
                'order'        => $s->order,
                'started_at'   => $s->started_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'elapsed_s'    => $s->elapsedSeconds(),
                'detail'       => $s->detail,
            ])
            ->all();

        return response()->json([
            'status'              => $audit->status,
            'overall_score'       => $audit->overall_score,
            'pillar_scores'       => collect((array) $audit->pillar_scores)
                ->mapWithKeys(fn ($data, $slug) => [
                    $slug => is_array($data) ? ($data['score'] ?? null) : null,
                ])
                ->toArray(),
            'activation_kit_path'    => $audit->activation_kit_path,
            'instagram_audit_status' => $audit->instagram_audit_status,
            'steps'                  => $steps,
        ]);
    }

    public function generateKit(string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        if (! $audit->isComplete()) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'audit_not_done',
            ], 422);
        }

        GenerateActivationKit::dispatch($audit);

        return response()->json(['status' => 'queued'], 202);
    }

    public function downloadKit(string $token): StreamedResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        if (! $audit->activation_kit_path || ! Storage::disk('local')->exists($audit->activation_kit_path)) {
            abort(404);
        }

        $brandSlug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $audit->brand_name) ?: 'brand';
        $filename  = "activation-kit-{$brandSlug}.pdf";

        return Storage::disk('local')->download($audit->activation_kit_path, $filename);
    }
}
