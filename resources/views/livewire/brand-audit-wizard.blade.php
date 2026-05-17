<?php

declare(strict_types=1);

use App\Jobs\AnalyzeBrand;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\CreditLedger;
use App\Services\PlacesApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;

layout('layouts.audit');

new class extends Component {
    use WithFileUploads;

    // Phase 12c BB91 — top-level view state. 'wizard' = new v2 4-step
    // flow (default for new audits). 'analyzing' / 'dashboard' are
    // reached via mount(token) when an existing audit is loaded.
    // 'touchpoint_inputs' is the legacy v1 single-page form, kept in
    // place for any in-flight session but unreachable by default — BB98
    // cleanup deletes that code path.
    public string $step = 'wizard';

    // Form fields
    public string $brandName        = '';
    public string $city             = '';
    public string $serviceType      = 'kiloan';
    public string $instagramUrl     = '';
    public string $websiteUrl       = '';
    public string $tiktokUrl        = '';
    public string $gmapsUrl         = '';
    public bool   $whatsappBusiness = false;

    // BB73: operator declarations (Phase 11). All optional; empty means
    // "let the system auto-detect from touchpoints". Combined with
    // service_signals (BB74) by ExperienceScorer's tier classifier
    // (BB75) to attribute Brand Experience bonus sub-bucket scores.
    public ?bool  $declEkspres        = null;
    public string $declEkspresUrl     = '';
    public ?bool  $declAntarJemput    = null;
    public string $declAntarJemputUrl = '';
    /** @var list<string> */
    public array  $declServiceVariants = [];
    public ?bool  $declSopKeluhan     = null;
    public string $declSopKeluhanUrl  = '';
    public ?bool  $declPriceList      = null;
    public string $declPriceListUrl   = '';

    // File uploads (multi-file, image, max 3 each, 5MB each)
    public array $outletPhotosOuter = [];
    public array $outletPhotosInner = [];

    // Audit results
    public ?string $auditId            = null;
    public ?string $sessionToken       = null;
    public string  $auditStatus        = '';
    public ?int    $overallScore       = null;
    public ?string $overallLabel       = null;
    public array   $pillarScores       = [];
    public array   $subBucketScores    = [];
    public array   $keyFindings        = [];
    public array   $recommendations    = [];
    public ?string $errorMessage       = null;
    public ?string $activationKitPath  = null;
    public bool    $kitGenerating      = false;
    // BB79: surface PDF generation failures to the operator.
    public ?string $pdfError           = null;
    public array   $scoreBreakdown     = [];

    // Phase 7-C BB15: Instagram audit data for dashboard rendering.
    public array   $instagramAudit       = [];
    public ?string $instagramAuditStatus = null;

    // Phase 8 BB29: GMaps reviews data for dashboard rendering.
    public array   $gmapsReviews         = [];
    public ?string $gmapsReviewsStatus   = null;

    // BB60: validation warning surface — populated from audit_evidence.validation
    // when ValidateEvidenceJob (BB53) wrote a low-confidence result.
    public bool   $hasValidationWarning = false;
    /** @var list<string> */
    public array  $validationWarnings   = [];
    public float  $validationConfidence = 1.0;

    // BB21: per-step progress for the live loading view. Each entry:
    // ['key', 'track', 'status', 'order', 'elapsed_s', 'detail']
    public array   $auditSteps = [];

    // Modal
    public bool    $showModal   = false;
    public ?string $modalPillar = null;

    // BB82: surfaced when a signed-in user tries to start an audit with
    // zero credits. The wizard renders an "Sisa kredit habis" modal that
    // explains the manual top-up path until Phase 12b ships Xendit.
    public bool    $showInsufficientCreditsModal = false;

    // ─────────────────────────────────────────────────────────────────
    // Phase 12c BB91 — v2 wizard state (4-step Pomelli-style flow).
    // ─────────────────────────────────────────────────────────────────
    //
    // Step 1 — Cari bisnismu (Places Autocomplete + maps.app.goo.gl
    //          shortlink fallback). placeId is the authoritative anchor;
    //          all other place_* fields hydrate from Places Details.
    // Step 2 — Jenis layanan (card-based service_type select, incl.
    //          self_service).
    // Step 3 — Akun sosialmu (optional IG + TT username, server-side
    //          URL stripping via updated hooks).
    // Step 4 — Catatan tambahan + submit (charges credit, dispatches
    //          AnalyzeBrand, transitions to 'analyzing').
    //
    // wizardStep is the integer sub-state inside $step === 'wizard'.
    // ─────────────────────────────────────────────────────────────────

    public int $wizardStep = 1;
    public int $totalWizardSteps = 4;

    // Step 1 — Place anchor.
    public ?string $placeId         = null;
    public ?string $placeName       = null;
    public ?string $placeAddress    = null;
    public ?float  $placeLat        = null;
    public ?float  $placeLng        = null;
    public ?string $placePhone      = null;
    public ?string $placeWebsite    = null;
    /** @var list<string> */
    public array   $placeCategories = [];
    /** @var array<string,mixed>|null */
    public ?array  $placeRaw        = null;

    // Step 1 fallback path — paste a maps.app.goo.gl or google.com/maps URL.
    public string $manualGmapsUrl     = '';
    public bool   $showManualFallback = false;
    public ?string $manualResolveError = null;

    // Step 3 — username-only inputs (URLs stripped server-side).
    public ?string $instagramUsername = null;
    public ?string $tiktokUsername    = null;

    // Step 4 — optional free-form context for the LLM analysis layer.
    public ?string $notes = null;

    /**
     * BB93 — Step 2 service type catalogue. The wizard renders one
     * card per entry; clicking sets $serviceType to the slug. Emoji
     * icons are intentional per the Phase 12c spec (SVG migration is
     * Phase 12d backlog). Slugs match the BrandAudit.service_type
     * string column — the rule below in $rules constrains writes.
     *
     * @var list<array{slug:string,label:string,icon:string,subtitle:string}>
     */
    public array $availableServiceTypes = [
        ['slug' => 'kiloan',       'label' => 'Kiloan',       'icon' => '🧺', 'subtitle' => 'Per kg'],
        ['slug' => 'self_service', 'label' => 'Self Service', 'icon' => '🪙', 'subtitle' => 'Cuci sendiri'],
        ['slug' => 'satuan',       'label' => 'Satuan',       'icon' => '👔', 'subtitle' => 'Per pakaian'],
        ['slug' => 'express',      'label' => 'Express',      'icon' => '⚡', 'subtitle' => '3-6 jam selesai'],
        ['slug' => 'premium',      'label' => 'Premium',      'icon' => '💎', 'subtitle' => 'Kain halus'],
        ['slug' => 'campuran',     'label' => 'Campuran',     'icon' => '🎯', 'subtitle' => 'Beberapa jenis'],
    ];

    protected array $rules = [
        'brandName'              => 'required|string|max:100',
        'city'                   => 'nullable|string|max:100',
        // BB93 — v2 wizard adds 'self_service' + 'campuran'. 'mixed' is
        // kept on the validator so any in-flight legacy v1 submission
        // (where serviceType defaulted to a 'mixed' string) still
        // passes. New v2 audits use 'campuran' as the canonical slug.
        'serviceType'            => 'required|string|in:kiloan,self_service,satuan,express,premium,campuran,mixed',
        'instagramUrl'           => 'nullable|url|max:500',
        'websiteUrl'             => 'nullable|url|max:500',
        'tiktokUrl'              => 'nullable|url|max:500',
        'gmapsUrl'               => 'nullable|url|max:500',
        'whatsappBusiness'       => 'boolean',
        'outletPhotosOuter'      => 'nullable|array|max:3',
        'outletPhotosOuter.*'    => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        'outletPhotosInner'      => 'nullable|array|max:3',
        'outletPhotosInner.*'    => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        // BB73: operator declarations — all optional, free-form.
        'declEkspres'            => 'nullable|boolean',
        'declEkspresUrl'         => 'nullable|url|max:500',
        'declAntarJemput'        => 'nullable|boolean',
        'declAntarJemputUrl'     => 'nullable|url|max:500',
        'declServiceVariants'    => 'nullable|array',
        'declServiceVariants.*'  => 'string|in:kiloan,satuan,dry_cleaning,sepatu,karpet,bedding,gaun_dress,boneka,lainnya',
        'declSopKeluhan'         => 'nullable|boolean',
        'declSopKeluhanUrl'      => 'nullable|url|max:500',
        'declPriceList'          => 'nullable|boolean',
        'declPriceListUrl'       => 'nullable|url|max:500',
    ];

    protected array $messages = [
        'brandName.required'     => 'Nama brand wajib diisi.',
        'instagramUrl.url'       => 'Format URL Instagram tidak valid.',
        'websiteUrl.url'         => 'Format URL website tidak valid.',
        'tiktokUrl.url'          => 'Format URL TikTok tidak valid.',
        'gmapsUrl.url'           => 'Format URL Google Maps tidak valid.',
        'outletPhotosOuter.max'  => 'Maksimal 3 foto outlet luar.',
        'outletPhotosOuter.*.image' => 'File harus berupa gambar.',
        'outletPhotosOuter.*.mimes' => 'Format harus JPG, PNG, atau WEBP.',
        'outletPhotosOuter.*.max'   => 'Ukuran maksimal 5 MB.',
        'outletPhotosInner.max'  => 'Maksimal 3 foto outlet dalam.',
        'outletPhotosInner.*.image' => 'File harus berupa gambar.',
        'outletPhotosInner.*.mimes' => 'Format harus JPG, PNG, atau WEBP.',
        'outletPhotosInner.*.max'   => 'Ukuran maksimal 5 MB.',
    ];

    public function mount(string $token = ''): void
    {
        if (! $token) {
            return;
        }

        $audit = BrandAudit::where('session_token', $token)->first();
        if (! $audit) {
            abort(404);
        }

        $this->loadAudit($audit);
    }

    private function loadAudit(BrandAudit $audit): void
    {
        $this->auditId           = $audit->id;
        $this->sessionToken      = $audit->session_token;
        $this->auditStatus       = $audit->status;
        $this->brandName         = $audit->brand_name ?? '';
        $this->overallScore      = $audit->overall_score;
        $this->overallLabel      = $audit->overall_label;
        $this->pillarScores      = $audit->pillar_scores ?? [];
        $this->subBucketScores   = $audit->sub_bucket_scores ?? [];
        $this->keyFindings       = $audit->key_findings ?? [];
        $this->recommendations   = $audit->recommendations ?? [];
        $this->errorMessage      = $audit->error_message;
        $this->activationKitPath = $audit->activation_kit_path;
        $this->scoreBreakdown    = $audit->score_breakdown ?? [];

        $this->instagramAudit       = (array) ($audit->instagram_audit ?? []);
        $this->instagramAuditStatus = $audit->instagram_audit_status;

        // Phase 8 BB29: GMaps reviews data for the new "Ulasan
        // Pelanggan" dashboard section.
        $this->gmapsReviews         = (array) ($audit->gmaps_reviews ?? []);
        $this->gmapsReviewsStatus   = $audit->gmaps_reviews_status;

        // BB60: validation warning surface — populated when
        // ValidateEvidenceJob (BB53) wrote a confidence < 0.5 result
        // OR the audit's top-level status is STATUS_VALIDATION_WARNING.
        $validation = (array) ($audit->audit_evidence['validation'] ?? []);
        $this->hasValidationWarning = $audit->hasValidationWarning()
            || ((float) ($validation['confidence'] ?? 1.0)) < 0.5;
        $this->validationWarnings = (array) ($validation['warnings'] ?? []);
        $this->validationConfidence = (float) ($validation['confidence'] ?? 1.0);

        // BB21: load audit_steps for live loading view.
        $this->auditSteps = AuditStep::where('brand_audit_id', $audit->id)
            ->orderBy('order')
            ->get()
            ->map(fn ($s) => [
                'key'       => $s->step_key,
                'track'     => $s->track,
                'status'    => $s->status,
                'order'     => $s->order,
                'elapsed_s' => $s->elapsedSeconds(),
                'detail'    => $s->detail,
            ])
            ->all();

        // Stop showing the spinner once the file has actually landed.
        if ($this->activationKitPath) {
            $this->kitGenerating = false;
            $this->pdfError = null;
        }

        // BB79: surface PDF generation failure to the operator. When the
        // generate_pdf audit_step row landed status=failed (real error
        // now propagates per BB79 GenerateActivationKit fix), pull the
        // error message + reset the spinner so the "Buat Activation Kit"
        // retry button re-appears.
        foreach ($this->auditSteps as $s) {
            if ($s['key'] !== 'generate_pdf') {
                continue;
            }
            if ($s['status'] === 'failed') {
                $this->kitGenerating = false;
                $this->pdfError = (string) ($s['detail']['error'] ?? 'PDF gagal di-generate. Klik tombol untuk mencoba lagi.');
            } elseif ($s['status'] === 'done' && ! $this->activationKitPath) {
                // BB79 pre-fix audits or partial-failure edge case: step
                // marked done but no file landed. Show a soft retry prompt.
                $this->kitGenerating = false;
                $this->pdfError = $this->pdfError
                    ?? 'PDF belum tersedia. Klik tombol untuk membuat ulang.';
            }
            break;
        }

        $this->step = match ($audit->status) {
            'done', 'failed' => 'dashboard',
            default          => 'analyzing',
        };
    }

    /**
     * BB73: shape operator_declarations JSON column from form state.
     * Returns null when the operator left every field empty (no bool
     * checked, no URL filled, no variant selected) — keeps the column
     * NULL so BB75 Tier classifier can short-circuit on "undeclared".
     *
     * @return array<string,mixed>|null
     */
    private function collectOperatorDeclarations(): ?array
    {
        $hasAnyDeclaration = $this->declEkspres !== null
            || $this->declAntarJemput !== null
            || $this->declSopKeluhan !== null
            || $this->declPriceList !== null
            || $this->declServiceVariants !== []
            || trim($this->declEkspresUrl) !== ''
            || trim($this->declAntarJemputUrl) !== ''
            || trim($this->declSopKeluhanUrl) !== ''
            || trim($this->declPriceListUrl) !== '';

        if (! $hasAnyDeclaration) {
            return null;
        }

        return [
            'has_ekspres'        => $this->declEkspres,
            'ekspres_url'        => trim($this->declEkspresUrl) ?: null,
            'has_antar_jemput'   => $this->declAntarJemput,
            'antar_jemput_url'   => trim($this->declAntarJemputUrl) ?: null,
            'service_variants'   => array_values($this->declServiceVariants),
            'has_sop_keluhan'    => $this->declSopKeluhan,
            'sop_keluhan_url'    => trim($this->declSopKeluhanUrl) ?: null,
            'has_price_list'     => $this->declPriceList,
            'price_list_url'     => trim($this->declPriceListUrl) ?: null,
        ];
    }

    public function removePhoto(string $bucket, int $index): void
    {
        $prop = $bucket === 'outer' ? 'outletPhotosOuter' : 'outletPhotosInner';
        $arr = $this->{$prop};
        if (isset($arr[$index])) {
            array_splice($arr, $index, 1);
            $this->{$prop} = $arr;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Phase 12c BB91 — wizard step navigation + Step-1 place selection.
    // BB92-95 fill in the validation + Places API resolution bodies.
    // ─────────────────────────────────────────────────────────────────

    public function nextStep(): void
    {
        if (! $this->validateCurrentWizardStep()) {
            return;
        }
        $this->wizardStep = min($this->totalWizardSteps, $this->wizardStep + 1);
    }

    public function previousStep(): void
    {
        $this->wizardStep = max(1, $this->wizardStep - 1);
    }

    /**
     * Called from the Places Autocomplete onResponse handler in the
     * Step 1 partial. Hydrates the place_* state from the Place Details
     * payload Google returns to the client.
     *
     * @param array<string,mixed> $placeData
     */
    public function selectPlace(array $placeData): void
    {
        $this->placeId        = isset($placeData['place_id']) ? (string) $placeData['place_id'] : null;
        $this->placeName      = isset($placeData['name']) ? (string) $placeData['name'] : null;
        $this->placeAddress   = isset($placeData['formatted_address']) ? (string) $placeData['formatted_address'] : null;
        $this->placePhone     = isset($placeData['international_phone_number']) ? (string) $placeData['international_phone_number'] : null;
        $this->placeWebsite   = isset($placeData['website']) ? (string) $placeData['website'] : null;
        $this->placeCategories = array_values(array_map('strval', (array) ($placeData['types'] ?? [])));

        $loc = $placeData['geometry']['location'] ?? null;
        $this->placeLat = isset($loc['lat']) ? (float) $loc['lat'] : null;
        $this->placeLng = isset($loc['lng']) ? (float) $loc['lng'] : null;

        $this->placeRaw = is_array($placeData) ? $placeData : null;

        // Successful selection cancels any pending manual-fallback state.
        $this->showManualFallback  = false;
        $this->manualResolveError  = null;
    }

    public function clearSelectedPlace(): void
    {
        $this->placeId         = null;
        $this->placeName       = null;
        $this->placeAddress    = null;
        $this->placeLat        = null;
        $this->placeLng        = null;
        $this->placePhone      = null;
        $this->placeWebsite    = null;
        $this->placeCategories = [];
        $this->placeRaw        = null;
    }

    /**
     * BB92 — paste a maps.app.goo.gl shortlink or full google.com/maps
     * URL and resolve it via the server-side PlacesApiService (Text
     * Search → Place Details). Hydrates place_* state on success;
     * surfaces a user-friendly Indonesian error on failure.
     */
    public function submitManualGmapsUrl(PlacesApiService $places): void
    {
        $this->manualResolveError = null;
        $url = trim((string) $this->manualGmapsUrl);

        if ($url === '') {
            $this->manualResolveError = 'Tempel dulu link Google Maps di kolom di atas.';
            return;
        }

        // Only allow google.com/maps or maps.app.goo.gl URLs through.
        if (! preg_match('#^https?://(www\.)?(google\.[a-z.]+/maps|maps\.app\.goo\.gl)/#i', $url)) {
            $this->manualResolveError = 'Link harus dari Google Maps (google.com/maps atau maps.app.goo.gl).';
            return;
        }

        $payload = $places->resolveManualUrl($url);
        if (! $payload || empty($payload['place_id'])) {
            $this->manualResolveError = 'Link tidak valid atau bisnis tidak ditemukan. Coba pencarian di atas.';
            return;
        }

        $this->selectPlace($payload);
        // Successful manual resolution closes the fallback panel.
        $this->showManualFallback = false;
    }

    /**
     * BB94 — Step 3 input normalizer. If the user pasted a full
     * profile URL, strip it to the username segment. Idempotent so
     * a re-render doesn't double-strip a previously-normalized
     * value. Returns null for empty/whitespace input.
     */
    public function updatedInstagramUsername(): void
    {
        $this->instagramUsername = $this->normalizeUsername($this->instagramUsername, 'instagram');
    }

    public function updatedTiktokUsername(): void
    {
        $this->tiktokUsername = $this->normalizeUsername($this->tiktokUsername, 'tiktok');
    }

    private function normalizeUsername(?string $input, string $platform): ?string
    {
        if ($input === null) {
            return null;
        }
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        // Strip URLs first — if a URL was pasted, extract the
        // username segment, otherwise leave the raw string alone for
        // the @-prefix + character filter below to handle.
        if (preg_match('#^https?://#i', $trimmed)) {
            $extracted = match ($platform) {
                'instagram' => preg_replace(
                    '#^https?://(?:www\.)?instagram\.com/@?([A-Za-z0-9_.]+).*$#i',
                    '$1',
                    $trimmed,
                ),
                'tiktok'    => preg_replace(
                    '#^https?://(?:www\.|vm\.)?tiktok\.com/@?([A-Za-z0-9_.]+).*$#i',
                    '$1',
                    $trimmed,
                ),
                default     => $trimmed,
            };
            // If preg_replace returned the original (no match), the URL
            // was malformed — clear so the validation surfaces an error.
            if ($extracted === $trimmed) {
                return null;
            }
            $trimmed = (string) $extracted;
        }

        // Strip leading @ if present after URL handling.
        $trimmed = ltrim($trimmed, '@');

        // Drop anything past a slash/space/query — defensive against
        // partial pastes like 'instagram.com/foo'. Tilde delimiter is
        // used so the '#' fragment marker can appear unescaped inside
        // the character class.
        $trimmed = preg_replace('~[\s/?#].*$~', '', $trimmed) ?? $trimmed;

        // Final character whitelist — both IG and TT allow [A-Za-z0-9._]
        // and limit to 30 chars (IG's stricter ceiling).
        $trimmed = preg_replace('#[^A-Za-z0-9_.]#', '', $trimmed) ?? '';
        $trimmed = mb_substr($trimmed, 0, 30);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * BB91 + fix from BB97 — per-step validation hook called by
     * nextStep(). Returns false (and registers an error on the
     * relevant key) when the current step is incomplete so the caller
     * can short-circuit without throwing. Throwing a ValidationException
     * was the v1 idea but it conflicted with Livewire's hydration:
     * the error bag was rolled back along with the exception, so the
     * UI showed nothing.
     */
    private function validateCurrentWizardStep(): bool
    {
        if ($this->wizardStep === 1 && ! $this->placeId) {
            $this->addError('placeId', 'Pilih bisnismu dulu dari daftar Google Maps.');
            return false;
        }
        if ($this->wizardStep === 2) {
            $validSlugs = array_column($this->availableServiceTypes, 'slug');
            if (! in_array($this->serviceType, $validSlugs, true)) {
                $this->addError('serviceType', 'Pilih salah satu jenis layanan.');
                return false;
            }
        }
        // BB94 — Step 3 social handles are optional, so no gate.
        // BB95 — Step 4 notes are optional, so no gate; submit() does
        // the final cross-step validation.
        return true;
    }

    /**
     * BB95 — v2 submit pipeline. Replaces the BB82 v1 form-upload
     * flow entirely. The v1 'touchpoint_inputs' UI is now unreachable
     * (step default is 'wizard'); this method consumes only the v2
     * state placeId/placeName/.../serviceType/instagramUsername/
     * tiktokUsername/notes.
     *
     * Differences from the v1 submit() (which this replaces):
     *   - No file uploads (photos come from FetchPlacesApiJob via the
     *     resolved place_id).
     *   - No "at least 2 of 5 touchpoint signals" rule — place_id is
     *     mandatory and provides the canonical anchor by itself.
     *   - touchpoints is now DERIVED from place_* + Step 3 inputs
     *     (per operator-confirmed option (i): downstream readers
     *     unchanged, single source of truth lives in place_*).
     *   - operator_declarations is null (v1 wizard exposed declarations
     *     via separate form fields; v2 wizard surfaces them later as
     *     a follow-up question in the results dashboard — out of scope
     *     for Phase 12c).
     *   - wizard_version stamped 'v2' so audit history view (BB97)
     *     can branch render path correctly.
     */
    public function submit(CreditLedger $ledger): void
    {
        if (! Auth::check()) {
            $this->redirect(route('auth.google.redirect'));
            return;
        }

        if (! $this->placeId) {
            // Step 1 wasn't completed (or state was wiped). Bounce
            // the user back to Step 1 with a helpful message.
            $this->wizardStep = 1;
            $this->addError('placeId', 'Pilih bisnis dulu di langkah 1.');
            return;
        }

        $validSlugs = array_column($this->availableServiceTypes, 'slug');
        if (! in_array($this->serviceType, $validSlugs, true)) {
            $this->wizardStep = 2;
            $this->addError('serviceType', 'Pilih jenis layanan.');
            return;
        }

        // BB82 balance gate — preserved verbatim.
        if ((int) Auth::user()->credits_balance < 1) {
            $this->showInsufficientCreditsModal = true;
            return;
        }

        $token        = Str::random(64);
        $derivedCity  = $this->deriveCityFromPlaceRaw($this->placeRaw);
        $touchpoints  = $this->deriveTouchpoints();

        $audit = DB::transaction(function () use ($token, $touchpoints, $derivedCity, $ledger) {
            $audit = BrandAudit::create([
                'session_token'    => $token,
                'user_id'          => Auth::id(),
                'ip_address'       => request()->ip(),

                // Legacy compat — brand_name surfaces in PDFs, /audits
                // history, and admin exports. place_name is the canonical
                // source for v2; brand_name mirrors it so existing readers
                // keep working without a sweep.
                'brand_name'       => $this->placeName ?? '',
                'city'             => $derivedCity,
                'service_type'     => $this->serviceType,
                'touchpoints'      => $touchpoints,
                'operator_declarations' => null,

                // v2 anchor fields (BB90).
                'place_id'          => $this->placeId,
                'place_name'        => $this->placeName,
                'place_address'     => $this->placeAddress,
                'place_lat'         => $this->placeLat,
                'place_lng'         => $this->placeLng,
                'place_phone'       => $this->placePhone,
                'place_website'     => $this->placeWebsite,
                'place_categories'  => $this->placeCategories,
                'place_raw'         => $this->placeRaw,

                'notes'           => $this->notes !== null && trim($this->notes) !== '' ? trim($this->notes) : null,
                'wizard_version'  => BrandAudit::WIZARD_V2,

                'status'          => BrandAudit::STATUS_PENDING,
                'expires_at'      => now()->addDays(30),
            ]);

            if (! $ledger->charge(Auth::user(), $audit)) {
                // Defensive: concurrent admin "remove credits" between
                // pre-check and charge — abort transaction so neither
                // the row nor the debit commits.
                throw new \RuntimeException('Credit charge failed despite pre-check.');
            }

            return $audit;
        });

        AnalyzeBrand::dispatch($audit->id);

        $this->redirect(route('audit.show', ['token' => $token]), navigate: true);
    }

    /**
     * BB95 — assemble the legacy touchpoints[] shape from v2 state so
     * downstream readers (FetchInstagramAuditJob, FetchWebsiteJob,
     * scoring services) don't need to know about place_*. Per
     * operator decision (i): place_* is the single source of truth;
     * touchpoints is a derived view rebuilt on every submit.
     *
     * @return array<string,mixed>
     */
    private function deriveTouchpoints(): array
    {
        $instagramUrl = $this->instagramUsername
            ? 'https://www.instagram.com/' . $this->instagramUsername
            : null;
        $tiktokUrl = $this->tiktokUsername
            ? 'https://www.tiktok.com/@' . $this->tiktokUsername
            : null;
        $gmapsUrl = $this->placeId
            ? 'https://www.google.com/maps/place/?q=place_id:' . $this->placeId
            : null;

        return [
            'gmaps_url'                => $gmapsUrl,
            'instagram_url'            => $instagramUrl,
            'tiktok_url'               => $tiktokUrl,
            'website_url'              => $this->placeWebsite ?: null,
            'whatsapp_business_active' => false,
            'outlet_photo_paths'       => [],
            'outlet_photo_outer_paths' => [],
            'outlet_photo_inner_paths' => [],
        ];
    }

    /**
     * Derive a display-ready city name from the Places address_components
     * array. Walks the components looking for administrative_area_level_2
     * (Indonesian "Kabupaten/Kota") first, then falls back to 'locality',
     * then 'administrative_area_level_1' (province). Returns null when
     * no matching component is found.
     *
     * @param array<string,mixed>|null $placeRaw
     */
    private function deriveCityFromPlaceRaw(?array $placeRaw): ?string
    {
        if (! is_array($placeRaw)) {
            return null;
        }
        $components = $placeRaw['address_components'] ?? [];
        if (! is_array($components)) {
            return null;
        }

        $byTypePriority = ['administrative_area_level_2', 'locality', 'administrative_area_level_1'];
        foreach ($byTypePriority as $wantedType) {
            foreach ($components as $c) {
                if (! is_array($c)) {
                    continue;
                }
                $types = $c['types'] ?? [];
                if (! is_array($types) || ! in_array($wantedType, $types, true)) {
                    continue;
                }
                return (string) ($c['long_name'] ?? $c['longText'] ?? '') ?: null;
            }
        }
        return null;
    }

    public function dismissInsufficientCreditsModal(): void
    {
        $this->showInsufficientCreditsModal = false;
    }

    public function pollStatus(): void
    {
        if (in_array($this->auditStatus, ['done', 'failed'], true) || ! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if ($audit) {
            $this->loadAudit($audit);
        }
    }

    public function openModal(string $pillar): void
    {
        $this->modalPillar = $pillar;
        $this->dispatch('open-modal-recommendations');
    }

    /**
     * BB59: re-run a single gather step + re-flow scoring + PDF.
     * Routes through AuditController::retryStep so the same dispatch
     * chain runs whether triggered via this Livewire action or a
     * straight POST to /audit/{token}/retry-step.
     */
    public function retryStep(string $stepKey): void
    {
        $allowed = ['gather_gmaps', 'gather_instagram'];
        if (! in_array($stepKey, $allowed, true) || ! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if (! $audit) {
            return;
        }

        $step = \App\Models\AuditStep::where('brand_audit_id', $audit->id)
            ->where('step_key', $stepKey)
            ->first();
        if ($step === null) {
            return;
        }

        $step->update([
            'status'       => \App\Models\AuditStep::STATUS_PENDING,
            'started_at'   => null,
            'completed_at' => null,
            'detail'       => null,
        ]);

        \App\Models\AuditStep::where('brand_audit_id', $audit->id)
            ->whereIn('step_key', [
                'score_recall', 'score_digital', 'score_konsistensi', 'score_experience',
                'generate_recommendations', 'generate_quick_wins', 'generate_positioning', 'generate_pdf',
            ])
            ->update([
                'status'       => \App\Models\AuditStep::STATUS_PENDING,
                'started_at'   => null,
                'completed_at' => null,
                'detail'       => null,
            ]);

        $audit->update(['status' => BrandAudit::STATUS_ANALYZING]);

        $fetchJob = $stepKey === 'gather_gmaps'
            ? new \App\Jobs\FetchGMapsReviewsJob($audit->id)
            : new \App\Jobs\FetchInstagramAuditJob($audit->id);

        \Illuminate\Support\Facades\Bus::batch([$fetchJob])
            ->name("audit:{$audit->id}:retry-{$stepKey}")
            ->allowFailures()
            ->then(static function (\Illuminate\Bus\Batch $batch) use ($audit): void {
                \Illuminate\Support\Facades\Bus::batch([new \App\Jobs\ScorePillarsJob($audit->id)])
                    ->name("audit:{$audit->id}:retry-score")
                    ->then(static function (\Illuminate\Bus\Batch $b2) use ($audit): void {
                        \App\Jobs\GenerateInsightsJob::dispatch($audit->id);
                    })
                    ->dispatch();
            })
            ->dispatch();

        $audit->refresh();
        $this->loadAudit($audit);
    }

    public function closeModal(): void
    {
        $this->modalPillar = null;
        $this->dispatch('close-modal-recommendations');
    }

    public function generateKit(): void
    {
        if (! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if (! $audit || ! $audit->isComplete()) {
            return;
        }

        $this->kitGenerating = true;
        $this->pdfError      = null;  // clear prior error on retry
        \App\Jobs\GenerateActivationKit::dispatch($audit);
    }

    /**
     * BB60: take the user back to the wizard's touchpoint_inputs step
     * with the existing form fields preserved (they live on public
     * properties already). The dashboard's audit-id state is cleared
     * so submitting will create a fresh BrandAudit row — the original
     * audit is left untouched (operators can navigate back to it via
     * the session_token URL if they change their mind).
     */
    public function editAndRerun(): void
    {
        $this->step = 'touchpoint_inputs';
        $this->auditId     = null;
        $this->auditStatus = '';
        $this->sessionToken = null;
        // Score + finding state cleared so the partial dashboard
        // doesn't flash when the user returns from a different audit.
        $this->overallScore         = null;
        $this->overallLabel         = null;
        $this->pillarScores         = [];
        $this->subBucketScores      = [];
        $this->scoreBreakdown       = [];
        $this->keyFindings          = [];
        $this->recommendations      = [];
        $this->activationKitPath    = null;
        $this->auditSteps           = [];
        $this->hasValidationWarning = false;
        $this->validationWarnings   = [];
        $this->validationConfidence = 1.0;
    }

    public function checkKit(): void
    {
        if (! $this->kitGenerating || ! $this->auditId) {
            return;
        }

        $audit = BrandAudit::find($this->auditId);
        if ($audit && $audit->activation_kit_path) {
            $this->activationKitPath = $audit->activation_kit_path;
            $this->kitGenerating     = false;
        }
    }

    public function with(): array
    {
        // 2x2 grid order: Konsistensi TL, Recall TR, Experience BL, Digital BR
        $pillarMeta = [
            'brand-konsistensi' => ['label' => 'Konsistensi Brand', 'icon' => 'ti-layers-intersect'],
            'brand-recall'      => ['label' => 'Brand Recall',      'icon' => 'ti-message-star'],
            'brand-experience'  => ['label' => 'Brand Experience',  'icon' => 'ti-users'],
            'digital-presence'  => ['label' => 'Digital Presence',  'icon' => 'ti-world'],
        ];

        $subBucketLabels = [
            // brand-recall
            'rating_tier'         => 'Rating',
            'review_count_tier'   => 'Jumlah Review',
            'keyword_saturation'  => 'Kata Kunci Positif di Ulasan', // BB18 alias for old rows
            'review_keyword_quality' => 'Kata Kunci Positif di Ulasan',
            'sentiment_quality'   => 'Sentimen',
            'search_recall'       => 'Search Recall',
            // digital-presence
            'has_gmaps'           => 'Google Maps',
            'has_instagram'       => 'Instagram',
            'has_website'         => 'Website',
            'has_wa'              => 'WhatsApp Business',
            'has_tiktok'          => 'TikTok',
            'review_bonus'        => 'Bonus Review',
            // brand-konsistensi
            'kehadiran_digital'   => 'Kehadiran Digital',
            'konsistensi_visual'  => 'Konsistensi Visual',
            'kelengkapan_layanan' => 'Kelengkapan Layanan',
            'transparansi_harga'  => 'Transparansi Harga',
            // brand-experience
            'base'                  => 'Dasar',
            'bonus_ekspres'         => 'Layanan Ekspres',
            'bonus_antar_jemput'    => 'Antar Jemput',
            'bonus_variasi_layanan' => 'Variasi Layanan',
            'bonus_sop_keluhan'     => 'SOP Keluhan',
            'bonus_price_list'      => 'Daftar Harga',
            'penalty_keterlambatan' => 'Penalti Keterlambatan',
            'penalty_pakaian_hilang' => 'Penalti Pakaian Hilang',
            'penalty_no_response_wa' => 'Penalti No-Response WA',
        ];

        // Normalize pillar scores: {pillar => {score, evidence, ...}} → {pillar => int}
        $pillarScoreInts = [];
        foreach ($this->pillarScores as $slug => $data) {
            $pillarScoreInts[$slug] = is_array($data) ? ($data['score'] ?? null) : $data;
        }

        // Build bucket → pillar reverse lookup so we can group recommendations by pillar
        $bucketToPillar = [];
        foreach ($this->subBucketScores as $pillarSlug => $buckets) {
            foreach (array_keys((array) $buckets) as $bucket) {
                $bucketToPillar[$bucket] = $pillarSlug;
            }
        }

        $modalRecs = $this->modalPillar
            ? array_values(array_filter(
                $this->recommendations,
                fn ($r) => ($bucketToPillar[$r['bucket'] ?? ''] ?? '') === $this->modalPillar,
            ))
            : [];

        // Count recs per pillar so cards know whether to show the link
        $recsByPillar = [];
        foreach ($this->recommendations as $r) {
            $p = $bucketToPillar[$r['bucket'] ?? ''] ?? null;
            if ($p) {
                $recsByPillar[$p] = ($recsByPillar[$p] ?? 0) + 1;
            }
        }

        return compact(
            'pillarMeta',
            'subBucketLabels',
            'modalRecs',
            'pillarScoreInts',
            'recsByPillar',
        ) + [
            'serviceTypes' => [
                'kiloan'  => 'Kiloan (per kg)',
                'satuan'  => 'Satuan (per pakaian)',
                'express' => 'Express',
                'premium' => 'Premium',
                'mixed'   => 'Campuran',
            ],
        ];
    }
};
?>

<div class="relative">
    <style>
        @keyframes baw-spin { to { transform: rotate(360deg); } }
        @keyframes baw-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.25; } }
        .baw-spinner { width: 48px; height: 48px; border: 3px solid var(--chimera-100); border-top-color: var(--chimera-500); border-radius: 50%; animation: baw-spin .8s linear infinite; }
        .baw-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--chimera-300); flex-shrink: 0; animation: baw-pulse 1.4s ease-in-out infinite; }
        .baw-err { font-size: 12px; color: var(--color-danger); margin-top: 4px; }
        .baw-photo-btn { display: inline-flex; align-items: center; gap: 6px; border: 1px dashed var(--border-strong); border-radius: var(--radius-md); padding: 10px 14px; background: var(--surface-muted); color: var(--text-secondary); font-size: 13px; cursor: pointer; transition: border-color .15s; }
        .baw-photo-btn:hover { border-color: var(--chimera-500); color: var(--chimera-600); }
        .baw-photo-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px; background: var(--surface-muted); border: 1px solid var(--border-default); border-radius: var(--radius-sm); font-size: 12px; }
        .baw-photo-remove { background: none; border: none; color: var(--color-danger); cursor: pointer; font-size: 14px; padding: 0 4px; }
    </style>

    {{-- ===== STEP 1: FORM ===== --}}
    {{-- ───────────────────────────────────────────────────────────
         Phase 12c BB91 — v2 wizard shell. The legacy v1 form block
         below is unreachable when $step === 'wizard' (initial default
         for new audits). It survives so any in-flight v1 session
         keeps working, and is deleted in BB98 cleanup.
         ─────────────────────────────────────────────────────────── --}}
    @if ($step === 'wizard')
        <div class="bb-wizard" x-data="{}">
            {{-- Progress dots --}}
            <div class="bb-progress">
                @for ($i = 1; $i <= $totalWizardSteps; $i++)
                    <span class="bb-progress-dot
                        @if ($i === $wizardStep) is-active
                        @elseif ($i < $wizardStep) is-done
                        @endif"></span>
                @endfor
            </div>

            <div class="bb-wizard-card">
                {{-- Back button (Steps 2–4 only, hidden for guests) --}}
                @auth
                    @if ($wizardStep > 1)
                        <button type="button" wire:click="previousStep" class="bb-back">
                            <i class="ti ti-arrow-left"></i> Kembali
                        </button>
                    @endif
                @endauth

                {{-- Auth gate: sign-in CTA for guests, partial cluster for signed-in. --}}
                @guest
                    <div class="bb-auth-panel">
                        <h2>Masuk dulu untuk mulai audit</h2>
                        <p>Saya akan menyimpan hasil audit di akun Google kamu.</p>
                        <a href="{{ route('auth.google.redirect') }}" class="signin">
                            <i class="ti ti-brand-google"></i> Masuk dengan Google
                        </a>
                    </div>
                @else
                    @if ($wizardStep === 1)
                        @include('livewire.audit-wizard.step-1-find-business')
                    @elseif ($wizardStep === 2)
                        @include('livewire.audit-wizard.step-2-service-type')
                    @elseif ($wizardStep === 3)
                        @include('livewire.audit-wizard.step-3-social')
                    @elseif ($wizardStep === 4)
                        @include('livewire.audit-wizard.step-4-notes')
                    @endif
                @endguest
            </div>
        </div>

        {{-- BB95: insufficient-credits modal lifted from the v1 block
             so a signed-in user with 0 credits sees the same explainer
             when they click "Mulai Analisis" on Step 4. Identical
             markup; consolidating to a shared partial is BB98 cleanup. --}}
        @if ($showInsufficientCreditsModal)
            <div class="fixed inset-0 z-40 flex items-center justify-center p-6" style="background: rgba(15,20,17,0.55);" wire:click.self="dismissInsufficientCreditsModal">
                <div class="nui-card max-w-md w-full p-8 text-center" style="position: relative;">
                    <button type="button" wire:click="dismissInsufficientCreditsModal" style="position: absolute; top: 12px; right: 14px; background: none; border: none; color: var(--text-tertiary); font-size: 22px; cursor: pointer; line-height: 1;" aria-label="Tutup">
                        <i class="ti ti-x"></i>
                    </button>
                    <div style="width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; background: var(--surface-muted); display: flex; align-items: center; justify-content: center;">
                        <i class="ti ti-coin-off" style="font-size: 28px; color: var(--color-warning);"></i>
                    </div>
                    <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary);">Kredit Anda tidak cukup</h2>
                    <p style="font-size: 14px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                        Audit baru membutuhkan 1 kredit. Saldo Anda saat ini 0. Top up kredit akan tersedia segera — sementara ini hubungi tim Nema untuk menambah saldo manual.
                    </p>
                    <button type="button" wire:click="dismissInsufficientCreditsModal" class="nui-btn-secondary rounded-pill" style="margin-top: 20px; font-size: 14px; padding: 10px 20px;">
                        Mengerti
                    </button>
                </div>
            </div>
        @endif
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         BB98 DEPRECATION NOTICE — Legacy v1 single-page form block.

         This block is UNREACHABLE for new audits (default $step is now
         'wizard' per BB91). It survives only so any in-flight v1 audit
         session (pre-2026-05-17, before Phase 12c shipped) can still
         submit. Audits older than 30 days expire automatically via the
         expires_at column, so this block becomes safe to delete once
         the BB91 deploy is ~30 days old — target removal in Phase 12d.

         Removal procedure: delete from the line below through the
         matching @endif for ($step === 'touchpoint_inputs'), plus the
         v1 $rules/$messages keys (outletPhotos*, declEkspres*,
         instagramUrl, websiteUrl, gmapsUrl, whatsappBusiness,
         brandName), the $outletPhotos* + decl* + brand_name +
         instagram/website/tiktok/gmaps Url public properties on the
         Volt class, removePhoto(), and collectOperatorDeclarations().
         Leave 'mixed' in the serviceType IN: rule until v1 audits roll
         out of the 30-day window.
         ═══════════════════════════════════════════════════════════════ --}}
    @if ($step === 'touchpoint_inputs')
        {{-- BB82: sign-in gate. Guests see a single-CTA card; signed-in
             users see the full form. The wizard's submit() server-side
             re-checks auth + balance, so the gate cannot be bypassed by
             editing the client state. --}}
        @guest
            <section class="max-w-3xl mx-auto">
                <div class="mb-8 text-center">
                    <h1 style="font-size: 30px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em;">Brand Health Check</h1>
                    <p style="font-size: 15px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                        Masuk dengan akun Google untuk memulai audit pertama. Audit pertama gratis — tidak perlu kartu kredit.
                    </p>
                </div>
                <div class="nui-card p-10 flex flex-col items-center gap-6 text-center">
                    <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                        <i class="ti ti-shield-check" style="font-size: 32px; color: var(--chimera-600);"></i>
                    </div>
                    <div>
                        <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary);">Masuk untuk Mulai</h2>
                        <p style="font-size: 14px; color: var(--text-secondary); margin-top: 6px; max-width: 360px;">
                            Riwayat audit dan kredit Anda tersimpan di akun Google. Tidak ada password baru yang perlu diingat.
                        </p>
                    </div>
                    @if (session('auth_error'))
                        <div style="font-size: 13px; color: var(--color-danger); padding: 8px 14px; border: 1px solid var(--color-danger); border-radius: var(--radius-md);">
                            {{ session('auth_error') }}
                        </div>
                    @endif
                    <a
                        href="{{ route('auth.google.redirect') }}"
                        class="nui-btn-primary rounded-pill"
                        style="font-size: 15px; font-weight: 500; padding: 12px 24px; display: inline-flex; align-items: center; gap: 10px;"
                    >
                        <i class="ti ti-brand-google"></i>
                        Masuk dengan Google
                    </a>
                </div>
            </section>
        @endguest

        @auth
        <section class="max-w-3xl mx-auto">
            <div class="mb-8">
                <h1 style="font-size: 30px; font-weight: 600; color: var(--text-primary); letter-spacing: -0.01em;">Brand Health Check</h1>
                <p style="font-size: 15px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                    Isi informasi brand laundry Anda. Saya akan menganalisis 4 pilar kekuatan brand dalam 30–60 detik.
                </p>
            </div>

            <div class="nui-card p-8">
                <form wire:submit="submit" class="flex flex-col gap-6">

                    {{-- Brand info --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-nui-form-input
                            name="brandName"
                            label="Nama Brand"
                            wire:model="brandName"
                            placeholder="Contoh: Less Worry Laundry"
                            autocomplete="organization"
                            :required="true"
                            :error="$errors->first('brandName')"
                        />
                        <x-nui-form-input
                            name="city"
                            label="Kota / Area"
                            wire:model="city"
                            placeholder="Contoh: Jakarta Selatan"
                            autocomplete="address-level2"
                            :error="$errors->first('city')"
                        />
                    </div>

                    {{-- Service type --}}
                    <x-nui-form-select
                        name="serviceType"
                        label="Jenis Layanan Utama"
                        wire:model="serviceType"
                        :options="$serviceTypes"
                        :selected="$serviceType"
                        :required="true"
                    />

                    {{-- Touchpoints heading --}}
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary);">Touchpoint Digital</p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                            Minimal 2 dari 5 touchpoint harus diisi (Google Maps, Instagram, Website, TikTok, atau WhatsApp Business).
                        </p>
                    </div>

                    {{-- URL inputs --}}
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <x-nui-form-input
                            name="gmapsUrl"
                            label="Google Maps"
                            type="url"
                            wire:model="gmapsUrl"
                            placeholder="https://maps.app.goo.gl/..."
                            :error="$errors->first('gmapsUrl')"
                        />
                        <x-nui-form-input
                            name="instagramUrl"
                            label="Instagram"
                            type="url"
                            wire:model="instagramUrl"
                            placeholder="https://instagram.com/laundry_anda"
                            :error="$errors->first('instagramUrl')"
                        />
                        <x-nui-form-input
                            name="websiteUrl"
                            label="Website"
                            type="url"
                            wire:model="websiteUrl"
                            placeholder="https://laundryanda.com"
                            :error="$errors->first('websiteUrl')"
                        />
                        <x-nui-form-input
                            name="tiktokUrl"
                            label="TikTok"
                            type="url"
                            wire:model="tiktokUrl"
                            placeholder="https://tiktok.com/@laundry_anda"
                            :error="$errors->first('tiktokUrl')"
                        />
                    </div>

                    {{-- WhatsApp Business checkbox --}}
                    <label
                        for="whatsappBusiness"
                        class="flex items-start gap-3 p-4 rounded-lg cursor-pointer"
                        style="background: var(--surface-muted); border: 1px solid var(--border-default);"
                    >
                        <input
                            type="checkbox"
                            id="whatsappBusiness"
                            wire:model="whatsappBusiness"
                            style="width: 18px; height: 18px; margin-top: 2px; accent-color: var(--chimera-500);"
                        />
                        <div>
                            <p style="font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                <i class="ti ti-brand-whatsapp" style="color: var(--color-success); margin-right: 4px;"></i>
                                Saya menggunakan WhatsApp Business untuk pelanggan
                            </p>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">
                                Centang jika brand Anda aktif menggunakan WhatsApp Business (bukan WhatsApp pribadi).
                            </p>
                        </div>
                    </label>

                    {{-- BB73: operator declarations (optional self-report) --}}
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                            Layanan & Kebijakan Operasional
                            <span style="font-weight: 400; color: var(--text-tertiary);">(Opsional)</span>
                        </p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px; margin-bottom: 16px;">
                            Jika kosong, sistem akan mencoba mendeteksi otomatis dari touchpoint Anda. Mengisi langsung membantu skor Brand Experience lebih akurat.
                        </p>

                        <div class="flex flex-col gap-5">

                            {{-- Ekspres --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-bolt" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Layanan ekspres / same-day tersedia?
                                </label>
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declEkspres" :value="true" style="accent-color: var(--chimera-500);">
                                        Ya
                                    </label>
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declEkspres" :value="false" style="accent-color: var(--chimera-500);">
                                        Tidak
                                    </label>
                                    <button type="button"
                                        wire:click="$set('declEkspres', null)"
                                        style="font-size: 12px; color: var(--text-tertiary); text-decoration: underline;">
                                        Tidak yakin
                                    </button>
                                </div>
                                @if ($declEkspres === true)
                                    <x-nui-form-input
                                        name="declEkspresUrl"
                                        label=""
                                        type="url"
                                        wire:model="declEkspresUrl"
                                        placeholder="URL bukti (opsional): IG post, halaman website, dsb"
                                        :error="$errors->first('declEkspresUrl')"
                                    />
                                @endif
                            </div>

                            {{-- Antar Jemput --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-truck-delivery" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Antar jemput tersedia?
                                </label>
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declAntarJemput" :value="true" style="accent-color: var(--chimera-500);">
                                        Ya
                                    </label>
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declAntarJemput" :value="false" style="accent-color: var(--chimera-500);">
                                        Tidak
                                    </label>
                                    <button type="button"
                                        wire:click="$set('declAntarJemput', null)"
                                        style="font-size: 12px; color: var(--text-tertiary); text-decoration: underline;">
                                        Tidak yakin
                                    </button>
                                </div>
                                @if ($declAntarJemput === true)
                                    <x-nui-form-input
                                        name="declAntarJemputUrl"
                                        label=""
                                        type="url"
                                        wire:model="declAntarJemputUrl"
                                        placeholder="URL bukti (opsional)"
                                        :error="$errors->first('declAntarJemputUrl')"
                                    />
                                @endif
                            </div>

                            {{-- Variasi Layanan --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-list-details" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Variasi layanan
                                </label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach ([
                                        'kiloan'       => 'Kiloan',
                                        'satuan'       => 'Satuan',
                                        'dry_cleaning' => 'Dry Cleaning',
                                        'sepatu'       => 'Sepatu',
                                        'karpet'       => 'Karpet',
                                        'bedding'      => 'Bedding / Selimut',
                                        'gaun_dress'   => 'Gaun / Dress',
                                        'boneka'       => 'Boneka',
                                        'lainnya'      => 'Lainnya',
                                    ] as $key => $label)
                                        <label class="flex items-center gap-2 p-2 rounded" style="background: var(--surface-muted); font-size: 13px; cursor: pointer;">
                                            <input type="checkbox"
                                                wire:model="declServiceVariants"
                                                value="{{ $key }}"
                                                style="accent-color: var(--chimera-500);">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- SOP Keluhan --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-shield-check" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    SOP keluhan / garansi dipublikasikan?
                                </label>
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declSopKeluhan" :value="true" style="accent-color: var(--chimera-500);">
                                        Ya
                                    </label>
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declSopKeluhan" :value="false" style="accent-color: var(--chimera-500);">
                                        Tidak
                                    </label>
                                    <button type="button"
                                        wire:click="$set('declSopKeluhan', null)"
                                        style="font-size: 12px; color: var(--text-tertiary); text-decoration: underline;">
                                        Tidak yakin
                                    </button>
                                </div>
                                @if ($declSopKeluhan === true)
                                    <x-nui-form-input
                                        name="declSopKeluhanUrl"
                                        label=""
                                        type="url"
                                        wire:model="declSopKeluhanUrl"
                                        placeholder="URL bukti (opsional): kebijakan, FAQ, IG highlight"
                                        :error="$errors->first('declSopKeluhanUrl')"
                                    />
                                @endif
                            </div>

                            {{-- Price List --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-receipt" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Price list publik tersedia?
                                </label>
                                <div class="flex flex-wrap items-center gap-3">
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declPriceList" :value="true" style="accent-color: var(--chimera-500);">
                                        Ya
                                    </label>
                                    <label class="flex items-center gap-2" style="font-size: 13px;">
                                        <input type="radio" wire:model="declPriceList" :value="false" style="accent-color: var(--chimera-500);">
                                        Tidak
                                    </label>
                                    <button type="button"
                                        wire:click="$set('declPriceList', null)"
                                        style="font-size: 12px; color: var(--text-tertiary); text-decoration: underline;">
                                        Tidak yakin
                                    </button>
                                </div>
                                @if ($declPriceList === true)
                                    <x-nui-form-input
                                        name="declPriceListUrl"
                                        label=""
                                        type="url"
                                        wire:model="declPriceListUrl"
                                        placeholder="URL bukti (opsional)"
                                        :error="$errors->first('declPriceListUrl')"
                                    />
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- File uploads --}}
                    <div>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary);">Foto Outlet (Opsional)</p>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-top: 2px; margin-bottom: 16px;">
                            Maksimal 3 foto per kategori. Format JPG, PNG, atau WEBP. Ukuran maksimal 5 MB per foto.
                        </p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {{-- Outer photos --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-building-store" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Foto Outlet Luar
                                </label>
                                <label for="outerPhotos" class="baw-photo-btn">
                                    <i class="ti ti-upload"></i>
                                    Pilih foto ({{ count($outletPhotosOuter) }}/3)
                                </label>
                                <input
                                    id="outerPhotos"
                                    type="file"
                                    wire:model="outletPhotosOuter"
                                    multiple
                                    accept="image/jpeg,image/png,image/webp"
                                    style="display: none;"
                                />
                                <div wire:loading wire:target="outletPhotosOuter" style="font-size: 12px; color: var(--text-tertiary);">
                                    Mengunggah...
                                </div>
                                @if (count($outletPhotosOuter) > 0)
                                    <div class="flex flex-col gap-1.5 mt-1">
                                        @foreach ($outletPhotosOuter as $idx => $photo)
                                            <div class="baw-photo-row">
                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <i class="ti ti-photo" style="color: var(--chimera-500);"></i>
                                                    {{ method_exists($photo, 'getClientOriginalName') ? $photo->getClientOriginalName() : 'foto-' . ($idx + 1) }}
                                                </span>
                                                <button type="button" wire:click="removePhoto('outer', {{ $idx }})" class="baw-photo-remove" title="Hapus">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('outletPhotosOuter') <p class="baw-err">{{ $message }}</p> @enderror
                                @error('outletPhotosOuter.*') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>

                            {{-- Inner photos --}}
                            <div class="flex flex-col gap-2">
                                <label style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <i class="ti ti-armchair" style="color: var(--chimera-500); margin-right: 4px;"></i>
                                    Foto Outlet Dalam
                                </label>
                                <label for="innerPhotos" class="baw-photo-btn">
                                    <i class="ti ti-upload"></i>
                                    Pilih foto ({{ count($outletPhotosInner) }}/3)
                                </label>
                                <input
                                    id="innerPhotos"
                                    type="file"
                                    wire:model="outletPhotosInner"
                                    multiple
                                    accept="image/jpeg,image/png,image/webp"
                                    style="display: none;"
                                />
                                <div wire:loading wire:target="outletPhotosInner" style="font-size: 12px; color: var(--text-tertiary);">
                                    Mengunggah...
                                </div>
                                @if (count($outletPhotosInner) > 0)
                                    <div class="flex flex-col gap-1.5 mt-1">
                                        @foreach ($outletPhotosInner as $idx => $photo)
                                            <div class="baw-photo-row">
                                                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <i class="ti ti-photo" style="color: var(--chimera-500);"></i>
                                                    {{ method_exists($photo, 'getClientOriginalName') ? $photo->getClientOriginalName() : 'foto-' . ($idx + 1) }}
                                                </span>
                                                <button type="button" wire:click="removePhoto('inner', {{ $idx }})" class="baw-photo-remove" title="Hapus">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('outletPhotosInner') <p class="baw-err">{{ $message }}</p> @enderror
                                @error('outletPhotosInner.*') <p class="baw-err">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Privacy note --}}
                    <div class="flex items-start gap-3 p-4 rounded-lg" style="background: var(--chimera-50); border: 1px solid var(--chimera-100);">
                        <i class="ti ti-info-circle" style="color: var(--chimera-600); font-size: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                        <p style="font-size: 13px; color: var(--chimera-700);">
                            Saya menggunakan data publik dari Google Maps, Instagram, dan TikTok. Foto outlet hanya dipakai untuk audit visual brand. Tidak ada data sensitif yang disimpan.
                        </p>
                    </div>

                    {{-- Submit (inside card, full width) --}}
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                        class="nui-btn-primary rounded-pill"
                        style="font-size: 15px; font-weight: 500; padding: 12px 24px; align-self: stretch;"
                    >
                        <span wire:loading.remove wire:target="submit">
                            Analisis Brand <i class="ti ti-sparkles"></i>
                        </span>
                        <span wire:loading wire:target="submit">Memulai analisis...</span>
                    </button>

                </form>
            </div>
        </section>
        @endauth

        {{-- BB82: insufficient-credits modal shown when a signed-in user
             with balance < 1 tries to submit. The Phase 12b top-up flow
             will replace the placeholder copy with a Xendit CTA. --}}
        @if ($showInsufficientCreditsModal)
            <div class="fixed inset-0 z-40 flex items-center justify-center p-6" style="background: rgba(15,20,17,0.55);" wire:click.self="dismissInsufficientCreditsModal">
                <div class="nui-card max-w-md w-full p-8 text-center" style="position: relative;">
                    <button type="button" wire:click="dismissInsufficientCreditsModal" style="position: absolute; top: 12px; right: 14px; background: none; border: none; color: var(--text-tertiary); font-size: 22px; cursor: pointer; line-height: 1;" aria-label="Tutup">
                        <i class="ti ti-x"></i>
                    </button>
                    <div style="width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; background: var(--surface-muted); display: flex; align-items: center; justify-content: center;">
                        <i class="ti ti-coin-off" style="font-size: 28px; color: var(--color-warning);"></i>
                    </div>
                    <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary);">Kredit Anda tidak cukup</h2>
                    <p style="font-size: 14px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6;">
                        Audit baru membutuhkan 1 kredit. Saldo Anda saat ini 0. Top up kredit akan tersedia segera — sementara ini hubungi tim Nema untuk menambah saldo manual.
                    </p>
                    <button type="button" wire:click="dismissInsufficientCreditsModal" class="nui-btn-secondary rounded-pill" style="margin-top: 20px; font-size: 14px; padding: 10px 20px;">
                        Mengerti
                    </button>
                </div>
            </div>
        @endif
    @endif

    {{-- ===== STEP 2: ANALYZING (BB21 live progress) ===== --}}
    @if ($step === 'analyzing')
        @php
            // BB72 — Phase 11 5-phase progression labels.
            $stepLabels = [
                // Phase 1 — gather
                'gather_places'              => 'Mengambil data Google Places',
                'gather_gmaps'               => 'Scraping ulasan Google Maps',
                'gather_instagram'           => 'Scraping profil Instagram',
                'fetch_website'              => 'Mengambil metadata website',
                // Phase 2 — analyze
                'analyze_instagram'          => 'Analisis konten Instagram (AI)',
                'extract_service_signals'    => 'Mengekstrak sinyal layanan',
                // Phase 3 — validate
                'validate_evidence'          => 'Validasi kecocokan brand',
                // Phase 4 — score
                'score_recall'               => 'Skoring Brand Recall',
                'score_digital'              => 'Skoring Digital Presence',
                'score_konsistensi'          => 'Skoring Brand Konsistensi',
                'score_experience'           => 'Skoring Brand Experience',
                // Phase 5 — insights + PDF
                'generate_recommendations'   => 'Generate 5 rekomendasi prioritas',
                'generate_quick_wins'        => 'Generate quick wins',
                'generate_positioning'       => 'Generate posisi kompetitif',
                'generate_pdf'               => 'Generate activation kit PDF',
            ];
            $trackLabels = [
                'gather'    => 'Fase 1 · Kumpulkan data',
                'analyze'   => 'Fase 2 · Analisis AI',
                'validate'  => 'Fase 3 · Validasi',
                'score'     => 'Fase 4 · Skoring pilar',
                'final'     => 'Fase 5 · Insight + PDF',
            ];
            $groupedSteps = [];
            foreach ($auditSteps as $s) {
                $groupedSteps[$s['track']][] = $s;
            }
            $stepIcon = static fn (string $st): string => match ($st) {
                'done'    => '✓',
                'running' => '⏳',
                'failed'  => '✗',
                default   => '○',
            };
            $stepClr = static fn (string $st): string => match ($st) {
                'done'    => 'var(--color-success)',
                'running' => 'var(--chimera-600)',
                'failed'  => 'var(--color-danger)',
                default   => 'var(--text-tertiary)',
            };

            // BB96 — derive a single high-level "Pomelli phase" from the
            // 15-step audit_steps progression. The detail grid below is
            // unchanged (operators can still drill in step-by-step), but
            // the primary above-the-fold UI is now one badge + label.
            $pomelliActiveStep = null;
            $pomelliLastDoneStep = null;
            foreach ($auditSteps as $s) {
                if ($s['status'] === 'running' && $pomelliActiveStep === null) {
                    $pomelliActiveStep = $s;
                }
                if ($s['status'] === 'done') {
                    $pomelliLastDoneStep = $s;
                }
            }
            $pomelliKey = $pomelliActiveStep['key'] ?? $pomelliLastDoneStep['key'] ?? null;

            $pomelliPhase = (function (?string $key, string $status): array {
                if (in_array($status, ['done', 'validation_warning'], true)) {
                    return ['icon' => '✅', 'label' => 'Selesai! Mengarahkan ke hasil…', 'caption' => 'Audit lengkap.'];
                }
                if ($status === 'failed') {
                    return ['icon' => '✗', 'label' => 'Audit gagal di tengah jalan', 'caption' => 'Cek detail di bawah untuk troubleshoot.'];
                }
                if ($key === null) {
                    return ['icon' => '⚙️', 'label' => 'Memulai analisis…', 'caption' => 'Mempersiapkan pipeline.'];
                }
                return match (true) {
                    str_starts_with($key, 'gather_') => ['icon' => '🌐', 'label' => 'Menganalisis Google Maps & sosial mediamu', 'caption' => 'Mengumpulkan ulasan, foto, dan konten.'],
                    in_array($key, ['analyze_instagram', 'extract_service_signals'], true) => ['icon' => '✨', 'label' => 'Menganalisis brand DNA-mu', 'caption' => 'AI sedang mengekstrak sinyal brand.'],
                    $key === 'validate_evidence' => ['icon' => '🔎', 'label' => 'Memvalidasi data brand', 'caption' => 'Cross-check brand name dengan listing.'],
                    str_starts_with($key, 'score_') => ['icon' => '📊', 'label' => 'Menyusun skor pilar brand', 'caption' => 'Konsistensi · Recall · Experience · Digital.'],
                    in_array($key, ['generate_recommendations', 'generate_quick_wins', 'generate_positioning'], true) => ['icon' => '💡', 'label' => 'Menyusun insight & rekomendasi', 'caption' => '5 rekomendasi prioritas + quick wins.'],
                    $key === 'generate_pdf' => ['icon' => '📄', 'label' => 'Membuat activation kit PDF', 'caption' => 'Hampir selesai.'],
                    default => ['icon' => '⚙️', 'label' => 'Menganalisis…', 'caption' => null],
                };
            })($pomelliKey, $auditStatus);

            // Source link for the audited place — only visible when the
            // user reached the analyzing screen via a v2 wizard (or any
            // legacy audit that happens to have a stored gmaps_url in
            // touchpoints, which is the common case).
            $pomelliSourceName = $placeName ?? $brandName ?? null;
            $pomelliSourceUrl  = isset($placeId) && $placeId
                ? 'https://www.google.com/maps/place/?q=place_id:' . $placeId
                : null;
        @endphp
        <div @if (! in_array($auditStatus, ['done', 'failed'])) wire:poll.2000ms="pollStatus" @endif class="max-w-3xl mx-auto py-12">
            {{-- BB96 Pomelli-style hero. --}}
            <div style="text-align: center; padding: 24px 16px 32px;">
                <h1 style="font-size: 36px; font-weight: 600; line-height: 1.15; color: var(--text-primary); margin: 0 0 12px;">
                    Menganalisis bisnismu
                </h1>
                <p style="font-size: 16px; color: var(--text-secondary); line-height: 1.5; max-width: 480px; margin: 0 auto;">
                    Saya sedang meneliti dan menganalisis. Ini mungkin butuh beberapa menit.
                </p>

                <div class="pomelli-badge" style="display: inline-flex; align-items: center; gap: 12px; margin-top: 28px; padding: 14px 24px; background: var(--surface-card); border: 1px solid var(--chimera-200); border-radius: var(--radius-pill); box-shadow: var(--shadow-popover); font-size: 15px; font-weight: 500; color: var(--text-primary);">
                    <span style="font-size: 22px; line-height: 1;">{{ $pomelliPhase['icon'] }}</span>
                    <span>{{ $pomelliPhase['label'] }}</span>
                </div>

                @if (! empty($pomelliPhase['caption']))
                    <p style="font-size: 12px; color: var(--text-tertiary); margin: 12px 0 0;">{{ $pomelliPhase['caption'] }}</p>
                @endif

                @if ($pomelliSourceName && $pomelliSourceUrl)
                    <a href="{{ $pomelliSourceUrl }}" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 6px; margin-top: 20px; font-size: 13px; color: var(--text-secondary); text-decoration: none; padding: 6px 14px; border-radius: var(--radius-pill); border: 1px solid var(--border-default); background: var(--surface-card);">
                        <i class="ti ti-link" style="font-size: 12px;"></i> {{ $pomelliSourceName }}
                    </a>
                @endif

                <p style="font-size: 12px; color: var(--text-tertiary); margin: 24px 0 0;">
                    Bisa kembali nanti — hasilnya tersimpan otomatis di
                    <a href="{{ route('audits.index') }}" wire:navigate style="color: var(--text-secondary); text-decoration: underline;">Riwayat audit</a>.
                </p>
            </div>

            <style>
                @keyframes pomelli-pulse {
                    0%, 100% { box-shadow: var(--shadow-popover), 0 0 0 0 rgba(61, 137, 72, 0.45); }
                    50%      { box-shadow: var(--shadow-popover), 0 0 0 14px rgba(61, 137, 72, 0); }
                }
                .pomelli-badge { animation: pomelli-pulse 2.2s ease-in-out infinite; }
            </style>

            <p style="text-align: center; font-size: 13px; color: var(--text-tertiary); margin: 28px 0 16px;">
                <i class="ti ti-list-details" style="font-size: 13px;"></i> Detail teknis per langkah pipeline:
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                {{-- BB72: render the 5-phase tracks (gather / analyze /
                     validate / score). Final goes in its own row below. --}}
                @foreach (['gather', 'analyze', 'validate', 'score'] as $trackKey)
                    <x-nui-card>
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">
                            {{ $trackLabels[$trackKey] ?? $trackKey }}
                        </p>
                        @forelse ($groupedSteps[$trackKey] ?? [] as $s)
                            <div class="flex items-center justify-between py-2" style="border-bottom: 1px solid var(--border-default);">
                                <div class="flex items-center gap-3">
                                    <span style="font-size: 16px; color: {{ $stepClr($s['status']) }}; min-width: 18px; display: inline-block; text-align: center;">{{ $stepIcon($s['status']) }}</span>
                                    <span style="font-size: 13px; color: {{ $s['status'] === 'pending' ? 'var(--text-tertiary)' : 'var(--text-primary)' }};">
                                        {{ $stepLabels[$s['key']] ?? $s['key'] }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($s['elapsed_s'] !== null)
                                        <span style="font-size: 11px; color: var(--text-tertiary); font-variant-numeric: tabular-nums;">{{ $s['elapsed_s'] }}s</span>
                                    @endif
                                    {{-- BB59: per-row "Coba lagi" button for retryable gather steps. --}}
                                    @if ($s['status'] === 'failed' && in_array($s['key'], ['gather_gmaps', 'gather_instagram']))
                                        <button
                                            type="button"
                                            wire:click="retryStep('{{ $s['key'] }}')"
                                            wire:loading.attr="disabled"
                                            style="font-size: 11px; padding: 4px 10px; border: 1px solid var(--color-danger); color: var(--color-danger); border-radius: var(--radius-pill); background: var(--surface-card);"
                                            title="Ulangi langkah ini dan re-skor"
                                        >
                                            Coba lagi
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p style="font-size: 13px; color: var(--text-tertiary);">(menunggu jadwal)</p>
                        @endforelse
                    </x-nui-card>
                @endforeach
            </div>

            @if (! empty($groupedSteps['final']))
                <div class="mt-6">
                    <x-nui-card>
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">
                            {{ $trackLabels['final'] }}
                        </p>
                        @foreach ($groupedSteps['final'] as $s)
                            <div class="flex items-center justify-between py-2">
                                <div class="flex items-center gap-3">
                                    <span style="font-size: 16px; color: {{ $stepClr($s['status']) }}; min-width: 18px; display: inline-block; text-align: center;">{{ $stepIcon($s['status']) }}</span>
                                    <span style="font-size: 13px; color: {{ $s['status'] === 'pending' ? 'var(--text-tertiary)' : 'var(--text-primary)' }};">
                                        {{ $stepLabels[$s['key']] ?? $s['key'] }}
                                        @if ($s['status'] === 'pending') <span style="color: var(--text-tertiary); font-size: 11px;">(menunggu skoring pilar selesai)</span> @endif
                                    </span>
                                </div>
                                @if ($s['elapsed_s'] !== null)
                                    <span style="font-size: 11px; color: var(--text-tertiary); font-variant-numeric: tabular-nums;">{{ $s['elapsed_s'] }}s</span>
                                @endif
                            </div>
                        @endforeach
                    </x-nui-card>
                </div>
            @endif
        </div>
    @endif

    {{-- ===== STEP 3: DASHBOARD ===== --}}
    @if ($step === 'dashboard')
        @php
            $tierColor = static fn (?int $s): string => match (true) {
                ($s ?? 0) >= 70 => 'var(--chimera-500)',
                ($s ?? 0) >= 50 => 'var(--color-warning)',
                default         => 'var(--color-danger)',
            };
            $circ     = 263.89;
            $filled   = $overallScore ? round(($overallScore / 100) * $circ, 2) : 0;
            $scoreClr = $tierColor($overallScore);
        @endphp

        <div class="max-w-5xl mx-auto pb-16">

            {{-- BB60: validation warning banner — renders when
                 audit_evidence.validation.confidence < 0.5 OR audit.status
                 is STATUS_VALIDATION_WARNING. Operator sees this BEFORE
                 the score section so a misleading audit (wrong URL paste)
                 gets corrected, not acted on. --}}
            @if ($hasValidationWarning)
                <div
                    class="mb-8"
                    style="background: rgba(201, 122, 27, 0.08); border: 1px solid var(--color-warning); border-radius: var(--radius-lg); padding: 20px;"
                    role="alert"
                >
                    <div class="flex items-start gap-3">
                        <span style="font-size: 22px; color: var(--color-warning); line-height: 1;">⚠</span>
                        <div style="flex: 1;">
                            <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px;">
                                Sistem mendeteksi kemungkinan ketidakcocokan antara brand input dan URL yang discrap.
                            </p>
                            <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px;">
                                Harap periksa kembali URL Instagram dan Google Maps yang Anda masukkan. Skor di bawah dihitung dari data yang discrap — jika brand/lokasi tidak cocok, hasil audit mungkin tidak akurat.
                            </p>
                            @if (! empty($validationWarnings))
                                <ul style="font-size: 13px; color: var(--text-secondary); margin: 0 0 12px 0; padding-left: 18px; list-style: disc;">
                                    @foreach ($validationWarnings as $warn)
                                        <li style="margin-bottom: 4px;">{{ $warn }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="flex items-center gap-3 flex-wrap">
                                <button
                                    type="button"
                                    wire:click="editAndRerun"
                                    style="font-size: 13px; padding: 8px 16px; border: 1px solid var(--color-warning); color: var(--text-primary); background: var(--surface-card); border-radius: var(--radius-pill); font-weight: 500;"
                                >
                                    Edit URL & ulangi audit
                                </button>
                                <span style="font-size: 12px; color: var(--text-tertiary);">
                                    Confidence skor: {{ number_format($validationConfidence * 100, 0) }}/100
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Header --}}
            <div class="flex items-start justify-between mb-10 flex-wrap gap-4">
                <div>
                    <p style="font-size: 13px; color: var(--text-tertiary); margin-bottom: 4px;">Hasil Brand Health Check</p>
                    <h2 style="font-size: 26px; font-weight: 600; color: var(--text-primary);">{{ $brandName }}</h2>
                </div>
                <div class="flex items-center gap-3 flex-wrap" @if ($kitGenerating) wire:poll.3000ms="checkKit" @endif>
                    <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline;">
                        Analisis brand lain
                    </a>

                    @if ($activationKitPath && $sessionToken)
                        <a
                            href="{{ route('audit.kit.download', ['token' => $sessionToken]) }}"
                            class="nui-btn-primary rounded-pill"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 13px; font-weight: 500;"
                        >
                            <i class="ti ti-download"></i>
                            Download Activation Kit (PDF)
                        </a>
                    @elseif ($kitGenerating)
                        <span
                            style="display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-default); border-radius: var(--radius-pill); padding: 8px 16px; font-size: 13px; font-weight: 500; color: var(--text-secondary); background: var(--surface-muted);"
                        >
                            <span style="width: 14px; height: 14px; border: 2px solid var(--chimera-200); border-top-color: var(--chimera-500); border-radius: 50%; display: inline-block; animation: baw-spin .8s linear infinite;"></span>
                            Membuat activation kit...
                        </span>
                    @else
                        <div style="display: inline-flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                            <button
                                type="button"
                                wire:click="generateKit"
                                wire:loading.attr="disabled"
                                wire:target="generateKit"
                                class="nui-btn-primary rounded-pill"
                                style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 13px; font-weight: 500;"
                            >
                                <span wire:loading.remove wire:target="generateKit">
                                    <i class="ti ti-file-text"></i>
                                    {{ $pdfError ? 'Coba Buat PDF Lagi' : 'Buat Activation Kit (PDF)' }}
                                </span>
                                <span wire:loading wire:target="generateKit">Memulai...</span>
                            </button>
                            @if ($pdfError)
                                <p style="font-size: 11px; color: var(--color-danger); max-width: 320px; text-align: right;">
                                    {{ $pdfError }}
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            @if ($auditStatus === 'failed')
                <x-nui-card>
                    <div style="border-left: 3px solid var(--color-danger); padding-left: 16px;">
                        <p style="font-weight: 500; color: var(--color-danger); margin-bottom: 4px;">Analisis gagal</p>
                        @if ($errorMessage)
                            <p style="font-size: 13px; color: var(--text-secondary);">{{ $errorMessage }}</p>
                        @endif
                        <a href="{{ route('home') }}" style="font-size: 13px; color: var(--chimera-600); text-decoration: underline; margin-top: 12px; display: inline-block;">
                            Coba lagi
                        </a>
                    </div>
                </x-nui-card>
            @else

                {{-- ===== Overall score ring (centered, top of dashboard) ===== --}}
                <div class="max-w-md mx-auto mb-12 text-center">
                    <div style="position: relative; width: 220px; height: 220px; margin: 0 auto;">
                        <svg viewBox="0 0 100 100" style="width: 220px; height: 220px;">
                            <circle cx="50" cy="50" r="42" fill="none" stroke="var(--chimera-50)" stroke-width="7"/>
                            <circle
                                cx="50" cy="50" r="42"
                                fill="none"
                                stroke="{{ $scoreClr }}"
                                stroke-width="7"
                                stroke-dasharray="{{ $filled }} {{ $circ }}"
                                stroke-linecap="round"
                                transform="rotate(-90 50 50)"
                            />
                        </svg>
                        <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                            <span style="font-size: 56px; font-weight: 700; line-height: 1; color: {{ $scoreClr }}; font-family: var(--font-display);">{{ $overallScore ?? '—' }}</span>
                            <span style="font-size: 12px; color: var(--text-tertiary); margin-top: 4px;">dari 100</span>
                        </div>
                    </div>
                    @if ($overallLabel)
                        <p style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-top: 16px;">
                            {{ $overallLabel }}
                        </p>
                    @endif
                </div>

                {{-- ===== Pillar grid (single column) ===== --}}
                @php
                    // BB32: methodology copy explaining WHY each pillar gets its
                    // weight and why each sub-bucket carries its specific cap.
                    // Keyed on pillar slug; values match config/branding.php.
                    // Sourced from config:
                    //   pillar_weights — Konsistensi 35 / Recall 35 / Experience 20 / Digital 10
                    //   pillar_sub_buckets — see config/branding.php:40-73
                    $pillarMethodology = [
                        'brand-konsistensi' => 'Brand Konsistensi (35% of overall score) measures how unified your brand identity is across digital touchpoints — the strongest predictor of long-term recall in customer interviews. Kehadiran Digital (40 pts) carries the highest weight because consistent presence across IG + Website + GMaps + WA + TikTok signals a real operating brand, not a side hustle. Konsistensi Visual (35 pts) judges whether logo, color, and tone hold together when a customer scans across channels. Kelengkapan Layanan (15 pts) and Transparansi Harga (10 pts) round out the score with specific operational signals.',
                        'brand-recall' => 'Brand Recall (35% of overall score) measures how easily potential customers recognize your brand when searching for laundry services. Search Recall (35 pts) carries the highest weight because Google Autocomplete demand is the strongest proxy for true brand awareness in your area. Rating (25 pts) and Jumlah Review (15 pts) reflect cumulative social proof. Kata Kunci Positif di Ulasan (15 pts) and Sentimen (10 pts) measure qualitative reception drawn from up to 30 scraped GMaps reviews per audit.',
                        'brand-experience' => 'Brand Experience (20% of overall score) measures the operational signals customers encounter when interacting with the brand. Every audit starts at a Dasar of 30 pts, with bonuses fired by LLM analysis of touchpoint copy: Variasi Layanan (+15), SOP Keluhan (+15), Antar Jemput (+12), Layanan Ekspres (+10), Daftar Harga (+10). Penalty deltas — Pakaian Hilang (−10), Keterlambatan (−8), No-Response WA (−8) — are deterministic, fired only when the GMaps review corpus contains explicit complaints.',
                        'digital-presence' => 'Digital Presence (10% of overall score) measures how discoverable and complete your brand is across the channels customers actually check. Weights reflect platform impact for laundry SMBs: Google Maps (25 pts — discovery driver #1), Instagram (20 pts), Website (20 pts), WhatsApp Business (15 pts — direct order channel), TikTok (10 pts — emerging). A Bonus Review of up to 15 extra pts fires when the GMaps listing has accumulated meaningful review volume.',
                    ];
                @endphp
                <div class="grid grid-cols-1 gap-6 mb-12">
                    @foreach ($pillarMeta as $slug => $meta)
                        @php
                            $ps      = $pillarScoreInts[$slug] ?? null;
                            $pc      = $tierColor($ps);
                            $sbs     = $subBucketScores[$slug] ?? [];
                            $hasRecs = ($recsByPillar[$slug] ?? 0) > 0;
                            $methodology = $pillarMethodology[$slug] ?? null;
                            // BB34: pillar-level LLM reasoning — rendered ONCE above
                            // the sub-bucket list so it isn't duplicated under every
                            // row (the BB17 anti-pattern this fix retires).
                            $pillarLlmReasoning = (string) (
                                is_array($pillarScores[$slug] ?? null)
                                    ? ($pillarScores[$slug]['reasoning'] ?? '')
                                    : ''
                            );
                        @endphp
                        <x-nui-card>
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                                        <i class="ti {{ $meta['icon'] }}" style="color: var(--chimera-600); font-size: 18px;"></i>
                                    </div>
                                    <span style="font-size: 15px; font-weight: 600; color: var(--text-primary);">{{ $meta['label'] }}</span>
                                </div>
                                <span style="font-size: 28px; font-weight: 700; color: {{ $pc }}; line-height: 1;">{{ $ps ?? '—' }}</span>
                            </div>

                            <div style="height: 6px; background: var(--chimera-50); border-radius: 999px; margin-bottom: 16px;">
                                <div style="height: 100%; width: {{ $ps ?? 0 }}%; background: {{ $pc }}; border-radius: 999px;"></div>
                            </div>

                            {{-- BB32: "About this score" methodology block — explains the
                                 weight allocation so the numbers below are interpretable
                                 instead of opaque. Renders as muted annotation, not
                                 primary content. --}}
                            @if ($methodology !== null)
                                <div style="margin-bottom: 16px; padding: 12px 14px; background: var(--surface-muted); border-left: 3px solid var(--chimera-200); border-radius: var(--radius-sm);">
                                    <p style="font-size: 10px; font-weight: 600; color: var(--chimera-700); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">About this score</p>
                                    <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.6; margin: 0;">{{ $methodology }}</p>
                                </div>
                            @endif

                            {{-- BB34: pillar-level LLM reasoning — rendered ONCE here
                                 instead of being copied under every sub-bucket row. The
                                 narrative summarises the LLM's overall judgment of THIS
                                 audit's data, vs the static "About this score" block
                                 above which explains the scoring methodology. --}}
                            @if ($pillarLlmReasoning !== '')
                                <div style="margin-bottom: 16px; padding: 12px 14px; background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-sm);">
                                    <p style="font-size: 10px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">Ringkasan penilaian</p>
                                    <p style="font-size: 13px; color: var(--text-primary); line-height: 1.65; margin: 0;">{{ $pillarLlmReasoning }}</p>
                                </div>
                            @endif

                            @if (count($sbs) > 0)
                                <div class="flex flex-col mb-4" style="border-top: 1px solid var(--border-default);">
                                    @foreach ($sbs as $k => $v)
                                        @php $bd = is_array($scoreBreakdown[$slug][$k] ?? null) ? $scoreBreakdown[$slug][$k] : null; @endphp
                                        <div style="border-bottom: 1px solid var(--border-default);">
                                            <div class="flex justify-between items-center py-2">
                                                <span style="font-size: 12px; color: var(--text-secondary);">{{ $subBucketLabels[$k] ?? $k }}</span>
                                                <span style="font-size: 13px; font-weight: 500; color: var(--text-primary);">{{ $v }}</span>
                                            </div>
                                            @if ($bd !== null)
                                                {{-- BB32: BB17/BB23 dropdown reverted — sub-bucket breakdown
                                                     renders inline so users see how the score is computed
                                                     without needing to click. Per-pillar methodology lives
                                                     in the "About this score" block above the sub-bucket
                                                     list, demystifying weights without repeating data. --}}
                                                <div style="padding: 8px 12px 12px; background: var(--surface-muted); border-top: 1px solid var(--border-default); font-size: 11px; color: var(--text-secondary);">
                                                    @php
                                                        $formula      = $bd['formula'] ?? 'unknown';
                                                        $rawInputs    = (array) ($bd['raw_inputs'] ?? []);
                                                        $tierTable    = (array) ($bd['tier_table'] ?? []);
                                                        $signals      = (array) ($bd['signals'] ?? []);
                                                        $suggestions  = (array) ($rawInputs['suggestions'] ?? []);
                                                        $llmReasoning = (string) ($bd['llm_reasoning'] ?? '');
                                                        $limitations  = (array) ($bd['limitations'] ?? []);
                                                        $signalLabels = [
                                                            'brand_recognition' => 'Pengenalan Brand',
                                                            'geographic_spread' => 'Sebaran Lokasi',
                                                            'variant_coverage'  => 'Variasi Pencarian',
                                                        ];

                                                        if ($formula === 'llm_judgment') {
                                                            $inputLine = implode(', ', (array) ($rawInputs['context_provided'] ?? []));
                                                        } elseif ($formula === 'deterministic_signals') {
                                                            $inputLine = sprintf(
                                                                'brand_stem: %s · sumber: %s',
                                                                (string) ($rawInputs['brand_stem'] ?? '—'),
                                                                (string) ($rawInputs['source'] ?? 'Google Autocomplete'),
                                                            );
                                                        } else {
                                                            $parts = [];
                                                            foreach ($rawInputs as $rk => $rv) {
                                                                if (in_array($rk, ['source', 'suggestions', 'suggestion_count', 'brand_name', 'brand_stem', 'fetched_at'], true)) continue;
                                                                $parts[] = $rk . ': ' . (is_bool($rv) ? ($rv ? 'Ya' : 'Tidak') : $rv);
                                                            }
                                                            $inputLine = implode(' · ', $parts);
                                                        }
                                                    @endphp

                                                    {{-- BB23: header moved out to the toggle button above. --}}
                                                    @php
                                                        $formulaLabel = match ($formula) {
                                                            'deterministic_threshold' => 'Threshold tier-based (deterministik)',
                                                            'deterministic_signals'   => 'Signal-based weighted (deterministik)',
                                                            'llm_judgment'            => 'Penilaian LLM (Claude)',
                                                            default                   => 'Lainnya',
                                                        };
                                                        $sourceLabel = match ($k) {
                                                            'rating_tier', 'review_count_tier', 'keyword_saturation', 'review_keyword_quality', 'sentiment_quality' => 'Google Maps reviews',
                                                            'search_recall' => 'Google Autocomplete',
                                                            'has_gmaps', 'has_instagram', 'has_website', 'has_wa', 'has_tiktok' => 'Touchpoint input form',
                                                            'review_bonus' => 'Google Maps review count threshold',
                                                            'kehadiran_digital', 'konsistensi_visual', 'kelengkapan_layanan', 'transparansi_harga' => 'Penilaian visual + URL touchpoint',
                                                            'base', 'bonus_ekspres', 'bonus_antar_jemput', 'bonus_variasi_layanan', 'bonus_sop_keluhan', 'bonus_price_list', 'penalty_keterlambatan', 'penalty_pakaian_hilang', 'penalty_no_response_wa' => 'Penilaian LLM dari konteks brand',
                                                            default => 'Konteks brand',
                                                        };
                                                    @endphp
                                                    <p style="margin: 0 0 6px;"><strong>Sumber:</strong> {{ $sourceLabel }} · <strong>Formula:</strong> {{ $formulaLabel }}</p>

                                                    @if ($k === 'search_recall')
                                                        <p style="margin: 0 0 6px; line-height: 1.55;">Frekuensi dan variasi brand muncul di hasil autocomplete pencarian.</p>
                                                    @endif

                                                    <p style="margin: 0 0 6px;"><strong>Berdasarkan:</strong> {{ $inputLine }}</p>

                                                    @if ($formula === 'deterministic_threshold' && count($tierTable) > 0)
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px;">
                                                            @foreach ($tierTable as $tier)
                                                                @php $isMatch = (bool) ($tier['matched'] ?? false); @endphp
                                                                <tr style="background: {{ $isMatch ? 'var(--chimera-500)' : 'transparent' }}; color: {{ $isMatch ? '#FFFFFF' : 'inherit' }}; border-radius: 4px;">
                                                                    <td style="padding: 3px 8px;">{{ $tier['range'] }}</td>
                                                                    <td style="padding: 3px 8px; text-align: right; font-weight: {{ $isMatch ? '600' : 'normal' }};">{{ $tier['points'] }} pt</td>
                                                                </tr>
                                                            @endforeach
                                                        </table>
                                                    @endif

                                                    @if ($formula === 'deterministic_signals' && count($signals) > 0)
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px;">
                                                            @foreach ($signals as $sigKey => $sig)
                                                                @php
                                                                    $sigScore  = (int) ($sig['score'] ?? 0);
                                                                    $sigCap    = (int) ($sig['cap'] ?? 0);
                                                                    $sigDetail = (string) ($sig['detail'] ?? '');
                                                                    $isFull    = $sigCap > 0 && $sigScore >= $sigCap;
                                                                @endphp
                                                                <tr style="background: {{ $isFull ? 'var(--chimera-500)' : 'transparent' }}; color: {{ $isFull ? '#FFFFFF' : 'inherit' }};">
                                                                    <td style="padding: 3px 8px; vertical-align: top;">{{ $signalLabels[$sigKey] ?? $sigKey }}</td>
                                                                    <td style="padding: 3px 8px; text-align: right; font-weight: {{ $isFull ? '600' : 'normal' }}; white-space: nowrap;">{{ $sigScore }} / {{ $sigCap }} pt</td>
                                                                </tr>
                                                                @if ($sigDetail !== '')
                                                                    <tr>
                                                                        <td colspan="2" style="padding: 0 8px 4px; font-size: 10px; color: var(--text-tertiary); line-height: 1.5;">{{ $sigDetail }}</td>
                                                                    </tr>
                                                                @endif
                                                            @endforeach
                                                        </table>

                                                        @if (count($suggestions) > 0)
                                                            <p style="margin: 4px 0 2px; font-weight: 600; color: var(--text-secondary);">Top {{ count($suggestions) }} hasil autocomplete:</p>
                                                            <ol style="margin: 0 0 6px 18px; padding: 0; font-size: 10px; color: var(--text-secondary); line-height: 1.55;">
                                                                @foreach ($suggestions as $sug)
                                                                    <li>{{ $sug }}</li>
                                                                @endforeach
                                                            </ol>
                                                        @endif
                                                    @endif

                                                    @if ($formula === 'llm_judgment' && $llmReasoning !== '')
                                                        <p style="margin: 0 0 4px; line-height: 1.55;">{{ $llmReasoning }}</p>
                                                    @endif

                                                    @if (count($limitations) > 0)
                                                        <p style="margin: 0; font-style: italic; color: var(--text-tertiary); font-size: 10px;">Keterbatasan: {{ implode('; ', $limitations) }}</p>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($hasRecs)
                                <button
                                    type="button"
                                    wire:click="openModal('{{ $slug }}')"
                                    style="font-size: 13px; font-weight: 500; color: var(--chimera-600); text-decoration: none; background: none; border: none; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px;"
                                    onmouseover="this.style.textDecoration='underline'"
                                    onmouseout="this.style.textDecoration='none'"
                                >
                                    Lihat rekomendasi <i class="ti ti-arrow-right" style="font-size: 13px;"></i>
                                </button>
                            @endif
                        </x-nui-card>
                    @endforeach
                </div>

                {{-- ===== Phase 7-C BB15: Audit Profil Instagram ===== --}}
                @include('livewire._instagram-audit-section', [
                    'instagramAudit'       => $instagramAudit,
                    'instagramAuditStatus' => $instagramAuditStatus,
                ])

                {{-- ===== Phase 8 BB29: Ulasan Pelanggan (Google Maps) ===== --}}
                @include('livewire._gmaps-reviews-section', ['audit' => (object) [
                    'gmaps_reviews_status' => $gmapsReviewsStatus,
                    'gmaps_reviews'        => $gmapsReviews,
                    'score_breakdown'      => $scoreBreakdown,
                ]])

                {{-- ===== Temuan Utama ===== --}}
                @if (count($keyFindings) > 0)
                    <div class="mb-4">
                        <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">Temuan Utama</h3>
                        <p style="font-size: 13px; color: var(--text-secondary);">Hal-hal positif yang perlu dipertahankan dan area yang perlu diperhatikan.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-12">
                        @foreach ($keyFindings as $finding)
                            @php
                                $impact = is_array($finding) ? ($finding['impact'] ?? 'neutral') : 'neutral';
                                $obs    = is_array($finding) ? ($finding['observation'] ?? '') : (string) $finding;
                                $tp     = is_array($finding) ? ($finding['touchpoint'] ?? null) : null;
                                $isPositive = $impact === 'positive';
                                $borderClr  = $isPositive ? 'var(--color-success)' : 'var(--color-warning)';
                                $tagClr     = $isPositive ? 'var(--chimera-700)' : 'var(--color-warning)';
                                $tagBg      = $isPositive ? 'var(--chimera-50)' : '#FEF3DC';
                                $tagLabel   = $isPositive ? 'Positif' : 'Perlu perhatian';
                                $iconName   = $isPositive ? 'ti-circle-check-filled' : 'ti-alert-triangle-filled';
                            @endphp
                            <x-nui-card padding="none" :class="''">
                                <div style="border-left: 4px solid {{ $borderClr }}; padding: 20px 22px; border-radius: var(--radius-lg);">
                                    <div class="flex items-center gap-2 mb-2">
                                        <i class="ti {{ $iconName }}" style="color: {{ $borderClr }}; font-size: 14px;"></i>
                                        <span style="font-size: 11px; font-weight: 500; color: {{ $tagClr }}; background: {{ $tagBg }}; border-radius: var(--radius-pill); padding: 2px 10px;">
                                            {{ $tagLabel }}
                                        </span>
                                        @if ($tp)
                                            <span style="font-size: 11px; color: var(--text-tertiary); margin-left: auto;">{{ $tp }}</span>
                                        @endif
                                    </div>
                                    <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">{{ $obs }}</p>
                                </div>
                            </x-nui-card>
                        @endforeach
                    </div>
                @endif

                {{-- ===== Ringkasan Brand Health ===== --}}
                @if ($overallScore !== null && count($pillarScoreInts) > 0)
                    @php
                        $summaryPillarArr = $pillarScoreInts;
                        arsort($summaryPillarArr);
                        $highestSlug  = array_key_first($summaryPillarArr) ?? '';
                        $lowestSlug   = array_key_last($summaryPillarArr) ?? '';
                        $highestScore = $pillarScoreInts[$highestSlug] ?? null;
                        $lowestScore  = $pillarScoreInts[$lowestSlug] ?? null;
                        $highestLabel = $pillarMeta[$highestSlug]['label'] ?? $highestSlug;
                        $lowestLabel  = $pillarMeta[$lowestSlug]['label'] ?? $lowestSlug;

                        $summaryTierDescs = [
                            'EXCELLENT' => 'Pertahankan momentum ini dengan konsistensi pada semua touchpoint.',
                            'GOOD'      => 'Beberapa area kunci masih bisa dioptimalkan untuk mencapai level excellent.',
                            'AVERAGE'   => 'Perbaikan sistematis pada area lemah akan memberikan dampak paling besar.',
                            'BELOW AVG' => 'Prioritaskan perbaikan segera pada area dengan gap terbesar.',
                            'CRITICAL'  => 'Mulai dengan membangun kehadiran digital dan konsistensi identitas brand.',
                        ];
                        $summaryTierKey  = trim((string) explode('—', (string) ($overallLabel ?? ''))[0]);
                        $summaryTierDesc = $summaryTierDescs[$summaryTierKey] ?? '';

                        $summaryPriorityOrder = ['tinggi' => 0, 'penting' => 1, 'opsional' => 2];
                        $top3Recs = (array) $recommendations;
                        usort($top3Recs, function (array $a, array $b) use ($summaryPriorityOrder): int {
                            return ($summaryPriorityOrder[$a['priority'] ?? 'opsional'] ?? 2)
                                <=> ($summaryPriorityOrder[$b['priority'] ?? 'opsional'] ?? 2);
                        });
                        $top3Recs = array_slice($top3Recs, 0, 3);
                    @endphp

                    <x-nui-card padding="lg" style="margin-top: 24px;">

                        <h2 style="font-size: 24px; font-weight: 600; color: var(--text-primary); margin-bottom: 24px;">Ringkasan Brand Health</h2>

                        {{-- Three-column metric strip --}}
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6" style="padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 24px;">
                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Skor Total</p>
                                <div style="display: flex; align-items: baseline; gap: 6px; margin-bottom: 4px;">
                                    <span style="font-size: 40px; font-weight: 700; color: {{ $tierColor($overallScore) }}; line-height: 1;">{{ $overallScore }}</span>
                                    <span style="font-size: 13px; color: var(--text-tertiary);">/100</span>
                                </div>
                                <p style="font-size: 13px; font-weight: 500; color: var(--text-secondary);">{{ $overallLabel }}</p>
                            </div>

                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Kekuatan Utama</p>
                                <p style="font-size: 15px; font-weight: 600; color: var(--chimera-600); margin-bottom: 4px;">{{ $highestLabel }}</p>
                                <p style="font-size: 24px; font-weight: 700; color: var(--chimera-500); line-height: 1;">{{ $highestScore }}<span style="font-size: 13px; font-weight: 400; color: var(--text-tertiary);">/100</span></p>
                            </div>

                            <div>
                                <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 6px;">Area Pengembangan</p>
                                <p style="font-size: 15px; font-weight: 600; color: var(--color-warning); margin-bottom: 4px;">{{ $lowestLabel }}</p>
                                <p style="font-size: 24px; font-weight: 700; color: var(--color-warning); line-height: 1;">{{ $lowestScore }}<span style="font-size: 13px; font-weight: 400; color: var(--text-tertiary);">/100</span></p>
                            </div>
                        </div>

                        {{-- Synthesis paragraph --}}
                        <p style="font-size: 14px; color: var(--text-secondary); line-height: 1.75; padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 24px;">
                            <strong style="color: var(--text-primary);">{{ $brandName }}</strong> menunjukkan profil brand dengan tier <strong>{{ $overallLabel }}</strong>.
                            Kekuatan terbesar berada pada pilar <strong>{{ $highestLabel }}</strong> ({{ $highestScore }}/100),
                            sementara <strong>{{ $lowestLabel }}</strong> ({{ $lowestScore }}/100) menjadi area dengan potensi pengembangan terbesar.
                            @if ($summaryTierDesc) {{ $summaryTierDesc }} @endif
                        </p>

                        {{-- Top 3 priority recommendations --}}
                        @if (count($top3Recs) > 0)
                            <div style="padding-bottom: 24px; border-bottom: 1px solid var(--border-default); margin-bottom: 0;">
                                <h3 style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 16px;">3 Tindakan Prioritas</h3>
                                <ol style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px;">
                                    @foreach ($top3Recs as $ri => $rec)
                                        @php
                                            $rp      = $rec['priority'] ?? 'opsional';
                                            $rpClr   = match ($rp) {
                                                'tinggi'  => 'var(--color-danger)',
                                                'penting' => 'var(--color-warning)',
                                                default   => 'var(--text-tertiary)',
                                            };
                                            $rpLabel = match ($rp) {
                                                'tinggi'  => 'Tinggi',
                                                'penting' => 'Penting',
                                                default   => 'Opsional',
                                            };
                                        @endphp
                                        <li style="display: flex; align-items: flex-start; gap: 12px;">
                                            <span style="font-size: 20px; font-weight: 700; color: var(--chimera-500); line-height: 1.2; flex-shrink: 0; width: 24px;">{{ $ri + 1 }}</span>
                                            <div>
                                                <span style="display: inline-block; font-size: 11px; font-weight: 500; color: {{ $rpClr }}; background: var(--surface-muted); border-radius: var(--radius-pill); padding: 2px 10px; border: 1px solid {{ $rpClr }}; margin-bottom: 4px;">{{ $rpLabel }}</span>
                                                <p style="font-size: 14px; font-weight: 500; color: var(--text-primary); margin: 0;">{{ $rec['title'] ?? '' }}</p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif

                        {{-- CTA row --}}
                        <div class="flex justify-between items-center flex-wrap gap-3" style="margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-default);">
                            <a href="{{ route('home') }}"
                               style="font-size: 14px; color: var(--chimera-600); text-decoration: none;"
                               onmouseover="this.style.textDecoration='underline'"
                               onmouseout="this.style.textDecoration='none'">
                                ← Analisis brand lain
                            </a>

                            @if ($activationKitPath && $sessionToken)
                                <a
                                    href="{{ route('audit.kit.download', ['token' => $sessionToken]) }}"
                                    class="nui-btn-primary rounded-pill"
                                    style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    <i class="ti ti-download"></i>
                                    Download Activation Kit (PDF) ↓
                                </a>
                            @elseif ($kitGenerating)
                                <span style="display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-default); border-radius: var(--radius-pill); padding: 10px 24px; font-size: 14px; font-weight: 500; color: var(--text-secondary); background: var(--surface-muted);">
                                    <span style="width: 14px; height: 14px; border: 2px solid var(--chimera-200); border-top-color: var(--chimera-500); border-radius: 50%; display: inline-block; animation: baw-spin .8s linear infinite;"></span>
                                    Membuat activation kit...
                                </span>
                            @else
                                <button
                                    type="button"
                                    wire:click="generateKit"
                                    wire:loading.attr="disabled"
                                    wire:target="generateKit"
                                    class="nui-btn-primary rounded-pill"
                                    style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; font-size: 14px; font-weight: 600;"
                                >
                                    <span wire:loading.remove wire:target="generateKit">
                                        <i class="ti ti-file-text"></i> Download Activation Kit (PDF) ↓
                                    </span>
                                    <span wire:loading wire:target="generateKit">Memulai...</span>
                                </button>
                            @endif
                        </div>

                    </x-nui-card>
                @endif

            @endif
        </div>
    @endif

    {{-- ===== MODAL: RECOMMENDATIONS (kit-driven, only visible when triggered) =====
         Phase 12c hot-fix: wrapped in @if ($step === 'dashboard') so the
         x-nui-modal wrapper element doesn't render as a second top-level
         child of <div class="relative"> during the wizard / analyzing
         steps. The modal is only opened from pillar cards on the
         dashboard, so guarding it here is structurally correct as well
         as fixing the multi-root render error.
         ============================================================== --}}
    @if ($step === 'dashboard')
    <x-nui-modal name="recommendations" maxWidth="lg">
        @if ($modalPillar)
            <div class="flex items-start justify-between" style="margin-bottom: 20px;">
                <div>
                    <p style="font-size: 12px; color: var(--text-tertiary); margin-bottom: 2px;">Rekomendasi</p>
                    <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary);">
                        {{ $pillarMeta[$modalPillar]['label'] ?? $modalPillar }}
                    </h3>
                </div>
                <button
                    type="button"
                    @click="$dispatch('close-modal-recommendations'); $wire.closeModal()"
                    style="width: 32px; height: 32px; border-radius: var(--radius-sm); border: 1px solid var(--border-default); background: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-secondary);"
                    aria-label="Tutup"
                >
                    <i class="ti ti-x" style="font-size: 16px;"></i>
                </button>
            </div>

            @if (count($modalRecs) === 0)
                <p style="font-size: 14px; color: var(--text-secondary);">Tidak ada rekomendasi spesifik untuk pilar ini.</p>
            @else
                <div class="flex flex-col gap-5">
                    @foreach ($modalRecs as $i => $rec)
                        @php
                            $prio      = $rec['priority'] ?? 'opsional';
                            $prioClr   = match ($prio) {
                                'tinggi'  => 'var(--color-danger)',
                                'penting' => 'var(--color-warning)',
                                default   => 'var(--text-tertiary)',
                            };
                            $prioLabel = match ($prio) {
                                'tinggi'  => 'Prioritas Tinggi',
                                'penting' => 'Penting',
                                default   => 'Opsional',
                            };
                            $bucketLabel = $subBucketLabels[$rec['bucket'] ?? ''] ?? ($rec['bucket'] ?? '');
                        @endphp
                        <div @if ($i < count($modalRecs) - 1) style="padding-bottom: 20px; border-bottom: 1px solid var(--border-default);" @endif>
                            <div class="flex items-center gap-2" style="margin-bottom: 8px; flex-wrap: wrap;">
                                <span style="font-size: 11px; font-weight: 500; color: {{ $prioClr }}; background: var(--surface-muted); border-radius: var(--radius-pill); padding: 2px 10px; border: 1px solid {{ $prioClr }};">
                                    {{ $prioLabel }}
                                </span>
                                @if ($bucketLabel)
                                    <span style="font-size: 11px; color: var(--text-tertiary);">{{ $bucketLabel }}</span>
                                @endif
                                @if (isset($rec['gap']))
                                    <span style="font-size: 11px; color: var(--text-tertiary);">· gap {{ $rec['gap'] }} pt</span>
                                @endif
                            </div>
                            <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px;">{{ $rec['title'] ?? '' }}</p>
                            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.65;">{{ $rec['body'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-nui-modal>
    @endif

</div>
