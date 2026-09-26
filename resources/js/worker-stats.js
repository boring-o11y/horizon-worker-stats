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
     * Turn the response into the two series the charts draw.
     *
     * A bucket nothing sampled stays null so the line breaks there, instead of
     * dropping to a fleet using nothing.
     */
    function series() {
        const data = state.data || {};
        const labels = data.labels || [];
        const memory = (data.memory || []).map((value) => value === null ? null : value / BYTES_IN_MB);
        const cpu = (data.cpu || []).map((value) => value === null ? null : round(value, 2));

        const useGigabytes = Math.max(0, ...memory.filter((value) => value !== null)) >= 1024;
        const unit = useGigabytes ? 'GB' : 'MB';

        return {
            labels,
            hasData: memory.some((value) => value !== null),
            memory: {
                unit,
                color: '#7746ec',
                values: memory.map((value) => value === null
                    ? null
                    : round(useGigabytes ? value / 1024 : value, 2)),
            },
            cpu: {
                unit: 'cores',
                color: '#f6993f',
                values: cpu,
            },
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
     * Draw one line chart into a container.
     *
     * Runs of consecutive values become one path each, so a gap in sampling is
     * a break in the line. A value with a gap on both sides would draw nothing
     * at all, which includes the only value there is right after sampling
     * starts, so those get a point of their own.
     */
    function drawChart(container, labels, line) {
        const width = Math.max(240, container.clientWidth);
        const height = CHART_HEIGHT;
        const plotWidth = width - PADDING.left - PADDING.right;
        const plotHeight = height - PADDING.top - PADDING.bottom;
        const values = line.values;
        const max = niceMax(Math.max(0, ...values.filter((value) => value !== null)));
        const count = Math.max(1, labels.length - 1);

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
            text.textContent = round(value, 2) + (step === 4 ? ' ' + line.unit : '');
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

        let run = [];

        const flush = () => {
            if (run.length > 1) {
                chart.appendChild(svg('path', {
                    d: run.map((point, i) => (i === 0 ? 'M' : 'L') + point[0] + ' ' + point[1]).join(' '),
                    fill: 'none',
                    stroke: line.color,
                    'stroke-width': '2',
                    'stroke-linejoin': 'round',
                }));
            } else if (run.length === 1) {
                chart.appendChild(svg('circle', {cx: run[0][0], cy: run[0][1], r: '2.5', fill: line.color}));
            }

            run = [];
        };

        values.forEach((value, i) => value === null ? flush() : run.push([x(i), y(value)]));
        flush();

        const cursor = svg('line', {y1: PADDING.top, y2: PADDING.top + plotHeight, class: 'hws-cursor', visibility: 'hidden'});
        const dot = svg('circle', {r: '3.5', fill: line.color, visibility: 'hidden'});
        chart.appendChild(cursor);
        chart.appendChild(dot);

        const tooltip = document.createElement('div');
        tooltip.className = 'hws-tooltip';
        tooltip.hidden = true;

        chart.addEventListener('mousemove', (event) => {
            const bounds = chart.getBoundingClientRect();
            const offset = event.clientX - bounds.left;
            const i = Math.min(labels.length - 1, Math.max(0, Math.round(((offset - PADDING.left) / plotWidth) * count)));
            const value = values[i];

            cursor.setAttribute('x1', x(i));
            cursor.setAttribute('x2', x(i));
            cursor.setAttribute('visibility', 'visible');

            if (value === null) {
                dot.setAttribute('visibility', 'hidden');
            } else {
                dot.setAttribute('cx', x(i));
                dot.setAttribute('cy', y(value));
                dot.setAttribute('visibility', 'visible');
            }

            tooltip.innerHTML = '<div class="text-muted">' + escapeHtml(formatDateTime(labels[i])) + '</div>'
                + '<strong>' + escapeHtml(value === null ? 'Not sampled' : value + ' ' + line.unit) + '</strong>';
            tooltip.hidden = false;

            const left = Math.min(x(i) + 12, width - tooltip.offsetWidth - 4);
            tooltip.style.left = Math.max(4, left) + 'px';
        });

        chart.addEventListener('mouseleave', () => {
            cursor.setAttribute('visibility', 'hidden');
            dot.setAttribute('visibility', 'hidden');
            tooltip.hidden = true;
        });

        container.innerHTML = '';
        container.appendChild(chart);
        container.appendChild(tooltip);
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
            card('memory', 'Worker Memory — ' + span, 'Resident memory, summed across every worker on every machine'),
            card('cpu', 'Worker CPU — ' + span, 'Cores in use, summed across every worker on every machine'),
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
            const now = root.querySelector('[data-hws-now="' + key + '"]');
            const line = data[key];
            const current = latest(line.values);

            now.textContent = current === null ? '' : 'Now: ' + current + ' ' + line.unit;

            if (!data.hasData) {
                container.innerHTML = '<p class="text-center text-muted m-0 p-3">'
                    + (state.data ? 'Not Enough Data' : 'Loading…') + '</p>';

                return;
            }

            drawChart(container, data.labels, line);
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

    window.addEventListener('popstate', sync);
    window.addEventListener('hws:navigated', sync);

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', sync)
        : sync();
})();
