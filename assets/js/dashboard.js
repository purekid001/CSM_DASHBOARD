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
        const dashboardShell = document.getElementById('dashboardShell');
        const dashboardLoadingOverlay = document.getElementById('dashboardLoadingOverlay');
        const dashboardLoadingMessage = document.getElementById('dashboardLoadingMessage');
        const dashboardLoadingDetail = document.getElementById('dashboardLoadingDetail');
        const autoRefreshIntervalMs = 10 * 60 * 1000;
        const autoRefreshRetryDelayMs = 30 * 1000;
        const autoRefreshStateKey = 'mfoodAutoRefreshState';
        let autoRefreshTimeoutId = null;
        let autoRefreshDueAt = 0;

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

        const readSessionJson = (key) => {
            const rawValue = readSessionFlag(key);

            if (!rawValue) {
                return null;
            }

            try {
                return JSON.parse(rawValue);
            } catch (error) {
                clearSessionFlag(key);
                return null;
            }
        };

        const writeSessionJson = (key, value) => {
            try {
                window.sessionStorage.setItem(key, JSON.stringify(value));
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

        const replaceQueryParam = (name, value) => {
            if (!window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            const url = new URL(window.location.href);

            if (value) {
                url.searchParams.set(name, value);
            } else {
                url.searchParams.delete(name);
            }

            window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
        };

        const isDashboardBusy = () => {
            return document.body.classList.contains('is-dashboard-loading')
                || document.body.classList.contains('is-year-changing')
                || Boolean(document.querySelector('form.is-submitting'));
        };

        const startDashboardLoading = (
            message = 'กำลังดึงข้อมูลใหม่',
            detail = 'กรุณารอสักครู่ ระบบกำลังดึงข้อมูลล่าสุดจากฐานข้อมูล'
        ) => {
            if (dashboardLoadingMessage) {
                dashboardLoadingMessage.textContent = message;
            }

            if (dashboardLoadingDetail) {
                dashboardLoadingDetail.textContent = detail;
            }

            if (dashboardLoadingOverlay) {
                dashboardLoadingOverlay.hidden = false;
                dashboardLoadingOverlay.setAttribute('aria-hidden', 'false');
            }

            document.body.classList.add('is-dashboard-loading');

            if (dashboardShell) {
                dashboardShell.setAttribute('aria-busy', 'true');
            }
        };

        const stopDashboardLoading = () => {
            document.body.classList.remove('is-dashboard-loading');

            if (dashboardLoadingOverlay) {
                dashboardLoadingOverlay.setAttribute('aria-hidden', 'true');
                dashboardLoadingOverlay.hidden = true;
            }

            if (dashboardShell) {
                dashboardShell.setAttribute('aria-busy', 'false');
            }
        };

        const setSubmitButtonLoadingState = (button, loadingLabel) => {
            if (!button) {
                return;
            }

            if (!button.dataset.originalLabel) {
                button.dataset.originalLabel = button.textContent.trim();
            }

            button.classList.add('is-loading');
            button.disabled = true;

            if (loadingLabel) {
                button.textContent = loadingLabel;
            }
        };

        const isModifiedNavigationEvent = (event) => {
            return event.defaultPrevented
                || event.button !== 0
                || event.metaKey
                || event.ctrlKey
                || event.shiftKey
                || event.altKey;
        };

        const isSameDashboardNavigation = (href) => {
            try {
                const targetUrl = new URL(href, window.location.href);
                const currentUrl = new URL(window.location.href);

                return targetUrl.origin === currentUrl.origin
                    && targetUrl.pathname === currentUrl.pathname;
            } catch (error) {
                return false;
            }
        };

        const persistAutoRefreshState = () => {
            writeSessionJson(autoRefreshStateKey, {
                scrollY: Math.max(window.scrollY || window.pageYOffset || 0, 0)
            });
        };

        const restoreAutoRefreshState = () => {
            const state = readSessionJson(autoRefreshStateKey);
            clearSessionFlag(autoRefreshStateKey);

            if (!state) {
                return;
            }

            const scrollY = Number(state.scrollY || 0);

            if (!Number.isFinite(scrollY) || scrollY <= 0) {
                return;
            }

            window.requestAnimationFrame(() => {
                window.scrollTo({
                    top: scrollY,
                    behavior: 'auto'
                });
            });
        };

        const triggerAutoRefresh = () => {
            if (isDashboardBusy()) {
                scheduleAutoRefresh(autoRefreshRetryDelayMs);
                return;
            }

            persistAutoRefreshState();
            startDashboardLoading(
                'กำลังดึงข้อมูลล่าสุด',
                'ระบบกำลังรีเฟรชแดชบอร์ดอัตโนมัติทุก 10 นาที'
            );
            window.setTimeout(() => {
                window.location.reload();
            }, 140);
        };

        function scheduleAutoRefresh(delay = autoRefreshIntervalMs) {
            if (autoRefreshTimeoutId !== null) {
                window.clearTimeout(autoRefreshTimeoutId);
            }

            autoRefreshDueAt = Date.now() + delay;
            autoRefreshTimeoutId = window.setTimeout(() => {
                if (document.hidden) {
                    autoRefreshTimeoutId = null;
                    autoRefreshDueAt = Date.now();
                    return;
                }

                triggerAutoRefresh();
            }, delay);
        }

        function setupAutoRefresh() {
            scheduleAutoRefresh();

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && autoRefreshDueAt && Date.now() >= autoRefreshDueAt) {
                    triggerAutoRefresh();
                }
            });
        }

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

        const warehouseReceiptValueLabelsPlugin = {
            id: 'warehouseReceiptValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || !['warehouseReceiptMonthlyChart', 'warehouseReceiptCustomerChart', 'warehouseReceiptProductChart'].includes(chart.canvas.id)) {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();
                const fillColor = readThemeValue('--neutral-700', '#334155');
                const primaryColor = readThemeValue('--primary-700', '#4338ca');

                ctx.save();

                if (['warehouseReceiptCustomerChart', 'warehouseReceiptProductChart'].includes(chart.canvas.id)) {
                    const dataset = chart.data.datasets[0];
                    const meta = chart.getDatasetMeta(0);

                    if (!dataset || !meta || meta.hidden) {
                        ctx.restore();
                        return;
                    }

                    ctx.font = `700 12px ${Chart.defaults.font.family}`;
                    ctx.textBaseline = 'middle';

                    meta.data.forEach((bar, index) => {
                        if (!bar) return;
                        const props = bar.getProps ? bar.getProps(['x', 'y'], true) : bar;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const label = formatQuantity(rawValue);
                        const textWidth = ctx.measureText(label).width;
                        let textX = x + 8;
                        let textColor = fillColor;

                        if (textX + textWidth > chartArea.right - 4) {
                            textX = Math.max(chartArea.left + 4, x - textWidth - 10);
                            textColor = readThemeValue('--button-text', '#ffffff');
                        }

                        drawLabelWithOutline(ctx, label, textX, y, textColor, outlineColor);
                    });

                    ctx.restore();
                    return;
                }

                ctx.font = `700 12px ${Chart.defaults.font.family}`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    const isLine = dataset.type === 'line';

                    meta.data.forEach((item, index) => {
                        if (!item) return;
                        const props = item.getProps ? item.getProps(['x', 'y'], true) : item;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const label = dataset.yAxisID === 'y1'
                            ? `${formatQuantity(rawValue)} ใบ`
                            : formatQuantity(rawValue);
                        const textY = Math.max(chartArea.top + 14, y - (isLine ? 12 : 8));
                        drawLabelWithOutline(ctx, label, x, textY, isLine ? primaryColor : fillColor, outlineColor);
                    });
                });

                ctx.restore();
            }
        };

        const purchaseValueLabelsPlugin = {
            id: 'purchaseValueLabels',
            afterDatasetsDraw(chart) {
                if (!chart || !['purchaseMonthlyChart', 'purchaseStatusChart', 'purchaseDepartmentChart', 'purchaseSupplierChart', 'purchaseProductChart'].includes(chart.canvas.id)) {
                    return;
                }

                const { ctx, chartArea } = chart;
                const outlineColor = getOutlineColor();
                const fillColor = readThemeValue('--neutral-700', '#334155');
                const primaryColor = readThemeValue('--primary-700', '#4338ca');

                ctx.save();

                if (chart.canvas.id === 'purchaseStatusChart') {
                    const dataset = chart.data.datasets[0];
                    const meta = chart.getDatasetMeta(0);

                    if (!dataset || !meta || meta.hidden) {
                        ctx.restore();
                        return;
                    }

                    ctx.font = `700 12px ${Chart.defaults.font.family}`;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';

                    meta.data.forEach((arc, index) => {
                        if (!arc || typeof arc.tooltipPosition !== 'function') return;
                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const position = arc.tooltipPosition();
                        drawLabelWithOutline(ctx, formatQuantity(rawValue), position.x, position.y, '#ffffff', 'rgba(15, 23, 42, 0.5)');
                    });

                    ctx.restore();
                    return;
                }

                if (['purchaseDepartmentChart', 'purchaseSupplierChart', 'purchaseProductChart'].includes(chart.canvas.id)) {
                    const dataset = chart.data.datasets[0];
                    const meta = chart.getDatasetMeta(0);

                    if (!dataset || !meta || meta.hidden) {
                        ctx.restore();
                        return;
                    }

                    ctx.font = `700 12px ${Chart.defaults.font.family}`;
                    ctx.textBaseline = 'middle';

                    meta.data.forEach((bar, index) => {
                        if (!bar) return;
                        const props = bar.getProps ? bar.getProps(['x', 'y'], true) : bar;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const label = formatQuantity(rawValue);
                        const textWidth = ctx.measureText(label).width;
                        let textX = x + 8;
                        let textColor = fillColor;

                        if (textX + textWidth > chartArea.right - 4) {
                            textX = Math.max(chartArea.left + 4, x - textWidth - 10);
                            textColor = readThemeValue('--button-text', '#ffffff');
                        }

                        drawLabelWithOutline(ctx, label, textX, y, textColor, outlineColor);
                    });

                    ctx.restore();
                    return;
                }

                ctx.font = `700 12px ${Chart.defaults.font.family}`;
                ctx.textAlign = 'center';

                chart.data.datasets.forEach((dataset, datasetIndex) => {
                    const meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    const isLine = dataset.type === 'line';
                    ctx.textBaseline = 'bottom';

                    meta.data.forEach((item, index) => {
                        if (!item) return;
                        const props = item.getProps ? item.getProps(['x', 'y'], true) : item;
                        const x = props.x;
                        const y = props.y;
                        if (x === undefined || y === undefined) return;

                        const rawValue = Number(dataset.data[index] || 0);
                        if (!rawValue) return;

                        const label = formatQuantity(rawValue);
                        const textY = Math.max(chartArea.top + 14, y - (isLine ? 12 : 8));
                        drawLabelWithOutline(ctx, label, x, textY, isLine ? primaryColor : fillColor, outlineColor);
                    });
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
            loadingValueLabelsPlugin,
            warehouseReceiptValueLabelsPlugin,
            purchaseValueLabelsPlugin
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

        function setupDashboardDataRequests() {
            const forms = Array.from(document.querySelectorAll('#dashboardShell form[method="get"]'));
            const quickLinks = Array.from(document.querySelectorAll('#dashboardShell .filter-actions a[href], .status-box-actions a[href]'));

            if (!forms.length && !quickLinks.length) {
                return;
            }

            forms.forEach((form) => {
                let isSubmitting = false;
                const isYearForm = form.id === 'yearFilterForm';
                const submitButton = form.querySelector('button[type="submit"], button:not([type])');
                const submitButtonLabel = isYearForm ? 'กำลังอัปเดตข้อมูล' : 'กำลังดึงข้อมูล';
                const overlayMessage = isYearForm ? 'กำลังอัปเดตข้อมูลแดชบอร์ด' : 'กำลังดึงข้อมูลใหม่';
                const overlayDetail = isYearForm
                    ? 'ระบบกำลังสรุปข้อมูลล่าสุดของปีที่เลือก'
                    : 'ระบบกำลังดึงข้อมูลล่าสุดจากฐานข้อมูล';
                const submitDelayMs = isYearForm ? 220 : 140;

                form.addEventListener('submit', (event) => {
                    if (isSubmitting || isDashboardBusy()) {
                        event.preventDefault();
                        return;
                    }

                    event.preventDefault();
                    isSubmitting = true;

                    if (isYearForm) {
                        writeSessionFlag('mfoodYearTransition', '1');
                        document.body.classList.add('is-year-changing');
                    }

                    form.classList.add('is-submitting');
                    setSubmitButtonLoadingState(submitButton, submitButtonLabel);
                    startDashboardLoading(overlayMessage, overlayDetail);

                    window.setTimeout(() => {
                        form.submit();
                    }, submitDelayMs);
                });
            });

            quickLinks.forEach((link) => {
                link.addEventListener('click', (event) => {
                    if (isDashboardBusy() || isModifiedNavigationEvent(event) || !isSameDashboardNavigation(link.href)) {
                        return;
                    }

                    event.preventDefault();

                    const parentForm = link.closest('form');
                    const parentSubmitButton = parentForm
                        ? parentForm.querySelector('button[type="submit"], button:not([type])')
                        : null;

                    if (parentForm) {
                        parentForm.classList.add('is-submitting');
                    }

                    setSubmitButtonLoadingState(parentSubmitButton, 'กำลังดึงข้อมูล');
                    startDashboardLoading(
                        'กำลังดึงข้อมูลใหม่',
                        'ระบบกำลังอัปเดตข้อมูลตามตัวกรองล่าสุด'
                    );

                    window.setTimeout(() => {
                        window.location.href = link.href;
                    }, 120);
                });
            });
        }

        function setupFilterDatePickers() {
            const inputs = Array.from(document.querySelectorAll('[data-date-picker="thai"]'));

            if (!inputs.length) {
                return;
            }

            inputs.forEach((input) => {
                input.setAttribute('readonly', 'readonly');

                input.addEventListener('paste', (event) => event.preventDefault());
                input.addEventListener('drop', (event) => event.preventDefault());
                input.addEventListener('keydown', (event) => {
                    const canOpenPicker = event.key === 'Enter' || event.key === ' ';
                    const allowedNavigationKey = event.key === 'Tab' || event.key === 'Escape';

                    if (canOpenPicker) {
                        event.preventDefault();
                        if (input._flatpickr) {
                            input._flatpickr.open();
                        }
                        return;
                    }

                    if (!allowedNavigationKey) {
                        event.preventDefault();
                    }
                });
            });

            if (typeof window.flatpickr !== 'function') {
                return;
            }

            const thaiLocale = window.flatpickr.l10ns && window.flatpickr.l10ns.th
                ? window.flatpickr.l10ns.th
                : undefined;

            inputs.forEach((input) => {
                window.flatpickr(input, {
                    allowInput: false,
                    ariaDateFormat: 'd/m/Y',
                    clickOpens: true,
                    dateFormat: 'd/m/Y',
                    disableMobile: true,
                    locale: thaiLocale
                });
            });
        }

        function setupChartSectionControls() {
            const tabs = Array.from(document.querySelectorAll('[data-chart-section-target]'));
            const sections = Array.from(document.querySelectorAll('[data-chart-section]'));

            if (!tabs.length || !sections.length) {
                return;
            }

            const getTarget = (tab) => tab ? tab.dataset.chartSectionTarget : '';
            const getActiveTarget = () => {
                const requestedTarget = new URLSearchParams(window.location.search).get('chart_section');
                if (requestedTarget && tabs.some((tab) => getTarget(tab) === requestedTarget)) {
                    return requestedTarget;
                }

                const activeTab = tabs.find((tab) => tab.classList.contains('is-active')) || tabs[0];
                return getTarget(activeTab);
            };

            function resizeActiveCharts() {
                window.requestAnimationFrame(() => {
                    chartInstances.forEach((chart) => {
                        if (!chart || !chart.canvas || !chart.canvas.closest('.chart-section.is-active')) {
                            return;
                        }

                        chart.resize();
                        chart.update('none');
                    });
                });
            }

            function animateSectionEntrance(section) {
                if (!section || prefersReducedMotion) {
                    return;
                }

                const motionTargets = Array.from(section.children).filter((node) => node.matches && node.matches('form, .panel'));
                motionTargets.forEach((node, index) => {
                    node.style.setProperty('--section-enter-delay', `${Math.min(index, 7) * 26}ms`);
                });

                section.classList.remove('is-section-entering');
                void section.offsetWidth;
                section.classList.add('is-section-entering');

                window.setTimeout(() => {
                    section.classList.remove('is-section-entering');
                }, 360);
            }

            function activateChartSection(target) {
                document.body.dataset.activeSection = target;
                replaceQueryParam('chart_section', target);

                tabs.forEach((tab) => {
                    const isActive = getTarget(tab) === target;
                    tab.classList.toggle('is-active', isActive);
                    tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    tab.tabIndex = isActive ? 0 : -1;
                });

                sections.forEach((section) => {
                    const isActive = section.dataset.chartSection === target;
                    section.classList.toggle('is-active', isActive);
                    section.toggleAttribute('hidden', !isActive);
                    if (isActive) {
                        animateSectionEntrance(section);
                    }
                });

                document.body.classList.add('is-chart-filter-ready');
                resizeActiveCharts();
            }

            tabs.forEach((tab, index) => {
                tab.addEventListener('click', () => {
                    activateChartSection(getTarget(tab));
                });

                tab.addEventListener('keydown', (event) => {
                    const keyMap = {
                        ArrowRight: 1,
                        ArrowDown: 1,
                        ArrowLeft: -1,
                        ArrowUp: -1
                    };

                    if (event.key === 'Home') {
                        event.preventDefault();
                        tabs[0].focus();
                        activateChartSection(getTarget(tabs[0]));
                        return;
                    }

                    if (event.key === 'End') {
                        event.preventDefault();
                        const lastTab = tabs[tabs.length - 1];
                        lastTab.focus();
                        activateChartSection(getTarget(lastTab));
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(keyMap, event.key)) {
                        return;
                    }

                    event.preventDefault();
                    const nextIndex = (index + keyMap[event.key] + tabs.length) % tabs.length;
                    tabs[nextIndex].focus();
                    activateChartSection(getTarget(tabs[nextIndex]));
                });
            });

            activateChartSection(getActiveTarget());
        }

        function setupDashboardAmbientMotion() {
            const spotlightTargets = Array.from(document.querySelectorAll('.summary-card, .panel, .chart-section-tabs'));
            const supportsPointerMotion = !prefersReducedMotion
                && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

            if (supportsPointerMotion) {
                spotlightTargets.forEach((element) => {
                    const syncPointer = (event) => {
                        const rect = element.getBoundingClientRect();
                        if (!rect.width || !rect.height) {
                            return;
                        }

                        const x = ((event.clientX - rect.left) / rect.width) * 100;
                        const y = ((event.clientY - rect.top) / rect.height) * 100;
                        element.style.setProperty('--pointer-x', `${Math.max(0, Math.min(100, x))}%`);
                        element.style.setProperty('--pointer-y', `${Math.max(0, Math.min(100, y))}%`);
                        element.classList.add('is-pointer-active');
                    };

                    element.addEventListener('pointerenter', syncPointer);
                    element.addEventListener('pointermove', syncPointer);
                    element.addEventListener('pointerleave', () => {
                        element.classList.remove('is-pointer-active');
                    });
                });
            }

            const syncScrolledState = () => {
                document.body.classList.toggle('is-dashboard-scrolled', window.scrollY > 24);
            };

            syncScrolledState();
            window.addEventListener('scroll', syncScrolledState, { passive: true });
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

        function renderWarehouseReceiptMonthlyChart(canvas, sharedLegend, sharedTooltip, defaultTicks, defaultGrid, palette) {
            const weightSeries = dashboardData.warehouseReceiptWeightSeries || [];
            const docSeries = dashboardData.warehouseReceiptDocSeries || [];
            const weightTotal = weightSeries.reduce((sum, value) => sum + Number(value || 0), 0);
            const docTotal = docSeries.reduce((sum, value) => sum + Number(value || 0), 0);
            const qtyGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.mint, 0.92),
                withAlpha(palette.skySoft, 0.48)
            );
            const docGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.primaryDeep, 0.24),
                withAlpha(palette.primaryDeep, 0.04)
            );

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: dashboardData.monthLabels,
                    datasets: [{
                        type: 'bar',
                        label: 'น้ำหนักรับเข้า',
                        data: weightSeries,
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 30,
                        backgroundColor: qtyGradient,
                        borderColor: withAlpha(palette.mint, 0.96),
                        borderWidth: 1.2,
                        yAxisID: 'y',
                        order: 2
                    }, {
                        type: 'line',
                        label: 'เอกสารรับเข้า',
                        data: docSeries,
                        borderColor: palette.primaryDeep,
                        backgroundColor: docGradient,
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
                                    const suffix = ds && ds.yAxisID === 'y1' ? ' ใบ' : ' กก.';
                                    return ` ${ds ? ds.label : ''}: ${formatQuantity(context.raw)}${suffix}`;
                                },
                                afterBody: () => [
                                    `น้ำหนักรวม: ${formatQuantity(weightTotal)} กก.`,
                                    `เอกสารรวม: ${formatQuantity(docTotal)} ใบ`
                                ]
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
                                text: 'น้ำหนักรับเข้า (กก.)',
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
                                text: 'เอกสาร',
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

        function renderWarehouseReceiptRankingChart(canvas, labels, qtySeries, docSeries, lineSeries, chartTitle, defaultTicks, defaultGrid, sharedTooltip, palette, colorRole) {
            const colorStart = colorRole === 'product' ? palette.sky : palette.primary;
            const colorEnd = colorRole === 'product' ? palette.mint : palette.skySoft;
            const borderColor = colorRole === 'product' ? palette.sky : palette.primaryDeep;
            const rankGradient = createHorizontalGradient(
                canvas,
                withAlpha(colorStart, 0.94),
                withAlpha(colorEnd, 0.66)
            );

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'น้ำหนักรับเข้า',
                        data: qtySeries,
                        borderRadius: 12,
                        borderSkipped: false,
                        maxBarThickness: 26,
                        backgroundColor: rankGradient,
                        hoverBackgroundColor: rankGradient,
                        borderColor: withAlpha(borderColor, 0.96),
                        borderWidth: 1.2
                    }]
                },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            right: 64
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
                                label: (context) => ` น้ำหนักรับเข้า: ${formatQuantity(context.raw)} กก.`,
                                afterBody: (items) => {
                                    if (!items.length) {
                                        return [];
                                    }

                                    const index = items[0].dataIndex;
                                    return [
                                        `เอกสาร: ${formatQuantity(docSeries[index] || 0)} ใบ`,
                                        `รายการ: ${formatQuantity(lineSeries[index] || 0)} รายการ`
                                    ];
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
                                text: 'น้ำหนักรับเข้า (กก.)',
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

        function renderPurchaseMonthlyChart(canvas, sharedLegend, sharedTooltip, defaultTicks, defaultGrid, palette) {
            const prGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.primary, 0.92),
                withAlpha(palette.primarySoft, 0.42)
            );
            const poGradient = createVerticalGradient(
                canvas,
                withAlpha(palette.sky, 0.22),
                withAlpha(palette.sky, 0.04)
            );
            const prTotal = (dashboardData.purchaseMonthlyPrSeries || []).reduce((sum, value) => sum + value, 0);
            const poTotal = (dashboardData.purchaseMonthlyPoSeries || []).reduce((sum, value) => sum + value, 0);

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: dashboardData.monthLabels,
                    datasets: [{
                        type: 'bar',
                        label: 'PR',
                        data: dashboardData.purchaseMonthlyPrSeries || [],
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 28,
                        backgroundColor: prGradient,
                        borderColor: withAlpha(palette.primaryDeep, 0.96),
                        borderWidth: 1.2,
                        order: 2
                    }, {
                        type: 'line',
                        label: 'PO',
                        data: dashboardData.purchaseMonthlyPoSeries || [],
                        borderColor: palette.sky,
                        backgroundColor: poGradient,
                        pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                        pointBorderColor: palette.sky,
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        borderWidth: 3,
                        tension: 0.42,
                        order: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            top: 30
                        }
                    },
                    plugins: {
                        legend: sharedLegend,
                        tooltip: {
                            ...sharedTooltip,
                            callbacks: {
                                title: (items) => items.length ? `เดือน ${items[0].label}` : '',
                                label: (context) => {
                                    const ds = context.dataset || context.chart.data.datasets[context.datasetIndex];
                                    return ` ${ds ? ds.label : ''}: ${formatQuantity(context.raw)} รายการ`;
                                },
                                afterBody: () => [
                                    `PR ทั้งปี: ${formatQuantity(prTotal)} รายการ`,
                                    `PO ทั้งปี: ${formatQuantity(poTotal)} รายการ`
                                ]
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: defaultGrid,
                            ticks: {
                                ...defaultTicks,
                                precision: 0,
                                callback: (value) => formatQuantity(value)
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

        function renderPurchaseStatusChart(canvas, sharedLegend, sharedTooltip, palette) {
            const labels = dashboardData.purchaseStatusLabels || [];
            const lineSeries = dashboardData.purchaseStatusLineSeries || [];
            const qtySeries = dashboardData.purchaseStatusQtySeries || [];
            const purchasedQtySeries = dashboardData.purchaseStatusPurchasedQtySeries || [];
            const colors = [
                withAlpha(palette.sky, 0.86),
                withAlpha(palette.primary, 0.86),
                withAlpha(palette.mint, 0.86),
                withAlpha(palette.primaryDeep, 0.86),
                withAlpha(readThemeValue('--chart-8', '#f59e0b'), 0.86),
                withAlpha(readThemeValue('--chart-9', '#fb7185'), 0.86)
            ];
            const totalLines = lineSeries.reduce((sum, value) => sum + value, 0);

            registerChart(new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        label: 'รายการ',
                        data: lineSeries,
                        backgroundColor: labels.map((label, index) => colors[index % colors.length]),
                        borderColor: readThemeValue('--chart-surface-top', '#ffffff'),
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            ...sharedLegend,
                            position: 'bottom',
                            align: 'center'
                        },
                        tooltip: {
                            ...sharedTooltip,
                            callbacks: {
                                label: (context) => {
                                    const value = Number(context.raw || 0);
                                    const pct = totalLines > 0 ? ((value / totalLines) * 100).toFixed(1) : '0.0';
                                    return ` ${context.label}: ${formatQuantity(value)} รายการ (${pct}%)`;
                                },
                                afterBody: (items) => {
                                    if (!items.length) {
                                        return [];
                                    }

                                    const index = items[0].dataIndex;
                                    return [
                                        `จำนวน PR: ${formatQuantity(qtySeries[index] || 0)}`,
                                        `จำนวนเปิดซื้อ: ${formatQuantity(purchasedQtySeries[index] || 0)}`
                                    ];
                                }
                            }
                        }
                    }
                }
            }));
        }

        function renderPurchaseDepartmentChart(canvas, defaultTicks, defaultGrid, sharedTooltip, palette) {
            const labels = dashboardData.purchaseDepartmentLabels || [];
            const lineSeries = dashboardData.purchaseDepartmentLineSeries || [];
            const qtySeries = dashboardData.purchaseDepartmentQtySeries || [];
            const poLineSeries = dashboardData.purchaseDepartmentPoLineSeries || [];

            renderPurchaseRankingChart(canvas, {
                labels,
                values: lineSeries,
                datasetLabel: 'รายการ PR',
                titlePrefix: '',
                colorStart: withAlpha(palette.mint, 0.94),
                colorEnd: withAlpha(palette.skySoft, 0.7),
                borderColor: withAlpha(palette.mint, 0.96),
                defaultTicks,
                defaultGrid,
                sharedTooltip,
                tooltipLabel: (context) => ` รายการ PR: ${formatQuantity(context.raw)} รายการ`,
                tooltipAfterBody: (index) => [
                    `เปิด PO แล้ว: ${formatQuantity(poLineSeries[index] || 0)} รายการ`,
                    `จำนวน PR: ${formatQuantity(qtySeries[index] || 0)}`
                ]
            });
        }

        function renderPurchaseSupplierChart(canvas, defaultTicks, defaultGrid, sharedTooltip, palette) {
            const labels = dashboardData.purchaseSupplierLabels || [];
            const poSeries = dashboardData.purchaseSupplierPoSeries || [];
            const prSeries = dashboardData.purchaseSupplierPrSeries || [];
            const lineSeries = dashboardData.purchaseSupplierLineSeries || [];

            renderPurchaseRankingChart(canvas, {
                labels,
                values: poSeries,
                datasetLabel: 'PO',
                colorStart: withAlpha(palette.primary, 0.94),
                colorEnd: withAlpha(palette.skySoft, 0.66),
                borderColor: withAlpha(palette.primaryDeep, 0.96),
                defaultTicks,
                defaultGrid,
                sharedTooltip,
                tooltipLabel: (context) => ` PO: ${formatQuantity(context.raw)} ใบ`,
                tooltipAfterBody: (index) => [
                    `PR ที่เกี่ยวข้อง: ${formatQuantity(prSeries[index] || 0)} เอกสาร`,
                    `รายการที่เปิดซื้อ: ${formatQuantity(lineSeries[index] || 0)} รายการ`
                ]
            });
        }

        function renderPurchaseProductChart(canvas, defaultTicks, defaultGrid, sharedTooltip, palette) {
            const labels = dashboardData.purchaseProductLabels || [];
            const lineSeries = dashboardData.purchaseProductLineSeries || [];
            const prSeries = dashboardData.purchaseProductPrSeries || [];
            const poLineSeries = dashboardData.purchaseProductPoLineSeries || [];
            const qtySeries = dashboardData.purchaseProductQtySeries || [];

            renderPurchaseRankingChart(canvas, {
                labels,
                values: lineSeries,
                datasetLabel: 'รายการ PR',
                colorStart: withAlpha(readThemeValue('--chart-8', '#f59e0b'), 0.9),
                colorEnd: withAlpha(palette.mint, 0.6),
                borderColor: withAlpha(readThemeValue('--chart-8', '#f59e0b'), 0.96),
                defaultTicks,
                defaultGrid,
                sharedTooltip,
                tooltipLabel: (context) => ` รายการ PR: ${formatQuantity(context.raw)} รายการ`,
                tooltipAfterBody: (index) => [
                    `PR ที่เกี่ยวข้อง: ${formatQuantity(prSeries[index] || 0)} เอกสาร`,
                    `เปิด PO แล้ว: ${formatQuantity(poLineSeries[index] || 0)} รายการ`,
                    `จำนวนที่ขอ: ${formatQuantity(qtySeries[index] || 0)}`
                ]
            });
        }

        function renderPurchaseRankingChart(canvas, config) {
            const labels = config.labels || [];
            const rankGradient = createHorizontalGradient(
                canvas,
                config.colorStart,
                config.colorEnd
            );

            registerChart(new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: config.datasetLabel,
                        data: config.values || [],
                        borderRadius: 12,
                        borderSkipped: false,
                        maxBarThickness: 26,
                        backgroundColor: rankGradient,
                        hoverBackgroundColor: rankGradient,
                        borderColor: config.borderColor,
                        borderWidth: 1.2
                    }]
                },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            right: 64
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...config.sharedTooltip,
                            callbacks: {
                                title: (items) => items.length ? labels[items[0].dataIndex] : '',
                                label: config.tooltipLabel,
                                afterBody: (items) => {
                                    if (!items.length) {
                                        return [];
                                    }

                                    return config.tooltipAfterBody(items[0].dataIndex);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: config.defaultGrid,
                            ticks: {
                                ...config.defaultTicks,
                                precision: 0,
                                callback: (value) => formatQuantity(value)
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                ...config.defaultTicks,
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

            const purchaseMonthlyCanvas = document.getElementById('purchaseMonthlyChart');
            if (purchaseMonthlyCanvas) {
                renderPurchaseMonthlyChart(
                    purchaseMonthlyCanvas,
                    sharedLegend,
                    sharedTooltip,
                    defaultTicks,
                    defaultGrid,
                    palette
                );
            }

            const purchaseStatusCanvas = document.getElementById('purchaseStatusChart');
            if (purchaseStatusCanvas && (dashboardData.purchaseStatusLabels || []).length) {
                renderPurchaseStatusChart(
                    purchaseStatusCanvas,
                    sharedLegend,
                    sharedTooltip,
                    palette
                );
            }

            const purchaseDepartmentCanvas = document.getElementById('purchaseDepartmentChart');
            if (purchaseDepartmentCanvas && (dashboardData.purchaseDepartmentLabels || []).length) {
                renderPurchaseDepartmentChart(
                    purchaseDepartmentCanvas,
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette
                );
            }

            const purchaseSupplierCanvas = document.getElementById('purchaseSupplierChart');
            if (purchaseSupplierCanvas && (dashboardData.purchaseSupplierLabels || []).length) {
                renderPurchaseSupplierChart(
                    purchaseSupplierCanvas,
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette
                );
            }

            const purchaseProductCanvas = document.getElementById('purchaseProductChart');
            if (purchaseProductCanvas && (dashboardData.purchaseProductLabels || []).length) {
                renderPurchaseProductChart(
                    purchaseProductCanvas,
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette
                );
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

            const warehouseReceiptMonthlyCanvas = document.getElementById('warehouseReceiptMonthlyChart');
            if (warehouseReceiptMonthlyCanvas) {
                renderWarehouseReceiptMonthlyChart(
                    warehouseReceiptMonthlyCanvas,
                    sharedLegend,
                    sharedTooltip,
                    defaultTicks,
                    defaultGrid,
                    palette
                );
            }

            const warehouseReceiptCustomerCanvas = document.getElementById('warehouseReceiptCustomerChart');
            if (warehouseReceiptCustomerCanvas && (dashboardData.warehouseReceiptCustomerLabels || []).length) {
                renderWarehouseReceiptRankingChart(
                    warehouseReceiptCustomerCanvas,
                    dashboardData.warehouseReceiptCustomerLabels || [],
                    dashboardData.warehouseReceiptCustomerQtySeries || [],
                    dashboardData.warehouseReceiptCustomerDocSeries || [],
                    dashboardData.warehouseReceiptCustomerLineSeries || [],
                    'Top 10 ลูกค้าที่ฝากตามน้ำหนัก',
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette,
                    'customer'
                );
            }

            const warehouseReceiptProductCanvas = document.getElementById('warehouseReceiptProductChart');
            if (warehouseReceiptProductCanvas && (dashboardData.warehouseReceiptProductLabels || []).length) {
                renderWarehouseReceiptRankingChart(
                    warehouseReceiptProductCanvas,
                    dashboardData.warehouseReceiptProductLabels || [],
                    dashboardData.warehouseReceiptProductQtySeries || [],
                    dashboardData.warehouseReceiptProductDocSeries || [],
                    dashboardData.warehouseReceiptProductLineSeries || [],
                    'Top 10 สินค้าที่ฝากตามน้ำหนัก',
                    defaultTicks,
                    defaultGrid,
                    sharedTooltip,
                    palette,
                    'customer'
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
            setupChartSectionControls();
            restoreAutoRefreshState();
            setupDashboardAmbientMotion();
            setupCounters();
            setupDashboardDataRequests();
            setupFilterDatePickers();
            setupAutoRefresh();
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => {
                    stopDashboardLoading();
                });
            });
        } catch (e) {
            stopDashboardLoading();
            console.error("Dashboard initialization error:", e);
            const errorBanner = document.createElement('div');
            errorBanner.style.cssText = 'position:fixed;top:10px;left:10px;right:10px;background:#fef2f2;color:#991b1b;border:1px solid #fee2e2;padding:15px;z-index:9999;border-radius:8px;font-family:sans-serif;box-shadow:0 4px 6px rgba(0,0,0,0.1)';
            errorBanner.innerHTML = `<strong>พบข้อผิดพลาดในการโหลดแดชบอร์ด:</strong> ${e.message}<br><small style="opacity:0.8; white-space:pre-wrap; display:block; margin-top:5px; font-family:monospace">${e.stack}</small>`;
            document.body.appendChild(errorBanner);
        }
