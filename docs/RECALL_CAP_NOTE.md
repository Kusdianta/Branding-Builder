# Brand Recall — Structural Cap Note

**Filed:** 2026-05-10
**Phase 4 context:** Smoke-tested against Less Worry Laundry (4.9★, 291 reviews)

## What happens

`keyword_quality` and `review_management` sub-buckets both score **0** for almost every brand.

| Sub-bucket | Cap | Why it's always 0 |
|---|---|---|
| `keyword_quality` | 20 | Places API (New) returns **max 5 reviews** per call. Those 5 rarely contain our keyword cluster phrases even when the brand has hundreds of positive reviews. |
| `review_management` | 20 | Places API (New) does **not** expose owner reply data. `owner_response_rate` is always `0.0`. |

Net effect: Brand Recall is structurally capped at **60/100** (rating 35 + review_count 25) regardless of how well the brand actually manages its reviews or how keyword-rich its review corpus is.

## Workaround in v1

None. The 0s are displayed but annotated in the UI as "data tidak tersedia via API" rather than penalising the brand score further. Both zero sub-buckets are excluded from the recommendations engine (since they reflect API gaps, not brand actions).

## Phase 4 polish item

**Replace Places API sampling with Apify Google Maps Reviews scraper (post-Phase-4):**

- Apify actor `compass/google-maps-reviews-scraper` returns up to 100+ reviews per call
- Enables real keyword cluster analysis and owner-response-rate calculation
- Estimated implementation: 1 slice (swap `GoogleMapsReviewsFetcher`, keep `RecallScorer` unchanged — it already accepts the structured `keyword_hits` format)
- Cost: ~$0.10–0.30 per brand audit at 100 reviews

## Impact when fixed

Recall ceiling rises from 60 → 100. Brands with active review management and positive keyword-rich reviews will score meaningfully higher. Overall score ceiling rises from ~82 → ~100.
