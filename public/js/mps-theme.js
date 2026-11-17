(() => {
    'use strict';

    const key = 'mps-theme';
    const root = document.documentElement;
    let theme = 'light';
    try {
        theme = localStorage.getItem(key) === 'dark' ? 'dark' : 'light';
    } catch (error) {
        console.warn('MPS theme preference could not be read.', error);
    }
    root.dataset.theme = theme;
    root.style.colorScheme = theme;

    function updateButton() {
        const button = document.getElementById('mps-theme-toggle');
        if (!button) return;
        const dark = root.dataset.theme === 'dark';
        const label = dark ? 'Tukar ke Light Mode' : 'Tukar ke Dark Mode';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(dark));
    }

    function setTheme(value, persist = true) {
        const next = value === 'dark' ? 'dark' : 'light';
        root.dataset.theme = next;
        root.style.colorScheme = next;
        if (persist) {
            try {
                localStorage.setItem(key, next);
            } catch (error) {
                console.warn('MPS theme changed but could not be saved.', error);
            }
        }
        updateButton();
        if (window.Chart) {
            Object.values(window.Chart.instances).forEach((chart) => chart.update('none'));
        }
    }

    function registerCharts() {
        if (!window.Chart) return;
        const originals = new WeakMap();
        const palette = {
            '#0b5d32': '#34d399',
            '#0b5d32c9': '#34d399c9',
            '#0b5d3214': '#4ade8014',
            '#0b5d3215': '#4ade8015',
            '#c9a227': '#f5c76b',
            '#d89b20': '#e6bd55',
            '#3175bd': '#78b4f1',
        };
        function adaptColour(value, dark) {
            if (!dark) return value;
            if (Array.isArray(value)) return value.map((colour) => adaptColour(colour, true));
            return typeof value === 'string' ? (palette[value.toLowerCase()] || value) : value;
        }

        window.Chart.register({
            id: 'mpsTheme',
            beforeInit(chart) {
                const options = chart.config.options;
                originals.set(chart, {
                    colour: options.color,
                    scales: Object.fromEntries(Object.entries(options.scales || {}).map(([id, scale]) => [id, {
                        ticks: scale.ticks?.color,
                        title: scale.title?.color,
                        grid: scale.grid?.color,
                        border: scale.border?.color,
                    }])),
                    legend: options.plugins?.legend?.labels?.color,
                    tooltip: Object.fromEntries(
                        ['backgroundColor', 'titleColor', 'bodyColor', 'footerColor', 'borderColor', 'borderWidth']
                            .map((property) => [property, options.plugins?.tooltip?.[property]])
                    ),
                    datasets: chart.data.datasets.map((dataset) => ({
                        borderColor: dataset.borderColor,
                        backgroundColor: dataset.backgroundColor,
                    })),
                });
            },
            beforeUpdate(chart) {
                const saved = originals.get(chart);
                if (!saved) return;
                const dark = root.dataset.theme === 'dark';
                const text = dark ? '#afc0ce' : '#66756b';
                const grid = 'rgba(255, 255, 255, 0.10)';
                chart.options.color = dark ? text : (saved.colour || '#666');
                Object.entries(chart.options.scales || {}).forEach(([id, scale]) => {
                    const original = saved.scales[id] || {};
                    scale.ticks.color = dark ? text : (original.ticks || '#666');
                    scale.title.color = dark ? text : (original.title || '#666');
                    scale.grid.color = dark ? grid : (original.grid || 'rgba(0, 0, 0, 0.1)');
                    scale.border.color = dark ? grid : (original.border || 'rgba(0, 0, 0, 0.1)');
                });
                if (chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels.color = dark ? text : (saved.legend || '#666');
                }
                if (chart.options.plugins.tooltip) {
                    const darkTooltip = {
                        backgroundColor: '#1d3043',
                        titleColor: '#f1f5f9',
                        bodyColor: '#afc0ce',
                        footerColor: '#f1f5f9',
                        borderColor: 'rgba(255, 255, 255, 0.10)',
                        borderWidth: 1,
                    };
                    for (const property of Object.keys(saved.tooltip)) {
                        chart.options.plugins.tooltip[property] = dark
                            ? darkTooltip[property]
                            : (saved.tooltip[property] ?? window.Chart.defaults.plugins.tooltip[property]);
                    }
                }
                chart.data.datasets.forEach((dataset, index) => {
                    const original = saved.datasets[index];
                    if (!original) return;
                    for (const property of ['borderColor', 'backgroundColor']) {
                        if (original[property] !== undefined) {
                            dataset[property] = adaptColour(original[property], dark);
                        }
                    }
                });
            },
        });
    }

    window.MpsTheme = { setTheme, registerCharts };
    document.addEventListener('DOMContentLoaded', () => {
        updateButton();
        document.getElementById('mps-theme-toggle')?.addEventListener('click', () => {
            setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    });
    window.addEventListener('storage', (event) => {
        if (event.key === key) setTheme(event.newValue, false);
    });
})();
