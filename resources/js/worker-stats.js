/**
 * The Worker Stats page for the Horizon dashboard.
 *
 * Horizon's dashboard is a compiled Vue bundle with no extension point, and the
 * Chart.js it bundles is not exposed, so this runs alongside it as plain
 * JavaScript and draws its own SVG charts. It renders into the mount the server
 * spliced in after Horizon's router outlet, on a path Horizon's own router does
 * not know: there, Horizon renders an empty outlet and this page is the only
 * content in the column.
 */
(function () {
    const settings = window.HorizonWorkerStats;

    if (!settings) {
        return;
    }

    const BYTES_IN_MB = 1024 * 1024;
    const CHART_HEIGHT = 220;
    const PADDING = {top: 12, right: 12, bottom: 28, left: 52};
    const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * One colour per supervisor, in a fixed order validated for colour-blind
     * separation between neighbours, with its own steps for the dark theme.
     * Supervisors past the last slot fold into "Other" rather than reusing one.
     */
    const PALETTE = {
        light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
        dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
    };

    const state = {
        data: null,
        error: null,
    };

    let pollTimer = null;
    let mounted = false;

    /* ------------------------------------------------------------- helpers */

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function mount() {
        return document.getElementById(settings.pageId);
    }

    function onPage() {
        return window.location.pathname.replace(/\/$/, '') === settings.pagePath.replace(/\/$/, '');
    }

    function formatTime(timestamp) {
        return new Date(timestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    function formatDateTime(timestamp) {
        return new Date(timestamp * 1000).toLocaleString([], {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
        });
    }

    function round(value, places) {
        const factor = Math.pow(10, places);

        return Math.round(value * factor) / factor;
    }

    function latest(values) {
        for (let i = values.length - 1; i >= 0; i--) {
            if (values[i] !== null) {
                return values[i];
            }
        }

        return null;
    }

    /* ------------------------------------------------------------ requests */

    function load() {
        return fetch(settings.indexUrl, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Request failed with status ' + response.status);
                }

                return response.json();
            })
            .then((data) => {
                state.data = data;
                state.error = null;
            })
            .catch((error) => {
                state.error = error.message || 'Could not load worker stats.';
            })
            .finally(render);
    }

    /* ---------------------------------------------------------- the series */

    /**
     * Whether Horizon is showing its dark theme.
     *
     * Horizon switches themes by setting the media query on its dark stylesheet
     * rather than with a class, so that stylesheet is what is asked.
     */
    function dark() {
        const sheet = document.querySelector('style[data-scheme="dark"]');
        const media = sheet ? sheet.getAttribute('media') : '(prefers-color-scheme: dark)';

        return !media || window.matchMedia(media).matches;
    }

    /**
     * Give each supervisor its colour, folding any past the last one into "Other".
     *
     * When there are too many, the ones using the least across the window are
     * folded, so the band that matters is never the one hidden. Colours go by
     * name among the supervisors shown, so they hold between refreshes for as
     * long as the same supervisors are in the window.
     */
    function layers(supervisors) {
        const colors = PALETTE[dark() ? 'dark' : 'light'];
        const paint = (list) => list.map((supervisor, i) => Object.assign({color: colors[i]}, supervisor));

        if (supervisors.length <= colors.length) {
            return paint(supervisors);
        }

        const total = (supervisor, key) => supervisor[key].reduce((sum, value) => sum + (value || 0), 0);
        const fleet = (key) => supervisors.reduce((sum, supervisor) => sum + total(supervisor, key), 0) || 1;
        const share = (supervisor) => total(supervisor, 'memory') / fleet('memory') + total(supervisor, 'cpu') / fleet('cpu');

        const ranked = supervisors.slice().sort((a, b) => share(b) - share(a));
        const shown = ranked.slice(0, colors.length - 1).map((supervisor) => supervisor.name);
        const kept = supervisors.filter((supervisor) => shown.includes(supervisor.name));
        const rest = supervisors.filter((supervisor) => !shown.includes(supervisor.name));
        const sum = (key) => rest[0][key].map((value, i) => value === null
            ? null
            : rest.reduce((total, supervisor) => total + (supervisor[key][i] || 0), 0));

        return paint(kept).concat([{
            name: 'Other (' + rest.length + ')',
            color: colors[colors.length - 1],
            memory: sum('memory'),
            cpu: sum('cpu'),
        }]);
    }

    /**
     * Turn the response into what the two charts draw.
     *
     * Each chart is the fleet total broken into one layer per supervisor. A
     * bucket nothing sampled stays null so the chart breaks there, instead of
     * dropping to a fleet using nothing.
     */
    function series() {
        const data = state.data || {};
        const labels = data.labels || [];
        // A repository that predates the breakdown still gets its total drawn.
        const supervisors = layers(data.supervisors && data.supervisors.length
            ? data.supervisors
            : [{name: 'All workers', memory: data.memory || [], cpu: data.cpu || []}]);

        const megabytes = (value) => value === null ? null : value / BYTES_IN_MB;
        const useGigabytes = Math.max(0, ...(data.memory || []).map(megabytes).filter((value) => value !== null)) >= 1024;
        // Kept unrounded, so the bands stack to exactly the total. Only the
        // numbers shown as text are rounded.
        const toMemory = (value) => value === null ? null : (useGigabytes ? megabytes(value) / 1024 : megabytes(value));
        const toCpu = (value) => value;

        const chart = (key, unit, convert) => ({
            unit,
            total: (data[key] || []).map(convert),
            layers: supervisors.map((supervisor) => ({
                name: supervisor.name,
                color: supervisor.color,
                values: supervisor[key].map(convert),
            })),
        });

        return {
            labels,
            hasData: (data.memory || []).some((value) => value !== null),
            memory: chart('memory', useGigabytes ? 'GB' : 'MB', toMemory),
            cpu: chart('cpu', 'cores', toCpu),
        };
    }

    /* -------------------------------------------------------------- charts */

    function svg(name, attributes) {
        const element = document.createElementNS(SVG_NS, name);

        Object.keys(attributes || {}).forEach((key) => element.setAttribute(key, attributes[key]));

        return element;
    }

    /**
     * A tidy maximum for the y axis, so the gridlines land on round numbers.
     */
    function niceMax(value) {
        if (value <= 0) {
            return 1;
        }

        const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        const steps = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];

        for (let i = 0; i < steps.length; i++) {
            if (steps[i] * magnitude >= value) {
                return steps[i] * magnitude;
            }
        }

        return 10 * magnitude;
    }

    /**
     * Draw one stacked area chart into a container.
     *
     * Each supervisor is a band on top of the ones before it, so the top edge is
     * the fleet total. Runs of sampled buckets are drawn separately, so a gap in
     * sampling is a break in the chart. A bucket with a gap on both sides would
     * draw nothing at all, which includes the only one there is right after
     * sampling starts, so those get a point per band instead.
     */
    function drawChart(container, labels, data) {
        const width = Math.max(240, container.clientWidth);
        const height = CHART_HEIGHT;
        const plotWidth = width - PADDING.left - PADDING.right;
        const plotHeight = height - PADDING.top - PADDING.bottom;
        const count = Math.max(1, labels.length - 1);

        // The running total under and over each band, bucket by bucket.
        const stacked = [];

        data.layers.forEach((layer, l) => {
            stacked.push(layer.values.map((value, i) => {
                const below = l === 0 || stacked[l - 1][i] === null ? 0 : stacked[l - 1][i].top;

                return value === null ? null : {bottom: below, top: below + value};
            }));
        });

        const tops = stacked.length ? stacked[stacked.length - 1] : [];
        const max = niceMax(Math.max(0,
            ...data.total.filter((value) => value !== null),
            ...tops.filter((point) => point !== null).map((point) => point.top)));

        const x = (i) => PADDING.left + (i / count) * plotWidth;
        const y = (value) => PADDING.top + plotHeight - (value / max) * plotHeight;

        const chart = svg('svg', {
            width: String(width),
            height: String(height),
            viewBox: '0 0 ' + width + ' ' + height,
            class: 'hws-chart',
            role: 'img',
        });

        for (let step = 0; step <= 4; step++) {
            const value = (max / 4) * step;

            chart.appendChild(svg('line', {
                x1: PADDING.left, x2: width - PADDING.right, y1: y(value), y2: y(value), class: 'hws-grid',
            }));

            const text = svg('text', {x: PADDING.left - 8, y: y(value) + 4, 'text-anchor': 'end', class: 'hws-axis'});
            text.textContent = round(value, 2) + (step === 4 ? ' ' + data.unit : '');
            chart.appendChild(text);
        }

        const tickEvery = Math.max(1, Math.ceil(labels.length / Math.max(2, Math.floor(plotWidth / 90))));

        labels.forEach((label, i) => {
            if (i % tickEvery !== 0) {
                return;
            }

            const text = svg('text', {x: x(i), y: height - 8, 'text-anchor': 'middle', class: 'hws-axis'});
            text.textContent = formatTime(label);
            chart.appendChild(text);
        });

        const runs = [];
        let run = [];

        data.total.forEach((value, i) => {
            if (value === null) {
                run.length && runs.push(run);
                run = [];
            } else {
                run.push(i);
            }
        });

        run.length && runs.push(run);

        runs.forEach((indexes) => {
            if (indexes.length === 1) {
                return;
            }

            stacked.forEach((band, l) => {
                const top = indexes.map((i) => x(i) + ' ' + y(band[i].top));
                const bottom = indexes.slice().reverse().map((i) => x(i) + ' ' + y(band[i].bottom));

                chart.appendChild(svg('path', {
                    d: 'M' + top.join(' L') + ' L' + bottom.join(' L') + ' Z',
                    fill: data.layers[l].color,
                    class: 'hws-band',
                }));
            });
        });

        // Edges go on top band first, so a band using nothing, whose edge lies
        // along the one below, is drawn over by that band's own colour.
        runs.forEach((indexes) => stacked.slice().reverse().forEach((band, r) => {
            const color = data.layers[stacked.length - 1 - r].color;

            if (indexes.length === 1) {
                chart.appendChild(svg('circle', {cx: x(indexes[0]), cy: y(band[indexes[0]].top), r: '2.5', fill: color, class: 'hws-ring'}));

                return;
            }

            chart.appendChild(svg('path', {
                d: 'M' + indexes.map((i) => x(i) + ' ' + y(band[i].top)).join(' L'),
                fill: 'none',
                stroke: color,
                'stroke-width': '2',
                'stroke-linejoin': 'round',
            }));
        }));

        const cursor = svg('line', {y1: PADDING.top, y2: PADDING.top + plotHeight, class: 'hws-cursor', visibility: 'hidden'});
        chart.appendChild(cursor);

        const dots = data.layers.map((layer) => {
            const dot = svg('circle', {r: '4', fill: layer.color, class: 'hws-ring', visibility: 'hidden'});
            chart.appendChild(dot);

            return dot;
        });

        const tooltip = document.createElement('div');
        tooltip.className = 'hws-tooltip';
        tooltip.hidden = true;

        chart.addEventListener('mousemove', (event) => {
            const bounds = chart.getBoundingClientRect();
            const offset = event.clientX - bounds.left;
            const i = Math.min(labels.length - 1, Math.max(0, Math.round(((offset - PADDING.left) / plotWidth) * count)));
            const total = data.total[i];

            cursor.setAttribute('x1', x(i));
            cursor.setAttribute('x2', x(i));
            cursor.setAttribute('visibility', 'visible');

            dots.forEach((dot, l) => {
                const point = stacked[l][i];

                if (point === null) {
                    dot.setAttribute('visibility', 'hidden');
                } else {
                    dot.setAttribute('cx', x(i));
                    dot.setAttribute('cy', y(point.top));
                    dot.setAttribute('visibility', 'visible');
                }
            });

            // Listed top band first, the same order the eye meets them in.
            const rows = total === null ? '' : data.layers.slice().reverse().map((layer) => '<div class="hws-row">'
                + '<span class="hws-swatch" style="background:' + layer.color + '"></span>'
                + '<span class="hws-name">' + escapeHtml(layer.name) + '</span>'
                + '<span class="hws-value">' + escapeHtml(round(layer.values[i], 2) + ' ' + data.unit) + '</span>'
                + '</div>').join('');

            tooltip.innerHTML = '<div class="text-muted">' + escapeHtml(formatDateTime(labels[i])) + '</div>'
                + rows
                + '<div class="hws-row hws-total"><span class="hws-name">Total</span><strong class="hws-value">'
                + escapeHtml(total === null ? 'Not sampled' : round(total, 2) + ' ' + data.unit) + '</strong></div>';
            tooltip.hidden = false;

            const left = Math.min(x(i) + 12, width - tooltip.offsetWidth - 4);
            tooltip.style.left = Math.max(4, left) + 'px';
        });

        chart.addEventListener('mouseleave', () => {
            cursor.setAttribute('visibility', 'hidden');
            dots.forEach((dot) => dot.setAttribute('visibility', 'hidden'));
            tooltip.hidden = true;
        });

        container.innerHTML = '';
        container.appendChild(chart);
        container.appendChild(tooltip);
    }

    /**
     * List each supervisor with its latest value, so identity and the numbers
     * never rest on colour alone.
     */
    function drawLegend(container, data) {
        container.innerHTML = data.layers.slice().reverse().map((layer) => {
            const current = latest(layer.values);

            return '<span class="hws-legend-item">'
                + '<span class="hws-swatch" style="background:' + layer.color + '"></span>'
                + escapeHtml(layer.name)
                + (current === null ? '' : '<span class="hws-legend-value text-muted">' + escapeHtml(round(current, 2) + ' ' + data.unit) + '</span>')
                + '</span>';
        }).join('');
    }

    /* ---------------------------------------------------------- rendering */

    function card(key, title, subtitle) {
        return [
            '<div class="col-12 col-xl-6 mb-4">',
            '  <div class="card overflow-hidden h-100">',
            '    <div class="card-header d-flex align-items-center justify-content-between">',
            '      <h2 class="h6 m-0">' + escapeHtml(title) + '</h2>',
            '      <small class="text-muted" data-hws-now="' + key + '"></small>',
            '    </div>',
            '    <div class="card-body card-bg-secondary">',
            '      <small class="text-muted d-block mb-2">' + escapeHtml(subtitle) + '</small>',
            '      <div class="hws-chart-container" data-hws-chart="' + key + '"></div>',
            '      <div class="hws-legend" data-hws-legend="' + key + '"></div>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join('');
    }

    function shell() {
        const span = settings.retention === 1 ? 'Last Hour' : 'Last ' + settings.retention + ' Hours';

        return [
            '<div data-hws-notice></div>',
            '<div class="row">',
            card('memory', 'Worker Memory — ' + span, 'Resident memory by supervisor, summed across every machine'),
            card('cpu', 'Worker CPU — ' + span, 'Cores in use by supervisor, summed across every machine'),
            '</div>',
        ].join('');
    }

    function render() {
        const root = mount();

        if (!root || !onPage()) {
            return;
        }

        if (!mounted) {
            root.innerHTML = shell();
            mounted = true;
        }

        const notice = root.querySelector('[data-hws-notice]');
        const data = series();

        notice.innerHTML = state.error
            ? '<div class="alert alert-danger">' + escapeHtml(state.error) + '</div>'
            : '';

        ['memory', 'cpu'].forEach((key) => {
            const container = root.querySelector('[data-hws-chart="' + key + '"]');
            const legend = root.querySelector('[data-hws-legend="' + key + '"]');
            const now = root.querySelector('[data-hws-now="' + key + '"]');
            const chart = data[key];
            const current = latest(chart.total);

            now.textContent = current === null ? '' : 'Now: ' + round(current, 2) + ' ' + chart.unit;

            if (!data.hasData) {
                container.innerHTML = '<p class="text-center text-muted m-0 p-3">'
                    + (state.data ? 'Not Enough Data' : 'Loading…') + '</p>';
                legend.innerHTML = '';

                return;
            }

            drawChart(container, data.labels, chart);
            drawLegend(legend, chart);
        });
    }

    /* ----------------------------------------------------------- lifecycle */

    function start() {
        stop();
        load();
        pollTimer = setInterval(load, settings.pollInterval);
    }

    function stop() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function highlight() {
        document.querySelectorAll('[data-hws-nav]').forEach((link) => link.classList.toggle('active', onPage()));
    }

    function sync() {
        const root = mount();

        highlight();

        if (!root) {
            return;
        }

        if (!onPage()) {
            stop();
            mounted = false;
            root.innerHTML = '';

            return;
        }

        if (pollTimer === null) {
            start();
        }
    }

    /**
     * Horizon navigates with the History API, which fires no event of its own,
     * so pushState is wrapped to announce itself. Without this the page would
     * only appear on a full load.
     */
    ['pushState', 'replaceState'].forEach((method) => {
        const original = history[method];

        history[method] = function () {
            const result = original.apply(this, arguments);

            window.dispatchEvent(new Event('hws:navigated'));

            return result;
        };
    });

    let resizeTimer = null;

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 150);
    });

    // Redrawn in the other theme's colours when Horizon's toggle, or the
    // system setting it follows, switches between light and dark.
    const darkSheet = document.querySelector('style[data-scheme="dark"]');

    darkSheet && new MutationObserver(render).observe(darkSheet, {attributes: true, attributeFilter: ['media']});
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', render);

    window.addEventListener('popstate', sync);
    window.addEventListener('hws:navigated', sync);

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', sync)
        : sync();
})();
