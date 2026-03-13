<x-app-layout>
    <x-slot name="title">Система — мониторинг</x-slot>

    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>

    <style>
        [v-cloak] { display: none; }
        .widget { background: #fff; border-radius: 0.75rem; padding: 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .widget-title { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; margin-bottom: .75rem; }
        .widget-value { font-size: 2rem; font-weight: 700; color: #111827; line-height: 1; }
        .widget-sub   { font-size: .8rem; color: #6b7280; margin-top: .25rem; }

        /* Chart wrapper */
        .chart-wrap canvas { width: 100% !important; }

        /* Process table */
        .proc-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .proc-table th { background: #f3f4f6; padding: 4px 8px; text-align: left; font-weight: 600; color: #374151; }
        .proc-table td { padding: 4px 8px; border-top: 1px solid #f3f4f6; color: #374151; }
        .proc-table tr:hover td { background: #f9fafb; }

        /* Sites table */
        .sites-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
        .sites-table th { background: #f3f4f6; padding: 6px 10px; text-align: left; font-weight: 600; color: #374151; }
        .sites-table td { padding: 6px 10px; border-top: 1px solid #f3f4f6; color: #374151; vertical-align: middle; }
        .sites-table tr:hover td { background: #f9fafb; }

        /* Disk bar */
        .disk-bar-bg { background: #e5e7eb; border-radius: 999px; height: 10px; overflow: hidden; }
        .disk-bar-fill { height: 100%; border-radius: 999px; transition: width .4s; }

        /* Badge */
        .badge-green { display:inline-flex; align-items:center; gap:3px; background:#d1fae5; color:#065f46; border-radius:999px; padding:2px 8px; font-size:.72rem; font-weight:600; }
        .badge-red   { display:inline-flex; align-items:center; gap:3px; background:#fee2e2; color:#991b1b; border-radius:999px; padding:2px 8px; font-size:.72rem; font-weight:600; }

        /* Loading pulse */
        .pulse-dot { width:8px; height:8px; border-radius:50%; background:#10b981; animation: pulse 1.5s infinite; display:inline-block; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }
    </style>

    <div id="app" v-cloak>

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Система</h1>
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <span class="pulse-dot"></span>
                Обновлено: @{{ lastUpdate || '—' }}
            </div>
        </div>

        <!-- Row 1: CPU + Memory charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="widget chart-wrap">
                <div class="widget-title">Загрузка CPU (последние 5 часов)</div>
                <canvas id="cpuChart" height="120"></canvas>
            </div>
            <div class="widget chart-wrap">
                <div class="widget-title">Использование памяти (последние 5 часов)</div>
                <canvas id="memChart" height="120"></canvas>
            </div>
        </div>

        <!-- Row 2: Top processes -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="widget">
                <div class="widget-title">Топ-5 процессов по CPU</div>
                <table class="proc-table">
                    <thead>
                        <tr><th>Процесс</th><th>CPU %</th><th>MEM %</th><th>PID</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in topCpu" :key="'cpu-' + p.pid">
                            <td class="max-w-xs truncate" :title="p.cmd">@{{ p.name }}</td>
                            <td class="font-mono">@{{ p.cpu.toFixed(1) }}</td>
                            <td class="font-mono">@{{ p.mem.toFixed(1) }}</td>
                            <td class="text-gray-400 font-mono">@{{ p.pid }}</td>
                        </tr>
                        <tr v-if="!topCpu.length"><td colspan="4" class="text-gray-400 text-center py-2">нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="widget">
                <div class="widget-title">Топ-5 процессов по памяти</div>
                <table class="proc-table">
                    <thead>
                        <tr><th>Процесс</th><th>MEM %</th><th>CPU %</th><th>PID</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in topMem" :key="'mem-' + p.pid">
                            <td class="max-w-xs truncate" :title="p.cmd">@{{ p.name }}</td>
                            <td class="font-mono">@{{ p.mem.toFixed(1) }}</td>
                            <td class="font-mono">@{{ p.cpu.toFixed(1) }}</td>
                            <td class="text-gray-400 font-mono">@{{ p.pid }}</td>
                        </tr>
                        <tr v-if="!topMem.length"><td colspan="4" class="text-gray-400 text-center py-2">нет данных</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Row 3: Disk + Sites count -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">

            <!-- Disk total -->
            <div class="widget">
                <div class="widget-title">Объём диска</div>
                <div class="widget-value">@{{ formatBytes(disk.total) }}</div>
                <div class="widget-sub">Всего на /</div>
            </div>

            <!-- Disk free -->
            <div class="widget">
                <div class="widget-title">Свободно на диске</div>
                <div class="widget-value" :class="disk.usedPercent > 85 ? 'text-red-600' : 'text-emerald-600'">
                    @{{ formatBytes(disk.free) }}
                </div>
                <div class="widget-sub mt-2">
                    <div class="flex justify-between text-xs mb-1">
                        <span>Занято @{{ disk.usedPercent }}%</span>
                        <span>@{{ formatBytes(disk.used) }} / @{{ formatBytes(disk.total) }}</span>
                    </div>
                    <div class="disk-bar-bg">
                        <div class="disk-bar-fill"
                             :style="{ width: disk.usedPercent + '%', background: disk.usedPercent > 85 ? '#ef4444' : disk.usedPercent > 65 ? '#f59e0b' : '#10b981' }">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sites count -->
            <div class="widget">
                <div class="widget-title">Количество сайтов</div>
                <div class="widget-value text-blue-600">@{{ sites.length }}</div>
                <div class="widget-sub">сайтов на *.our24.ru</div>
            </div>

        </div>

        <!-- Row 4: Sites table -->
        <div class="widget">
            <div class="widget-title">Список сайтов</div>
            <div v-if="!sites.length" class="text-gray-400 text-sm py-4 text-center">нет сайтов в /www/wwwroot</div>
            <div v-else class="overflow-x-auto">
                <table class="sites-table">
                    <thead>
                        <tr>
                            <th>Адрес сайта</th>
                            <th style="width:130px; text-align:center">Планировщик</th>
                            <th style="width:160px">Активность (30 мин)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="site in sites" :key="site.domain">
                            <td class="font-mono text-sm">@{{ site.domain }}</td>
                            <td style="text-align:center">
                                <span v-if="site.scheduler" class="badge-green">
                                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                        <path d="M2 5l2.5 2.5L8 3" stroke="#065f46" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    активен
                                </span>
                                <span v-else class="badge-red">
                                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                        <path d="M3 3l4 4M7 3l-4 4" stroke="#991b1b" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                    нет
                                </span>
                            </td>
                            <td>
                                <canvas :id="'spark-' + site.domain" width="150" height="36" style="display:block"></canvas>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- #app -->

    <script>
    (function () {
        const { createApp, ref, onMounted, nextTick } = Vue;

        // ── Tiny canvas chart helpers ──────────────────────────────────────────

        /**
         * Draw a filled line chart on a canvas element.
         * @param {HTMLCanvasElement} canvas
         * @param {number[]} values     - data points (0-100)
         * @param {string[]} labels     - x-axis labels (shown every ~60 pts)
         * @param {string}   lineColor
         * @param {string}   fillColor
         */
        function drawLineChart(canvas, values, labels, lineColor, fillColor) {
            if (!canvas) return;
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width  = rect.width  * dpr;
            canvas.height = rect.height * dpr;
            const ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);

            const W = rect.width;
            const H = rect.height;
            const padL = 38, padR = 8, padT = 8, padB = 22;
            const cW = W - padL - padR;
            const cH = H - padT - padB;

            ctx.clearRect(0, 0, W, H);

            // Grid
            ctx.strokeStyle = '#e5e7eb';
            ctx.lineWidth   = 1;
            [0, 25, 50, 75, 100].forEach(pct => {
                const y = padT + cH - (pct / 100) * cH;
                ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + cW, y); ctx.stroke();
                ctx.fillStyle = '#9ca3af';
                ctx.font = '9px sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(pct + '%', padL - 4, y + 3);
            });

            if (!values.length) return;

            const n    = values.length;
            const xPos = i => padL + (i / (n - 1 || 1)) * cW;
            const yPos = v => padT + cH - Math.min(1, Math.max(0, v / 100)) * cH;

            // Fill area
            ctx.beginPath();
            ctx.moveTo(xPos(0), yPos(values[0]));
            for (let i = 1; i < n; i++) ctx.lineTo(xPos(i), yPos(values[i]));
            ctx.lineTo(xPos(n - 1), padT + cH);
            ctx.lineTo(xPos(0), padT + cH);
            ctx.closePath();
            ctx.fillStyle = fillColor;
            ctx.fill();

            // Line
            ctx.beginPath();
            ctx.moveTo(xPos(0), yPos(values[0]));
            for (let i = 1; i < n; i++) ctx.lineTo(xPos(i), yPos(values[i]));
            ctx.strokeStyle = lineColor;
            ctx.lineWidth   = 1.5;
            ctx.lineJoin    = 'round';
            ctx.stroke();

            // X labels (every ~60 points)
            ctx.fillStyle   = '#9ca3af';
            ctx.font        = '9px sans-serif';
            ctx.textAlign   = 'center';
            const step = Math.max(1, Math.floor(n / 5));
            for (let i = 0; i < n; i += step) {
                if (labels[i]) {
                    ctx.fillText(labels[i], xPos(i), padT + cH + padB - 4);
                }
            }
            // Always show last label
            if (labels[n - 1]) {
                ctx.fillText(labels[n - 1], xPos(n - 1), padT + cH + padB - 4);
            }
        }

        /**
         * Draw a sparkline inside a tiny canvas element (for the sites table).
         */
        function drawSparkline(canvas, data) {
            if (!canvas) return;
            canvas.width  = 150;
            canvas.height = 36;
            const ctx = canvas.getContext('2d');
            const W   = canvas.width;
            const H   = canvas.height;

            ctx.clearRect(0, 0, W, H);

            if (!data || data.length < 2) {
                ctx.fillStyle = '#e5e7eb';
                ctx.fillRect(0, H / 2, W, 1);
                return;
            }

            const max  = Math.max(...data, 1);
            const n    = data.length;
            const xPos = i => (i / (n - 1)) * W;
            const yPos = v => H - 2 - (v / max) * (H - 4);

            // Fill
            ctx.beginPath();
            ctx.moveTo(xPos(0), yPos(data[0]));
            for (let i = 1; i < n; i++) ctx.lineTo(xPos(i), yPos(data[i]));
            ctx.lineTo(xPos(n - 1), H);
            ctx.lineTo(0, H);
            ctx.closePath();
            ctx.fillStyle = 'rgba(99,102,241,.15)';
            ctx.fill();

            // Line
            ctx.beginPath();
            ctx.moveTo(xPos(0), yPos(data[0]));
            for (let i = 1; i < n; i++) ctx.lineTo(xPos(i), yPos(data[i]));
            ctx.strokeStyle = '#6366f1';
            ctx.lineWidth   = 1.5;
            ctx.lineJoin    = 'round';
            ctx.stroke();
        }

        // ── Vue app ────────────────────────────────────────────────────────────

        createApp({
            setup() {
                const cpuHistory = ref([]);
                const memHistory = ref([]);
                const topCpu     = ref([]);
                const topMem     = ref([]);
                const disk       = ref({ total: 0, free: 0, used: 0, usedPercent: 0 });
                const sites      = ref([]);
                const lastUpdate = ref('');

                let cpuChartEl = null;
                let memChartEl = null;

                const fetchData = async () => {
                    try {
                        const res  = await fetch('/system/data');
                        const data = await res.json();

                        cpuHistory.value = data.cpuHistory || [];
                        memHistory.value = data.memHistory || [];
                        topCpu.value     = data.topCpu     || [];
                        topMem.value     = data.topMem     || [];
                        disk.value       = data.disk       || disk.value;
                        sites.value      = data.sites      || [];
                        lastUpdate.value = new Date().toLocaleTimeString('ru-RU');

                        await nextTick();
                        renderCharts();
                    } catch (e) {
                        console.error('System data fetch error:', e);
                    }
                };

                const renderCharts = () => {
                    const cpuVals  = cpuHistory.value.map(p => p.v);
                    const memVals  = memHistory.value.map(p => p.v);
                    const cpuLbls  = cpuHistory.value.map(p => p.ts);
                    const memLbls  = memHistory.value.map(p => p.ts);

                    drawLineChart(cpuChartEl, cpuVals, cpuLbls, '#3b82f6', 'rgba(59,130,246,.12)');
                    drawLineChart(memChartEl, memVals, memLbls, '#8b5cf6', 'rgba(139,92,246,.12)');

                    sites.value.forEach(site => {
                        const canvas = document.getElementById('spark-' + site.domain);
                        drawSparkline(canvas, site.activity);
                    });
                };

                const formatBytes = (bytes) => {
                    if (!bytes) return '—';
                    const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
                    let v = bytes, i = 0;
                    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
                    return v.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
                };

                onMounted(async () => {
                    cpuChartEl = document.getElementById('cpuChart');
                    memChartEl = document.getElementById('memChart');

                    await fetchData();

                    // Refresh every 10 seconds
                    setInterval(fetchData, 10000);

                    // Redraw charts on resize
                    window.addEventListener('resize', renderCharts);
                });

                return { topCpu, topMem, disk, sites, lastUpdate, formatBytes };
            }
        }).mount('#app');
    })();
    </script>
</x-app-layout>
