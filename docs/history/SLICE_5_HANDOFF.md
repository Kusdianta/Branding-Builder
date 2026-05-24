# Slice 5 Handoff — Fresh-Context Smoke Test Run

## Commits landed (branding-builder `main`)

| Hash | Description |
|------|-------------|
| `27fe66e` | feat: add GoogleMapsReviewsFetcher with keyword cluster scanning |
| `13042d1` | feat: add TouchpointPresenceDetector for digital-presence input shape |
| `b452c5f` | feat: add WebsiteFetcher with OG metadata extraction and 24h cache |
| `2bcdf16` | test: add slice 5 end-to-end smoke test script |

## What is NOT yet done

The smoke test script exists but has **not been run against a real brand**. The next session must:

1. Get test brand inputs from Naufal (see below)
2. Fill in `scripts/smoke_slice5.php` TEST BRAND section
3. Run the script and verify all 4 PillarScores against gut-check expectations
4. If any pillar is off by more than ±10 from expectation, investigate before proceeding to slice 6

## Required test inputs (get from Naufal before running)

Open `scripts/smoke_slice5.php` and fill in the `TEST BRAND` section at the top:

```php
$BRAND_NAME    = '';   // brand display name — used for GMaps text-search fallback
$INSTAGRAM_URL = '';   // e.g. https://www.instagram.com/handle/
$WEBSITE_URL   = '';   // e.g. https://laundrybersih.com
$GMAPS_URL     = '';   // full or short Google Maps URL (maps.app.goo.gl/... is fine)
$WA_ACTIVE     = false; // true if WA Business is listed
$TIKTOK_URL    = '';   // leave empty string if none
```

Also verify `vault/branding-builder.json` has a valid `google_maps_key` entry (or the GMaps fetcher will use synthetic fallback data and Recall will score 0).

## Smoke test command

```bash
cd branding-builder
php scripts/smoke_slice5.php
```

## Expected outputs to verify

After the run, check:

1. **Recall `keyword_hits` dict** — the raw `{'positive': {...}, 'negative': {...}}` structure printed in the results box. Confirm the clusters that lit up match what you'd expect from the brand's Google reviews.
2. **4 PillarScores** — gut-check each against what you know about the brand:
   - Konsistensi sub-buckets: `kehadiran_digital` should match touchpoint count × 8
   - Recall: rating/review_count buckets should match GMaps numbers exactly
   - Experience: `base=30` always; bonuses/penalties should reflect what the website/IG actually says
   - Digital: sum of presence flags should match the booleans printed in step 2
3. **Weighted overall** = `konsistensi×0.35 + recall×0.35 + experience×0.20 + digital×0.10`
4. **5-tier label** maps correctly to the overall score

Gate for slice 6: all 4 scores within ±10 of Naufal's gut-check AND Recall sub-buckets numerically match GMaps data.

## Known limitations to watch for

| Item | Limitation |
|------|-----------|
| GMaps reviews | Places API (New) returns **max 5 reviews** per call — keyword_hits reflects only those 5, not full review history |
| `owner_response_rate` | Always `0.0` — Places API New does not expose owner replies. Recall `review_management` sub-bucket will always score as "never responds" (0 pts) in v1 |
| WebsiteFetcher | Returns `null` on SSL cert errors, timeouts, 4xx/5xx — Experience `website_excerpt` will be empty string; LLM will score based on IG only |
| Short GMaps URLs | `maps.app.goo.gl` links need an HTTP redirect follow to extract placeId. If that fails, fetcher falls back to text search by brand name — result may not be exact match |
| Keyword clusters | Live in `config/branding.recall_keyword_clusters`. If a phrase variant is missing, add it there — no code change needed |
