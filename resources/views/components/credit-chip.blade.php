{{--
    BB124 — auth-aware credit balance chip rendered in the layout cta slot.

    Rendered statically from `auth()->user()->credits_balance`. No polling:
    after submit() the wizard redirects to /audit/{token}, which re-renders
    the layout and refreshes the balance. The /audits page already reads
    balance fresh on each request.
--}}
@auth
    <span
        title="Sisa kredit untuk audit baru"
        style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: var(--radius-pill); background: var(--chimera-50); color: var(--chimera-700); border: 1px solid var(--chimera-100); line-height: 1;"
    >
        <i class="ti ti-coin" style="font-size: 13px;"></i>
        {{ (int) auth()->user()->credits_balance }} kredit
    </span>
@endauth
