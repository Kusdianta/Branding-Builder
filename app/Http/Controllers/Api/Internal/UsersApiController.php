<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\BrandAudit;
use App\Models\CreditAdjustment;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BB84 — internal API surface consumed by the Hub Filament dashboard.
 * Three endpoints:
 *
 *   GET  /api/internal/users               paginated user list + counters
 *   GET  /api/internal/users/{id}          user detail + audit history + adjustment trail
 *   POST /api/internal/users/{id}/credits/adjust  body: { amount, reason, adjusted_by }
 *
 * Authentication is enforced upstream by the HubUsersApiKey middleware.
 * Response shape is deliberately stable JSON; the Hub client maps it
 * straight into a Filament table — schema changes here ripple cross-repo.
 */
class UsersApiController extends Controller
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $search  = trim((string) $request->query('search', ''));

        $query = User::query()
            ->withCount('brandAudits')
            ->orderByDesc('last_login_at')
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->items() === [] ? [] : array_map(
                fn (User $u) => $this->summarize($u),
                $page->items(),
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        /** @var User|null $user */
        $user = User::with([
            'brandAudits' => fn ($q) => $q->orderByDesc('created_at')->limit(50),
        ])->find($id);

        if (! $user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        $adjustments = CreditAdjustment::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CreditAdjustment $a) => [
                'id'          => $a->id,
                'amount'      => $a->amount,
                'reason'      => $a->reason,
                'adjusted_by' => $a->adjusted_by,
                'created_at'  => $a->created_at?->toIso8601String(),
            ])->all();

        return response()->json([
            'user' => $this->summarize($user, withDetails: true),
            'audits' => $user->brandAudits->map(fn (BrandAudit $a) => [
                'id'             => $a->id,
                'session_token'  => $a->session_token,
                'brand_name'     => $a->brand_name,
                'city'           => $a->city,
                'status'         => $a->status,
                'overall_score'  => $a->overall_score,
                'credits_charged' => $a->credits_charged,
                'created_at'     => $a->created_at?->toIso8601String(),
            ])->all(),
            'adjustments' => $adjustments,
        ]);
    }

    public function adjustCredits(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount'      => 'required|integer|not_in:0',
            'reason'      => 'required|string|max:255',
            'adjusted_by' => 'required|string|max:120',
        ]);

        /** @var User|null $user */
        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        $newBalance = $this->ledger->adjust(
            user: $user,
            amount: (int) $validated['amount'],
            reason: (string) $validated['reason'],
            adjustedBy: (string) $validated['adjusted_by'],
        );

        return response()->json([
            'user_id'         => $user->id,
            'credits_balance' => $newBalance,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function summarize(User $user, bool $withDetails = false): array
    {
        $base = [
            'id'                      => $user->id,
            'email'                   => $user->email,
            'name'                    => $user->name,
            'avatar_url'              => $user->avatar_url,
            'credits_balance'         => (int) $user->credits_balance,
            'credits_lifetime_earned' => (int) $user->credits_lifetime_earned,
            'credits_lifetime_spent'  => (int) $user->credits_lifetime_spent,
            'last_login_at'           => $user->last_login_at?->toIso8601String(),
            'created_at'              => $user->created_at?->toIso8601String(),
            'total_audits'            => (int) ($user->brand_audits_count ?? $user->brandAudits()->count()),
        ];

        if ($withDetails) {
            $base['google_id'] = $user->google_id;
        }

        return $base;
    }
}
