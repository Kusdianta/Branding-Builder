import './bootstrap';

// BB92 — exposes window.bbInitPlacesAutocomplete for the wizard's
// Step 1 partial to call via @script after Livewire mounts.
import './places-autocomplete';

// BB138 — audit dashboard charts (Chart.js). Idempotent + lazy, so it is
// safe to re-run on every Livewire navigation and DOM morph.
import { initAuditCharts } from './charts';

document.addEventListener('DOMContentLoaded', initAuditCharts);
document.addEventListener('livewire:navigated', initAuditCharts);
document.addEventListener('livewire:initialized', initAuditCharts);
// Livewire re-renders the result view as the audit completes; pick up any
// chart canvases that appear after a server round-trip.
document.addEventListener('livewire:update', () => queueMicrotask(initAuditCharts));
