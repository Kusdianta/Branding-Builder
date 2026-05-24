<?php

declare(strict_types=1);

use App\Jobs\AnalyzeBrand;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\CreditLedger;
use App\Services\HandleCheckers\InstagramHandleChecker;
use App\Services\HandleCheckers\TikTokHandleChecker;
use App\Services\HubCredentialsClient;
use App\Services\PlacesApiService;
use App\Services\PlatformHealthChecker;
use App\Services\Scoring\WebsiteLivenessScorer;
use App\Support\AuditLabels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nema\WorkerClient\NemaWorkerClient;
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
    // BB111 — secondary layanan multi-select. Variety count
    // (primary + secondaries deduped) feeds the Kelengkapan Layanan
    // sub-bucket in Brand Konsistensi.
    /** @var list<string> */
    public array $secondaryServiceTypes = [];
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

    // Phase 12c.2-rubric-alignment BB118 — surface wizard_version
    // on the dashboard so per-row source attribution can degrade
    // honestly for v1/v2 audits ("Sumber: tidak tersedia, audit
    // pra-rubrik") vs v3 audits ("Sumber: ...real attribution").
    public ?string $auditWizardVersion   = null;

    // Phase 8 BB29: GMaps reviews data for dashboard rendering.
    public array   $gmapsReviews         = [];
    public ?string $gmapsReviewsStatus   = null;

    // BB60: validation warning surface — populated from audit_evidence.validation
    // when ValidateEvidenceJob (BB53) wrote a low-confidence result.
    public bool   $hasValidationWarning = false;
    /** @var list<string> */
    public array  $validationWarnings   = [];
    public float  $validationConfidence = 1.0;

    // Phase 12c.4 FIX E — full audit_evidence array surfaced to the
    // dashboard so the target-skor card can read the LLM-generated
    // ``target_score_reasoning`` paragraphs.
    /** @var array<string,mixed> */
    public array  $auditEvidence        = [];

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

    // BB105 Part 3 — platform health probe populated by mount() + refreshed
    // by submit(). Shape mirrors PlatformHealthChecker::check() return type:
    //   ['healthy' => bool, 'services' => array<string,array{ok:bool,message:string,...}>, 'checked_at' => string]
    // Default healthy=true is intentional: prevents the soft banner from
    // flashing before mount() runs. The submit() gate force-refreshes the
    // cache so the gate is never decided from a stale value.
    /** @var array<string,mixed> */
    public array   $platformHealth = ['healthy' => true, 'services' => [], 'checked_at' => null];

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

    // BB102 — Step 3 WhatsApp Business number (Indonesian local part,
    // i.e. without +62 / 0 prefix). Normalized in updatedWhatsappNumber()
    // and surfaced as whatsapp_url / whatsapp_number / whatsapp_business_active
    // in deriveTouchpoints(). Optional; null when the operator skipped.
    public ?string $whatsappNumber    = null;

    // BB106 — Step 3 verification state, driven by Volt. Replaces the
    // BB100/BB102 Alpine state machines (step3Gate/igHandleChecker/
    // whatsappValidator/ttHandleChecker) that produced phantom
    // `checkFormat is not defined` errors on certain morph paths.
    //
    // IG / TT enum: idle | checking | found | not_found | error
    //   - idle      → never checked or field edited since last check
    //   - checking  → request in flight
    //   - found     → HandleChecker confirmed the profile exists
    //   - not_found → HandleChecker confirmed the profile does not exist
    //   - error     → worker unreachable, rate-limited, or unparseable
    //
    // whatsappValidity enum: idle | valid | invalid
    //   - format-only check (no worker call); recomputed in
    //     updatedWhatsappNumber() from the raw input BEFORE
    //     normalizeWhatsApp() collapses empty + invalid to null.
    public string $igCheckStatus    = 'idle';
    public string $ttCheckStatus    = 'idle';
    public string $whatsappValidity = 'idle';

    // BB130 — follower counts captured from the HandleChecker DTO on
    // a successful check, surfaced next to the "Ditemukan" badge so
    // operators can disambiguate near-duplicate handles before
    // committing to the audit. Reset to null whenever the handle
    // field is edited (the *Username updated hook flips the status
    // back to 'idle' and clears these alongside).
    public ?int $igFollowerCount = null;
    public ?int $ttFollowerCount = null;

    // BB135 — display name from the handle check, shown next to the badge so
    // the operator can confirm the right account (e.g. "Ion Laundry Surabaya").
    // Cleared alongside the follower counts on edit.
    public ?string $igDisplayName = null;
    public ?string $ttDisplayName = null;

    // BB138 — Step 3 website URL + liveness check.
    //
    // Renders below the WhatsApp input. The operator can paste any URL
    // (with or without scheme — normalised on check); we run a single
    // 5-second HEAD-with-GET-fallback via WebsiteLivenessScorer and
    // surface the result inline. When non-empty, $wizardWebsiteUrl
    // overrides $placeWebsite (Google Places auto-fill) in
    // deriveTouchpoints(); a blank value falls back to Places.
    //
    // Status enum: idle | checking | live | dead | error
    //   - idle      → never checked or field edited since last check
    //   - checking  → request in flight
    //   - live      → 2xx/3xx response within timeout
    //   - dead      → 4xx/5xx response or timeout (treated as Tidak aktif)
    //   - error     → transport/exception path (advisory, not blocking)
    public string $wizardWebsiteUrl       = '';
    public string $websiteCheckStatus     = 'idle';
    public ?int   $websiteCheckHttpStatus = null;
    public ?int   $websiteCheckResponseMs = null;
    public ?string $websiteCheckHost      = null;

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
        // BB137 — catalogue aligned with PPT laundry-segment taxonomy.
        // Old slugs (express/premium/campuran) retired from the wizard
        // but still accepted by the validator below for backwards-compat
        // with audits created before 2026-05-19. Slug `kiloan`/`satuan`/
        // `self_service` are preserved verbatim so existing rows + the
        // BrandAudit.service_type column don't need a data migration.
        ['slug' => 'kiloan',        'label' => 'Cuci Kiloan',           'icon' => '🧺', 'subtitle' => 'Per kg'],
        ['slug' => 'satuan',        'label' => 'Cuci Satuan',           'icon' => '👔', 'subtitle' => 'Per pakaian'],
        ['slug' => 'dry_cleaning',  'label' => 'Dry Cleaning',          'icon' => '🥼', 'subtitle' => 'Kain halus / jas'],
        ['slug' => 'cuci_sepatu',   'label' => 'Cuci Sepatu',           'icon' => '👟', 'subtitle' => 'Sneaker, sandal'],
        ['slug' => 'cuci_karpet',   'label' => 'Cuci Karpet/Bedcover',  'icon' => '🛋️', 'subtitle' => 'Karpet, bedcover, gorden'],
        ['slug' => 'self_service',  'label' => 'Self Service',          'icon' => '🪙', 'subtitle' => 'Laundry koin'],
    ];

    protected array $rules = [
        'brandName'              => 'required|string|max:100',
        'city'                   => 'nullable|string|max:100',
        // BB137 — service-type slugs realigned to the PPT laundry-segment
        // taxonomy. Old slugs (express/premium/campuran/mixed) retained
        // on the IN: rule so audits created before 2026-05-19 still
        // round-trip through editAndRerun(). New audits go through one
        // of the six canonical slugs in $availableServiceTypes.
        'serviceType'            => 'required|string|in:kiloan,satuan,dry_cleaning,cuci_sepatu,cuci_karpet,self_service,express,premium,campuran,mixed',
        // BB111/BB137 — secondary layanan multi-select. Each entry must
        // be a valid service slug; deduplication against the primary
        // slug happens at persistence time.
        'secondaryServiceTypes'   => 'nullable|array',
        'secondaryServiceTypes.*' => 'string|in:kiloan,satuan,dry_cleaning,cuci_sepatu,cuci_karpet,self_service,express,premium,campuran',
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
        // BB102 → Phase 12c.4 FIX 5 — Step 3 WhatsApp local part
        // (Indonesian mobile, no country code, no leading zero).
        // normalizeWhatsApp() strips formatting before the validator
        // sees it. WhatsApp is the de facto CTA for laundry brands;
        // making it required prevents the audit-without-WA situation
        // that produces a confusing 0/15 Digital Presence score the
        // operator can't explain.
        'whatsappNumber'         => 'required|string|regex:/^8\d{8,11}$/',
        // BB138 — Step 3 website URL input. Optional; when set,
        // overrides $placeWebsite in deriveTouchpoints().
        'wizardWebsiteUrl'       => 'nullable|url|max:500',
    ];

    protected array $messages = [
        'brandName.required'     => 'Nama brand wajib diisi.',
        'instagramUrl.url'       => 'Format URL Instagram tidak valid.',
        'websiteUrl.url'         => 'Format URL website tidak valid.',
        'tiktokUrl.url'          => 'Format URL TikTok tidak valid.',
        'gmapsUrl.url'           => 'Format URL Google Maps tidak valid.',
        'whatsappNumber.required'=> 'Nomor WhatsApp wajib diisi — wajib untuk skor Digital Presence.',
        'whatsappNumber.regex'   => 'Nomor WhatsApp tidak valid. Contoh: 8123456789.',
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
        // BB105 Part 3 — probe platform health on every wizard mount.
        // Cached for 60 s so back/forward navigation doesn't hammer the
        // worker, but submit() force-refreshes so the gate never fires
        // off a stale value.
        $this->checkPlatformHealth();

        if (! $token) {
            return;
        }

        $audit = BrandAudit::where('session_token', $token)->first();
        if (! $audit) {
            abort(404);
        }

        $this->loadAudit($audit);
    }

    /**
     * BB105 Part 3 — populate $platformHealth from PlatformHealthChecker.
     *
     * Shared cache key across users (the result is platform-wide, not
     * user-specific) so a single check per minute covers everyone. The
     * key is invalidated by submit() before the hard gate decision.
     */
    public function checkPlatformHealth(bool $force = false): void
    {
        // "Cek lagi" passes $force=true so the button actually re-probes
        // the platform instead of returning the same 60s-cached result
        // (the old button re-read the cache and looked broken).
        if ($force) {
            Cache::forget('platform-health');
        }

        $this->platformHealth = Cache::remember(
            'platform-health',
            60,
            fn () => app(PlatformHealthChecker::class)->check(),
        );
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
        $this->auditWizardVersion   = $audit->wizard_version;

        // BB144 — hydrate placeRaw so the dashboard's "Foto outlet"
        // strip can iterate place_raw.photos. Wizard Step 1 already
        // populates this property on the new-audit path; loadAudit()
        // covers the /audit/{token} entry where the wizard state is
        // hydrated from the DB row, not from selectPlace().
        $this->placeRaw = is_array($audit->place_raw) ? $audit->place_raw : null;

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
        // Phase 12c.4 FIX E — expose the entire evidence array so the
        // dashboard can read ``target_score_reasoning`` (and any
        // future evidence-backed surfaces) without an extra DB call.
        $this->auditEvidence = (array) ($audit->audit_evidence ?? []);

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

        // BB146 — hard-gate the reveal on the IG bonus audit too. A main
        // 'failed' always reveals; a main 'done' only reveals once the IG
        // audit has settled (terminal). While IG is still pending/scraped we
        // stay on the analyzing screen (which keeps polling), so the user
        // never sees a result page with a never-resolving "masih dalam
        // proses" IG banner. GeneratePdfJob coerces a stuck IG status to a
        // terminal failure before it flips to 'done', so this can't hang.
        $this->step = match (true) {
            $audit->status === 'failed'                                     => 'dashboard',
            $audit->status === 'done' && $audit->instagramAuditIsTerminal() => 'dashboard',
            default                                                         => 'analyzing',
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
        // BB106 — any edit invalidates the previous check result.
        $this->igCheckStatus    = 'idle';
        $this->igFollowerCount  = null;
        $this->igDisplayName    = null;
    }

    public function updatedTiktokUsername(): void
    {
        $this->tiktokUsername = $this->normalizeUsername($this->tiktokUsername, 'tiktok');
        // BB106 — any edit invalidates the previous check result.
        $this->ttCheckStatus    = 'idle';
        $this->ttFollowerCount  = null;
        $this->ttDisplayName    = null;
    }

    /**
     * BB138 — any edit to the website URL invalidates the previous
     * check result. The operator must re-click "Cek dulu" before the
     * dashboard reads the live/dead state.
     */
    public function updatedWizardWebsiteUrl(): void
    {
        $this->websiteCheckStatus     = 'idle';
        $this->websiteCheckHttpStatus = null;
        $this->websiteCheckResponseMs = null;
        $this->websiteCheckHost       = null;
    }

    /**
     * BB102 — strip non-digits, then strip leading 0 / 62 / +62. The
     * result is stored as the local Indonesian mobile part (starts
     * with 8). Empty / invalid input becomes null so downstream
     * derivation knows the field was not provided.
     *
     * Validation pattern enforced here is intentionally permissive
     * (8 + 8–11 more digits). wa.me's own resolver is the ultimate
     * authority on whether the number is on WhatsApp; we only filter
     * obvious garbage so the UI doesn't show a wa.me link to nowhere.
     */
    public function updatedWhatsappNumber(): void
    {
        // BB106 — distinguish empty (idle) from invalid (invalid) BEFORE
        // normalizeWhatsApp() collapses both to null. Without this peek
        // the gate cannot tell "operator left WA blank → allowed" apart
        // from "operator typed 12345 → blocked".
        $rawDigits = preg_replace('/[^\d]/', '', (string) $this->whatsappNumber) ?? '';

        $this->whatsappNumber   = $this->normalizeWhatsApp($this->whatsappNumber);
        $this->whatsappValidity = match (true) {
            $rawDigits === ''               => 'idle',
            $this->whatsappNumber !== null  => 'valid',
            default                         => 'invalid',
        };
    }

    private function normalizeWhatsApp(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', $input);
        if ($digits === null || $digits === '') {
            return null;
        }
        // Strip leading 0, 62, or +62 (the + was already dropped above).
        $digits = preg_replace('/^(?:62|0)/', '', $digits) ?? $digits;

        if (! preg_match('/^8\d{8,11}$/', $digits)) {
            return null;
        }
        return $digits;
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
     * BB106 — explicit Instagram handle verification, triggered by the
     * Step 3 "Cek dulu" button. Pure server-side roundtrip (no Alpine);
     * InstagramHandleChecker owns the HTTP scrape, parsing, and caching.
     *
     * No-op when the field is empty so a stray button click during a
     * Livewire morph cannot flip status off 'idle'.
     *
     * @deprecated BB136 — the wizard's bottom button calls checkAllHandles()
     *   now. Retained for WizardHandleGateTest per-platform coverage.
     */
    public function checkInstagram(InstagramHandleChecker $checker): void
    {
        if (! $this->instagramUsername) {
            $this->igCheckStatus   = 'idle';
            $this->igFollowerCount = null;
            $this->igDisplayName   = null;
            return;
        }
        $this->igCheckStatus   = 'checking';
        $this->igFollowerCount = null;
        $this->igDisplayName   = null;
        $result = $checker->check($this->instagramUsername);
        $this->igCheckStatus   = $this->mapHandleStatus($result->exists, $result->status);
        // BB130 — only retain follower count on a clean 'found'; for
        // 'not_found' / 'error' the DTO carries null anyway, but
        // forcing null here keeps the view branch tidy.
        $this->igFollowerCount = $this->igCheckStatus === 'found' ? $result->followerCount : null;
        $this->igDisplayName   = $this->igCheckStatus === 'found' ? $result->displayName : null;
    }

    /**
     * @deprecated BB136 — the wizard's bottom button calls checkAllHandles()
     *   now. Retained for WizardHandleGateTest per-platform coverage.
     */
    public function checkTiktok(TikTokHandleChecker $checker): void
    {
        if (! $this->tiktokUsername) {
            $this->ttCheckStatus   = 'idle';
            $this->ttFollowerCount = null;
            $this->ttDisplayName   = null;
            return;
        }
        $this->ttCheckStatus   = 'checking';
        $this->ttFollowerCount = null;
        $this->ttDisplayName   = null;
        $result = $checker->check($this->tiktokUsername);
        $this->ttCheckStatus   = $this->mapHandleStatus($result->exists, $result->status);
        $this->ttFollowerCount = $this->ttCheckStatus === 'found' ? $result->followerCount : null;
        $this->ttDisplayName   = $this->ttCheckStatus === 'found' ? $result->displayName : null;
    }

    /**
     * BB135 — unified "Cek dulu" handler. Checks Instagram and TikTok in ONE
     * click. When healthy credentials exist for the relevant platforms, both
     * run in PARALLEL through the worker's /v1/handles/check-both (≈ the time
     * of the slower one); platforms without a credential fall back to their
     * per-platform checker (IG anonymous probe; TikTok legacy oembed).
     *
     * No-op when both fields are empty so a stray morph-time click can't flip
     * a status off 'idle'.
     */
    public function checkBothHandles(
        HubCredentialsClient $hub,
        NemaWorkerClient $worker,
        InstagramHandleChecker $igChecker,
        TikTokHandleChecker $ttChecker,
    ): void {
        $ig = $this->instagramUsername;
        $tt = $this->tiktokUsername;
        if (! $ig && ! $tt) {
            return;
        }

        if ($ig) {
            $this->igCheckStatus = 'checking';
            $this->igFollowerCount = null;
            $this->igDisplayName = null;
        }
        if ($tt) {
            $this->ttCheckStatus = 'checking';
            $this->ttFollowerCount = null;
            $this->ttDisplayName = null;
        }

        // Claim cookies for the parallel worker path (best-effort).
        $igCookies = $ig ? $this->claimHandleCookies($hub, 'instagram') : null;
        $ttCookies = $tt ? $this->claimHandleCookies($hub, 'tiktok') : null;

        $igViaWorker = $ig && is_array($igCookies);
        $ttViaWorker = $tt && is_array($ttCookies);

        if ($igViaWorker || $ttViaWorker) {
            try {
                $res = $worker->checkBothHandles(
                    $igViaWorker ? $ig : null,
                    $igViaWorker ? $igCookies : null,
                    $ttViaWorker ? $tt : null,
                    $ttViaWorker ? $ttCookies : null,
                );
                if ($igViaWorker) {
                    $this->applyHandleResult('ig', $res['instagram'] ?? null);
                }
                if ($ttViaWorker) {
                    $this->applyHandleResult('tt', $res['tiktok'] ?? null);
                }
            } catch (\Throwable $e) {
                Log::info('checkBothHandles worker call failed; per-platform fallback', [
                    'error' => $e->getMessage(),
                ]);
                $igViaWorker = false;
                $ttViaWorker = false;
            }
        }

        // Per-platform fallback for whatever the worker didn't resolve.
        if ($ig && ! $igViaWorker) {
            $r = $igChecker->check($ig);
            $this->igCheckStatus   = $this->mapHandleStatus($r->exists, $r->status);
            $this->igFollowerCount = $this->igCheckStatus === 'found' ? $r->followerCount : null;
            $this->igDisplayName   = $this->igCheckStatus === 'found' ? $r->displayName : null;
        }
        if ($tt && ! $ttViaWorker) {
            $r = $ttChecker->check($tt);
            $this->ttCheckStatus   = $this->mapHandleStatus($r->exists, $r->status);
            $this->ttFollowerCount = $this->ttCheckStatus === 'found' ? $r->followerCount : null;
            $this->ttDisplayName   = $this->ttCheckStatus === 'found' ? $r->displayName : null;
        }
    }

    /**
     * Apply one normalized worker check-both result (shape:
     * ['status','exists','display_name','follower_count', ...]) to the ig/tt
     * wizard state.
     *
     * @param array<string,mixed>|null $r
     */
    private function applyHandleResult(string $platform, ?array $r): void
    {
        $status = is_array($r) ? (string) ($r['status'] ?? 'error') : 'error';
        $mapped = match ($status) {
            'found'     => 'found',
            'not_found' => 'not_found',
            default     => 'error',
        };
        $followers = ($mapped === 'found' && is_array($r)) ? ($r['follower_count'] ?? null) : null;
        $name      = ($mapped === 'found' && is_array($r)) ? ($r['display_name'] ?? null) : null;

        if ($platform === 'ig') {
            $this->igCheckStatus   = $mapped;
            $this->igFollowerCount = $followers !== null ? (int) $followers : null;
            $this->igDisplayName   = $name !== null ? (string) $name : null;
        } else {
            $this->ttCheckStatus   = $mapped;
            $this->ttFollowerCount = $followers !== null ? (int) $followers : null;
            $this->ttDisplayName   = $name !== null ? (string) $name : null;
        }
    }

    /**
     * Claim a healthy session's cookies for $platform from the Hub. Returns
     * null (→ caller uses the per-platform fallback) when no credential is
     * available or the Hub call fails.
     *
     * @return list<array<string,mixed>>|null
     */
    private function claimHandleCookies(HubCredentialsClient $hub, string $platform): ?array
    {
        try {
            $cred = $hub->getNextCredential($platform);
        } catch (\Throwable $e) {
            Log::info('checkBothHandles: Hub credential fetch failed', [
                'platform' => $platform,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
        if (! is_array($cred)) {
            return null;
        }
        $cookies = $cred['session_cookies'] ?? null;
        return (is_array($cookies) && $cookies !== []) ? $cookies : null;
    }

    /**
     * BB138 — "Cek dulu" handler for the Step 3 website URL input.
     *
     * Normalises the URL (prepends https:// when scheme is missing),
     * runs WebsiteLivenessScorer::check() (single 5-second HTTP probe),
     * and stores the human-readable bits the wizard view needs. The
     * downstream ScorePillarsJob re-runs the same check against
     * touchpoints.website_url at scoring time — the wizard probe is
     * UX feedback only, not the score source of truth (otherwise a
     * site that flickered down at submit would land a stale 'live'
     * read on the dashboard).
     */
    public function checkWebsite(WebsiteLivenessScorer $scorer): void
    {
        $raw = trim($this->wizardWebsiteUrl);
        if ($raw === '') {
            $this->websiteCheckStatus     = 'idle';
            $this->websiteCheckHttpStatus = null;
            $this->websiteCheckResponseMs = null;
            $this->websiteCheckHost       = null;
            return;
        }

        // Prepend https:// when missing. The Volt validation rule below
        // requires a scheme; this hook lets operators paste "foo.com".
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
            $this->wizardWebsiteUrl = $raw;
        }

        $this->websiteCheckStatus = 'checking';
        try {
            $result = $scorer->check($raw);
        } catch (\Throwable $e) {
            $this->websiteCheckStatus = 'error';
            return;
        }

        $evidence = (array) ($result['evidence'] ?? []);
        $this->websiteCheckHttpStatus = isset($evidence['http_status']) ? (int) $evidence['http_status'] : null;
        $this->websiteCheckResponseMs = isset($evidence['response_time_ms']) ? (int) $evidence['response_time_ms'] : null;
        $this->websiteCheckHost       = parse_url($raw, PHP_URL_HOST) ?: $raw;

        $this->websiteCheckStatus = ($result['is_live'] ?? false) ? 'live' : 'dead';
    }

    /**
     * BB136 — single bottom "Cek semua handle" handler. Verifies Instagram +
     * TikTok + Website in ONE click by composing the existing checks:
     *   - IG + TikTok via checkBothHandles() (parallel worker path when
     *     credentials exist, per-platform fallback when they don't).
     *   - Website via checkWebsite() (one HTTP liveness probe).
     *
     * Each sub-check only runs for a filled field, and a failure in one does
     * not abort the others — checkBothHandles isolates worker errors and the
     * website probe is independent. No-op when all three fields are empty so
     * a stray morph-time click can't flip a status off 'idle'.
     *
     * Website stays ADVISORY (BB138): a dead/unreachable site shows its badge
     * but does NOT block Lanjutkan — canAdvanceFromStep3 ignores website
     * state. Only IG ('found') and WhatsApp ('valid') gate advancement.
     */
    public function checkAllHandles(
        HubCredentialsClient $hub,
        NemaWorkerClient $worker,
        InstagramHandleChecker $igChecker,
        TikTokHandleChecker $ttChecker,
        WebsiteLivenessScorer $webScorer,
    ): void {
        $hasHandle  = $this->instagramUsername || $this->tiktokUsername;
        $hasWebsite = trim((string) $this->wizardWebsiteUrl) !== '';
        if (! $hasHandle && ! $hasWebsite) {
            return;
        }

        if ($hasHandle) {
            $this->checkBothHandles($hub, $worker, $igChecker, $ttChecker);
        }
        if ($hasWebsite) {
            $this->checkWebsite($webScorer);
        }
    }

    /**
     * BB106 — collapse the DTO (exists, status) pair into the Volt enum.
     * Mirrors the old Alpine fetchHandle() resolver: only a clean
     * exists=true + status='found' clears the gate; everything else
     * blocks (not_found = correct rejection; error = transport failure).
     */
    private function mapHandleStatus(bool $exists, string $status): string
    {
        if ($exists && $status === 'found') {
            return 'found';
        }
        if ($status === 'not_found') {
            return 'not_found';
        }
        return 'error';
    }

    /**
     * BB106 — "Lewati semua" path. Nulls all three Step 3 fields so
     * the gate is satisfied (all-empty always passes) before calling
     * nextStep(). Separate from nextStep() because nextStep() honours
     * the gate; this method intentionally bypasses by emptying first.
     */
    public function skipStep3(): void
    {
        $this->instagramUsername = null;
        $this->tiktokUsername    = null;
        $this->whatsappNumber    = null;
        $this->igCheckStatus     = 'idle';
        $this->ttCheckStatus     = 'idle';
        $this->whatsappValidity  = 'idle';
        $this->igFollowerCount   = null;
        $this->ttFollowerCount   = null;
        $this->igDisplayName     = null;
        $this->ttDisplayName     = null;
        // BB138 — also clear the website override so "Lewati semua"
        // falls back to Places.website (the GMaps-derived URL) instead
        // of carrying a half-typed string into deriveTouchpoints().
        $this->wizardWebsiteUrl       = '';
        $this->websiteCheckStatus     = 'idle';
        $this->websiteCheckHttpStatus = null;
        $this->websiteCheckResponseMs = null;
        $this->websiteCheckHost       = null;
        $this->nextStep();
    }

    /**
     * BB106 — Step 3 gate. Empty fields always pass (opt-out); filled
     * fields must have been explicitly verified. The :disabled binding
     * on the Lanjutkan button uses this; validateCurrentWizardStep()
     * uses it as the server-side guard so a stray morph cannot bypass.
     */
    public function getCanAdvanceFromStep3Property(): bool
    {
        // BB135 — `error` (rate-limit / CAPTCHA) is treated as advisory,
        // not blocking. IG handle still gates on `found` because the
        // downstream IG audit needs a confirmed username; TikTok is
        // bonus-only (+10 Digital Presence) so an unverified TT handle
        // is allowed through (it just won't earn the bonus).
        if ($this->instagramUsername && $this->igCheckStatus !== 'found') return false;
        if ($this->tiktokUsername    && ! in_array($this->ttCheckStatus, ['found', 'error'], true)) return false;
        // BB106 — gate on validity, not field presence. normalizeWhatsApp()
        // collapses both empty and malformed input to null; without this
        // check, typing "12345" silently passes the gate because
        // whatsappNumber goes back to null after the hook runs.
        if ($this->whatsappValidity === 'invalid') return false;
        return true;
    }

    /**
     * BB106 — single muted hint string below the Lanjutkan button.
     * Returns null when the gate is satisfied. Centralises the
     * "why is the button disabled" copy in one ordered branch so the
     * Blade has no inline @if ladder.
     */
    public function getStep3BlockReasonProperty(): ?string
    {
        if ($this->igCheckStatus === 'checking' || $this->ttCheckStatus === 'checking') {
            return 'Sebentar, sedang mengecek...';
        }
        // BB136 — one bottom "Cek semua" button now triggers every check, so
        // the per-field "Cek dulu pada X" hints collapse into one consistent
        // prompt that matches the button label.
        if (($this->instagramUsername && $this->igCheckStatus === 'idle')
            || ($this->tiktokUsername && $this->ttCheckStatus === 'idle')) {
            return 'Klik "Cek semua" dulu sebelum lanjut.';
        }
        if ($this->igCheckStatus === 'not_found' || $this->ttCheckStatus === 'not_found') {
            return 'Periksa lagi handle yang ditandai merah.';
        }
        // BB135 — IG `error` still blocks (downstream audit requires
        // a confirmed username). TikTok `error` is advisory only:
        // canAdvanceFromStep3 lets it through, so the gate hint stays
        // silent for TT errors and only fires for IG.
        if ($this->igCheckStatus === 'error') {
            return 'Cek Instagram sedang dibatasi. Coba lagi sebentar — kalau masih gagal, kosongkan field-nya untuk lanjut.';
        }
        if ($this->whatsappValidity === 'invalid') {
            return 'Format nomor WhatsApp belum valid.';
        }
        return null;
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
        // BB106 — Step 3 server-side mirror of $canAdvanceFromStep3.
        // The skipStep3() path nulls the fields first so the gate
        // passes naturally; this branch only fires when the operator
        // hits Lanjutkan with an unverified handle or invalid WA.
        if ($this->wizardStep === 3 && ! $this->canAdvanceFromStep3) {
            // No addError() — the inline $step3BlockReason hint in the
            // partial already explains what's wrong.
            return false;
        }
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
            $this->redirect(route('login'));
            return;
        }

        // BB105 Part 3 — hard health gate. Force a fresh probe (never
        // accept a cached value for the submit decision) so we don't
        // dispatch an AnalyzeBrand job into a dead worker / queue.
        Cache::forget('platform-health');
        $this->checkPlatformHealth();
        if (! ($this->platformHealth['healthy'] ?? false)) {
            $this->dispatch(
                'show-platform-unhealthy-modal',
                services: $this->platformHealth['services'] ?? [],
            );
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
                // BB111/BB112 — stamp v3 so the dashboard knows the
                // touchpoints JSON carries service_types + operational
                // blocks and renders per-row source attribution per
                // the BB118 rubric-alignment contract.
                'wizard_version'  => BrandAudit::WIZARD_V3,

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

        // BB102 — whatsapp_business_active is derived from whatsappNumber
        // presence so existing scorers (DigitalPresenceScorer.has_wa,
        // KonsistensiScorer.kehadiran_digital) award their WA points when
        // the operator filled the new Step 3 field. whatsapp_url is the
        // canonical wa.me deeplink consumed by recommendation copy and
        // the activation kit PDF; whatsapp_number is the E.164-ish form
        // (62 + local part, no plus sign) for log/export use.
        $whatsappE164 = $this->whatsappNumber ? '62' . $this->whatsappNumber : null;
        $whatsappUrl  = $whatsappE164 ? 'https://wa.me/' . $whatsappE164 : null;

        // BB111 — derive service_types block. Secondaries are deduped
        // against the primary so variety_count never double-counts.
        $secondaries  = array_values(array_unique(array_filter(
            $this->secondaryServiceTypes,
            fn ($slug) => is_string($slug) && $slug !== '' && $slug !== $this->serviceType,
        )));
        $varietyCount = count(array_unique([$this->serviceType, ...$secondaries]));

        // BB138 — operator-supplied website URL (Step 3) overrides the
        // Places auto-fill. Trim + null-coalesce so an empty string
        // doesn't masquerade as a populated website signal.
        $wizardWebsite = trim($this->wizardWebsiteUrl);
        $websiteUrl    = $wizardWebsite !== '' ? $wizardWebsite : ($this->placeWebsite ?: null);

        // Phase 12c.4 FIX D — persist the TikTok verification result so
        // the scorer can read it. The wizard's own checker (oembed)
        // already confirmed the handle exists when ttCheckStatus is
        // 'found'; that signal was lost downstream because the
        // BrandAudit model has no tiktok_check_status column. Storing
        // the boolean on touchpoints (a JSON column) avoids a
        // migration and matches the BB102 whatsapp_business_active
        // pattern.
        $tiktokVerified = $this->tiktokUsername !== null
            && $this->tiktokUsername !== ''
            && ($this->ttCheckStatus ?? null) === 'found';

        return [
            'gmaps_url'                => $gmapsUrl,
            'instagram_url'            => $instagramUrl,
            'tiktok_url'               => $tiktokUrl,
            'tiktok_verified'          => $tiktokVerified,
            'website_url'              => $websiteUrl,
            'whatsapp_url'             => $whatsappUrl,
            'whatsapp_number'          => $whatsappE164,
            'whatsapp_business_active' => $whatsappUrl !== null,
            'outlet_photo_paths'       => [],
            'outlet_photo_outer_paths' => [],
            'outlet_photo_inner_paths' => [],

            // BB111 — service-type variety. Consumed by Kelengkapan
            // Layanan (Brand Konsistensi) and the +15 variety bonus in
            // Brand Experience.
            'service_types' => [
                'primary'       => $this->serviceType,
                'secondary'     => $secondaries,
                'variety_count' => $varietyCount,
            ],

            // BB112 — operator-declared operational signals. Verified
            // downstream against scraped review keywords + price-list
            // detection. Each is a user-declared bool only; scoring
            // attribution clearly tags Sumber as "deklarasi operator".
            'operational' => [
                'express_service' => (bool) $this->declEkspres,
                'pickup_delivery' => (bool) $this->declAntarJemput,
                'complaint_sop'   => (bool) $this->declSopKeluhan,
                'price_list'      => (bool) $this->declPriceList,
            ],
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
        // BB146 — keep polling until the reveal gate opens: a main 'failed',
        // or a main 'done' AND the IG bonus audit has settled (terminal).
        // Without the IG check, a 'done' main status while IG is still
        // pending/scraped would stop polling and strand the analyzing screen.
        $igTerminal = ! in_array(
            (string) ($this->instagramAuditStatus ?? 'pending'),
            BrandAudit::INSTAGRAM_NON_TERMINAL_STATUSES,
            true,
        );
        $gateOpen = $this->auditStatus === 'failed'
            || ($this->auditStatus === 'done' && $igTerminal);

        if ($gateOpen || ! $this->auditId) {
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

        // Phase 12c.2 BB113/BB114: single source-of-truth for sub-bucket
        // labels lives in App\Support\AuditLabels. The Blade still references
        // $subBucketLabels for backwards-compat with existing partials.
        $subBucketLabels = AuditLabels::SUB_BUCKET;

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
                        <a href="{{ route('login') }}" class="signin">
                            <i class="ti ti-brand-google"></i> Masuk dengan Google
                        </a>
                    </div>
                @else
                    {{-- BB105 Part 3 — soft warning banner. Non-blocking;
                         user can keep filling the wizard. submit() will
                         hard-gate via the modal below if still unhealthy. --}}
                    @if (! ($platformHealth['healthy'] ?? true))
                        <div class="bb-warning-banner" style="margin-bottom: 16px; padding: 12px 16px; background: #FFF7ED; border: 1px solid #FED7AA; border-left: 4px solid var(--color-warning); border-radius: var(--radius-md); color: var(--text-primary); font-size: 13px; line-height: 1.5;">
                            <strong>⚠ Sistem belum sepenuhnya siap.</strong>
                            {{-- Name the actual failing service(s) instead of a
                                 generic "worker tidak aktif" — usually it's the
                                 queue worker, not the scraping worker. --}}
                            @foreach (($platformHealth['services'] ?? []) as $svc)
                                @if (! ($svc['ok'] ?? true))
                                    {{ $svc['message'] }}
                                @endif
                            @endforeach
                            <button type="button" wire:click="checkPlatformHealth(true)" style="margin-left: 8px; background: none; border: none; color: var(--text-link); text-decoration: underline; cursor: pointer; padding: 0; font-size: 13px;">
                                Cek lagi
                            </button>
                        </div>
                    @endif

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

        {{-- BB105 Part 3 — platform-unhealthy modal. Alpine-driven, listens
             for the Livewire `show-platform-unhealthy-modal` event dispatched
             by submit() when the hard gate fails. Renders the per-service
             rows from $event.detail.services. "Coba Lagi" closes the modal
             and re-runs checkPlatformHealth(); user can then retry submit. --}}
        <div
            x-data="{ show: false, services: {} }"
            x-on:show-platform-unhealthy-modal.window="show = true; services = $event.detail.services || {}"
            x-show="show"
            x-cloak
            class="fixed inset-0 z-40 flex items-center justify-center p-6"
            style="background: rgba(15,20,17,0.55);"
            @click.self="show = false"
        >
            <div class="nui-card max-w-md w-full p-8" style="position: relative;">
                <button type="button" @click="show = false" style="position: absolute; top: 12px; right: 14px; background: none; border: none; color: var(--text-tertiary); font-size: 22px; cursor: pointer; line-height: 1;" aria-label="Tutup">
                    <i class="ti ti-x"></i>
                </button>

                <div style="width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; background: var(--surface-muted); display: flex; align-items: center; justify-content: center;">
                    <i class="ti ti-server-off" style="font-size: 28px; color: var(--color-danger);"></i>
                </div>

                <h2 style="font-size: 20px; font-weight: 600; color: var(--text-primary); text-align: center;">Audit tidak bisa dimulai sekarang</h2>
                <p style="font-size: 14px; color: var(--text-secondary); margin-top: 8px; line-height: 1.6; text-align: center;">
                    Ada layanan platform yang sedang tidak aktif. Cek di bawah:
                </p>

                <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <template x-for="(svc, name) in services" :key="name">
                        <div style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; background: var(--surface-muted); border-radius: var(--radius-sm);"
                             :style="svc.ok ? 'border-left: 3px solid var(--color-success)' : 'border-left: 3px solid var(--color-danger)'">
                            <span x-text="svc.ok ? '✓' : '✗'"
                                  :style="svc.ok ? 'color: var(--color-success); font-weight: 600;' : 'color: var(--color-danger); font-weight: 600;'"></span>
                            <div style="flex: 1; font-size: 13px;">
                                <strong x-text="name" style="text-transform: capitalize; color: var(--text-primary); display: block;"></strong>
                                <span x-text="svc.message" style="color: var(--text-secondary);"></span>
                            </div>
                        </div>
                    </template>
                </div>

                <p style="font-size: 12px; color: var(--text-tertiary); margin-top: 16px; line-height: 1.55;">
                    Jalankan <code style="background: var(--surface-muted); padding: 2px 6px; border-radius: 4px; font-family: var(--font-mono);">composer dev</code> di folder <code style="background: var(--surface-muted); padding: 2px 6px; border-radius: 4px; font-family: var(--font-mono);">branding-builder</code> untuk start semua worker. Setelah itu klik Coba Lagi.
                </p>

                <button
                    type="button"
                    @click="show = false; $wire.checkPlatformHealth()"
                    class="nui-btn-primary rounded-pill"
                    style="margin-top: 20px; font-size: 14px; padding: 10px 20px; width: 100%;"
                >
                    Coba Lagi
                </button>
            </div>
        </div>
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
                        href="{{ route('login') }}"
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
            // BB134 — the previous build grouped $auditSteps by track
            // ($trackLabels: gather / analyze / validate / score / final)
            // and rendered five cards. The hero badge above already
            // surfaces the active phase in plain copy, so the detailed
            // breakdown collapses to a single flat list and the track
            // grouping is dead code.
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

            // BB146 — keep polling until the reveal gate opens (main 'failed',
            // or main 'done' AND the IG bonus audit settled). FQN const —
            // @php blocks can't `use`. Mirrors pollStatus()'s server-side gate.
            $bbIgTerminal = ! in_array(
                (string) ($instagramAuditStatus ?? 'pending'),
                \App\Models\BrandAudit::INSTAGRAM_NON_TERMINAL_STATUSES,
                true,
            );
            $bbGateOpen = $auditStatus === 'failed'
                || ($auditStatus === 'done' && $bbIgTerminal);
        @endphp
        <div @if (! $bbGateOpen) wire:poll.2000ms="pollStatus" @endif class="max-w-3xl mx-auto py-12">
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

            {{-- BB136 — progress bar + single status line.
                 Replaces the BB134 15-step checklist. Operators were
                 confused by raw pipeline step names ("extract_service_signals",
                 "score_konsistensi") that didn't tell them how close
                 they were to a result. The pomelli hero already names
                 WHAT we're doing; this block answers HOW FAR. Percentage
                 is derived from done-count / total-count; running steps
                 don't add a partial credit (overstates progress when a
                 long LLM call is mid-flight). Retry control is preserved
                 below the bar — only renders when a retryable gather
                 step failed, so the happy path stays minimal. --}}
            @php
                $totalSteps      = max(1, count($auditSteps));
                $doneSteps       = 0;
                $runningStep     = null;
                $failedGather    = null;
                foreach ($auditSteps as $s) {
                    if ($s['status'] === 'done') {
                        $doneSteps++;
                    }
                    if ($s['status'] === 'running' && $runningStep === null) {
                        $runningStep = $s;
                    }
                    if ($s['status'] === 'failed' && in_array($s['key'], ['gather_gmaps', 'gather_instagram'], true) && $failedGather === null) {
                        $failedGather = $s;
                    }
                }
                $progressPct = (int) round(($doneSteps / $totalSteps) * 100);
                // The status line prefers the running step's plain-language
                // label. When nothing is running (between batches, or at
                // 100%), it falls back to the most recent done step.
                $statusKey = $runningStep['key']
                    ?? ($pomelliLastDoneStep['key'] ?? null);
                $statusLine = $statusKey !== null
                    ? ($stepLabels[$statusKey] ?? 'Menganalisis…')
                    : 'Mempersiapkan pipeline…';
                if ($auditStatus === 'done') {
                    $statusLine  = 'Selesai. Mengarahkan ke hasil…';
                    $progressPct = 100;
                }
            @endphp
            <div style="max-width: 480px; margin: 32px auto 0; padding: 0 16px;">
                <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;">
                    <span style="font-size: 13px; color: var(--text-primary); font-weight: 500; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $statusLine }}</span>
                    <span style="font-size: 12px; color: var(--text-secondary); font-variant-numeric: tabular-nums; margin-left: 12px; flex-shrink: 0;">{{ $progressPct }}%</span>
                </div>
                <div style="height: 8px; border-radius: var(--radius-pill); background: var(--surface-muted); overflow: hidden;" role="progressbar" aria-valuenow="{{ $progressPct }}" aria-valuemin="0" aria-valuemax="100">
                    <div style="height: 100%; width: {{ $progressPct }}%; background: linear-gradient(90deg, var(--chimera-500), var(--chimera-600)); transition: width 600ms ease-out;"></div>
                </div>

                @if ($failedGather)
                    <div style="margin-top: 18px; padding: 12px 14px; border-radius: var(--radius-md); background: var(--surface-card); border: 1px solid var(--color-danger); display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                        <p style="font-size: 12px; color: var(--text-primary); margin: 0; line-height: 1.5;">
                            <strong>{{ $stepLabels[$failedGather['key']] ?? $failedGather['key'] }}</strong> gagal. Saya bisa coba lagi tanpa menyentuh skor lainnya.
                        </p>
                        <button
                            type="button"
                            wire:click="retryStep('{{ $failedGather['key'] }}')"
                            wire:loading.attr="disabled"
                            style="font-size: 11px; padding: 6px 12px; border: 1px solid var(--color-danger); color: var(--color-danger); border-radius: var(--radius-pill); background: var(--surface-card); flex-shrink: 0; cursor: pointer;"
                        >
                            Coba lagi
                        </button>
                    </div>
                @endif
            </div>
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
                    {{-- BB138 Chart 1 — overall score arc gauge (replaces the plain ring). --}}
                    @include('livewire.charts._score-gauge')
                    @if ($overallLabel)
                        <p style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin-top: 16px;">
                            {{ $overallLabel }}
                        </p>
                    @endif
                    {{-- BB145 — explicit tier badge under the overall score
                         ring. $overallLabel already carries a tier string
                         (e.g. "EXCELLENT — Brand Kuat & Terpercaya") but
                         operators wanted the PPT's 5-tier vocabulary surfaced
                         consistently across overall + pillar headers. --}}
                    @if ($overallScore !== null)
                        @php
                            $overallTier        = AuditLabels::pillarTier((int) $overallScore);
                            $overallTierVariant = AuditLabels::pillarTierVariant((int) $overallScore);
                        @endphp
                        <div style="margin-top: 10px;">
                            <span class="bb-tier-badge bb-tier-badge--{{ $overallTierVariant }}" style="font-size: 12px;">{{ $overallTier }}</span>
                        </div>
                    @endif
                </div>

                {{-- ===== Phase 12c.2 BB111: Pillar breakdown table =====
                     4-row compact table under the overall score circle so
                     the pillar distribution is visible at-a-glance before
                     the user scrolls into the per-pillar sub-bucket detail
                     cards. Mobile (< 640px) collapses into stacked cards. --}}
                {{-- BB141 — Rincian Skor table + Profil Skor Pilar radar
                     rendered side by side (50/50 on desktop, stacking
                     < 768px). Both blocks read the same pillar-score data so
                     they render together; .pillar-summary-grid (see
                     wizard.css) neutralizes each child's own
                     max-w-3xl / mx-auto / mb-12 so it fills its grid column. --}}
                @if (count($pillarScoreInts) > 0)
                    <div class="pillar-summary-grid max-w-5xl mx-auto mb-12">
                        <div class="brand-health-table">
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">Rincian Skor</p>

                        {{-- Desktop / tablet: table layout --}}
                        <table class="pillar-table" style="width: 100%; border-collapse: collapse; background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-md); overflow: hidden;">
                            <thead>
                                <tr style="background: var(--surface-muted);">
                                    <th style="text-align: left; font-size: 11px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 14px;">Pilar</th>
                                    <th style="text-align: right; font-size: 11px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 14px;">Bobot</th>
                                    <th style="text-align: right; font-size: 11px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 14px;">Skor</th>
                                    <th style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); text-transform: uppercase; letter-spacing: 0.4px; padding: 10px 14px; width: 38%;">Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pillarMeta as $pillarSlug => $pillarMetaRow)
                                    @php
                                        $rowScore  = (int) ($pillarScoreInts[$pillarSlug] ?? 0);
                                        $rowWeight = AuditLabels::pillarWeight($pillarSlug);
                                        $rowColor  = AuditLabels::pillarColor($pillarSlug);
                                    @endphp
                                    <tr style="border-top: 1px solid var(--border-default);">
                                        <td style="padding: 12px 14px; font-size: 13px; font-weight: 500; color: var(--text-primary);">{{ $pillarMetaRow['label'] }}</td>
                                        <td style="padding: 12px 14px; text-align: right; font-size: 12px; color: var(--text-secondary);">{{ $rowWeight }}%</td>
                                        <td style="padding: 12px 14px; text-align: right; font-size: 14px; font-weight: 700; color: var(--text-primary);">{{ $rowScore }}</td>
                                        <td style="padding: 12px 14px;">
                                            <div style="height: 8px; background: var(--surface-muted); border-radius: var(--radius-pill); overflow: hidden;">
                                                <div style="height: 100%; width: {{ max(0, min(100, $rowScore)) }}%; background: {{ $rowColor }}; border-radius: var(--radius-pill); transition: width .4s ease;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        {{-- Mobile (< 640px): stacked card grid --}}
                        <div class="pillar-table-mobile" style="display: none; flex-direction: column; gap: 10px;">
                            @foreach ($pillarMeta as $pillarSlug => $pillarMetaRow)
                                @php
                                    $rowScore  = (int) ($pillarScoreInts[$pillarSlug] ?? 0);
                                    $rowWeight = AuditLabels::pillarWeight($pillarSlug);
                                    $rowColor  = AuditLabels::pillarColor($pillarSlug);
                                @endphp
                                <div style="background: var(--surface-card); border: 1px solid var(--border-default); border-radius: var(--radius-md); padding: 12px 14px;">
                                    <div class="flex items-baseline justify-between" style="margin-bottom: 6px;">
                                        <span style="font-size: 13px; font-weight: 600; color: var(--text-primary);">{{ $pillarMetaRow['label'] }}</span>
                                        <span style="font-size: 11px; color: var(--text-tertiary);">Bobot {{ $rowWeight }}%</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div style="flex: 1; height: 8px; background: var(--surface-muted); border-radius: var(--radius-pill); overflow: hidden;">
                                            <div style="height: 100%; width: {{ max(0, min(100, $rowScore)) }}%; background: {{ $rowColor }}; border-radius: var(--radius-pill);"></div>
                                        </div>
                                        <span style="font-size: 14px; font-weight: 700; color: var(--text-primary);">{{ $rowScore }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        </div>{{-- /.brand-health-table --}}

                        {{-- BB138 Chart 2 — pillar radar (augments the breakdown table). --}}
                        @include('livewire.charts._pillar-radar')
                    </div>{{-- /.pillar-summary-grid --}}
                @else
                    {{-- No pillar scores: the radar renders nothing here, but
                         keep the legacy unconditional include path intact. --}}
                    @include('livewire.charts._pillar-radar')
                @endif

                {{-- ============================================================
                     BB144 — Outlet photos from Google Places.
                     Data source: $audit->place_raw['photos'] (captured by
                     PlacesApiService::fetchPlaceDetails during the wizard's
                     Step 1 selectPlace). Each entry carries a photo_reference
                     that we proxy through audit.place-photo to keep the
                     server-side Maps API key out of the HTML. The proxy also
                     caches the binary so a repeat page-view doesn't re-bill
                     against our Places Photo quota.

                     Renders a horizontal scroll of up to 8 thumbnails; the
                     row is hidden entirely (not rendered at all) when no
                     photos are present, so legacy / pre-BB90 audits without
                     a place_raw blob don't get an empty strip.
                     ============================================================ --}}
                @php
                    $placePhotos = is_array($placeRaw ?? null) ? ($placeRaw['photos'] ?? []) : [];
                    if (! is_array($placePhotos)) {
                        $placePhotos = [];
                    }
                    $placePhotos = array_slice(array_values($placePhotos), 0, 8);
                @endphp
                @if (count($placePhotos) > 0 && $sessionToken)
                    <div class="mb-10">
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.5px; text-transform: uppercase; margin-bottom: 12px;">Foto outlet dari Google Maps</p>
                        <div
                            x-data="{ open: false, src: null, alt: '' }"
                            style="display: flex; gap: 12px; overflow-x: auto; padding: 4px 2px 8px; scroll-snap-type: x mandatory;"
                        >
                            @foreach ($placePhotos as $pIdx => $photo)
                                @php
                                    $thumbUrl = route('audit.place-photo', ['token' => $sessionToken, 'idx' => $pIdx]) . '?w=400';
                                    $bigUrl   = route('audit.place-photo', ['token' => $sessionToken, 'idx' => $pIdx]) . '?w=800';
                                @endphp
                                <button
                                    type="button"
                                    @click="open = true; src = '{{ $bigUrl }}'; alt = 'Foto outlet {{ $pIdx + 1 }}'"
                                    style="flex: 0 0 auto; width: 200px; height: 150px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-default); background: var(--surface-muted); padding: 0; cursor: zoom-in; scroll-snap-align: start;"
                                    aria-label="Buka foto outlet {{ $pIdx + 1 }}"
                                >
                                    <img
                                        src="{{ $thumbUrl }}"
                                        alt="Foto outlet {{ $pIdx + 1 }} dari Google Maps"
                                        loading="lazy"
                                        style="width: 100%; height: 100%; object-fit: cover; display: block;"
                                    />
                                </button>
                            @endforeach

                            {{-- Lightbox — single-instance, Alpine-driven so it
                                 doesn't re-render per thumbnail. --}}
                            <div
                                x-show="open"
                                x-cloak
                                x-transition.opacity
                                @keydown.escape.window="open = false"
                                @click.self="open = false"
                                style="position: fixed; inset: 0; z-index: 50; background: rgba(15,20,17,0.85); display: flex; align-items: center; justify-content: center; padding: 32px; cursor: zoom-out;"
                            >
                                <img
                                    :src="src"
                                    :alt="alt"
                                    style="max-width: 92vw; max-height: 88vh; border-radius: var(--radius-md); box-shadow: var(--shadow-popover);"
                                />
                                <button
                                    type="button"
                                    @click="open = false"
                                    style="position: absolute; top: 20px; right: 24px; width: 40px; height: 40px; border-radius: var(--radius-pill); border: none; background: var(--surface-card); color: var(--text-primary); cursor: pointer; font-size: 18px;"
                                    aria-label="Tutup"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- ===== Pillar grid (single column) ===== --}}
                @php
                    // BB32: methodology copy explaining WHY each pillar gets its
                    // weight and why each sub-bucket carries its specific cap.
                    // Keyed on pillar slug; values match config/branding.php.
                    // Sourced from config:
                    //   pillar_weights — Konsistensi 35 / Recall 35 / Experience 20 / Digital 10
                    //   pillar_sub_buckets — see config/branding.php:40-73
                    // Phase 12c.1 BB103 — pillar methodology copy in Indonesian
                    // (saya/kita register). BB101 demotion is reflected: TikTok
                    // is removed from the Konsistensi "kehadiran digital" list
                    // and reframed as a bonus signal in Digital Presence.
                    $pillarMethodology = [
                        'brand-konsistensi' => 'Brand Konsistensi (35% dari skor total) mengukur seberapa kompak identitas brand kita di seluruh touchpoint digital — prediktor terkuat untuk recall jangka panjang dalam wawancara pelanggan. Kehadiran Digital (40 pts) punya bobot tertinggi karena konsistensi presence di Instagram + Website + Google Maps + WhatsApp menandakan brand yang benar-benar beroperasi, bukan sambilan. Konsistensi Visual (35 pts) menilai apakah logo, warna, dan tone tetap menyatu saat pelanggan menyapu pandang antar kanal. Kelengkapan Layanan (15 pts) dan Transparansi Harga (10 pts) melengkapi skor dengan sinyal operasional yang spesifik.',
                        'brand-recall' => 'Brand Recall (35% dari skor total) mengukur seberapa mudah calon pelanggan mengenali brand kita ketika mencari jasa laundry. Search Recall (35 pts) punya bobot tertinggi karena permintaan Google Autocomplete adalah proxy terkuat untuk kesadaran brand yang nyata di area kita. Rating (25 pts) dan Jumlah Review (15 pts) mencerminkan akumulasi social proof. Kata Kunci Positif di Ulasan (15 pts) dan Sentimen (10 pts) mengukur kualitas penerimaan yang diambil dari hingga 30 review Google Maps yang di-scrape per audit.',
                        'brand-experience' => 'Brand Experience (20% dari skor total) mengukur sinyal operasional yang pelanggan rasakan saat berinteraksi dengan brand. Setiap audit mulai dari Dasar 30 pts, dengan bonus yang dipicu deklarasi operator + verifikasi otomatis: Variasi Layanan (+15), SOP Keluhan (+15), Antar Jemput (+12), Layanan Ekspres (+10), Daftar Harga (+10). Sinyal keluhan dari ulasan Google Maps mengurangi skor secara deterministik: "pakaian tertukar/hilang" (−10), "telat/lambat" (−8), "tidak respons WA" (−8). Jika korpus ulasan tidak memuat kata kunci keluhan, sinyal-sinyal ini tetap 0 — itu sinyal baik, bukan data yang hilang.',
                        'digital-presence' => 'Digital Presence (10% dari skor total) mengukur seberapa mudah brand kita ditemukan dan selengkap apa kehadirannya di kanal yang benar-benar dicek pelanggan. Bobotnya mencerminkan dampak platform untuk UMKM laundry: Google Maps (25 pts — driver penemuan utama), Instagram (20 pts), Website (20 pts), WhatsApp Business (15 pts — kanal pesan langsung). TikTok (bonus 3 pts) bersifat opsional dan tidak menjadi penalti kalau belum ada. Bonus Review hingga 15 pts tambahan tercetak saat listing Google Maps sudah mengakumulasi volume review yang signifikan.',
                    ];
                @endphp
                <div class="grid grid-cols-1 gap-6 mb-12">
                    @foreach ($pillarMeta as $slug => $meta)
                        @php
                            $ps      = $pillarScoreInts[$slug] ?? null;
                            $pc      = $tierColor($ps);
                            $sbs     = $subBucketScores[$slug] ?? [];
                            // Phase 12c.4 FIX 6 — legacy-data migration.
                            // Pre-BB141 audits stored Digital Presence's
                            // review bonus as two 5-pt sub-buckets
                            // (review_count_5plus + review_count_50plus).
                            // The current scorer emits a single
                            // review_bonus row (0/5/10). Collapse the
                            // legacy keys at render time so the
                            // dashboard never shows two rows side-by-
                            // side; sum is identical at every tier.
                            if ($slug === 'digital-presence' && is_array($sbs)
                                && array_key_exists('review_count_5plus', $sbs)
                                && array_key_exists('review_count_50plus', $sbs)
                                && ! array_key_exists('review_bonus', $sbs)) {
                                $r10 = (int) ($sbs['review_count_5plus']  ?? 0);
                                $r50 = (int) ($sbs['review_count_50plus'] ?? 0);
                                $sbs['review_bonus'] = $r10 + $r50;
                                unset($sbs['review_count_5plus'], $sbs['review_count_50plus']);
                            }
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
                            {{-- BB145 — tier badge added next to the score.
                                 Shows the qualitative label (Sempurna /
                                 Sangat Baik / Baik / Cukup / Perlu Perbaikan)
                                 alongside the "X / 100" so operators get an
                                 instant read on where the score sits without
                                 having to mentally translate a number. --}}
                            @php
                                $pillarTierLabel   = $ps !== null ? AuditLabels::pillarTier($ps) : null;
                                $pillarTierVariant = $ps !== null ? AuditLabels::pillarTierVariant($ps) : 'bad';
                            @endphp
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div style="width: 36px; height: 36px; border-radius: var(--radius-md); background: var(--chimera-50); display: flex; align-items: center; justify-content: center;">
                                        <i class="ti {{ $meta['icon'] }}" style="color: var(--chimera-600); font-size: 18px;"></i>
                                    </div>
                                    <span style="font-size: 15px; font-weight: 600; color: var(--text-primary);">{{ $meta['label'] }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if ($pillarTierLabel)
                                        <span class="bb-tier-badge bb-tier-badge--{{ $pillarTierVariant }}" style="font-size: 11px;">{{ $pillarTierLabel }}</span>
                                    @endif
                                    <span style="font-size: 28px; font-weight: 700; color: {{ $pc }}; line-height: 1;">{{ $ps ?? '—' }}<span style="font-size: 14px; font-weight: 400; color: var(--text-tertiary);">/100</span></span>
                                </div>
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
                                    <p style="font-size: 10px; font-weight: 600; color: var(--chimera-700); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">Tentang skor ini</p>
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

                            {{-- BB138 Chart 3/4 — pillar-specific visual above the sub-bucket list. --}}
                            @if ($slug === 'brand-experience')
                                @include('livewire.charts._be-waterfall')
                            @elseif ($slug === 'digital-presence')
                                @include('livewire.charts._touchpoint-grid')
                            @endif

                            @if (count($sbs) > 0)
                                <div class="flex flex-col mb-4" style="border-top: 1px solid var(--border-default);">
                                    @foreach ($sbs as $k => $v)
                                        @php
                                            $bd = is_array($scoreBreakdown[$slug][$k] ?? null) ? $scoreBreakdown[$slug][$k] : null;
                                            $isDigitalPresence = $slug === 'digital-presence';
                                            // BB141 — prefer the breakdown's per-row cap (V3 scorers
                                            // emit their own caps that may differ from the legacy
                                            // config) over the static config value. Falls back to
                                            // config for legacy rows without a per-row cap.
                                            $cap = is_array($bd) && isset($bd['cap'])
                                                ? (int) $bd['cap']
                                                : (int) (config('branding.pillar_sub_buckets.' . $slug . '.' . $k . '.cap', 0));
                                            $hasPresence = ((int) $v) > 0;
                                            // Phase 12c.4 FIX D — TikTok no longer carries the
                                            // "opsional, tidak mengurangi skor" decoration. If the
                                            // operator declared a TikTok handle that the wizard
                                            // verified, it counts the same as any other
                                            // touchpoint. ``review_bonus`` stays optional — it is
                                            // a derived signal from review volume, not an
                                            // operator-declared touchpoint.
                                            $touchpointOptional = $k === 'review_bonus';
                                        @endphp
                                        <div style="border-bottom: 1px solid var(--border-default);">
                                            @if ($isDigitalPresence)
                                                {{-- BB116: Digital Presence rows render as ✓/✗ icon, name,
                                                     plain-Indonesian description, and points. Replaces the
                                                     generic two-column sub-bucket row for this pillar. --}}
                                                <div class="flex items-start gap-3 py-3 {{ $touchpointOptional && ! $hasPresence ? 'opacity-70' : '' }}">
                                                    <span style="font-size: 18px; line-height: 1.2; color: {{ $hasPresence ? 'var(--color-success)' : ($touchpointOptional ? 'var(--text-tertiary)' : 'var(--color-danger)') }}; width: 22px; text-align: center; flex-shrink: 0;">
                                                        {{ $hasPresence ? '✓' : '✗' }}
                                                    </span>
                                                    <div style="flex: 1; min-width: 0;">
                                                        <p style="font-size: 13px; font-weight: 600; color: var(--text-primary); margin: 0;">
                                                            {{ AuditLabels::subBucket($k) }}
                                                            @if ($touchpointOptional && ! $hasPresence)
                                                                <span style="font-size: 11px; color: var(--text-tertiary); font-weight: 400;">(opsional, tidak mengurangi skor)</span>
                                                            @endif
                                                        </p>
                                                        <p style="font-size: 12px; color: var(--text-secondary); margin: 2px 0 0; line-height: 1.5;">
                                                            {{ AuditLabels::touchpointDescription($k) }}
                                                        </p>
                                                    </div>
                                                    <span style="font-size: 13px; font-weight: 600; color: var(--text-primary); white-space: nowrap; flex-shrink: 0;">
                                                        {{ $v }}{{ $cap > 0 ? ' / ' . $cap : '' }} pt
                                                    </span>
                                                </div>
                                            @else
                                                @php
                                                    // Phase 12c.2-rubric-alignment BB119 — X/Y format + tier badge.
                                                    $scoreInt  = (int) $v;
                                                    $tierLabel = is_array($bd['tier'] ?? null) ? null : ($bd['tier'] ?? null);
                                                    if ($tierLabel === null && $cap > 0) {
                                                        $tierLabel = AuditLabels::tierForRatio($scoreInt / max(1, $cap));
                                                    }
                                                    $tierVariant = AuditLabels::tierVariant($tierLabel);

                                                    // BB140 — penalty rows get neutral phrasing when no
                                                    // complaints were detected. A score of 0 means the
                                                    // detector found nothing — that is a GOOD outcome,
                                                    // not a missing-data signal. Negative scores keep the
                                                    // red badge but show the magnitude as "−X poin".
                                                    $isPenaltyRow = str_starts_with($k, 'penalty_');
                                                    if ($isPenaltyRow) {
                                                        if ($scoreInt >= 0) {
                                                            $tierLabel   = 'Tidak ditemukan keluhan ✓';
                                                            $tierVariant = 'good';
                                                        } else {
                                                            $tierLabel   = '−' . abs($scoreInt) . ' poin';
                                                            $tierVariant = 'bad';
                                                        }
                                                    }
                                                    // Phase 12c.4 FIX C — declaration-driven rows that
                                                    // scored 0 should show "Tidak dinyatakan" with NO
                                                    // numeric "0/X pt" — the row is qualitative, not
                                                    // measured. Triggers on the new scorer formulas.
                                                    $declarationFormula = is_array($bd) && in_array(
                                                        (string) ($bd['formula'] ?? ''),
                                                        ['operator_declaration'],
                                                        true,
                                                    );
                                                    $isUndeclaredRow = $declarationFormula && $scoreInt === 0;
                                                    if ($isUndeclaredRow) {
                                                        $tierLabel   = 'Tidak dinyatakan';
                                                        $tierVariant = 'warning';
                                                    }
                                                    // FIX C — penalty rows with score=0 hide the
                                                    // numeric counter entirely; only the green tier
                                                    // badge "Tidak ditemukan keluhan ✓" remains.
                                                    $hidePointValue = ($isPenaltyRow && $scoreInt === 0) || $isUndeclaredRow;
                                                @endphp
                                                <div class="flex justify-between items-center py-2 gap-3">
                                                    <span style="font-size: 12px; color: var(--text-secondary);">{{ AuditLabels::subBucket($k) }}</span>
                                                    <span class="flex items-center gap-2" style="flex-shrink: 0;">
                                                        @if (! $hidePointValue)
                                                            <span style="font-size: 13px; font-weight: 600; color: var(--text-primary); white-space: nowrap;">
                                                                @if ($isPenaltyRow)
                                                                    {{ '−' . abs($scoreInt) . ' pt' }}
                                                                @else
                                                                    {{ $scoreInt }}{{ $cap > 0 ? ' / ' . $cap : '' }} pt
                                                                @endif
                                                            </span>
                                                        @endif
                                                        @if ($tierLabel !== null && $tierLabel !== '')
                                                            <span class="bb-tier-badge bb-tier-badge--{{ $tierVariant }}">{{ $tierLabel }}</span>
                                                        @endif
                                                    </span>
                                                </div>
                                                {{-- BB138 Chart 7 — owner reply-rate gauge beside Manajemen Ulasan. --}}
                                                @if ($slug === 'brand-recall' && $k === 'manajemen_ulasan')
                                                    @php $replyRatePct = (float) ($bd['raw_inputs']['reply_rate_pct'] ?? 0); @endphp
                                                    @if ($replyRatePct > 0)
                                                        @include('livewire.charts._reply-gauge')
                                                    @endif
                                                @endif
                                                @php
                                                    // BB118 — every row gets a source line. When the
                                                    // breakdown is absent we still surface the canonical
                                                    // source from AuditLabels::subBucketSource (BB112).
                                                    // For v1/v2 audits the canonical source is replaced
                                                    // with the honest pre-rubric label.
                                                    $rowSource = AuditLabels::subBucketSource($k);
                                                    if ($rowSource === null) {
                                                        $rowSource = isset($auditWizardVersion) && $auditWizardVersion !== BrandAudit::WIZARD_V3
                                                            ? AuditLabels::preRubricSource($auditWizardVersion ?? null)
                                                            : 'Sumber: tidak tersedia';
                                                    }
                                                @endphp
                                                @if ($bd === null)
                                                    <p style="font-size: 11px; color: var(--text-tertiary); margin: 0 0 8px; line-height: 1.5;">
                                                        {{ $rowSource }}
                                                    </p>
                                                @endif
                                                {{-- Phase 12c.4 FIX F — surface a one-line reason
                                                     when this sub-bucket scored less than its cap.
                                                     Reads from (in order):
                                                       1. $bd['llm_reasoning']  (per-row LLM judgment)
                                                       2. $scoreBreakdown[$slug]['sub_bucket_reasoning'][$k]
                                                          (pillar-level map populated by KonsistensiScorer
                                                          + ExperienceScorer V3)
                                                     Hidden for: full-marks rows, penalty rows (already
                                                     covered by the green/red badge), and undeclared
                                                     declaration rows (FIX C handles those). --}}
                                                @php
                                                    $isUnderCap = $cap > 0 && $scoreInt < $cap;
                                                    $reasonText = '';
                                                    if ($isUnderCap && ! $isPenaltyRow && ! ($isUndeclaredRow ?? false)) {
                                                        $reasonText = (string) (
                                                            (is_array($bd) ? ($bd['llm_reasoning'] ?? '') : '')
                                                            ?: ($scoreBreakdown[$slug]['sub_bucket_reasoning'][$k] ?? '')
                                                        );
                                                    }
                                                @endphp
                                                @if ($reasonText !== '')
                                                    <p style="font-size: 12px; color: var(--text-secondary); margin: 0 0 8px; padding: 8px 10px; background: var(--surface-muted); border-left: 3px solid var(--chimera-200); border-radius: var(--radius-sm); line-height: 1.55;">
                                                        <span style="font-weight: 600; color: var(--chimera-700);">Kenapa belum penuh:</span> {{ $reasonText }}
                                                    </p>
                                                @endif
                                            @endif
                                            @if ($bd !== null && ! $isDigitalPresence)
                                                {{-- Phase 12c.2 (BB113/BB114): sub-bucket detail block —
                                                     formula labels, sources, signals, and raw_inputs all
                                                     route through App\Support\AuditLabels so no English
                                                     strings, em-dash-noise labels, or raw_input keys leak
                                                     into the user-facing surface. BB112: each sub-bucket
                                                     gets its own plain-Indonesian source attribution
                                                     (e.g. Konsistensi Visual → "analisis AI atas screenshot"). --}}
                                                <div style="padding: 8px 12px 12px; background: var(--surface-muted); border-top: 1px solid var(--border-default); font-size: 11px; color: var(--text-secondary);">
                                                    @php
                                                        $formula      = $bd['formula'] ?? 'unknown';
                                                        $rawInputs    = (array) ($bd['raw_inputs'] ?? []);
                                                        $tierTable    = (array) ($bd['tier_table'] ?? []);
                                                        $signals      = (array) ($bd['signals'] ?? []);
                                                        $suggestions  = (array) ($rawInputs['suggestions'] ?? []);
                                                        $llmReasoning = (string) ($bd['llm_reasoning'] ?? '');
                                                        $limitations  = (array) ($bd['limitations'] ?? []);
                                                        $sourceLine   = AuditLabels::subBucketSource($k);
                                                        $formulaLine  = AuditLabels::formula($formula);

                                                        // BB114: translate raw_inputs keys through AuditLabels::RAW_INPUT
                                                        // and surface sample_source enum values via SAMPLE_SOURCE.
                                                        // Internal/debug keys are filtered out so users never see them.
                                                        $hiddenInputKeys = [
                                                            'source', 'suggestions', 'suggestion_count', 'brand_name',
                                                            'brand_stem', 'fetched_at', 'context_provided',
                                                        ];
                                                        if ($formula === 'llm_judgment') {
                                                            $inputLine = implode(', ', (array) ($rawInputs['context_provided'] ?? []));
                                                        } elseif ($formula === 'deterministic_signals') {
                                                            // Signals render in their own table below — skip the noisy
                                                            // "brand_stem: X · sumber: Y" debug line.
                                                            $inputLine = '';
                                                        } else {
                                                            $parts = [];
                                                            foreach ($rawInputs as $rk => $rv) {
                                                                if (in_array($rk, $hiddenInputKeys, true)) {
                                                                    continue;
                                                                }
                                                                $label = AuditLabels::rawInput((string) $rk);
                                                                if ($label === null) {
                                                                    continue; // unknown key → keep out of user view
                                                                }
                                                                $value = $rv;
                                                                if ($rk === 'sample_source') {
                                                                    $value = AuditLabels::sampleSource((string) $rv);
                                                                } elseif (is_bool($rv)) {
                                                                    $value = $rv ? 'Ya' : 'Tidak';
                                                                } elseif ($rk === 'hit_rate_pct' && is_numeric($rv)) {
                                                                    $value = number_format((float) $rv, 1, ',', '.') . '%';
                                                                }
                                                                $parts[] = $label . ': ' . $value;
                                                            }
                                                            $inputLine = implode(', ', $parts);
                                                        }
                                                    @endphp

                                                    @if ($sourceLine !== null)
                                                        <p style="margin: 0 0 4px;">{{ $sourceLine }}</p>
                                                    @endif
                                                    @if ($formulaLine !== null)
                                                        <p style="margin: 0 0 6px; color: var(--text-tertiary);">{{ $formulaLine }}</p>
                                                    @endif

                                                    @if ($k === 'search_recall')
                                                        {{-- BB115: Search Recall explainer in the same TENTANG
                                                             SKOR INI style as the per-pillar methodology block. --}}
                                                        <div style="margin: 8px 0 10px; padding: 10px 12px; background: var(--surface-card); border-left: 3px solid var(--chimera-200); border-radius: var(--radius-sm);">
                                                            <p style="font-size: 10px; font-weight: 600; color: var(--chimera-700); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 6px;">Tentang Search Recall</p>
                                                            <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.65; margin: 0;">
                                                                Search Recall mengukur seberapa mudah brand kita ditemukan lewat Google autocomplete. Skor ini melihat tiga hal: apakah nama brand muncul di hasil pencarian, apakah Google mengaitkan brand dengan lokasi outlet, dan apakah ada variasi pencarian non-brand yang menunjukkan pengenalan organik. Ideal: brand muncul di top-3 saran untuk pencarian nama, plus 2–3 variasi lokasi. Skor 0 berarti brand belum cukup dikenal Google untuk muncul sebagai saran.
                                                            </p>
                                                        </div>
                                                    @endif

                                                    @if ($inputLine !== '')
                                                        <p style="margin: 0 0 6px;"><strong>Berdasarkan:</strong> {{ $inputLine }}</p>
                                                    @endif

                                                    @if (in_array($formula, ['deterministic_threshold', 'qualitative_tier', 'operator_declaration'], true) && count($tierTable) > 0)
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px;">
                                                            @foreach ($tierTable as $tier)
                                                                @php
                                                                    $isMatch = (bool) ($tier['matched'] ?? false);
                                                                    // FIX B — new scorers emit `label`; legacy ones use `range`.
                                                                    $rowText = (string) ($tier['label'] ?? $tier['range'] ?? '');
                                                                @endphp
                                                                <tr style="background: {{ $isMatch ? 'var(--chimera-500)' : 'transparent' }}; color: {{ $isMatch ? '#FFFFFF' : 'inherit' }}; border-radius: 4px;">
                                                                    <td style="padding: 3px 8px;">{{ $rowText }}</td>
                                                                    <td style="padding: 3px 8px; text-align: right; font-weight: {{ $isMatch ? '600' : 'normal' }};">{{ $tier['points'] }} pt</td>
                                                                </tr>
                                                            @endforeach
                                                        </table>
                                                        @php $sopBonus = $bd['sop_bonus'] ?? null; @endphp
                                                        @if (is_array($sopBonus))
                                                            <p style="font-size: 11px; color: {{ ($sopBonus['awarded'] ?? false) ? 'var(--color-success)' : 'var(--text-tertiary)' }}; margin: 4px 0 0;">
                                                                {{ ($sopBonus['awarded'] ?? false) ? '✓' : '○' }}
                                                                +{{ (int) ($sopBonus['points'] ?? 0) }} bonus jika SOP keluhan ada
                                                            </p>
                                                        @endif
                                                        @if (! empty($bd['unavailable_reason']))
                                                            <p style="font-size: 11px; color: var(--text-tertiary); font-style: italic; margin: 6px 0 0;">
                                                                {{ $bd['unavailable_reason'] }}
                                                            </p>
                                                        @endif
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
                                                                    <td style="padding: 3px 8px; vertical-align: top;">{{ AuditLabels::signal((string) $sigKey) }}</td>
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

                                                    @php
                                                        // Phase 12c.2-rubric-alignment BB120 — embed up to 3
                                                        // matched review quotes inline so the operator can
                                                        // verify the source. Looks for both the canonical
                                                        // 'matched_reviews' (BB115 OwnerReplyRateScorer) and
                                                        // legacy review-keyword evidence shapes from
                                                        // RecallScorer / ExperiencePenaltyDetector.
                                                        $matchedReviews = [];
                                                        $evidenceBlock  = (array) ($bd['evidence'] ?? []);
                                                        if (isset($evidenceBlock['matched_reviews']) && is_array($evidenceBlock['matched_reviews'])) {
                                                            $matchedReviews = $evidenceBlock['matched_reviews'];
                                                        } elseif (isset($evidenceBlock['sample_quotes']) && is_array($evidenceBlock['sample_quotes'])) {
                                                            $matchedReviews = $evidenceBlock['sample_quotes'];
                                                        }
                                                        $matchedReviews = array_slice($matchedReviews, 0, 3);
                                                    @endphp
                                                    @if ($matchedReviews !== [])
                                                        <details class="bb-review-quotes">
                                                            <summary>{{ count($matchedReviews) }} ulasan jadi bukti, klik untuk lihat</summary>
                                                            @foreach ($matchedReviews as $review)
                                                                @php
                                                                    $reviewText   = (string) ($review['text'] ?? $review['reply_text'] ?? $review['snippet'] ?? '');
                                                                    $reviewerName = (string) ($review['reviewer_name'] ?? $review['author'] ?? 'Anonim');
                                                                    $reviewRating = (int)    ($review['rating'] ?? $review['rating_value'] ?? 0);
                                                                @endphp
                                                                @if ($reviewText !== '')
                                                                    <blockquote class="bb-review-quote">
                                                                        "{{ \Illuminate\Support\Str::limit($reviewText, 220) }}"
                                                                        <cite>— {{ $reviewerName }}@if ($reviewRating > 0), ★{{ $reviewRating }}/5 @endif</cite>
                                                                    </blockquote>
                                                                @endif
                                                            @endforeach
                                                        </details>
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

                {{-- BB142 — Instagram audit moved to a "Bonus" section at the
                     bottom of the dashboard (see below, after Ringkasan Brand
                     Health). The detailed IG profile analysis is supplemental
                     to the 4-pillar Brand Health, not a peer of it. --}}

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
                                {{-- BB145 — tier badge mirrors the one under the
                                     overall score ring at the top, so the
                                     Ringkasan card is self-contained. --}}
                                @if ($overallScore !== null)
                                    <span class="bb-tier-badge bb-tier-badge--{{ AuditLabels::pillarTierVariant((int) $overallScore) }}" style="font-size: 11px; display: inline-block; margin-top: 8px;">{{ AuditLabels::pillarTier((int) $overallScore) }}</span>
                                @endif
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

                {{-- ============================================================
                     Phase 12c.4 FIX 7 (revised) + FIX E — Target Skor
                     Berikutnya card. Calculation is centralised in
                     ``App\Services\TargetScoreCalculator`` so the
                     LLM reasoning (AggregateAuditJob) and the view
                     can never disagree on which actions were chosen.
                     The LLM-generated reasoning paragraphs are read
                     from ``audit_evidence.target_score_reasoning``
                     when present.
                     ============================================================ --}}
                @if (isset($overallScore) && $overallScore !== null)
                    @php
                        $targetPayload = App\Services\TargetScoreCalculator::compute(
                            (int) $overallScore,
                            $pillarScoreInts,
                            $subBucketScores,
                            $scoreBreakdown,
                        );
                        $current     = $targetPayload['current'];
                        $targetScore = $targetPayload['target'];
                        $delta       = $targetPayload['delta'];
                        $actions     = $targetPayload['actions'];

                        $cachedReasoning = (array) ($auditEvidence['target_score_reasoning'] ?? []);
                        $reasoningParagraphs = is_array($cachedReasoning['paragraphs'] ?? null)
                            ? array_values(array_filter(
                                array_map(static fn ($p) => is_string($p) ? trim($p) : '', $cachedReasoning['paragraphs']),
                                static fn (string $p) => $p !== '',
                            ))
                            : [];
                    @endphp
                    <x-nui-card padding="lg" style="margin-top: 32px;">
                        <p style="font-size: 11px; font-weight: 600; color: var(--text-tertiary); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 8px;">Target skor berikutnya (3 bulan)</p>
                        <div class="flex items-baseline gap-3" style="margin-bottom: 4px;">
                            <span style="font-size: 44px; font-weight: 700; color: var(--chimera-700); line-height: 1;">{{ $targetScore }}</span>
                            <span style="font-size: 18px; font-weight: 500; color: var(--text-tertiary);">/ 100</span>
                            <span style="font-size: 13px; font-weight: 600; color: var(--color-success); margin-left: 8px;">
                                ↑ +{{ $delta }} dari skor saat ini ({{ $current }})
                            </span>
                        </div>

                        @if (count($actions) > 0)
                            <ul style="list-style: none; padding: 0; margin: 20px 0 0; display: flex; flex-direction: column; gap: 10px;">
                                @foreach ($actions as $a)
                                    <li style="display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: var(--text-primary); line-height: 1.55;">
                                        <span style="color: var(--chimera-600); font-weight: 700; flex-shrink: 0;">•</span>
                                        <span><strong>{{ $a['text'] }}</strong> <span style="color: var(--text-tertiary);">→ +{{ $a['gain'] }} pt {{ $a['pillar'] }}</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Phase 12c.4 FIX E — LLM-generated reasoning paragraphs.
                             Read from audit_evidence.target_score_reasoning,
                             populated by AggregateAuditJob during the scoring
                             phase (BB145: before the DONE flip, which now
                             happens only at GeneratePdfJob). Block stays hidden
                             when generation failed or hasn't run yet — never an
                             error UI. --}}
                        @if (count($reasoningParagraphs) > 0)
                            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-default);">
                                <p style="font-size: 11px; font-weight: 600; color: var(--chimera-700); letter-spacing: 0.4px; text-transform: uppercase; margin: 0 0 12px;">Mengapa target ini realistis?</p>
                                @foreach ($reasoningParagraphs as $para)
                                    <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.65; margin: 0 0 12px;">{{ $para }}</p>
                                @endforeach
                            </div>
                        @endif
                    </x-nui-card>
                @endif

                {{-- ============================================================
                     BB142 — Bonus: Audit Profil Instagram.
                     Moved here from above the Ringkasan card so the 4-pillar
                     Brand Health stays the primary focus on the result page.
                     Instagram still contributes +20 pts to Digital Presence
                     (sub-bucket has_instagram); this section is the detailed
                     profile breakdown, which is supplemental — keep it visible
                     but below the headline summary.
                     ============================================================ --}}
                <div style="margin-top: 48px; padding-top: 32px; border-top: 1px dashed var(--border-default);">
                    <div style="text-align: center; margin-bottom: 24px;">
                        <span style="display: inline-block; font-size: 10px; font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; color: var(--chimera-700); background: var(--chimera-50); border: 1px solid var(--chimera-100); border-radius: var(--radius-pill); padding: 4px 14px;">
                            Bonus
                        </span>
                        <h2 style="font-size: 22px; font-weight: 600; color: var(--text-primary); margin: 12px 0 4px;">Audit Profil Instagram</h2>
                        <p style="font-size: 13px; color: var(--text-secondary); margin: 0; max-width: 480px; margin-left: auto; margin-right: auto;">
                            Analisis mendalam atas profil Instagram brand-mu — terpisah dari skor 4 pilar Brand Health di atas.
                        </p>
                    </div>

                    @include('livewire._instagram-audit-section', [
                        'instagramAudit'       => $instagramAudit,
                        'instagramAuditStatus' => $instagramAuditStatus,
                        'sessionToken'         => $sessionToken,
                    ])
                </div>

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
