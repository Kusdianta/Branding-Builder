# Slice 7 Handoff

## Slice 6 final state

### Commits (in order)

| Hash | Description |
|------|-------------|
| `f44b099` | feat: add ScorePillarJob with internal error capture and partial pillar-score write |
| `c64ab3e` | feat: add AggregateAuditJob and AnalyzeBrand orchestrator with Bus::batch pipeline |
| `62ccb38` | feat: add branding-recommendations config with 15 sub-bucket recommendation templates |
| `1f9a290` | test: add slice 6 end-to-end smoke test via AnalyzeBrand full pipeline |
| `ffacd0d` | refactor: redesign Recall pillar — replace 60-pt cap with saturation+sentiment sub-buckets |
| `de6ff94` | feat: expand recall keyword clusters with broader Indonesian laundry-review vocabulary |
| `27e9507` | fix: rewrite keyword_saturation and sentiment_quality recommendation bodies as concrete laundry-business actions |

### Key decisions made in slice 6

**AnalyzeBrand job pipeline:**
- `AnalyzeBrand` (orchestrator) → `Bus::batch` → 4× `ScorePillarJob` → `AggregateAuditJob`
- Single pillar failure: `status=done`, `error_message` notes which pillar
- 2+ pillar failures: `status=failed`
- Queue: `sync` driver works for smoke tests; production will use `database` driver with a worker

**Recall pillar redesign (ffacd0d):**
- Old: 4 sub-buckets (rating 35 + count 25 + keyword_quality 20 + review_management 20) → structural cap at 60 due to API gaps
- New: 4 sub-buckets (rating_tier 35 + review_count_tier 25 + keyword_saturation 25 + sentiment_quality 15) → cap 100
- `keyword_saturation`: share of sampled reviews with ≥1 positive cluster phrase, scaled to 25
- `sentiment_quality`: avg star rating of sampled reviews, tiered to 15
- `owner_response_rate` removed from output contract; `sampled_reviews: [{text, rating}]` added

**Expanded keyword clusters (de6ff94):**
- 5 positive groups: cleanliness, speed, service, recommendation, quality
- 4 negative groups: late, lost, unresponsive, damage
- Substring-matched (no word boundaries) — "bersih" hits "kebersihan"

**Recommendation templates (27e9507):**
- 15 templates keyed by sub-bucket slug
- `keyword_saturation` and `sentiment_quality` bodies rewritten as observable laundry-business actions

---

## Test brand in DB — Less Worry Laundry

The next session can load this row directly without re-fetching:

```
audit_id      = 01kr8ya7xq1xr9gyyy2d30nqv7
brand_name    = Less Worry Laundry
status        = done
overall_score = 82/100
```

Load in tinker:
```php
$audit = App\Models\BrandAudit::find('01kr8ya7xq1xr9gyyy2d30nqv7');
```

Pillar scores at last run:
- brand-recall: 90 (rating_tier=35, review_count_tier=25, keyword_saturation=15, sentiment_quality=15)
- digital-presence: 100
- brand-konsistensi: 71 (kehadiran_digital=40, konsistensi_visual=20, kelengkapan_layanan=7, transparansi_harga=4)
- brand-experience: 77 (base=30 + ekspres=10 + antar_jemput=12 + variasi=15 + price_list=10; sop_keluhan=0)

---

## Slice 7 spec

Spec is in `PROMPTS.md` (workspace root, Phase 4 / Slice 7 section). The BrandAuditWizard Livewire 4 component from slice 3 (`app/Livewire/BrandAuditWizard.php`) is the entry point — slice 7 wires the wizard submission to dispatch `AnalyzeBrand`, then shows the results view.

---

## Open items for slice 7 and beyond

| Item | Urgency | Notes |
|------|---------|-------|
| Mobile responsiveness check | Before launch | Results view and wizard form need QA on 375px viewport |
| Polling endpoint — keep lightweight | Slice 7 | Use `GET /audit/{id}/status` returning `{status, overall_score, pillar_scores}` only — no evidence blobs in the poll response |
| "Generate Activation Kit" button | Slice 8 | Stub the button in slice 7's results view (disabled, tooltip "Segera hadir") — `ClaudeService::generateActivationKit()` is already implemented |
| Recall cap note | Post-Phase-4 | `docs/RECALL_CAP_NOTE.md` — swap GMaps fetcher for Apify scraper to get 100+ reviews for better saturation/sentiment accuracy |
