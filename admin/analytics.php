<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

// Fetch available years for the filter
$stmt = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, LENGTH(class_name), class_name, section");
$all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = array_unique(array_column($all_classes, 'academic_year'));
$current_year = date('Y');
if (!in_array($current_year, $years)) {
    array_unshift($years, $current_year);
}
sort($years);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Analytics Command Center - SRMS Admin</title>
    <?php include('header.php'); ?>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        .analytics-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.02);
            height: 100%;
        }

        .analytics-card h5 {
            font-family: 'Playfair Display', serif;
            color: var(--prestige-navy);
            font-weight: 700;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--prestige-border);
            padding-bottom: 10px;
        }

        .kpi-box {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.02);
            border: 1px dashed var(--prestige-border);
            transition: all 0.3s ease;
        }

        .kpi-box.navy {
            background: rgba(15, 23, 42, 0.05);
            border-color: rgba(15, 23, 42, 0.1);
        }

        .kpi-box.gold {
            background: rgba(180, 83, 9, 0.05);
            border-color: rgba(180, 83, 9, 0.2);
        }

        .kpi-box h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 10px 0 0 0;
            color: var(--prestige-navy);
        }

        .kpi-box p {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            font-weight: 600;
            margin: 0;
        }

        .kpi-box i {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .kpi-box.navy i {
            color: var(--prestige-navy);
        }

        .kpi-box.gold i {
            color: var(--prestige-gold);
        }

        /* Dark Mode Analytics Adjustments */
        [data-theme="dark"] .analytics-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .analytics-card h5 {
            color: #e2e8f0 !important;
            border-bottom-color: rgba(255, 255, 255, 0.08) !important;
        }

        [data-theme="dark"] .kpi-box {
            background: rgba(255, 255, 255, 0.03) !important;
            border-color: rgba(255, 255, 255, 0.08) !important;
        }

        [data-theme="dark"] .kpi-box.navy {
            background: rgba(245, 158, 11, 0.03) !important;
            border-color: rgba(245, 158, 11, 0.1) !important;
        }

        [data-theme="dark"] .kpi-box.gold {
            background: rgba(245, 158, 11, 0.05) !important;
            border-color: rgba(245, 158, 11, 0.2) !important;
        }

        [data-theme="dark"] .kpi-box h2 {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .kpi-box p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .kpi-box.navy i {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .kpi-box.gold i {
            color: #f59e0b !important;
        }

        [data-theme="dark"] .form-label-custom {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .apexcharts-canvas text {
            fill: #94a3b8 !important;
        }

        [data-theme="dark"] .apexcharts-tooltip {
            background: #1f2937 !important;
            border-color: #374151 !important;
            color: #f3f4f6 !important;
        }

        [data-theme="dark"] .apexcharts-legend-text {
            color: #94a3b8 !important;
        }

        /* Badge Adjustments for Dark Mode */
        [data-theme="dark"] .badge.bg-success.bg-opacity-10 {
            background-color: rgba(16, 185, 129, 0.15) !important;
            color: #34d399 !important;
            border-color: rgba(16, 185, 129, 0.3) !important;
        }

        [data-theme="dark"] .badge.bg-danger.bg-opacity-10 {
            background-color: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
        }

        [data-theme="dark"] .analytics-card .mt-auto {
            color: #6b7280 !important;
            border-top-color: rgba(255, 255, 255, 0.07) !important;
        }
    </style>
</head>

<body>
    <?php include('sidebar.php'); ?>
    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-0 px-md-4">
            <div class="page-header mb-4" style="border-bottom: none; padding-bottom: 0;">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Analytics Command Center</h1>
                        <p class="mb-0 text-muted">Macro-level insights and performance metrics.</p>
                    </div>
                </div>
            </div>

            <!-- Dynamic Filters -->
            <div class="card analytics-card mb-4 p-3" style="border-left: 4px solid var(--prestige-gold);">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;"><i
                                class="fas fa-calendar-check"></i> Academic Session</label>
                        <select id="yearFilter" class="form-select select-premium shadow-sm"
                            onchange="updateClasses(); loadAnalytics();">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $current_year ? 'selected' : ''; ?>><?php echo $y; ?> </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;"><i
                                class="fas fa-file-certificate"></i> Exam Type</label>
                        <select id="examFilter" class="form-select select-premium shadow-sm" onchange="loadAnalytics()">
                            <option value="all">Combined (All Exams)</option>
                            <option value="Half Yearly">Half Yearly </option>

                            <option value="Final">Final Year </option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;"><i class="fas fa-users-class"></i>
                            Target Class</label>
                        <select id="classFilter" class="form-select select-premium shadow-sm"
                            onchange="updateSections(); loadAnalytics();">
                            <option value="all">All Classes</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label-custom" style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;"><i class="fas fa-layer-group"></i>
                            Target Section</label>
                        <select id="sectionFilter" class="form-select select-premium shadow-sm"
                            onchange="loadAnalytics()">
                            <option value="all">All Sections</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- KPIs -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="kpi-box navy">
                        <i class="fas fa-user-graduate"></i>
                        <p>Total Students</p>
                        <h2 id="kpi-students">0</h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-box gold">
                        <i class="fas fa-chart-line"></i>
                        <p>Pass Rate</p>
                        <h2 id="kpi-pass">0%</h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-box navy">
                        <i class="fas fa-times-circle" style="color: #e74a3b;"></i>
                        <p>Total Failed</p>
                        <h2 id="kpi-fail">0</h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-box gold">
                        <i class="fas fa-medal"></i>
                        <p>Excellence (A+)</p>
                        <h2 id="kpi-aplus">0</h2>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <!-- Pass/Fail Donut -->
                <div class="col-lg-4">
                    <div class="analytics-card d-flex flex-column" style="height: 100%;">
                        <h5>Pass / Fail Ratio</h5>
                        <div class="text-center mb-3">
                            <span class="badge bg-success bg-opacity-10 text-success border border-success me-2 px-3 py-2" style="font-size: 0.85rem;" id="badge-pass"><i class="fas fa-check-circle me-1"></i>0 Passed</span>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2" style="font-size: 0.85rem;" id="badge-fail"><i class="fas fa-times-circle me-1"></i>0 Failed</span>
                        </div>
                        <div id="passFailChart"
                            style="flex-grow: 1; display: flex; justify-content: center; align-items: center; min-height: 250px;">
                        </div>
                        <div class="mt-auto text-center" style="font-size: 0.85rem; color: #64748b; padding-top: 15px; border-top: 1px dashed var(--prestige-border);">
                            <i class="fas fa-info-circle me-1"></i> Pass criteria: Minimum 1.0 GPA
                        </div>
                    </div>
                </div>

                <!-- Class Performance Comparison -->
                <div class="col-lg-8">
                    <div class="analytics-card">
                        <h5>Performance Comparison (Average GPA)</h5>
                        <div id="classPerformanceChart" style="min-height: 400px;"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        let passFailChart, classChart;

        function initCharts() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? 'rgba(255,255,255,0.05)' : '#e2e8f0';

            // Options for Pass/Fail Ratio (Donut Chart)
            const passFailOptions = {
                series: [],
                chart: { 
                    type: 'donut', 
                    width: '100%', 
                    fontFamily: 'Inter, sans-serif',
                    background: 'transparent',
                    foreColor: textColor,
                    animations: { enabled: true, speed: 800 }
                },
                labels: ['Passed', 'Failed'],
                colors: ['#10ac84', '#e74a3b'],
                stroke: { show: false },
                plotOptions: {
                    pie: {
                        donut: { 
                            size: '70%', 
                            labels: { 
                                show: true, 
                                name: { show: true, color: textColor }, 
                                value: { show: true, color: isDark ? '#e2e8f0' : '#0f172a' },
                                total: { 
                                    show: true, 
                                    label: 'Total', 
                                    color: textColor,
                                    formatter: function (w) {
                                        return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                    }
                                }
                            } 
                        }
                    }
                },
                legend: { 
                    position: 'bottom',
                    labels: { colors: textColor }
                },
                theme: { mode: isDark ? 'dark' : 'light' }
            };
            if(passFailChart) passFailChart.destroy();
            passFailChart = new ApexCharts(document.querySelector("#passFailChart"), passFailOptions);
            passFailChart.render();

            // Options for Class Performance (Bar Chart)
            const classOptions = {
                series: [{ name: 'Average GPA', data: [] }],
                chart: { 
                    type: 'bar', 
                    height: 400, 
                    toolbar: { show: false }, 
                    fontFamily: 'Inter, sans-serif',
                    background: 'transparent',
                    foreColor: textColor,
                    theme: isDark ? 'dark' : 'light'
                },
                colors: ['#b45309'],
                plotOptions: {
                    bar: { 
                        borderRadius: 6, 
                        columnWidth: '40%', 
                        distributed: true,
                        dataLabels: { position: 'top' }
                    }
                },
                dataLabels: { 
                    enabled: true, 
                    offsetY: -20,
                    style: { fontSize: '12px', colors: [isDark ? '#e2e8f0' : '#0f172a'] },
                    formatter: function (val) { return val.toFixed(2); } 
                },
                xaxis: { 
                    categories: [],
                    labels: { style: { colors: textColor } },
                    axisBorder: { show: false },
                    axisTicks: { show: false }
                },
                yaxis: {
                    labels: { style: { colors: textColor } },
                    min: 0,
                    max: 5
                },
                grid: {
                    borderColor: gridColor,
                    strokeDashArray: 4,
                    yaxis: { lines: { show: true } }
                },
                legend: { show: false },
                theme: { mode: isDark ? 'dark' : 'light' }
            };
            if(classChart) classChart.destroy();
            classChart = new ApexCharts(document.querySelector("#classPerformanceChart"), classOptions);
            classChart.render();
        }

        const allData = <?php echo json_encode($all_classes); ?>;

        function updateClasses() {
            const year = document.getElementById('yearFilter').value;
            const classSelect = document.getElementById('classFilter');
            const retainedClass = classSelect.value;

            const filteredClasses = [...new Set(allData
                .filter(c => c.academic_year == year)
                .map(c => c.class_name))];

            classSelect.innerHTML = '<option value="all">All Classes</option>';
            filteredClasses.forEach(cls => {
                const opt = document.createElement('option');
                opt.value = cls;
                opt.textContent = cls;
                if (cls === retainedClass) opt.selected = true;
                classSelect.appendChild(opt);
            });
            updateSections();
        }

        function updateSections() {
            const year = document.getElementById('yearFilter').value;
            const className = document.getElementById('classFilter').value;
            const sectionSelect = document.getElementById('sectionFilter');
            const retainedSection = sectionSelect.value;

            if (className === 'all') {
                sectionSelect.innerHTML = '<option value="all">All Sections</option>';
                return;
            }

            const filteredSections = [...new Set(allData
                .filter(c => c.academic_year == year && c.class_name == className)
                .map(c => c.section))];

            sectionSelect.innerHTML = '<option value="all">All Sections</option>';
            filteredSections.forEach(sec => {
                const opt = document.createElement('option');
                opt.value = sec;
                opt.textContent = 'Section ' + sec;
                if (sec === retainedSection) opt.selected = true;
                sectionSelect.appendChild(opt);
            });
        }

        async function loadAnalytics() {
            const year = document.getElementById('yearFilter').value;
            const exam = document.getElementById('examFilter').value;
            const className = document.getElementById('classFilter').value;
            const section = document.getElementById('sectionFilter').value;

            try {
                const response = await fetch(`api/analytics_engine.php?year=${year}&exam=${exam}&class_name=${encodeURIComponent(className)}&section=${encodeURIComponent(section)}`);
                const res = await response.json();

                if (res.status === 'success') {
                    const data = res.data;

                    // Update KPIs
                    document.getElementById('kpi-students').textContent = data.kpis.total_students;
                    document.getElementById('kpi-pass').textContent = data.kpis.pass_rate + '%';
                    document.getElementById('kpi-fail').textContent = data.kpis.fail_count;
                    document.getElementById('kpi-aplus').textContent = data.kpis.aplus_count;


                    // Update Pass/Fail Donut and Badges
                    const pfData = data.pass_fail.map(item => item.value);
                    document.getElementById('badge-pass').innerHTML = `<i class="fas fa-check-circle me-1"></i>${pfData[0]} Passed`;
                    document.getElementById('badge-fail').innerHTML = `<i class="fas fa-times-circle me-1"></i>${pfData[1]} Failed`;

                    if (pfData[0] === 0 && pfData[1] === 0) {
                        passFailChart.updateSeries([0, 0]);
                    } else {
                        passFailChart.updateSeries(pfData);
                    }

                    // Update Class/Section Performance
                    const classCategories = data.class_performance.map(item => item.category_name);
                    const classGpaData = data.class_performance.map(item => parseFloat(item.avg_gpa));
                    classChart.updateSeries([{ name: 'Average GPA', data: classGpaData }]);
                    classChart.updateOptions({ xaxis: { categories: classCategories } });

                } else {
                    console.error("Failed to load analytics data:", res.message);
                }
            } catch (error) {
                console.error("Error fetching analytics:", error);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateClasses();
            initCharts();
            loadAnalytics();

            // Handle live theme switching
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.attributeName === 'data-theme') {
                        initCharts();
                        loadAnalytics();
                    }
                });
            });
            observer.observe(document.documentElement, { attributes: true });
        });
    </script>
</body>

</html>