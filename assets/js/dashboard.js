        const dashboardDataElement = document.getElementById('dashboard-data');
        const dashboardData = (() => {
            if (!dashboardDataElement) {
                return {};
            }

            try {
                return JSON.parse(dashboardDataElement.textContent || '{}');
            } catch (error) {
                console.error('Failed to parse dashboard payload:', error);
                return {};
            }
        })();
        const themeStorageKey = 'mfoodTheme';
        const availableThemes = ['sky', 'executive', 'midnight'];
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let rootStyle = getComputedStyle(document.documentElement);
        const chartInstances = [];

        const readThemeValue = (name, fallback) => {
            const value = rootStyle.getPropertyValue(name).trim();
            return value || fallback;
        };

        const refreshThemeContext = () => {
            rootStyle = getComputedStyle(document.documentElement);
        };

        const resolveTheme = (themeName) => {
            return availableThemes.indexOf(themeName) !== -1 ? themeName : 'sky';
        };

        const readSessionFlag = (key) => {
            try {
                return window.sessionStorage.getItem(key);
            } catch (error) {
                return null;
            }
        };

        const writeSessionFlag = (key, value) => {
            try {
                window.sessionStorage.setItem(key, value);
            } catch (error) {
                // Ignore session storage errors for restrictive environments.
            }
        };

        const clearSessionFlag = (key) => {
            try {
                window.sessionStorage.removeItem(key);
            } catch (error) {
                // Ignore session storage errors for restrictive environments.
            }
        };

        const persistTheme = (themeName) => {
            try {
                window.localStorage.setItem(themeStorageKey, themeName);
            } catch (error) {
                // Ignore storage errors and fall back to cookies.
            }

            document.cookie = `${themeStorageKey}=${encodeURIComponent(themeName)}; path=/; max-age=31536000; samesite=lax`;
        };

        const applyTheme = (themeName, shouldPersist = true, shouldRenderCharts = true) => {
            const resolvedTheme = resolveTheme(themeName);
            document.documentElement.setAttribute('data-theme', resolvedTheme);
            refreshThemeContext();

            if (shouldPersist) {
                persistTheme(resolvedTheme);
            }

            const themeSelector = document.getElementById('themeSelector');
            if (themeSelector && themeSelector.value !== resolvedTheme) {
                themeSelector.value = resolvedTheme;
            }

            if (shouldRenderCharts) {
                renderCharts();
            }
        };

        const compactLabel = (label, maxLength = 26) => {
            if (!label || window.innerWidth > 720 || label.length <= maxLength) {
                return label;
            }

            return `${label.slice(0, maxLength - 1)}…`;
        };

        const formatCurrency = (value) => new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(value);

        const formatQuantity = (value) => new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(value);

        const formatSpendBarLabel = (value) => {
            const absValue = Math.abs(value);

            if (absValue >= 1000000) {
                return `${new Intl.NumberFormat('th-TH', {
                    minimumFractionDigits: absValue >= 10000000 ? 0 : 1,
                    maximumFractionDigits: absValue >= 10000000 ? 0 : 1
                }).format(value / 1000000)} ล.`;
            }

            return new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(value);
        };

        const spendValueLabelsPlugin = {
            id: 'spendValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || chart.canvas.id !== 'spendChart') {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();

                ctx.save();
                ctx.font = `700 12px ${Chart.defaults.font.family}`;
                ctx.textBaseline = 'middle';

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    meta.data.forEach((bar, index) => {
                        if (!bar) return;
                        const props = bar.getProps ? bar.getProps(['x', 'y', 'base', 'horizontal'], true) : bar;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        const label = formatSpendBarLabel(rawValue);
                        const textWidth = ctx.measureText(label).width;
                        const isNegative = rawValue < 0;
                        const outsideOffset = 8;
                        const insideOffset = 10;
                        const defaultColor = readThemeValue('--neutral-700', '#334155');
                        const insideColor = datasetIndex === 0
                            ? readThemeValue('--button-text', '#ffffff')
                            : readThemeValue('--neutral-900', '#0f172a');
                        let textX = isNegative ? x - outsideOffset - textWidth : x + outsideOffset;
                        let textColor = defaultColor;

                        if (!isNegative && textX + textWidth > chartArea.right - 4) {
                            textX = Math.max(chartArea.left + 4, x - insideOffset - textWidth);
                            textColor = insideColor;
                        } else if (isNegative && textX < chartArea.left + 4) {
                            textX = Math.min(chartArea.right - textWidth - 4, x + insideOffset);
                            textColor = insideColor;
                        }

                        ctx.strokeStyle = outlineColor;
                        ctx.lineWidth = 3;
                        ctx.lineJoin = 'round';
                        ctx.strokeText(label, textX, y);
                        ctx.fillStyle = textColor;
                        ctx.fillText(label, textX, y);
                    });
                });

                ctx.restore();
            }
        };

        const drawLabelWithOutline = (ctx, label, x, y, fillColor, outlineColor) => {
            ctx.strokeStyle = outlineColor;
            ctx.lineWidth = 3;
            ctx.lineJoin = 'round';
            ctx.strokeText(label, x, y);
            ctx.fillStyle = fillColor;
            ctx.fillText(label, x, y);
        };

        const getOutlineColor = () => {
            const theme = document.documentElement.getAttribute('data-theme');
            return theme === 'midnight' ? 'rgba(15, 23, 42, 0.85)' : 'rgba(255, 255, 255, 0.92)';
        };

        const productionValueLabelsPlugin = {
            id: 'productionValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || chart.canvas.id !== 'productionChart') {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();

                ctx.save();
                ctx.font = `700 13px ${Chart.defaults.font.family}`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    if (dataset.type === 'line') {
                        return;
                    }

                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    const fillColor = readThemeValue('--neutral-700', '#334155');

                    meta.data.forEach((bar, index) => {
                        if (!bar) return;
                        const props = bar.getProps ? bar.getProps(['x', 'y'], true) : bar;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) {
                            return;
                        }

                        const label = formatQuantity(rawValue);
                        const textY = Math.max(chartArea.top + 14, y - 10);
                        drawLabelWithOutline(ctx, label, x, textY, fillColor, outlineColor);
                    });
                });

                ctx.restore();
            }
        };

        const productionDailyValueLabelsPlugin = {
            id: 'productionDailyValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || chart.canvas.id !== 'productionDailyChart') {
                    return;
                }

                const { ctx, chartArea } = chart;
                const dataset = chart.data.datasets[0];
                const meta = chart.getDatasetMeta(0);
                const outlineColor = getOutlineColor();

                if (!dataset || !meta || meta.hidden) {
                    return;
                }

                ctx.save();
                ctx.font = `700 12px ${Chart.defaults.font.family}`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';

                const fillColor = readThemeValue('--neutral-700', '#334155');

                meta.data.forEach((bar, index) => {
                    if (!bar) return;
                    const props = bar.getProps ? bar.getProps(['x', 'y'], true) : bar;
                    const x = props.x;
                    const y = props.y;
                    if (x === undefined || y === undefined) return;

                    const rawValue = Number(dataset.data[index] || 0);
                    if (!rawValue) {
                        return;
                    }

                    const label = formatQuantity(rawValue);
                    const textY = Math.max(chartArea.top + 12, y - 8);
                    drawLabelWithOutline(ctx, label, x, textY, fillColor, outlineColor);
                });

                ctx.restore();
            }
        };

        const productionLineValueLabelsPlugin = {
            id: 'productionLineValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || !['productionLineChart', 'productionProductChart'].includes(chart.canvas.id)) {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();

                ctx.save();
                ctx.textAlign = 'center';

                const stackTotals = [];

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    meta.data.forEach((bar, index) => {
                        if (!bar) return;
                        const props = bar.getProps ? bar.getProps(['x', 'y', 'base'], true) : bar;
                        const x = props.x;
                        const y = props.y;
                        const base = props.base;
                        if (x === undefined || y === undefined || base === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const segmentHeight = Math.abs(base - y);
                        if (segmentHeight >= 16) {
                            ctx.font = `700 10px ${Chart.defaults.font.family}`;
                            ctx.textBaseline = 'middle';
                            const label = formatQuantity(rawValue);
                            const textY = y + ((base - y) / 2);
                            
                            ctx.strokeStyle = 'rgba(0, 0, 0, 0.4)';
                            ctx.lineWidth = 2.5;
                            ctx.lineJoin = 'round';
                            ctx.strokeText(label, x, textY);
                            ctx.fillStyle = '#ffffff';
                            ctx.fillText(label, x, textY);
                        }

                        if (!stackTotals[index]) {
                            stackTotals[index] = { total: 0, topY: y, x: x };
                        }
                        stackTotals[index].total += rawValue;
                        if (y < stackTotals[index].topY) {
                            stackTotals[index].topY = y;
                            stackTotals[index].x = x;
                        }
                    });
                });

                ctx.font = `700 11px ${Chart.defaults.font.family}`;
                ctx.textBaseline = 'bottom';
                const totalColor = readThemeValue('--neutral-700', '#334155');

                stackTotals.forEach((stack) => {
                    if (!stack || !stack.total || stack.topY === null || stack.x === null) {
                        return;
                    }
                    const label = formatQuantity(stack.total);
                    const textY = Math.max(chartArea.top + 12, stack.topY - 6);
                    drawLabelWithOutline(ctx, label, stack.x, textY, totalColor, outlineColor);
                });

                ctx.restore();
            }
        };

        const loadingValueLabelsPlugin = {
            id: 'loadingValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || !['loadingExportChart', 'loadingDomesticChart', 'loadingOverviewChart', 'loadingExportTopProductChart', 'loadingDomesticTopProductChart'].includes(chart.canvas.id)) {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();

                ctx.save();

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    const isLine = dataset.type === 'line';

                    if (isLine) {
                        ctx.font = `700 13px ${Chart.defaults.font.family}`;
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        const fillColor = readThemeValue('--primary-700', '#4338ca');

                        meta.data.forEach((point, index) => {
                            if (!point) return;
                            const props = point.getProps ? point.getProps(['x', 'y'], true) : point;
                            const x = props.x;
                            const y = props.y;
                            if (x === undefined || y === undefined) return;

                            const rawValue = Number(dataset.data[index] || 0);
                            if (!rawValue) return;
                            const isKgSeries = dataset.yAxisID !== 'y1';
                            const label = isKgSeries ? `${formatQuantity(rawValue)} กก.` : `${formatQuantity(rawValue)} กล่อง`;
                            const textY = Math.max(chartArea.top + 14, y - 12);
                            drawLabelWithOutline(ctx, label, x, textY, fillColor, outlineColor);
                        });
                    } else {
                        ctx.font = `700 12px ${Chart.defaults.font.family}`;
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        const fillColor = readThemeValue('--neutral-700', '#334155');

                        meta.data.forEach((bar, index) => {
                            if (!bar) return;
                            const props = bar.getProps ? bar.getProps(['x', 'y'], true) : bar;
                            const x = props.x;
                            const y = props.y;
                            if (x === undefined || y === undefined) return;

                            const rawValue = Number(dataset.data[index] || 0);
                            if (!rawValue) return;
                            const isKgSeries = dataset.yAxisID !== 'y1';
                            const label = isKgSeries ? `${formatQuantity(rawValue)} กก.` : `${formatQuantity(rawValue)} กล่อง`;
                            const textY = Math.max(chartArea.top + 14, y - 8);
                            drawLabelWithOutline(ctx, label, x, textY, fillColor, outlineColor);
                        });
                    }
                });

                ctx.restore();
            }
        };

        // Register custom plugins globally in Chart.js
        Chart.register(
            spendValueLabelsPlugin,
            productionValueLabelsPlugin,
            productionDailyValueLabelsPlugin,
            productionLineValueLabelsPlugin,
            loadingValueLabelsPlugin
        );

        const getDefaultGrid = () => ({
            color: readThemeValue('--chart-grid', 'rgba(148, 163, 184, 0.18)'),
            drawBorder: false
        });

        const getDefaultTicks = () => ({
            color: readThemeValue('--chart-tick', '#667085'),
            font: {
                size: 12
            }
        });

        const getChartGradients = () => ({
            spendHoverStart: readThemeValue('--chart-spend-hover-start', '#6366f1'),
            spendHoverEnd: readThemeValue('--chart-spend-hover-end', '#0ea5e9'),
            productionBarStart: readThemeValue('--chart-production-bar-start', 'rgba(14, 165, 233, 0.92)'),
            productionBarEnd: readThemeValue('--chart-production-bar-end', 'rgba(99, 102, 241, 0.4)'),
            productionLineStart: readThemeValue('--chart-production-line-start', 'rgba(99, 102, 241, 0.28)'),
            productionLineEnd: readThemeValue('--chart-production-line-end', 'rgba(99, 102, 241, 0.03)'),
            loadingLineStart: readThemeValue('--chart-loading-line-start', 'rgba(99, 102, 241, 0.24)'),
            loadingLineEnd: readThemeValue('--chart-loading-line-end', 'rgba(99, 102, 241, 0.02)'),
            loadingBarStart: readThemeValue('--chart-loading-bar-start', 'rgba(20, 184, 166, 0.9)'),
            loadingBarEnd: readThemeValue('--chart-loading-bar-end', 'rgba(14, 165, 233, 0.52)')
        });

        const getPalette = () => ({
            primary: readThemeValue('--primary-500', '#6366f1'),
            primaryDeep: readThemeValue('--primary-700', '#4338ca'),
            primarySoft: readThemeValue('--primary-400', '#818cf8'),
            sky: readThemeValue('--accent-500', '#0ea5e9'),
            skySoft: readThemeValue('--accent-400', '#38bdf8'),
            mint: readThemeValue('--chart-mint', '#14b8a6'),
            departmental: [
                readThemeValue('--chart-1', '#4338ca'),
                readThemeValue('--chart-2', '#4f46e5'),
                readThemeValue('--chart-3', '#6366f1'),
                readThemeValue('--chart-4', '#818cf8'),
                readThemeValue('--chart-5', '#38bdf8'),
                readThemeValue('--chart-6', '#0ea5e9'),
                readThemeValue('--chart-7', '#14b8a6'),
                readThemeValue('--chart-8', '#f59e0b'),
                readThemeValue('--chart-9', '#fb7185'),
                readThemeValue('--chart-10', '#ef4444')
            ]
        });

        function createVerticalGradient(canvas, topColor, bottomColor) {
            const context = canvas.getContext('2d');
            const gradient = context.createLinearGradient(0, 0, 0, canvas.height || 320);
            gradient.addColorStop(0, topColor);
            gradient.addColorStop(1, bottomColor);

            return gradient;
        }

        function createHorizontalGradient(canvas, startColor, endColor) {
            const context = canvas.getContext('2d');
            const gradient = context.createLinearGradient(0, 0, canvas.width || 720, 0);
            gradient.addColorStop(0, startColor);
            gradient.addColorStop(1, endColor);

            return gradient;
        }

        function hexToRgb(hexColor) {
            const normalized = hexColor.replace('#', '').trim();
            if (normalized.length !== 6) {
                return null;
            }

            const parsed = Number.parseInt(normalized, 16);
            if (Number.isNaN(parsed)) {
                return null;
            }

            return {
                r: (parsed >> 16) & 255,
                g: (parsed >> 8) & 255,
                b: parsed & 255
            };
        }

        function withAlpha(color, alpha) {
            if (typeof color !== 'string') {
                return color;
            }

            if (color.startsWith('rgba(') || color.startsWith('hsla(')) {
                return color;
            }

            if (color.startsWith('rgb(')) {
                return color.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`);
            }

            if (color.startsWith('#')) {
                const rgb = hexToRgb(color);
                if (rgb) {
                    return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
                }
            }

            return color;
        }

        const getSharedTooltip = () => ({
            backgroundColor: readThemeValue('--tooltip-bg', 'rgba(255, 255, 255, 0.98)'),
            titleColor: readThemeValue('--tooltip-title', '#0f172a'),
            bodyColor: readThemeValue('--tooltip-body', '#334155'),
            borderColor: readThemeValue('--tooltip-border', 'rgba(226, 232, 240, 0.96)'),
            borderWidth: 1,
            titleFont: {
                weight: '700'
            },
            bodyFont: {
                weight: '600'
            },
            padding: 12,
            cornerRadius: 14,
            displayColors: true,
            boxPadding: 4
        });

        const chartReplayFlag = readSessionFlag('mfoodYearTransition') === '1';
        if (chartReplayFlag) {
            clearSessionFlag('mfoodYearTransition');
        }

        const sharedAnimation = {
            duration: prefersReducedMotion ? 0 : (chartReplayFlag ? 1500 : 900),
            easing: 'easeOutQuart'
        };

        const sharedInteraction = {
            intersect: false,
            mode: 'index'
        };

        const getSharedLegend = () => ({
            position: 'top',
            align: 'end',
            labels: {
                color: readThemeValue('--chart-legend', '#475569'),
                padding: 16,
                font: {
                    size: 12,
                    weight: '600'
                },
                usePointStyle: true,
                boxWidth: 10
            }
        });

        Chart.defaults.font.family = '"Inter", "Noto Sans Thai", "Segoe UI", Tahoma, sans-serif';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.animation.duration = prefersReducedMotion ? 0 : (chartReplayFlag ? 1500 : 900);
        Chart.defaults.animation.easing = 'easeOutQuart';
        Chart.defaults.interaction.intersect = false;
        Chart.defaults.interaction.mode = 'index';

        function formatCounterValue(value, format) {
            if (format === 'currency') {
                return new Intl.NumberFormat('th-TH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
            }

            if (format === 'quantity') {
                return formatQuantity(value);
            }

            return new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(value);
        }

        function animateCounter(element) {
            if (!element || element.dataset.counterAnimated === 'true' || prefersReducedMotion) {
                return;
            }

            const target = Number(element.dataset.counterValue || 0);
            const format = element.dataset.counterFormat || 'number';
            const duration = 1500;
            const start = performance.now();

            element.dataset.counterAnimated = 'true';

            function tick(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 4);
                const current = target * eased;

                element.textContent = formatCounterValue(current, format);

                if (progress < 1) {
                    window.requestAnimationFrame(tick);
                } else {
                    element.textContent = formatCounterValue(target, format);
                }
            }

            window.requestAnimationFrame(tick);
        }

        function setupCounters() {
            document.querySelectorAll('.counter-value[data-counter-value]').forEach((counter) => {
                animateCounter(counter);
            });
        }

        function setupYearTransition() {
            const yearForm = document.getElementById('yearFilterForm');
            const yearSubmitButton = document.getElementById('yearSubmitButton');
            const dashboardShell = document.getElementById('dashboardShell');

            if (!yearForm || !yearSubmitButton || !dashboardShell) {
                return;
            }

            let isSubmitting = false;

            function submitYearForm() {
                if (isSubmitting) {
                    return;
                }

                isSubmitting = true;
                writeSessionFlag('mfoodYearTransition', '1');
                document.body.classList.add('is-year-changing');
                yearForm.classList.add('is-submitting');
                yearSubmitButton.classList.add('is-loading');
                yearSubmitButton.textContent = 'กำลังอัปเดตข้อมูล';
                dashboardShell.setAttribute('aria-busy', 'true');

                window.setTimeout(() => {
                    yearForm.submit();
                }, 220);
            }

            yearForm.addEventListener('submit', (event) => {
                if (isSubmitting) {
                    return;
                }

                event.preventDefault();
                submitYearForm();
            });
        }

        function destroyCharts() {
            while (chartInstances.length > 0) {
                const chart = chartInstances.pop();

                if (chart) {
                    chart.destroy();
                }
            }
        }

        function registerChart(chart) {
            chartInstances.push(chart);
            return chart;
        }

        function createProductionStackedDatasets(canvas, datasets, stackKey, paletteColors) {
            return datasets.map((dataset, index) => {
                const baseColor = paletteColors[index % paletteColors.length];
                const gradient = createVerticalGradient(
                    canvas,
                    withAlpha(baseColor, 0.88),
                    withAlpha(baseColor, 0.38)
                );

                return {
                    type: 'bar',
                    label: dataset.label,
                    data: dataset.data,
                    borderColor: withAlpha(baseColor, 0.95),
                    backgroundColor: gradient,
                    borderWidth: 1.5,
                    borderRadius: 12,
                    borderSkipped: false,
                    maxBarThickness: 48,
                    stack: stackKey
                };
            });
        }

        function renderProductionStackedChart(canvas, labels, datasets, stackKey, labelPrefix, options) {
            const stackedDatasets = createProductionStackedDatasets(
                canvas,
                datasets,
                stackKey,
                options.paletteColors
            );

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: stackedDatasets
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 18
                        }
                    },
                    interaction: {
                        intersect: true,
                        mode: 'nearest'
                    },
                    onHover: (event, elements, chart) => {
                        chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: options.sharedLegend,
                        tooltip: {
                            ...options.sharedTooltip,
                            intersect: true,
                            mode: 'nearest',
                            callbacks: {
                                title: (items) => items.length ? `วันที่ ${items[0].label}` : '',
                                label: (context) => {
                                    const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                    return ` ${labelPrefix}: ${ds ? ds.label : ''}`;
                                },
                                afterLabel: (context) => `จำนวนที่ผลิตได้: ${formatQuantity(context.raw)} กก.`
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                display: false
                            },
                            ticks: options.defaultTicks
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            grid: options.defaultGrid,
                            ticks: {
                                ...options.defaultTicks,
                                callback: (value) => formatQuantity(value)
                            }
                        }
                    }
                }
            }));
        }

        function renderLoadingBreakdownChart(canvas, kgSeries, boxSeries, legendLabel, sharedLegend, sharedTooltip, defaultTicks, defaultGrid, chartGradients, palette) {
            const loadingLineGradient = createVerticalGradient(canvas, chartGradients.loadingLineStart, chartGradients.loadingLineEnd);
            const loadingBarGradient = createVerticalGradient(canvas, chartGradients.loadingBarStart, chartGradients.loadingBarEnd);
            const kgTotal = kgSeries.reduce((sum, value) => sum + value, 0);
            const boxTotal = boxSeries.reduce((sum, value) => sum + value, 0);

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: dashboardData.monthLabels,
                    datasets: [{
                        type: 'line',
                        label: 'น้ำหนักโหลด (กก.)',
                        data: kgSeries,
                        borderColor: palette.primary,
                        backgroundColor: loadingLineGradient,
                        pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                        pointBorderColor: palette.primary,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        borderWidth: 3,
                        tension: 0.42,
                        yAxisID: 'y'
                    }, {
                        type: 'bar',
                        label: 'จำนวนกล่อง',
                        data: boxSeries,
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 28,
                        backgroundColor: loadingBarGradient,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 18
                        }
                    },
                    plugins: {
                        legend: sharedLegend,
                        tooltip: {
                            ...sharedTooltip,
                            enabled: true,
                            callbacks: {
                                title: (items) => items.length ? `${legendLabel} เดือน ${items[0].label}` : '',
                                label: (context) => {
                                    const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                    return ` ${ds ? ds.label : ''}: ${formatQuantity(context.raw)}`;
                                },
                                afterBody: (items) => {
                                    if (!items.length) return [];

                                    const lines = [];
                                    const kgItem = items.find((item) => item.dataset && item.dataset.yAxisID === 'y');
                                    const boxItem = items.find((item) => item.dataset && item.dataset.yAxisID === 'y1');
                                    const kgVal = Number(kgItem ? kgItem.raw : 0);
                                    const boxVal = Number(boxItem ? boxItem.raw : 0);

                                    if (kgVal > 0 && kgTotal > 0) {
                                        lines.push(`สัดส่วนน้ำหนัก: ${((kgVal / kgTotal) * 100).toFixed(1)}% ของทั้งปี`);
                                    }
                                    if (boxVal > 0 && boxTotal > 0) {
                                        lines.push(`สัดส่วนจำนวนกล่อง: ${((boxVal / boxTotal) * 100).toFixed(1)}% ของทั้งปี`);
                                    }
                                    if (kgVal > 0 && boxVal > 0) {
                                        lines.push(`เฉลี่ยน้ำหนัก/กล่อง: ${(kgVal / boxVal).toFixed(2)} กก.`);
                                    }

                                    return lines;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: defaultGrid,
                            ticks: {
                                ...defaultTicks,
                                callback: (value) => formatQuantity(value)
                            },
                            title: {
                                display: true,
                                text: 'กิโลกรัม',
                                color: readThemeValue('--chart-tick', '#667085'),
                                font: { size: 11, weight: '600' }
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                display: false
                            },
                            ticks: {
                                ...defaultTicks,
                                callback: (value) => formatQuantity(value)
                            },
                            title: {
                                display: true,
                                text: 'จำนวนกล่อง',
                                color: readThemeValue('--chart-tick', '#667085'),
                                font: { size: 11, weight: '600' }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: defaultTicks
                        }
                    }
                }
            }));
        }

        function renderLoadingOverviewChart(canvas, sharedLegend, sharedTooltip, defaultTicks, defaultGrid, chartGradients, palette) {
            const exportBarGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.primary, 0.92),
                withAlpha(palette.primarySoft, 0.44)
            );
            const domesticBarGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.mint, 0.92),
                withAlpha(palette.skySoft, 0.46)
            );
            const exportLineGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.primaryDeep, 0.24),
                withAlpha(palette.primaryDeep, 0.04)
            );
            const domesticLineGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.sky, 0.22),
                withAlpha(palette.sky, 0.04)
            );
            const exportKgTotal = (dashboardData.loadingExportKgSeries || []).reduce((sum, value) => sum + value, 0);
            const domesticKgTotal = (dashboardData.loadingDomesticKgSeries || []).reduce((sum, value) => sum + value, 0);
            const exportBoxTotal = (dashboardData.loadingExportBoxSeries || []).reduce((sum, value) => sum + value, 0);
            const domesticBoxTotal = (dashboardData.loadingDomesticBoxSeries || []).reduce((sum, value) => sum + value, 0);

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: dashboardData.monthLabels,
                    datasets: [{
                        type: 'bar',
                        label: 'นอกประเทศ (กก.)',
                        data: dashboardData.loadingExportKgSeries || [],
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 24,
                        backgroundColor: exportBarGradient,
                        borderColor: withAlpha(palette.primaryDeep, 0.96),
                        borderWidth: 1.2,
                        yAxisID: 'y',
                        order: 3
                    }, {
                        type: 'bar',
                        label: 'ในประเทศ (กก.)',
                        data: dashboardData.loadingDomesticKgSeries || [],
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 24,
                        backgroundColor: domesticBarGradient,
                        borderColor: withAlpha(palette.mint, 0.96),
                        borderWidth: 1.2,
                        yAxisID: 'y',
                        order: 3
                    }, {
                        type: 'line',
                        label: 'นอกประเทศ (กล่อง)',
                        data: dashboardData.loadingExportBoxSeries || [],
                        borderColor: palette.primaryDeep,
                        backgroundColor: exportLineGradient,
                        pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                        pointBorderColor: palette.primaryDeep,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        borderWidth: 3,
                        tension: 0.42,
                        yAxisID: 'y1',
                        order: 1
                    }, {
                        type: 'line',
                        label: 'ในประเทศ (กล่อง)',
                        data: dashboardData.loadingDomesticBoxSeries || [],
                        borderColor: palette.sky,
                        backgroundColor: domesticLineGradient,
                        pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                        pointBorderColor: palette.sky,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        borderWidth: 3,
                        tension: 0.42,
                        yAxisID: 'y1',
                        order: 2
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 24
                        }
                    },
                    plugins: {
                        legend: sharedLegend,
                        tooltip: {
                            ...sharedTooltip,
                            enabled: true,
                            callbacks: {
                                title: (items) => items.length ? `เดือน ${items[0].label}` : '',
                                label: (context) => {
                                    const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                    const suffix = ds && ds.yAxisID === 'y1' ? ' กล่อง' : ' กก.';
                                    return ` ${ds ? ds.label : ''}: ${formatQuantity(context.raw)}${suffix}`;
                                },
                                afterBody: () => {
                                    return [
                                        `รวมนอกประเทศทั้งปี: ${formatQuantity(exportKgTotal)} กก. / ${formatQuantity(exportBoxTotal)} กล่อง`,
                                        `รวมในประเทศทั้งปี: ${formatQuantity(domesticKgTotal)} กก. / ${formatQuantity(domesticBoxTotal)} กล่อง`
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: defaultGrid,
                            ticks: {
                                ...defaultTicks,
                                callback: (value) => formatQuantity(value)
                            },
                            title: {
                                display: true,
                                text: 'กิโลกรัม',
                                color: readThemeValue('--chart-tick', '#667085'),
                                font: { size: 11, weight: '600' }
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                display: false
                            },
                            ticks: {
                                ...defaultTicks,
                                callback: (value) => formatQuantity(value)
                            },
                            title: {
                                display: true,
                                text: 'จำนวนกล่อง',
                                color: readThemeValue('--chart-tick', '#667085'),
                                font: { size: 11, weight: '600' }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: defaultTicks
                        }
                    }
                }
            }));
        }

        function renderLoadingTopProductChart(canvas, labels, kgSeries, boxSeries, chartTitle, defaultTicks, defaultGrid, sharedTooltip, palette) {
            const productGradient = createHorizontalGradient(
                canvas,
                withAlpha(palette.primary, 0.96),
                withAlpha(palette.skySoft, 0.72)
            );

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'น้ำหนักโหลด (กก.)',
                        data: kgSeries,
                        borderRadius: 12,
                        borderSkipped: false,
                        maxBarThickness: 26,
                        backgroundColor: productGradient,
                        hoverBackgroundColor: productGradient,
                        borderColor: withAlpha(palette.primaryDeep, 0.96),
                        borderWidth: 1.2,
                        yAxisID: 'y'
                    }]
                },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            right: 28
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...sharedTooltip,
                            callbacks: {
                                title: (items) => items.length ? chartTitle : '',
                                label: (context) => ` น้ำหนักโหลด: ${formatQuantity(context.raw)} กก.`,
                                afterBody: (items) => {
                                    if (!items.length) {
                                        return [];
                                    }

                                    const index = items[0].dataIndex;
                                    const boxValue = Number(boxSeries[index] || 0);
                                    return [`จำนวนกล่อง: ${formatQuantity(boxValue)} กล่อง`];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: defaultGrid,
                            ticks: {
                                ...defaultTicks,
                                callback: (value) => formatQuantity(value)
                            },
                            title: {
                                display: true,
                                text: 'กิโลกรัม',
                                color: readThemeValue('--chart-tick', '#667085'),
                                font: { size: 11, weight: '600' }
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                ...defaultTicks,
                                callback: (value, index) => compactLabel(labels[index], 34)
                            }
                        }
                    }
                }
            }));
        }

        function renderCharts() {
            refreshThemeContext();
            destroyCharts();

            const defaultGrid = getDefaultGrid();
            const defaultTicks = getDefaultTicks();
            const chartGradients = getChartGradients();
            const palette = getPalette();
            const sharedTooltip = getSharedTooltip();
            const sharedLegend = getSharedLegend();

            Chart.defaults.color = readThemeValue('--chart-legend', '#475569');
            Chart.defaults.borderColor = readThemeValue('--chart-grid', 'rgba(148, 163, 184, 0.18)');

            const spendCanvas = document.getElementById('spendChart');
            if (spendCanvas) {
                const spendGradient = createHorizontalGradient(spendCanvas, chartGradients.spendHoverStart, chartGradients.spendHoverEnd);
                const spendBudgetGradient = createHorizontalGradient(
                    spendCanvas,
                    readThemeValue('--primary-100', '#e0e7ff'),
                    readThemeValue('--primary-400', '#818cf8')
                );
                registerChart(new Chart(spendCanvas, {
                    type: 'bar',
                    data: {
                        labels: dashboardData.spendLabels,
                        datasets: [{
                            label: 'งบสุทธิ',
                            data: dashboardData.spendBudgetValues,
                            borderRadius: 12,
                            borderSkipped: false,
                            maxBarThickness: 20,
                            backgroundColor: spendBudgetGradient,
                            hoverBackgroundColor: spendBudgetGradient
                        }, {
                            label: 'ใช้จ่ายจริง',
                            data: dashboardData.spendValues,
                            borderRadius: 12,
                            borderSkipped: false,
                            maxBarThickness: 20,
                            backgroundColor: spendGradient,
                            hoverBackgroundColor: spendGradient
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                right: 92
                            }
                        },
                        animation: {
                            ...sharedAnimation,
                            delay: (context) => context.dataIndex * 55
                        },
                        scales: {
                            x: {
                                grid: defaultGrid,
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value) => formatCurrency(value)
                                }
                            },
                            y: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value, index) => compactLabel(dashboardData.spendLabels[index])
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                ...sharedTooltip,
                                callbacks: {
                                    label: (context) => {
                                        const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                        return ` ${ds ? ds.label : ''} ${formatCurrency(context.raw)} บาท`;
                                    },
                                    afterBody: (items) => {
                                        if (!items.length) {
                                            return [];
                                        }

                                        const index = items[0].dataIndex;
                                        return [`งบคงเหลือ ${formatCurrency(dashboardData.spendBalanceValues[index])} บาท`];
                                    }
                                }
                            },
                            legend: sharedLegend
                        }
                    }
                }));
            }

            const productionCanvas = document.getElementById('productionChart');
            if (productionCanvas) {
                const productionBarGradient = createVerticalGradient(productionCanvas, chartGradients.productionBarStart, chartGradients.productionBarEnd);
                const productionTrendGradient = createVerticalGradient(
                    productionCanvas,
                    readThemeValue('--chart-production-line-start', 'rgba(99, 102, 241, 0.28)'),
                    readThemeValue('--chart-production-line-end', 'rgba(99, 102, 241, 0.03)')
                );
                const productionTotal = dashboardData.productionQtySeries.reduce((sum, v) => sum + v, 0);
                const productionNonZero = dashboardData.productionQtySeries.filter(v => v > 0).length || 1;
                const productionAvg = productionTotal / productionNonZero;
                registerChart(new Chart(productionCanvas, {
                    type: 'bar',
                    data: {
                        labels: dashboardData.monthLabels,
                        datasets: [{
                            type: 'bar',
                            label: 'ปริมาณผลิต (กก.)',
                            data: dashboardData.productionQtySeries,
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: productionBarGradient,
                            order: 2
                        }, {
                            type: 'line',
                            label: 'แนวโน้ม',
                            data: dashboardData.productionQtySeries,
                            borderColor: palette.primary,
                            backgroundColor: productionTrendGradient,
                            pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                            pointBorderColor: palette.primary,
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 7,
                            fill: true,
                            borderWidth: 2.5,
                            tension: 0.4,
                            order: 1
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                top: 24
                            }
                        },
                        plugins: {
                            legend: sharedLegend,
                            tooltip: {
                                ...sharedTooltip,
                                enabled: true,
                                filter: (tooltipItem) => {
                                    const ds = tooltipItem.dataset || tooltipItem.chart.data.datasets[tooltipItem.datasetIndex];
                                    return ds && ds.type !== 'line';
                                },
                                callbacks: {
                                    title: (items) => items.length ? `เดือน ${items[0].label}` : '',
                                    label: (context) => {
                                        const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                        return ` ${ds ? ds.label : ''}: ${formatQuantity(context.raw)} กก.`;
                                    },
                                    afterBody: (items) => {
                                        if (!items.length) return [];
                                        const val = Number(items[0].raw || 0);
                                        const lines = [];
                                        if (productionAvg > 0 && val > 0) {
                                            const pct = ((val / productionAvg) * 100).toFixed(0);
                                            lines.push(`เทียบค่าเฉลี่ย: ${pct}% (avg ${formatQuantity(productionAvg)} กก.)`);
                                        }
                                        return lines;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: defaultGrid,
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value) => formatQuantity(value)
                                },
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: defaultTicks
                            }
                        }
                    }
                }));
            }

            const productionDailyCanvas = document.getElementById('productionDailyChart');
            if (productionDailyCanvas && dashboardData.productionDailyTotalSeries.length) {
                const productionDailyBarGradient = createVerticalGradient(
                    productionDailyCanvas,
                    readThemeValue('--chart-production-bar-start', 'rgba(14, 165, 233, 0.92)'),
                    readThemeValue('--chart-production-bar-end', 'rgba(99, 102, 241, 0.4)')
                );

                registerChart(new Chart(productionDailyCanvas, {
                    type: 'bar',
                    data: {
                        labels: dashboardData.productionDailyLabels,
                        datasets: [{
                            label: 'ยอดผลิตรวม (กก.)',
                            data: dashboardData.productionDailyTotalSeries,
                            backgroundColor: productionDailyBarGradient,
                            hoverBackgroundColor: createVerticalGradient(
                                productionDailyCanvas,
                                readThemeValue('--chart-production-bar-start', 'rgba(14, 165, 233, 0.98)'),
                                readThemeValue('--chart-production-bar-end', 'rgba(99, 102, 241, 0.62)')
                            ),
                            borderColor: withAlpha(palette.sky, 0.96),
                            borderWidth: 1,
                            hoverBorderColor: palette.primaryDeep,
                            hoverBorderWidth: 2,
                            borderRadius: 12,
                            borderSkipped: false,
                            maxBarThickness: 48
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                top: 18
                            }
                        },
                        onHover: (event, elements, chart) => {
                            chart.canvas.style.cursor = elements.length ? 'pointer' : 'default';
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                ...sharedTooltip,
                                callbacks: {
                                    title: (items) => items.length ? `วันที่ ${items[0].label}` : '',
                                    label: (context) => ` ยอดผลิตรวม ${formatQuantity(context.raw)} กก.`,
                                    afterBody: (items) => {
                                        if (!items.length) {
                                            return [];
                                        }

                                        const highlights = dashboardData.productionDailyHighlights[items[0].dataIndex] || [];
                                        if (!highlights.length) {
                                            return ['ไม่มีรายการผลิตในวันนี้'];
                                        }

                                        return ['ตัวหลักของวัน:',].concat(highlights.map((item) => `- ${item}`));
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: defaultTicks
                            },
                            y: {
                                beginAtZero: true,
                                grid: defaultGrid,
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value) => formatQuantity(value)
                                }
                            }
                        }
                    }
                }));
            }

            const productionLineCanvas = document.getElementById('productionLineChart');
            if (productionLineCanvas && dashboardData.productionLineDatasets.length) {
                renderProductionStackedChart(
                    productionLineCanvas,
                    dashboardData.productionLineLabels,
                    dashboardData.productionLineDatasets,
                    'productionLine',
                    'ไลน์ผลิต',
                    {
                        paletteColors: palette.departmental,
                        sharedLegend,
                        sharedTooltip,
                        defaultTicks,
                        defaultGrid
                    }
                );
            }

            const productionProductCanvas = document.getElementById('productionProductChart');
            if (productionProductCanvas && dashboardData.productionProductDatasets.length) {
                const productLegend = {
                    ...sharedLegend,
                    align: 'start',
                    labels: {
                        ...sharedLegend.labels,
                        padding: 10,
                        font: {
                            size: 11,
                            weight: '600'
                        }
                    }
                };

                renderProductionStackedChart(
                    productionProductCanvas,
                    dashboardData.productionProductLabels,
                    dashboardData.productionProductDatasets,
                    'productionProduct',
                    'สินค้า',
                    {
                        paletteColors: palette.departmental,
                        sharedLegend: productLegend,
                        sharedTooltip,
                        defaultTicks,
                        defaultGrid
                    }
                );
            }

            const loadingOverviewCanvas = document.getElementById('loadingOverviewChart');
            if (loadingOverviewCanvas) {
                renderLoadingOverviewChart(
                    loadingOverviewCanvas,
                    sharedLegend,
                    sharedTooltip,
                    defaultTicks,
                    defaultGrid,
                    chartGradients,
                    palette
                );
            }

            const loadingExportCanvas = document.getElementById('loadingExportChart');
            if (loadingExportCanvas) {
                renderLoadingBreakdownChart(
                    loadingExportCanvas,
                    dashboardData.loadingExportKgSeries || [],
                    dashboardData.loadingExportBoxSeries || [],
                    'โหลดนอกประเทศ',
                    sharedLegend,
                    sharedTooltip,
                    defaultTicks,
                    defaultGrid,
                    chartGradients,
                    palette
                );
            }

            const loadingDomesticCanvas = document.getElementById('loadingDomesticChart');
            if (loadingDomesticCanvas) {
                renderLoadingBreakdownChart(
                    loadingDomesticCanvas,
                    dashboardData.loadingDomesticKgSeries || [],
                    dashboardData.loadingDomesticBoxSeries || [],
                    'โหลดในประเทศ',
                    sharedLegend,
                    sharedTooltip,
                    defaultTicks,
                    defaultGrid,
                    chartGradients,
                    palette
                );
            }

            const loadingExportTopProductCanvas = document.getElementById('loadingExportTopProductChart');
            if (loadingExportTopProductCanvas && (dashboardData.loadingExportTopProductLabels || []).length) {
                renderLoadingTopProductChart(
                    loadingExportTopProductCanvas,
                    dashboardData.loadingExportTopProductLabels || [],
                    dashboardData.loadingExportTopProductKgSeries || [],
                    dashboardData.loadingExportTopProductBoxSeries || [],
                    'Top 10 สินค้านอกประเทศ',
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette
                );
            }

            const loadingDomesticTopProductCanvas = document.getElementById('loadingDomesticTopProductChart');
            if (loadingDomesticTopProductCanvas && (dashboardData.loadingDomesticTopProductLabels || []).length) {
                renderLoadingTopProductChart(
                    loadingDomesticTopProductCanvas,
                    dashboardData.loadingDomesticTopProductLabels || [],
                    dashboardData.loadingDomesticTopProductKgSeries || [],
                    dashboardData.loadingDomesticTopProductBoxSeries || [],
                    'Top 10 สินค้าในประเทศ',
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette
                );
            }

        }

        function setupThemeControls() {
            const themeSelector = document.getElementById('themeSelector');

            if (!themeSelector) {
                return;
            }

            const activeTheme = resolveTheme(document.documentElement.getAttribute('data-theme'));
            themeSelector.value = activeTheme;

            themeSelector.addEventListener('change', () => {
                applyTheme(themeSelector.value, true, true);
            });
        }

        try {
            setupThemeControls();
            renderCharts();
            setupCounters();
            setupYearTransition();
        } catch (e) {
            console.error("Dashboard initialization error:", e);
            const errorBanner = document.createElement('div');
            errorBanner.style.cssText = 'position:fixed;top:10px;left:10px;right:10px;background:#fef2f2;color:#991b1b;border:1px solid #fee2e2;padding:15px;z-index:9999;border-radius:8px;font-family:sans-serif;box-shadow:0 4px 6px rgba(0,0,0,0.1)';
            errorBanner.innerHTML = `<strong>พบข้อผิดพลาดในการโหลดแดชบอร์ด:</strong> ${e.message}<br><small style="opacity:0.8; white-space:pre-wrap; display:block; margin-top:5px; font-family:monospace">${e.stack}</small>`;
            document.body.appendChild(errorBanner);
        }
