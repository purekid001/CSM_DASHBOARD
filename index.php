<?php
require_once 'connect.php';

$thaiMonths = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

$yearOptions = [];
$selectedYear = (int) date('Y');
$errorMessage = null;

$summary = [
    'spend_total' => 0.0,
    'production_qty' => 0.0,
    'load_count' => 0,
    'load_order_count' => 0,
    'pr_count' => 0,
    'po_count' => 0,
];

$spendByDepartment = [];
$productionRows = [];
$loadingRows = [];
$prRows = [];
$poRows = [];

function fetchAllRows($conn, $sql, $params = array())
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchOneRow($conn, $sql, $params = array())
{
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

function buildMonthlySeries($rows, $valueKey)
{
    $series = array_fill(1, 12, 0);

    foreach ($rows as $row) {
        $month = isset($row['month_no']) ? (int) $row['month_no'] : 0;
        if ($month >= 1 && $month <= 12) {
            $series[$month] = (float) $row[$valueKey];
        }
    }

    return array_values($series);
}

function formatCurrency($amount)
{
    return number_format($amount, 2);
}

function formatNumber($amount)
{
    return number_format($amount, 0);
}

function formatDisplayYear($year)
{
    return $year . ' / ' . ($year + 543);
}

function formatThaiDateTime($dateTime, $thaiMonths)
{
    $monthNumber = (int) $dateTime->format('n');
    $monthLabel = isset($thaiMonths[$monthNumber]) ? $thaiMonths[$monthNumber] : $dateTime->format('M');

    return $dateTime->format('j') . ' ' . $monthLabel . ' ' . ((int) $dateTime->format('Y') + 543) . ' เวลา ' . $dateTime->format('H:i') . ' น.';
}

if ($conn) {
    try {
        $yearOptions = fetchAllRows(
            $conn,
            "
            SELECT DISTINCT [year_value]
            FROM (
                SELECT YEAR(ipodate) AS [year_value]
                FROM ipoh
                WHERE ipodate IS NOT NULL

                UNION

                SELECT YEAR(prdate) AS [year_value]
                FROM prh
                WHERE prdate IS NOT NULL

                UNION

                SELECT YEAR(auto_gen_date) AS [year_value]
                FROM prd
                WHERE auto_gen_date IS NOT NULL

                UNION

                SELECT YEAR(LoadingDate) AS [year_value]
                FROM BARCODE_vw_LoadingDetail
                WHERE LoadingDate IS NOT NULL
            ) AS years
            WHERE [year_value] BETWEEN 2020 AND 2100
            ORDER BY [year_value] DESC
            "
        );

        if (!empty($_GET['year'])) {
            $requestedYear = (int) $_GET['year'];
            $availableYears = array_map(function ($row) {
                return (int) $row['year_value'];
            }, $yearOptions);
            if (in_array($requestedYear, $availableYears, true)) {
                $selectedYear = $requestedYear;
            }
        } elseif (!empty($yearOptions)) {
            $selectedYear = (int) $yearOptions[0]['year_value'];
        }

        $startDate = sprintf('%04d-01-01', $selectedYear);
        $endDate = sprintf('%04d-01-01', $selectedYear + 1);
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        $spendTotalRow = fetchOneRow(
            $conn,
            "
            SELECT COALESCE(SUM(COALESCE(doctotamt, 0)), 0) AS spend_total
            FROM ipoh
            WHERE ipodate >= :start_date AND ipodate < :end_date
            ",
            $params
        );
        $summary['spend_total'] = isset($spendTotalRow['spend_total']) ? (float) $spendTotalRow['spend_total'] : 0;

        $productionQtyRow = fetchOneRow(
            $conn,
            "
            SELECT COALESCE(SUM(COALESCE(pqty, 0)), 0) AS production_qty
            FROM prd
            WHERE auto_gen_date >= :start_date AND auto_gen_date < :end_date
            ",
            $params
        );
        $summary['production_qty'] = isset($productionQtyRow['production_qty']) ? (float) $productionQtyRow['production_qty'] : 0;

        $loadingSummary = fetchOneRow(
            $conn,
            "
            SELECT
                COUNT(DISTINCT LoadingNo) AS load_count,
                COUNT(DISTINCT SaleOrderNo) AS load_order_count
            FROM BARCODE_vw_LoadingDetail
            WHERE LoadingDate >= :start_date AND LoadingDate < :end_date
            ",
            $params
        );
        $summary['load_count'] = isset($loadingSummary['load_count']) ? (int) $loadingSummary['load_count'] : 0;
        $summary['load_order_count'] = isset($loadingSummary['load_order_count']) ? (int) $loadingSummary['load_order_count'] : 0;

        $prCountRow = fetchOneRow(
            $conn,
            "
            SELECT COUNT(*) AS pr_count
            FROM prh
            WHERE prdate >= :start_date AND prdate < :end_date
            ",
            $params
        );
        $summary['pr_count'] = isset($prCountRow['pr_count']) ? (int) $prCountRow['pr_count'] : 0;

        $poCountRow = fetchOneRow(
            $conn,
            "
            SELECT COUNT(*) AS po_count
            FROM ipoh
            WHERE ipodate >= :start_date AND ipodate < :end_date
            ",
            $params
        );
        $summary['po_count'] = isset($poCountRow['po_count']) ? (int) $poCountRow['po_count'] : 0;

        $spendByDepartment = fetchAllRows(
            $conn,
            "
            SELECT TOP 10
                i.depcd,
                COALESCE(NULLIF(c.depnm, ''), i.depcd) AS department_name,
                SUM(COALESCE(i.doctotamt, 0)) AS total_amount,
                COUNT(*) AS po_count
            FROM ipoh AS i
            LEFT JOIN cdp AS c ON c.depcd = i.depcd
            WHERE i.ipodate >= :start_date
              AND i.ipodate < :end_date
              AND i.depcd IS NOT NULL
              AND LTRIM(RTRIM(i.depcd)) <> ''
            GROUP BY i.depcd, COALESCE(NULLIF(c.depnm, ''), i.depcd)
            ORDER BY total_amount DESC
            ",
            $params
        );

        $productionRows = fetchAllRows(
            $conn,
            "
            SELECT
                MONTH(auto_gen_date) AS month_no,
                SUM(COALESCE(pqty, 0)) AS total_qty,
                COUNT(*) AS order_count
            FROM prd
            WHERE auto_gen_date >= :start_date
              AND auto_gen_date < :end_date
            GROUP BY MONTH(auto_gen_date)
            ORDER BY month_no
            ",
            $params
        );

        $loadingRows = fetchAllRows(
            $conn,
            "
            SELECT
                MONTH(LoadingDate) AS month_no,
                COUNT(DISTINCT LoadingNo) AS load_count,
                COUNT(DISTINCT SaleOrderNo) AS order_count
            FROM BARCODE_vw_LoadingDetail
            WHERE LoadingDate >= :start_date
              AND LoadingDate < :end_date
            GROUP BY MONTH(LoadingDate)
            ORDER BY month_no
            ",
            $params
        );

        $prRows = fetchAllRows(
            $conn,
            "
            SELECT
                MONTH(prdate) AS month_no,
                COUNT(*) AS doc_count
            FROM prh
            WHERE prdate >= :start_date
              AND prdate < :end_date
            GROUP BY MONTH(prdate)
            ORDER BY month_no
            ",
            $params
        );

        $poRows = fetchAllRows(
            $conn,
            "
            SELECT
                MONTH(ipodate) AS month_no,
                COUNT(*) AS doc_count
            FROM ipoh
            WHERE ipodate >= :start_date
              AND ipodate < :end_date
            GROUP BY MONTH(ipodate)
            ORDER BY month_no
            ",
            $params
        );
    } catch (PDOException $e) {
        error_log('Dashboard query failed: ' . $e->getMessage());
        $errorMessage = 'ไม่สามารถดึงข้อมูล dashboard ได้ในขณะนี้';
    }
} else {
    $errorMessage = $dbError;
}

$monthLabels = array_values($thaiMonths);
$spendLabels = [];
$spendValues = [];
$spendCounts = [];

foreach ($spendByDepartment as $row) {
    $label = trim((string) $row['depcd']) . ' - ' . trim((string) $row['department_name']);
    $spendLabels[] = $label;
    $spendValues[] = round((float) $row['total_amount'], 2);
    $spendCounts[] = (int) $row['po_count'];
}

$productionQtySeries = buildMonthlySeries($productionRows, 'total_qty');
$productionOrderSeries = buildMonthlySeries($productionRows, 'order_count');
$loadingCountSeries = buildMonthlySeries($loadingRows, 'load_count');
$loadingOrderSeries = buildMonthlySeries($loadingRows, 'order_count');
$prSeries = buildMonthlySeries($prRows, 'doc_count');
$poSeries = buildMonthlySeries($poRows, 'doc_count');

$chartPayload = [
    'monthLabels' => $monthLabels,
    'spendLabels' => $spendLabels,
    'spendValues' => $spendValues,
    'spendCounts' => $spendCounts,
    'productionQtySeries' => $productionQtySeries,
    'productionOrderSeries' => $productionOrderSeries,
    'loadingCountSeries' => $loadingCountSeries,
    'loadingOrderSeries' => $loadingOrderSeries,
    'prSeries' => $prSeries,
    'poSeries' => $poSeries,
];

$spendTotalValue = isset($summary['spend_total']) ? (float) $summary['spend_total'] : 0;
$productionQtyValue = isset($summary['production_qty']) ? (float) $summary['production_qty'] : 0;
$loadCountValue = isset($summary['load_count']) ? (float) $summary['load_count'] : 0;
$loadOrderCountValue = isset($summary['load_order_count']) ? (float) $summary['load_order_count'] : 0;
$prCountValue = isset($summary['pr_count']) ? (float) $summary['pr_count'] : 0;
$poCountValue = isset($summary['po_count']) ? (float) $summary['po_count'] : 0;
$generatedAt = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$generatedAtLabel = formatThaiDateTime($generatedAt, $thaiMonths);
$retryUrl = '?year=' . rawurlencode((string) $selectedYear);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="shortcut icon" href="assets/img/favicon.png">
    <link rel="apple-touch-icon" href="assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <title>MFOOD Dashboard</title>
    <script>
        (function () {
            var storageKey = 'mfoodTheme';
            var availableThemes = ['sky', 'executive', 'midnight'];
            var savedTheme = null;
            var cookieMatch = document.cookie.match(/(?:^|; )mfoodTheme=([^;]+)/);

            if (cookieMatch && cookieMatch[1]) {
                savedTheme = decodeURIComponent(cookieMatch[1]);
            }

            try {
                savedTheme = window.localStorage.getItem(storageKey) || savedTheme;
            } catch (error) {
                // Keep the cookie fallback when localStorage is not available.
            }

            if (availableThemes.indexOf(savedTheme) === -1) {
                savedTheme = 'sky';
            }

            document.documentElement.setAttribute('data-theme', savedTheme);
        }());
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <main class="shell" id="dashboardShell">
        <header class="dashboard-header" aria-labelledby="pageTitle">
            <div class="toolbar">
                <div class="toolbar-copy">
                    <div class="brand-lockup">
                        <img class="brand-logo" src="assets/img/CSM_New_logo1.png" alt="Chocksamut logo">
                        <span class="eyebrow">MFOOD Dashboard</span>
                    </div>
                    <h1 id="pageTitle">ภาพรวมธุรกิจปี <?php echo htmlspecialchars(formatDisplayYear($selectedYear), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>สรุปข้อมูลการใช้จ่าย การผลิต การโหลดสินค้าออก และเอกสารจัดซื้อของปีที่เลือกในหน้าเดียว เพื่อให้ดูแนวโน้มและเช็กจุดผิดปกติได้เร็วขึ้น</p>
                    <div class="dashboard-meta">
                        <p class="data-stamp" id="dashboardTimestamp">ข้อมูลล่าสุด ณ <?php echo htmlspecialchars($generatedAtLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="theme-form theme-form-secondary">
                            <label for="themeSelector">มุมมองสี</label>
                            <select id="themeSelector" aria-label="เลือกมุมมองสีของ dashboard">
                                <option value="sky">มาตรฐาน</option>
                                <option value="executive">ผู้บริหาร</option>
                                <option value="midnight">กลางคืน</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="toolbar-actions">
                    <form class="year-form" id="yearFilterForm" method="get" aria-label="ตัวกรองปีของ dashboard" aria-describedby="dashboardTimestamp">
                        <label for="year">เลือกปี</label>
                        <select name="year" id="year">
                            <?php foreach ($yearOptions as $yearRow): ?>
                                <?php $yearValue = (int) $yearRow['year_value']; ?>
                                <option value="<?php echo $yearValue; ?>" <?php echo $yearValue === $selectedYear ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(formatDisplayYear($yearValue), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" id="yearSubmitButton">อัปเดตข้อมูล</button>
                    </form>
                </div>
            </div>

            <section class="summary-strip" aria-label="สรุปตัวชี้วัดหลัก">
                <div class="summary-card">
                    <div class="summary-label">ยอดใช้จ่ายรวม</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="currency" data-counter-value="<?php echo htmlspecialchars((string) $spendTotalValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatCurrency($spendTotalValue); ?>
                        </span>
                    </div>
                    <div class="summary-note">รวมยอดจากเอกสาร PO ของปีที่เลือก</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">ปริมาณการผลิต</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="number" data-counter-value="<?php echo htmlspecialchars((string) $productionQtyValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatNumber($productionQtyValue); ?>
                        </span>
                    </div>
                    <div class="summary-note">รวมปริมาณผลิตจากข้อมูลการผลิตของปีที่เลือก</div>
                </div>
                <div class="summary-card summary-card-detailed">
                    <div class="summary-label">การโหลดสินค้าออก</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="number" data-counter-value="<?php echo htmlspecialchars((string) $loadCountValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatNumber($loadCountValue); ?>
                        </span>
                    </div>
                    <div class="summary-note">จำนวนงานโหลดจาก Loading No. ในปีที่เลือก</div>
                    <div class="summary-detail-grid">
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">Sale Order ที่โหลด</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="number" data-counter-value="<?php echo htmlspecialchars((string) $loadOrderCountValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatNumber($loadOrderCountValue); ?>
                                </span>
                                รายการ
                            </span>
                        </div>
                    </div>
                </div>
                <div class="summary-card summary-card-detailed">
                    <div class="summary-label">เอกสารจัดซื้อ</div>
                    <div class="summary-detail-grid summary-detail-grid-two-up">
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">PR</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="number" data-counter-value="<?php echo htmlspecialchars((string) $prCountValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatNumber($prCountValue); ?>
                                </span>
                                รายการ
                            </span>
                        </div>
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">PO</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="number" data-counter-value="<?php echo htmlspecialchars((string) $poCountValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatNumber($poCountValue); ?>
                                </span>
                                รายการ
                            </span>
                        </div>
                    </div>
                    <div class="summary-note">เอกสารที่เปิดในปี <?php echo htmlspecialchars((string) $selectedYear, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </section>
        </header>

        <?php if ($errorMessage !== null): ?>
            <div class="status-box status-box-spaced" role="status" aria-live="polite">
                <strong class="status-box-title">ยังไม่สามารถอัปเดต dashboard ได้</strong>
                <p class="status-box-copy"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?> กรุณาลองโหลดข้อมูลอีกครั้ง หรือตรวจสอบการเชื่อมต่อฐานข้อมูล</p>
                <div class="status-box-actions">
                    <a class="secondary-action" href="<?php echo htmlspecialchars($retryUrl, ENT_QUOTES, 'UTF-8'); ?>">ลองโหลดอีกครั้ง</a>
                </div>
            </div>
        <?php else: ?>
            <section class="dashboard">
                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="spendChartTitle">การใช้จ่ายรายปีแยกตามแผนก</h2>
                            <p class="panel-desc">แสดง 10 แผนกที่มียอดใช้จ่ายสูงสุดจากข้อมูล PO เพื่อช่วยมองเห็นต้นทุนหลักของปีนี้</p>
                        </div>
                        <div class="chip">ใช้จ่าย</div>
                    </div>
                    <div class="split">
                        <div class="chart-wrap tall">
                            <canvas id="spendChart" role="img" aria-labelledby="spendChartTitle" aria-describedby="spendChartDesc spendChartData"></canvas>
                        </div>
                        <p class="sr-only" id="spendChartDesc">กราฟแท่งแนวนอนแสดงยอดใช้จ่ายสะสมของ 10 แผนกที่มียอดสูงที่สุดในปีที่เลือก โดยเรียงจากมากไปน้อย</p>
                        <ul class="sr-only" id="spendChartData">
                            <?php if (!empty($spendByDepartment)): ?>
                                <?php foreach ($spendByDepartment as $row): ?>
                                    <li><?php echo htmlspecialchars((string) $row['depcd'] . ' - ' . (string) $row['department_name'] . ' ยอดใช้จ่าย ' . formatCurrency((float) $row['total_amount']) . ' บาท และจำนวน PO ' . formatNumber((float) $row['po_count']) . ' รายการ', ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li>ไม่พบข้อมูลการใช้จ่ายแยกตามแผนกในปีที่เลือก</li>
                            <?php endif; ?>
                        </ul>
                        <div class="ranking">
                            <?php if (!empty($spendByDepartment)): ?>
                                <?php foreach (array_slice($spendByDepartment, 0, 5) as $row): ?>
                                    <div class="ranking-item">
                                        <strong><?php echo htmlspecialchars((string) $row['depcd'] . ' - ' . (string) $row['department_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span>ยอดใช้จ่าย <?php echo formatCurrency((float) $row['total_amount']); ?> บาท</span><br>
                                        <span>จำนวน PO <?php echo formatNumber((float) $row['po_count']); ?> รายการ</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="ranking-item">
                                    <strong>ยังไม่มีข้อมูลแผนก</strong>
                                    <span>ไม่พบรายการใช้จ่ายในปีที่เลือก</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>แผนก</th>
                                        <th>ยอดใช้จ่าย</th>
                                        <th>จำนวน PO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($spendByDepartment)): ?>
                                        <?php foreach ($spendByDepartment as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['depcd'] . ' - ' . (string) $row['department_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatCurrency((float) $row['total_amount']); ?></td>
                                                <td><?php echo formatNumber((float) $row['po_count']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">ไม่พบข้อมูลในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-wide">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="productionChartTitle">การผลิตรายเดือน</h2>
                            <p class="panel-desc">เปรียบเทียบปริมาณผลิตรวมกับจำนวนใบงานที่ถูกสร้างในแต่ละเดือนจากข้อมูล `prd.auto_gen_date`</p>
                        </div>
                        <div class="chip">การผลิต</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="productionChart" role="img" aria-labelledby="productionChartTitle" aria-describedby="productionChartDesc productionChartData"></canvas>
                    </div>
                    <p class="sr-only" id="productionChartDesc">กราฟแสดงปริมาณการผลิตและจำนวนใบงานในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="productionChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' ปริมาณผลิต ' . formatNumber($productionQtySeries[$monthIndex]) . ' และจำนวนใบงาน ' . formatNumber($productionOrderSeries[$monthIndex]), ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>ปริมาณผลิต</th>
                                        <th>จำนวนใบงาน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatNumber($productionQtySeries[$monthIndex]); ?></td>
                                            <td><?php echo formatNumber($productionOrderSeries[$monthIndex]); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-side">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingChartTitle">การโหลดสินค้าออก</h2>
                            <p class="panel-desc">นับจำนวนงานโหลดและจำนวน Sale Order ที่ถูกโหลดออกในแต่ละเดือน เพื่อดูภาระงานขนส่งจากข้อมูล barcode loading</p>
                        </div>
                        <div class="chip">โหลดสินค้า</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="loadingChart" role="img" aria-labelledby="loadingChartTitle" aria-describedby="loadingChartDesc loadingChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingChartDesc">กราฟแสดงจำนวนงานโหลดสินค้าออกและจำนวน Sale Order ที่ถูกโหลดในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' งานโหลด ' . formatNumber($loadingCountSeries[$monthIndex]) . ' และ Sale Order ที่โหลด ' . formatNumber($loadingOrderSeries[$monthIndex]), ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>จำนวนงานโหลด</th>
                                        <th>Sale Order ที่โหลด</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatNumber($loadingCountSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatNumber($loadingOrderSeries[$monthIndex]); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="procurementChartTitle">จำนวน PR / PO ต่อเดือน</h2>
                            <p class="panel-desc">ติดตามความเคลื่อนไหวของเอกสารจัดซื้อรายเดือน เพื่อดูปริมาณงานก่อนเข้าอนุมัติและแปลงเป็น PO</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="procurementChart" role="img" aria-labelledby="procurementChartTitle" aria-describedby="procurementChartDesc procurementChartData"></canvas>
                    </div>
                    <p class="sr-only" id="procurementChartDesc">กราฟแท่งเปรียบเทียบจำนวนเอกสาร PR และ PO ในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="procurementChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' PR ' . formatNumber($prSeries[$monthIndex]) . ' และ PO ' . formatNumber($poSeries[$monthIndex]), ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>PR</th>
                                        <th>PO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatNumber($prSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatNumber($poSeries[$monthIndex]); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
            </section>
        <?php endif; ?>

        <div class="footer-note">ข้อมูลดึงจากฐาน M_FOOD ตามปีที่เลือก และอัปเดตเมื่อผู้ใช้เปลี่ยนปีหรือกดอัปเดตข้อมูล</div>
    </main>

    <script>
        const dashboardData = <?php echo json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
            loadingBarEnd: readThemeValue('--chart-loading-bar-end', 'rgba(14, 165, 233, 0.52)'),
            prStart: readThemeValue('--chart-pr-start', 'rgba(99, 102, 241, 0.96)'),
            prEnd: readThemeValue('--chart-pr-end', 'rgba(129, 140, 248, 0.58)'),
            poStart: readThemeValue('--chart-po-start', 'rgba(14, 165, 233, 0.96)'),
            poEnd: readThemeValue('--chart-po-end', 'rgba(56, 189, 248, 0.56)')
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
        Chart.defaults.animation = sharedAnimation;
        Chart.defaults.interaction = sharedInteraction;

        function formatCounterValue(value, format) {
            if (format === 'currency') {
                return new Intl.NumberFormat('th-TH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
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
                registerChart(new Chart(spendCanvas, {
                    type: 'bar',
                    data: {
                        labels: dashboardData.spendLabels,
                        datasets: [{
                            label: 'ยอดใช้จ่าย (บาท)',
                            data: dashboardData.spendValues,
                            borderRadius: 12,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: dashboardData.spendValues.map((value, index) => {
                                return palette.departmental[index] || spendGradient;
                            }),
                            hoverBackgroundColor: spendGradient
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        maintainAspectRatio: false,
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
                                    label: (context) => ` ${formatCurrency(context.raw)} บาท`
                                }
                            },
                            legend: {
                                display: false
                            }
                        }
                    }
                }));
            }

            const productionCanvas = document.getElementById('productionChart');
            if (productionCanvas) {
                const productionBarGradient = createVerticalGradient(productionCanvas, chartGradients.productionBarStart, chartGradients.productionBarEnd);
                const productionLineGradient = createVerticalGradient(productionCanvas, chartGradients.productionLineStart, chartGradients.productionLineEnd);
                registerChart(new Chart(productionCanvas, {
                    data: {
                        labels: dashboardData.monthLabels,
                        datasets: [{
                            type: 'bar',
                            label: 'ปริมาณผลิต',
                            data: dashboardData.productionQtySeries,
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: productionBarGradient,
                            yAxisID: 'y'
                        }, {
                            type: 'line',
                            label: 'จำนวนใบงาน',
                            data: dashboardData.productionOrderSeries,
                            borderColor: palette.primaryDeep,
                            backgroundColor: productionLineGradient,
                            pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                            pointBorderColor: palette.primaryDeep,
                            pointBorderWidth: 2,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: palette.primaryDeep,
                            fill: true,
                            borderWidth: 3,
                            tension: 0.42,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: sharedLegend,
                            tooltip: sharedTooltip
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: defaultGrid,
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value) => formatCurrency(value)
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: {
                                    display: false
                                },
                                ticks: defaultTicks
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

            const loadingCanvas = document.getElementById('loadingChart');
            if (loadingCanvas) {
                const loadingLineGradient = createVerticalGradient(loadingCanvas, chartGradients.loadingLineStart, chartGradients.loadingLineEnd);
                const loadingBarGradient = createVerticalGradient(loadingCanvas, chartGradients.loadingBarStart, chartGradients.loadingBarEnd);
                registerChart(new Chart(loadingCanvas, {
                    data: {
                        labels: dashboardData.monthLabels,
                        datasets: [{
                            type: 'line',
                            label: 'จำนวนงานโหลด',
                            data: dashboardData.loadingCountSeries,
                            borderColor: palette.primary,
                            backgroundColor: loadingLineGradient,
                            pointBackgroundColor: readThemeValue('--chart-point-fill', '#ffffff'),
                            pointBorderColor: palette.primary,
                            pointBorderWidth: 2,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                            fill: true,
                            borderWidth: 3,
                            tension: 0.42,
                            yAxisID: 'y'
                        }, {
                            type: 'bar',
                            label: 'Sale Order ที่โหลด',
                            data: dashboardData.loadingOrderSeries,
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: loadingBarGradient,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: sharedLegend,
                            tooltip: sharedTooltip
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: defaultGrid,
                                ticks: defaultTicks
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    ...defaultTicks,
                                    callback: (value) => formatCurrency(value)
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

            const procurementCanvas = document.getElementById('procurementChart');
            if (procurementCanvas) {
                const prGradient = createVerticalGradient(procurementCanvas, chartGradients.prStart, chartGradients.prEnd);
                const poGradient = createVerticalGradient(procurementCanvas, chartGradients.poStart, chartGradients.poEnd);
                registerChart(new Chart(procurementCanvas, {
                    type: 'bar',
                    data: {
                        labels: dashboardData.monthLabels,
                        datasets: [{
                            label: 'PR',
                            data: dashboardData.prSeries,
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: prGradient
                        }, {
                            label: 'PO',
                            data: dashboardData.poSeries,
                            borderRadius: 10,
                            borderSkipped: false,
                            maxBarThickness: 28,
                            backgroundColor: poGradient
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: {
                            legend: sharedLegend,
                            tooltip: sharedTooltip
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: defaultGrid,
                                ticks: defaultTicks
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

        setupThemeControls();
        renderCharts();
        setupCounters();
        setupYearTransition();
    </script>
</body>
</html>
