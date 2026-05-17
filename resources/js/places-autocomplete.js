// BB99.1 — Wizard Step 1 Places Autocomplete integration.
//
// Earlier revision mounted via @script inside the Volt partial. That
// path was fragile for two reasons:
//   1. Blade tokenises @script linearly — any literal `@script` in a
//      JS comment closed the directive early (fixed in BB99, but the
//      class of footgun remained).
//   2. @script re-executes on every Livewire morph. We had an idempotency
//      flag, but flag-on-window means a navigation that legitimately
//      re-mounts the container (e.g. wizardStep 2 → 1 via "Kembali")
//      can't re-init because the flag is already set globally.
//
// This module replaces the @script integration with a MutationObserver-
// based mount. Config flows through DOM data attributes on the
// container, not through a runtime function call. Mount idempotency
// lives on the element itself (data-pac-mounted), so:
//
//   - Container present at DOMContentLoaded     → mount
//   - Container appears via Livewire morph      → MutationObserver fires → mount
//   - Container morph-preserved across roundtrip (wire:ignore) →
//     attribute survives → mount is a no-op
//   - Container removed (step navigation away) → no mount
//   - Container reappears fresh (step navigation back) → fresh element,
//     no attribute → mount runs
//
// The author's `places-autocomplete-js` library handles the Google
// Maps Platform session-token lifecycle internally; the only thing we
// own is mounting the widget into the right element with the right
// config and piping the onResponse payload back into the Volt
// component via Livewire.find(componentId).call('selectPlace', ...).

import { PlacesAutocomplete } from 'places-autocomplete-js';
import 'places-autocomplete-js/places-autocomplete.css';

const CONTAINER_SELECTOR = '#places-autocomplete-container';

/**
 * Reshape the New Places API response (camelCase, modern Place v1)
 * into the legacy shape the Volt component's selectPlace() handler
 * accepts. Same logic the BB92 manual-fallback path goes through, so
 * server-side state hydration is identical regardless of which input
 * channel the user used.
 */
function reshapePlace(place) {
    if (!place || typeof place !== 'object') {
        return null;
    }

    const name =
        place?.displayName?.text ??
        (typeof place?.displayName === 'string' ? place.displayName : null) ??
        place?.name ??
        null;

    const lat =
        place?.location?.latitude ??
        (typeof place?.location?.lat === 'function' ? place.location.lat() : place?.location?.lat) ??
        null;
    const lng =
        place?.location?.longitude ??
        (typeof place?.location?.lng === 'function' ? place.location.lng() : place?.location?.lng) ??
        null;

    return {
        place_id: place?.id ?? place?.place_id ?? null,
        name,
        formatted_address: place?.formattedAddress ?? place?.formatted_address ?? null,
        geometry: lat !== null && lng !== null ? { location: { lat, lng } } : null,
        // The response object keys match the requested fetchFields, so
        // place.websiteURI is canonical for new-API responses. Legacy
        // place.website / place.websiteUri kept as fallbacks for the
        // manual server-side PlacesApiService path which returns the
        // older shape.
        website: place?.websiteURI ?? place?.websiteUri ?? place?.website ?? null,
        international_phone_number:
            place?.internationalPhoneNumber ?? place?.international_phone_number ?? null,
        types: place?.types ?? [],
        address_components:
            place?.addressComponents ?? place?.address_components ?? [],
        raw: place,
    };
}

/**
 * Walk up the DOM from the container to the nearest Livewire root.
 * Returns the wire:id of that root, which we use with Livewire.find()
 * to resolve a component handle for $wire.call('selectPlace', ...).
 */
function resolveLivewireComponent(el) {
    if (!el) return null;
    const wireEl = el.closest('[wire\\:id]');
    if (!wireEl) return null;
    const componentId = wireEl.getAttribute('wire:id');
    if (!componentId || !window.Livewire || typeof window.Livewire.find !== 'function') {
        return null;
    }
    return window.Livewire.find(componentId);
}

/**
 * Mount the widget into the supplied container element. Idempotent —
 * a second call against the same element is a no-op.
 */
function mountInto(el) {
    if (!el || el.dataset.pacMounted === '1') return;

    const apiKey = el.dataset.apiKey || '';
    const countryBias = el.dataset.countryBias || 'id';

    if (!apiKey) {
        // Operator banner in step-1-find-business.blade.php already
        // surfaces the missing-key state — no console noise needed.
        return;
    }

    // Mark mounted BEFORE constructing so a synchronous re-entry
    // (MutationObserver fires while we're still inside this call)
    // can't double-mount.
    el.dataset.pacMounted = '1';

    try {
        new PlacesAutocomplete({
            containerId: el.id,
            googleMapsApiKey: apiKey,
            requestParams: {
                // BB99.2 — places-autocomplete-js wraps the legacy Google
                // Maps JS SDK, not the New Places REST API, so the legacy
                // param names apply here: `language` + `region`, NOT the
                // REST-style `languageCode` + `regionCode` (those throw
                // 'InvalidValueError: unknown property languageCode' on
                // every keystroke). `includedRegionCodes` IS REST-style
                // on a different surface, so it stays.
                includedRegionCodes: [countryBias],
                language: 'id',
                region: countryBias,
                includedPrimaryTypes: ['establishment'],
            },
            options: {
                placeholder: 'Cari nama bisnis di Google Maps...',
            },
            fetchFields: [
                // BB99.3 — Google's New Places JS Place class field
                // names use UPPERCASE for acronyms (URI, ID). Requesting
                // 'websiteUri' triggers 'Unknown fields requested:
                // websiteUri' on selection. The other fields here are
                // already correctly cased per the spec at
                // /maps/documentation/javascript/place-class#fetch-place-details.
                'id',
                'displayName',
                'formattedAddress',
                'addressComponents',
                'location',
                'websiteURI',
                'internationalPhoneNumber',
                'types',
            ],
            onResponse: (place) => {
                const reshaped = reshapePlace(place);
                if (!reshaped?.place_id) {
                    console.warn('[wizard] selected place has no place_id', place);
                    return;
                }
                const component = resolveLivewireComponent(el);
                if (!component) {
                    console.error('[wizard] could not resolve Livewire component');
                    return;
                }
                component.call('selectPlace', reshaped);
            },
            onError: (err) => {
                console.error('[wizard] PlacesAutocomplete error:', err);
            },
        });
    } catch (e) {
        console.error('[wizard] Failed to initialise PlacesAutocomplete:', e);
        // Reset the flag so a future MutationObserver tick can retry.
        delete el.dataset.pacMounted;
    }
}

function scanAndMount(root = document) {
    root.querySelectorAll?.(CONTAINER_SELECTOR).forEach(mountInto);
}

// Initial scan: container present on first paint? Mount.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => scanAndMount());
} else {
    scanAndMount();
}

// Livewire SPA-style navigation (audits.show ↔ home).
document.addEventListener('livewire:navigated', () => scanAndMount());

// Catch the container appearing mid-roundtrip — wizardStep 2 → 1
// via Kembali button, manual fallback toggle off, OAuth-return etc.
const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (node.nodeType !== Node.ELEMENT_NODE) continue;
            if (node.matches?.(CONTAINER_SELECTOR)) {
                mountInto(node);
            } else if (node.querySelector) {
                scanAndMount(node);
            }
        }
    }
});

function startObserving() {
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startObserving);
} else {
    startObserving();
}
