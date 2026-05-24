# Brand Recall — Scoring Notes

**Filed:** 2026-05-10
**Updated:** 2026-05-10 (Recall pillar redesigned — cap removed)

## Scoring model

Brand Recall is fully deterministic (no LLM scoring call). Sub-buckets (cap 100):

| Sub-bucket | Cap | Source |
|---|---|---|
| `rating_tier` | 35 | Overall Google Maps star rating |
| `review_count_tier` | 25 | Total `userRatingCount` from Places API |
| `keyword_saturation` | 25 | Share of sampled reviews containing ≥1 positive keyword phrase |
| `sentiment_quality` | 15 | Avg star rating of sampled reviews |

## Sampling tradeoff

Places API (New) returns **max 5 reviews** per call. `keyword_saturation` and `sentiment_quality` are computed on this sample.

**Assumption:** the 5-review sample is broadly representative of the brand's full review corpus.

**Known limitation:** cannot detect recent-reviews-only quality drops. If a brand maintained a 4.9★ average historically but received 5 bad reviews in the past week, `rating_tier` still reflects the aggregate, while `sentiment_quality` may catch the recent signal (since Places API tends to return recent reviews). These two sub-buckets can diverge for brands with recent service incidents.

## Phase 4 polish item (post-Phase-4)

Swap `GoogleMapsReviewsFetcher` to use the Apify Google Maps Reviews scraper (actor `compass/google-maps-reviews-scraper`) which returns 100+ reviews. `RecallScorer` requires no changes — it already accepts the `sampled_reviews: [{text, rating}]` contract. Larger sample improves saturation and sentiment accuracy at ~$0.10–0.30 per audit.
