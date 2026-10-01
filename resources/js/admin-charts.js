// Admin Overview charts. Each <canvas data-chart='{...}'> describes its own chart:
// { type: 'bar'|'line'|'spark', labels, series: [{name, data}], money, stacked, horizontal }
// Colours follow a validated categorical order (slot 1 blue, slot 2 orange); text uses ink tokens.
import {
    Chart, BarController, LineController, BarElement, LineElement, PointElement,
    CategoryScale, LinearScale, Tooltip, Legend, Filler,
} from 'chart.js';

Chart.register(BarController, LineController, BarElement, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler);

const SLOTS = ['#2a78d6', '#eb6834'];
const INK = '#475569';
const GRID = '#e2e8f0';
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const inr = (v, compact = false) => {
    if (compact && Math.abs(v) >= 1e7) return `₹${(v / 1e7).toFixed(1).replace(/\.0$/, '')} Cr`;
    if (compact && Math.abs(v) >= 1e5) return `₹${(v / 1e5).toFixed(1).replace(/\.0$/, '')} L`;
    if (compact && Math.abs(v) >= 1e3) return `₹${(v / 1e3).toFixed(1).replace(/\.0$/, '')}k`;
    return `₹${Math.round(v).toLocaleString('en-IN')}`;
};

export function initAdminCharts() {
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.color = INK;

    document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        const cfg = JSON.parse(canvas.dataset.chart);
        const spark = cfg.type === 'spark';
        const fmt = (v) => (cfg.money ? inr(v, true) : Number(v).toLocaleString('en-IN'));

        const datasets = cfg.series.map((s, i) => {
            const color = SLOTS[i % SLOTS.length];
            const base = { label: s.name, data: s.data, borderColor: color, backgroundColor: color };
            if (cfg.type === 'bar') {
                return { ...base, borderRadius: 4, borderSkipped: 'start', maxBarThickness: cfg.horizontal ? 18 : 22,
                    borderWidth: { top: 0, right: 0, bottom: 0, left: 0 }, stack: cfg.stacked ? 'all' : undefined };
            }
            return { ...base, borderWidth: 2, cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 5,
                pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2,
                fill: spark ? { target: 'origin', above: `${color}1f` } : false };
        });

        new Chart(canvas, {
            type: spark ? 'line' : cfg.type,
            data: { labels: cfg.labels, datasets },
            options: {
                animation: reduce ? false : { duration: 400 },
                maintainAspectRatio: false,
                indexAxis: cfg.horizontal ? 'y' : 'x',
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: !spark && cfg.series.length > 1, position: 'top', align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, useBorderRadius: true, borderRadius: 2 } },
                    tooltip: { enabled: true, backgroundColor: '#0f172a', padding: 10, cornerRadius: 8,
                        callbacks: { label: (c) => ` ${c.dataset.label}: ${fmt(c.parsed[cfg.horizontal ? 'x' : 'y'])}` } },
                },
                scales: spark ? { x: { display: false }, y: { display: false, beginAtZero: true } } : {
                    x: { stacked: !!cfg.stacked, grid: { display: !!cfg.horizontal, color: GRID }, border: { display: false },
                        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: cfg.horizontal ? 5 : 8, callback(v) {
                            return cfg.horizontal ? fmt(v) : this.getLabelForValue(v);
                        } }, beginAtZero: true },
                    y: { stacked: !!cfg.stacked, beginAtZero: true, grid: { display: !cfg.horizontal, color: GRID }, border: { display: false },
                        ticks: { maxTicksLimit: 5, precision: 0, callback(v) { return cfg.horizontal ? this.getLabelForValue(v) : fmt(v); } } },
                },
            },
        });
    });
}
