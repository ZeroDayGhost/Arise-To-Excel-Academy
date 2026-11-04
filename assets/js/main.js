// Offline helpers for the fee management dashboard.
document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    var sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    var sidebarClose = document.querySelector('[data-sidebar-close]');
    var themeToggle = document.querySelector('[data-theme-toggle]');
    var mobileQuery = window.matchMedia('(max-width: 991px)');

    if (themeToggle) {
        var themeIcon = themeToggle.querySelector('i');
        var applyTheme = function (theme, persist) {
            var isDark = theme === 'dark';
            document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
            var label = isDark ? 'Switch to light theme' : 'Switch to dark theme';
            themeToggle.setAttribute('aria-label', label);
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute('title', label);
            if (themeIcon) {
                themeIcon.className = 'fa-solid ' + (isDark ? 'fa-sun' : 'fa-moon');
            }
            if (window.Chart) {
                var chartTextColor = isDark ? '#b3b3b3' : '#667085';
                var chartGridColor = isDark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(148, 163, 184, 0.22)';
                Chart.defaults.color = chartTextColor;
                Chart.defaults.borderColor = chartGridColor;
                if (Chart.instances) {
                    Object.keys(Chart.instances).forEach(function (instanceId) {
                        var chart = Chart.instances[instanceId];
                        chart.options.color = chartTextColor;
                        if (chart.options.scales) {
                            Object.keys(chart.options.scales).forEach(function (scaleId) {
                                var scale = chart.options.scales[scaleId];
                                if (scale.ticks) scale.ticks.color = chartTextColor;
                                if (scale.grid) scale.grid.color = chartGridColor;
                            });
                        }
                        if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
                            chart.options.plugins.legend.labels.color = chartTextColor;
                        }
                        chart.update('none');
                    });
                }
            }
            if (persist) {
                try {
                    window.localStorage.setItem('ariseAdminTheme', isDark ? 'dark' : 'light');
                } catch (error) {}
            }
        };

        applyTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light', false);
        themeToggle.addEventListener('click', function () {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark', true);
        });
    }

    var closeSidebar = function () {
        body.classList.remove('sidebar-open');
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            if (mobileQuery.matches) {
                body.classList.toggle('sidebar-open');
                return;
            }

            body.classList.toggle('sidebar-collapsed');
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }

    var closeHeaderDropdowns = function (exceptDropdown) {
        document.querySelectorAll('[data-header-dropdown]').forEach(function (dropdown) {
            if (dropdown === exceptDropdown) {
                return;
            }

            var menu = dropdown.querySelector('.dropdown-menu');
            var button = dropdown.querySelector('[data-header-dropdown-toggle]');
            if (menu) {
                menu.classList.remove('show');
            }
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    };

    document.querySelectorAll('[data-header-dropdown-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var dropdown = button.closest('[data-header-dropdown]');
            var menu = dropdown ? dropdown.querySelector('.dropdown-menu') : null;
            if (!dropdown || !menu) {
                return;
            }

            closeHeaderDropdowns(dropdown);
            var isOpen = menu.classList.toggle('show');
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (mobileQuery.matches) {
                closeSidebar();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented || form.dataset.disableSubmitLock === 'true') {
                return;
            }

            var button = event.submitter || form.querySelector('button[type="submit"]');
            if (button) {
                setTimeout(function () {
                    button.disabled = true;
                }, 0);
            }
        });
    });

    document.querySelectorAll('.dashboard-search-button').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var form = button.closest('form');
            if (!form) {
                return;
            }

            event.preventDefault();
            form.submit();
        });
    });

    var closeExportMenus = function (exceptDropdown) {
        document.querySelectorAll('.export-dropdown').forEach(function (dropdown) {
            if (dropdown === exceptDropdown) {
                return;
            }

            var menu = dropdown.querySelector('.export-menu');
            var button = dropdown.querySelector('[data-export-toggle]');
            if (menu) {
                menu.classList.remove('show');
            }
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    };

    document.querySelectorAll('[data-export-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var dropdown = button.closest('.export-dropdown');
            var menu = dropdown ? dropdown.querySelector('.export-menu') : null;
            if (!dropdown || !menu) {
                return;
            }

            closeExportMenus(dropdown);
            var isOpen = menu.classList.toggle('show');
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-header-dropdown]')) {
            closeHeaderDropdowns(null);
        }

        if (!event.target.closest('.export-dropdown')) {
            closeExportMenus(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeExportMenus(null);
            closeHeaderDropdowns(null);
        }
    });

    document.querySelectorAll('[data-export-option]').forEach(function (link) {
        link.addEventListener('click', function () {
            var dropdown = link.closest('.export-dropdown');
            var button = dropdown ? dropdown.querySelector('[data-export-toggle]') : null;
            var label = button ? button.querySelector('[data-export-label]') : null;

            if (!button || !label) {
                return;
            }

            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            closeExportMenus(null);
            label.textContent = link.textContent.indexOf('Print') !== -1 ? 'Preparing...' : 'Exporting...';

            window.setTimeout(function () {
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
                label.textContent = 'Export';
            }, 3500);
        });
    });

    var categorySelect = document.getElementById('category');
    var customCategoryField = document.querySelector('.custom-category-field');
    var customCategoryInput = document.getElementById('custom_category_name');
    var toggleCustomCategory = function () {
        if (!categorySelect || !customCategoryField) {
            return;
        }

        var showCustomCategory = categorySelect.value === 'Other';
        customCategoryField.classList.toggle('d-none', !showCustomCategory);
        if (customCategoryInput) {
            customCategoryInput.required = showCustomCategory;
            if (!showCustomCategory) {
                customCategoryInput.value = '';
            }
        }
    };
    if (categorySelect && customCategoryField) {
        categorySelect.addEventListener('change', toggleCustomCategory);
        toggleCustomCategory();
    }

    if (!window.Chart) {
        return;
    }

    Chart.defaults.font.family = "'Onest', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    Chart.defaults.color = document.documentElement.dataset.theme === 'dark' ? '#b3b3b3' : '#667085';
    Chart.defaults.borderColor = 'rgba(148, 163, 184, 0.22)';

    var palette = ['#229ed1', '#16a34a', '#df2333', '#d97706', '#168fbf', '#b52642', '#ea580c', '#0f766e', '#475569'];

    var safeSeries = function (labels, values) {
        if (!labels || !labels.length) {
            return {
                labels: ['No data'],
                values: [0]
            };
        }

        return {
            labels: labels,
            values: values && values.length ? values : labels.map(function () { return 0; })
        };
    };

    var currencyTick = function (value) {
        return 'KES ' + Number(value || 0).toLocaleString();
    };

    var gradient = function (chart, firstColor, secondColor) {
        var ctx = chart.ctx;
        var area = chart.chartArea;

        if (!area) {
            return firstColor;
        }

        var fill = ctx.createLinearGradient(0, area.top, 0, area.bottom);
        fill.addColorStop(0, firstColor);
        fill.addColorStop(1, secondColor || firstColor);
        return fill;
    };

    var sharedOptions = function (type, moneyAxis) {
        var roundChart = type === 'pie' || type === 'doughnut';

        return {
            responsive: true,
            maintainAspectRatio: false,
            cutout: type === 'doughnut' ? '68%' : undefined,
            plugins: {
                legend: {
                    display: roundChart,
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        boxHeight: 8,
                        padding: 18,
                        font: {
                            weight: 700
                        }
                    }
                },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 12,
                    cornerRadius: 14,
                    titleFont: {
                        weight: 800
                    },
                    bodyFont: {
                        weight: 700
                    }
                }
            },
            scales: roundChart ? {} : {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            weight: 700
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(148, 163, 184, 0.18)',
                        drawBorder: false
                    },
                    ticks: {
                        callback: moneyAxis ? currencyTick : undefined,
                        font: {
                            weight: 700
                        }
                    }
                }
            }
        };
    };

    var drawChart = function (id, type, labels, values, label, colors, moneyAxis) {
        var canvas = document.getElementById(id);
        if (!canvas) {
            return;
        }

        var data = safeSeries(labels, values);
        var roundChart = type === 'pie' || type === 'doughnut';
        var backgroundColor = roundChart ? (colors || palette) : function (context) {
            if (type === 'bar' && colors && colors.length) {
                return colors[context.dataIndex % colors.length];
            }

            return gradient(context.chart, 'rgba(37, 99, 235, 0.88)', 'rgba(6, 182, 212, 0.58)');
        };

        new Chart(canvas, {
            type: type,
            data: {
                labels: data.labels,
                datasets: [{
                    label: label,
                    data: data.values,
                    backgroundColor: backgroundColor,
                    borderColor: roundChart ? '#ffffff' : '#229ed1',
                    borderWidth: roundChart ? 4 : 2,
                    borderRadius: type === 'bar' ? 12 : 0,
                    tension: type === 'line' ? 0.38 : 0,
                    fill: type === 'line',
                    pointRadius: type === 'line' ? 4 : 0,
                    pointHoverRadius: type === 'line' ? 7 : 0,
                    pointBackgroundColor: '#229ed1',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 3
                }]
            },
            options: sharedOptions(type, !!moneyAxis)
        });
    };

    if (window.dashboardCharts) {
        drawChart(
            'feeOverviewChart',
            'bar',
            window.dashboardCharts.feeOverview.labels,
            window.dashboardCharts.feeOverview.values,
            'Financial Overview',
            ['#229ed1', '#16a34a', '#d97706', '#168fbf'],
            true
        );

        drawChart(
            'studentStatsChart',
            'doughnut',
            window.dashboardCharts.studentStats.labels,
            window.dashboardCharts.studentStats.values,
            'Students',
            ['#229ed1', '#df2333']
        );

        drawChart(
            'collectionTrendChart',
            'line',
            window.dashboardCharts.collectionTrend.labels,
            window.dashboardCharts.collectionTrend.values,
            'Collections',
            null,
            true
        );
    }

    if (window.monthlyPaymentsChartData) {
        drawChart(
            'monthlyPaymentsChart',
            'bar',
            window.monthlyPaymentsChartData.labels,
            window.monthlyPaymentsChartData.totals,
            'Fees Collected',
            null,
            true
        );
    }

    if (window.reportCharts) {
        var drawReportChart = function (key, id, type, label, colors, moneyAxis) {
            if (!window.reportCharts[key]) {
                return;
            }

            drawChart(id, type, window.reportCharts[key].labels, window.reportCharts[key].values, label, colors, moneyAxis);
        };

        drawReportChart('feeCollectionsByClass', 'feeCollectionsByClassChart', 'bar', 'Fees Collected', null, true);
        drawReportChart('studentsPerClass', 'studentsPerClassChart', 'bar', 'Students');
        drawReportChart('paymentsByTerm', 'paymentsByTermChart', 'bar', 'Fees Paid', null, true);
        drawReportChart('feeCollectionSummary', 'feeCollectionSummaryChart', 'bar', 'Fee Summary', null, true);
        drawReportChart('paidUnpaid', 'paidUnpaidChart', 'doughnut', 'Paid vs Unpaid', ['#16a34a', '#ea580c']);
        drawReportChart('expensesSummary', 'expensesSummaryChart', 'doughnut', 'Expenses', palette);
        drawReportChart('transportPayments', 'transportPaymentsChart', 'bar', 'Transport Payments', null, true);
        drawReportChart('feedingPayments', 'feedingPaymentsChart', 'bar', 'Feeding Payments', null, true);
        drawReportChart('monthlyCollections', 'monthlyCollectionsChart', 'line', 'Monthly Collections', null, true);
        drawReportChart('incomeExpense', 'incomeExpenseChart', 'doughnut', 'Income vs Expenditure', ['#16a34a', '#dc2626']);
    }
});
