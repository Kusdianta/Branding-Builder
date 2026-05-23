// BB138 — Audit dashboard charts (Chart.js).
//
// Three Chart.js visualisations augment the brand-health dashboard:
//   - pillar-radar      (4 pillar scores vs ideal)
//   - content-donut     (IG reels/carousel/static mix)
//   - engagement-funnel (IG followers -> engaged, real-only 2 bars)
//
// SVG charts (score gauge, BE waterfall, touchpoint grid, reply gauge) are
// rendered server-side in Blade and need no JS.
//
// Design notes:
//   - Colours are resolved from the platform's CSS design tokens at init
//     time (getComputedStyle), never hardcoded — so charts inherit the
//     chimera palette and any future theme change for free.
//   - Charts inside collapsed Alpine accordions start at display:none and
//     would render at 0x0. An IntersectionObserver defers init until the
//     canvas actually has a box, so the donut draws correctly the first
//     time its accordion is opened.
//   - Each canvas is initialised at most once (guarded by data-bb-init);
//     the chart instance lives on the element so Livewire morphs over a
//     wire:ignore wrapper never double-init or duplicate it.

import {
    Chart,
    RadarController,
    RadialLinearScale,
    PointElement,
    LineElement,
    Filler,
    DoughnutController,
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(
    RadarController,
    RadialLinearScale,
    PointElement,
    LineElement,
    Filler,
    DoughnutController,
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

// Resolve the design-token palette from CSS custom properties so chart
// colours track the live theme instead of duplicating hex values.
function palette() {
    const cs = getComputedStyle(document.documentElement);
    const v = (name, fallback) => {
        const raw = cs.getPropertyValue(name).trim();
        return raw !== '' ? raw : fallback;
    };
    return {
        chimera500: v('--chimera-500', '#3D8948'),
        chimera600: v('--chimera-600', '#326D3A'),
        chimera200: v('--chimera-200', '#9CC393'),
        textPrimary: v('--text-primary', '#0F1411'),
        textSecondary: v('--text-secondary', '#5A6259'),
        textTertiary: v('--text-tertiary', '#8A9088'),
        border: v('--border-default', 'rgba(15,20,17,0.08)'),
        surfaceCard: v('--surface-card', '#FFFFFF'),
    };
}

const fmtInt = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n));

function radarConfig(data, p) {
    return {
        type: 'radar',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Skor Brand',
                    data: data.scores,
                    backgroundColor: 'rgba(61, 137, 72, 0.15)',
                    borderColor: p.chimera500,
                    borderWidth: 2,
                    pointBackgroundColor: p.chimera500,
                    pointBorderColor: p.chimera500,
                    pointRadius: 4,
                    fill: true,
                },
                {
                    label: 'Skor Ideal',
                    data: data.labels.map(() => 100),
                    backgroundColor: 'transparent',
                    borderColor: p.border,
                    borderWidth: 1,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    fill: false,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    min: 0,
                    max: 100,
                    ticks: { display: false, stepSize: 25 },
                    angleLines: { color: p.border },
                    grid: { color: p.border },
                    pointLabels: { color: p.textSecondary, font: { size: 12, weight: '500' } },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}/100` },
                },
            },
        },
    };
}

function donutConfig(data, p) {
    return {
        type: 'doughnut',
        data: {
            labels: ['Reels', 'Carousel', 'Foto Statis'],
            datasets: [
                {
                    data: [data.reels, data.carousel, data.static],
                    backgroundColor: [p.chimera500, p.chimera600, p.textTertiary],
                    borderColor: p.surfaceCard,
                    borderWidth: 2,
                    hoverOffset: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: p.textPrimary, padding: 14, font: { size: 12 }, boxWidth: 12, boxHeight: 12 },
                },
                tooltip: {
                    callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw}%` },
                },
            },
        },
    };
}

function funnelConfig(data, p) {
    // Real-only 2-bar "funnel": Jangkauan (followers) -> Engagement aktif
    // (ER-midpoint x followers). No invented downstream tiers.
    return {
        type: 'bar',
        data: {
            labels: ['Jangkauan (followers)', 'Engagement aktif'],
            datasets: [
                {
                    data: [data.reach, data.engaged],
                    backgroundColor: ['rgba(61,137,72,0.9)', 'rgba(61,137,72,0.45)'],
                    borderRadius: 6,
                    barPercentage: 0.7,
                },
            ],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (ctx.dataIndex === 1 && data.erMid) {
                                return `${fmtInt(ctx.raw)} (≈ ER ${data.erMid}%)`;
                            }
                            return fmtInt(ctx.raw);
                        },
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: p.border },
                    ticks: { color: p.textSecondary, callback: (val) => fmtInt(val) },
                },
                y: { grid: { display: false }, ticks: { color: p.textPrimary, font: { size: 12 } } },
            },
        },
    };
}

const BUILDERS = {
    'pillar-radar': radarConfig,
    'content-donut': donutConfig,
    'engagement-funnel': funnelConfig,
};

function buildChart(canvas) {
    if (canvas.__bbChart) {
        return;
    }
    const type = canvas.dataset.chartType;
    const builder = BUILDERS[type];
    if (!builder) {
        return;
    }
    let data;
    try {
        data = JSON.parse(canvas.dataset.chartData || '{}');
    } catch {
        return; // malformed data -> leave fallback markup visible
    }
    try {
        canvas.__bbChart = new Chart(canvas, builder(data, palette()));
    } catch {
        // Chart.js failed: the underlying text/bars/list stay visible.
    }
}

let observer = null;

function ensureObserver() {
    if (observer || typeof IntersectionObserver === 'undefined') {
        return observer;
    }
    observer = new IntersectionObserver(
        (entries, obs) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    buildChart(entry.target);
                    obs.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.05 },
    );
    return observer;
}

export function initAuditCharts() {
    const canvases = document.querySelectorAll('canvas[data-chart-type]:not([data-bb-init])');
    if (canvases.length === 0) {
        return;
    }
    const obs = ensureObserver();
    canvases.forEach((canvas) => {
        canvas.setAttribute('data-bb-init', '1');
        if (obs) {
            obs.observe(canvas);
        } else {
            buildChart(canvas); // no IO support -> init eagerly
        }
    });
}
