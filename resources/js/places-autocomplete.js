// BB92 — Wizard Step 1 Places Autocomplete integration.
//
// Wraps the places-autocomplete-js library (v1.2.4) so the wizard's
// step-1 partial can drop a single Volt @script block that hands off
// place selection back to the Livewire component via $wire.call().
//
// The library handles session-token lifecycle (cheap autocomplete
// keystrokes + one billed Place Details call on selection) for us,
// so we don't manage tokens ourselves.
//
// The shape we hand to Livewire mirrors the legacy google.maps.places
// shape (place_id, name, formatted_address, geometry.location.lat/lng,
// website, international_phone_number, types) so the server-side
// selectPlace() handler in the wizard Volt class doesn't need to
// branch on whether the data came from autocomplete or the manual
// fallback path.

import { PlacesAutocomplete } from 'places-autocomplete-js';
import 'places-autocomplete-js/places-autocomplete.css';

/**
 * Reshape the New Places API response (camelCase, modern Place v1)
 * into the legacy `Autocomplete` widget shape the wizard's
 * selectPlace() PHP handler expects.
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
        website: place?.websiteUri ?? place?.website ?? null,
        international_phone_number:
            place?.internationalPhoneNumber ?? place?.international_phone_number ?? null,
        types: place?.types ?? [],
        address_components:
            place?.addressComponents ?? place?.address_components ?? [],
        raw: place,
    };
}

/**
 * Mount a PlacesAutocomplete widget into `containerId` and pipe
 * selections back into the supplied Livewire component handle.
 *
 * Returns the widget instance so the caller can call .destroy() if
 * the component is removed from the DOM (e.g. wizard step transition).
 */
window.bbInitPlacesAutocomplete = function ({
    containerId,
    livewireComponent,
    apiKey,
    countryBias = 'id',
    onError,
} = {}) {
    if (!apiKey) {
        console.error('[wizard] Google Maps API key is missing; autocomplete disabled.');
        onError?.({ message: 'Google Maps API key is missing.' });
        return null;
    }

    const container = document.getElementById(containerId);
    if (!container) {
        console.error(`[wizard] container #${containerId} not found.`);
        return null;
    }

    try {
        return new PlacesAutocomplete({
            containerId,
            googleMapsApiKey: apiKey,
            requestParams: {
                includedRegionCodes: [countryBias],
                languageCode: 'id',
                regionCode: countryBias,
                includedPrimaryTypes: ['establishment'],
            },
            options: {
                placeholder: 'Cari nama bisnis di Google Maps...',
            },
            fetchFields: [
                'id',
                'displayName',
                'formattedAddress',
                'addressComponents',
                'location',
                'websiteUri',
                'internationalPhoneNumber',
                'types',
            ],
            onResponse: (place) => {
                const reshaped = reshapePlace(place);
                if (!reshaped?.place_id) {
                    console.warn('[wizard] selected place has no place_id', place);
                    return;
                }
                if (!livewireComponent || typeof livewireComponent.call !== 'function') {
                    console.error('[wizard] livewire component handle invalid');
                    return;
                }
                livewireComponent.call('selectPlace', reshaped);
            },
            onError: (err) => {
                console.error('[wizard] PlacesAutocomplete error:', err);
                onError?.(err);
            },
        });
    } catch (e) {
        console.error('[wizard] Failed to initialise PlacesAutocomplete:', e);
        onError?.({ message: e?.message ?? String(e) });
        return null;
    }
};
