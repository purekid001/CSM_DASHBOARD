<?php
require_once 'connect.php';
require_once 'helpers.php';

$thaiMonths = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

$yearOptions = [];
$selectedYear = (int) date('Y');
$selectedMonth = 0;
$selectedBudgetCode = '';
$selectedBudgetDesc = '';
$selectedProdStart = '';
$selectedProdEnd = '';
$errorMessage = null;

$summary = [
    'spend_total' => 0.0,
    'spend_budget_total' => 0.0,
    'spend_balance_total' => 0.0,
    'production_qty' => 0.0,
    'loading_kg' => 0.0,
    'loading_box' => 0.0,
    'loading_export_kg' => 0.0,
    'loading_domestic_kg' => 0.0,
    'pr_count' => 0,
    'po_count' => 0,
];

$spendByDepartment = [];
$productionRows = [];
$productionDailyRows = [];
$productionDailyProductRows = [];
$productionDailyStartDate = '';
$productionDailyEndDate = '';
$hasCustomProdDates = false;
$loadingRows = [];
$loadingDetailRows = [];
$loadingTopProductRows = [];

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

                SELECT YEAR(plordate) AS [year_value]
                FROM vw_shop_in_out
                WHERE plordate IS NOT NULL
                  AND i_type = 'O'
                  AND plorstat = 'E'
                  AND ittype = 'F'

                UNION

                SELECT YEAR(isidate) AS [year_value]
                FROM isih
                WHERE isidate IS NOT NULL
                  AND drefno = 'WI'
                  AND isitrntype = 'DO'
                  AND isistat = 'E'

                UNION

                SELECT date_year AS [year_value]
                FROM MFOOD_vw_bg_date_list
                WHERE date_year IS NOT NULL

                UNION

                SELECT date_year AS [year_value]
                FROM MFOOD_vw_pr_bg_amount
                WHERE date_year IS NOT NULL

                UNION

                SELECT date_year AS [year_value]
                FROM MFOOD_vw_rv_bg_amount
                WHERE date_year IS NOT NULL
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

        if (isset($_GET['month']) && $_GET['month'] !== '') {
            $requestedMonth = (int) $_GET['month'];
            if ($requestedMonth >= 1 && $requestedMonth <= 12) {
                $selectedMonth = $requestedMonth;
            }
        }

        if (isset($_GET['budgetcd'])) {
            $selectedBudgetCode = trim((string) $_GET['budgetcd']);
        }

        if (isset($_GET['budgetdesc'])) {
            $selectedBudgetDesc = trim((string) $_GET['budgetdesc']);
        }

        if (isset($_GET['prod_start'])) {
            $selectedProdStart = trim((string) $_GET['prod_start']);
        }

        if (isset($_GET['prod_end'])) {
            $selectedProdEnd = trim((string) $_GET['prod_end']);
        }

        $startDate = sprintf('%04d-01-01', $selectedYear);
        $endDate = sprintf('%04d-01-01', $selectedYear + 1);
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];
        $spendParams = [
            ':filter_year' => $selectedYear,
            ':filter_month' => $selectedMonth,
            ':filter_month_match' => $selectedMonth,
            ':filter_budgetcd' => $selectedBudgetCode,
            ':filter_budgetcd_match' => $selectedBudgetCode,
            ':filter_budgetdesc' => $selectedBudgetDesc,
            ':filter_budgetdesc_like' => $selectedBudgetDesc === '' ? '' : '%' . $selectedBudgetDesc . '%',
        ];
        $spendCte = "
            WITH spend_src AS (
                SELECT
                    depcd,
                    date_year,
                    date_month,
                    budgetcd,
                    ISNULL(bg_amount, 0) AS bg_amount,
                    ISNULL(rv_amount, 0) AS rv_amount,
                    ISNULL(ug_amount, 0) AS ug_amount,
                    0 AS pr_amount,
                    0 AS rcv_amount
                FROM MFOOD_vw_bg_date_list

                UNION ALL

                SELECT
                    depcd,
                    date_year,
                    date_month,
                    budgetcd,
                    0 AS bg_amount,
                    0 AS rv_amount,
                    0 AS ug_amount,
                    ISNULL(pr_amount, 0) AS pr_amount,
                    0 AS rcv_amount
                FROM MFOOD_vw_pr_bg_amount

                UNION ALL

                SELECT
                    depcd,
                    date_year,
                    date_month,
                    budgetcd,
                    0 AS bg_amount,
                    0 AS rv_amount,
                    0 AS ug_amount,
                    0 AS pr_amount,
                    ISNULL(rv_amount, 0) AS rcv_amount
                FROM MFOOD_vw_rv_bg_amount
            )
        ";
        $productionSourceCte = "
            WITH production_source AS (
                SELECT DISTINCT
                    v_shop.plorno,
                    CAST(v_shop.plordate AS date) AS plordate,
                    v_shop.wwrkc,
                    v_shop.wdesc,
                    v_shop.iprod,
                    v_shop.ilot,
                    v_shop.drefno,
                    COALESCE(v_shop.iqty, 0) AS iqty,
                    ISNULL(v_shop.iioq, 1) AS iioq,
                    COALESCE(v_shop.iqty, 0) * ISNULL(v_shop.iioq, 1) AS total_kg,
                    v_shop.idesc1,
                    N'กก.' AS unnm
                FROM vw_shop_in_out AS v_shop
                LEFT JOIN (
                    SELECT
                        plor.plordate,
                        assinp.iprod,
                        assinp.ilot,
                        assinp.drefno,
                        SUM(assinp.iqty) AS iqty
                    FROM plor
                    INNER JOIN assinp
                        ON plor.plorno = assinp.plorno
                       AND plor.plorstat = 'E'
                    GROUP BY plor.plordate, assinp.iprod, assinp.ilot, assinp.drefno
                ) AS assinp
                    ON v_shop.plordate = assinp.plordate
                   AND v_shop.iprod = assinp.iprod
                   AND v_shop.ilot = assinp.ilot
                   AND v_shop.drefno = assinp.drefno
                   AND v_shop.i_type = 'O'
                   AND v_shop.ittype = 'S'
                LEFT JOIN iim
                    ON v_shop.iprod = iim.iprod
                LEFT JOIN unit
                    ON iim.uncd = unit.uncd
                LEFT JOIN unit AS unit_ass
                    ON iim.ass_uncd = unit_ass.uncd
                LEFT JOIN (
                    SELECT
                        ipod.iprod,
                        ipod.ilot,
                        ipod.k08,
                        ipod.k09,
                        ipod.k11,
                        ipod.k13
                    FROM ipod
                    INNER JOIN ipoh
                        ON ipod.ipono = ipoh.ipono
                       AND ipoh.ipostat = 'E'
                       AND ipoh.ipotrntype NOT IN ('SHR', 'TRN')
                ) AS ipod
                    ON v_shop.iprod = ipod.iprod
                   AND v_shop.ilot = ipod.ilot
                LEFT JOIN (
                    SELECT
                        plor.plordate,
                        assoutp.iprod,
                        assoutp.ilot,
                        assoutp.k02,
                        assoutp.k03,
                        assoutp.k04,
                        assoutp.k05
                    FROM plor
                    INNER JOIN assoutp
                        ON plor.plorno = assoutp.plorno
                       AND plor.plorstat = 'E'
                ) AS assoutp
                    ON v_shop.iprod = assoutp.iprod
                   AND v_shop.ilot = assoutp.ilot
                WHERE v_shop.i_type = 'O'
                  AND v_shop.plorstat = 'E'
                  AND v_shop.ittype = 'F'
                  AND v_shop.plordate >= :start_date
                  AND v_shop.plordate < :end_date
            )
        ";
        $loadingSourceCte = "
            WITH loading_source AS (
                SELECT
                    CONVERT(varchar(10), isih.isidate, 23) AS date_key,
                    isih.isidate,
                    MONTH(isih.isidate) AS month_no,
                    isid.iprod,
                    COALESCE(NULLIF(LTRIM(RTRIM(iim.idesc1)), ''), 'ไม่ระบุสินค้า') AS idesc1,
                    CASE
                        WHEN isih.intrefno LIKE 'DO%' THEN 'export'
                        ELSE 'domestic'
                    END AS load_type,
                    COALESCE(NULLIF(LTRIM(RTRIM(do_cust.custnme)), ''), 'ไม่ระบุลูกค้า') AS do_cust,
                    CASE
                        WHEN COALESCE(ipod.k04, 0) > 0 THEN COALESCE(isid.iqty, 0)
                        WHEN COALESCE(iim.iioq, 0) > 0 THEN COALESCE(isid.iqty, 0) * COALESCE(iim.iioq, 0)
                        ELSE COALESCE(isid.iqty, 0)
                    END AS loaded_kg,
                    CASE
                        WHEN COALESCE(ipod.k04, 0) > 0 THEN COALESCE(ipod.k04, 0)
                        ELSE COALESCE(isid.iqty, 0)
                    END AS box_qty
                FROM isih
                INNER JOIN isid ON isih.isino = isid.isino
                INNER JOIN iim ON iim.iprod = isid.iprod
                INNER JOIN unit ON unit.uncd = iim.uncd
                INNER JOIN ilm ON isid.wloc = ilm.wloc
                LEFT JOIN customer AS do_cust ON isih.extcd = do_cust.custcd
                LEFT JOIN iln ON isid.iprod = iln.iprod AND isid.ilot = iln.ilot
                LEFT JOIN supplier ON iln.spprcd = supplier.spprcd
                LEFT JOIN customer ON iln.spprcd = customer.custcd
                LEFT JOIN user_name ON isih.endusr = user_name.uname
                LEFT JOIN (
                    SELECT *
                    FROM (
                        SELECT
                            ipoh.ipotrntype,
                            ipoh.extcd,
                            ipoh.ipoveninv,
                            ipod.iprod,
                            ipod.ilot,
                            ipod.drefno,
                            ipod.pono,
                            ipod.docseq,
                            ipoh.intrefno,
                            ipoh.ipodate,
                            ipod.exprdt,
                            ipod.iqty,
                            ipod.k02,
                            ipod.k03,
                            ipod.k04,
                            ipod.k05,
                            ipod.k06,
                            ipod.k07,
                            ipod.k08,
                            ipod.k09,
                            ipod.k10,
                            ipod.k11,
                            ipod.k12,
                            ipod.k13,
                            ROW_NUMBER() OVER (
                                PARTITION BY ipod.iprod, ipod.ilot
                                ORDER BY ipoh.ipodate DESC, ipoh.ipono DESC, ipod.docseq DESC
                            ) AS rn
                        FROM ipod
                        INNER JOIN ipoh
                            ON ipod.ipono = ipoh.ipono
                           AND ipoh.ipostat = 'E'
                           AND ipoh.ipotrntype NOT IN ('SHR', 'SRT', 'TRN')
                    ) AS ranked_ipod
                    WHERE ranked_ipod.rn = 1
                ) AS ipod
                    ON isid.iprod = ipod.iprod
                   AND isid.ilot = ipod.ilot
                WHERE isih.drefno = 'WI'
                  AND isih.isitrntype = 'DO'
                  AND isih.isistat = 'E'
                  AND isih.isidate >= :start_date
                  AND isih.isidate < :end_date
            )
        ";
        $spendFilterSql = "
            WHERE s.date_year = :filter_year
              AND (:filter_month = 0 OR s.date_month = :filter_month_match)
              AND (:filter_budgetcd = '' OR s.budgetcd = :filter_budgetcd_match)
              AND (:filter_budgetdesc = '' OR b.budgetdesc LIKE :filter_budgetdesc_like)
        ";

        $spendTotalRow = fetchOneRow(
            $conn,
            $spendCte . "
            SELECT
                COALESCE(SUM(s.rcv_amount), 0) AS spend_total,
                COALESCE(SUM(s.bg_amount - s.rv_amount + s.ug_amount), 0) AS spend_budget_total,
                COALESCE(SUM(s.bg_amount - s.rv_amount + s.ug_amount - s.rcv_amount), 0) AS spend_balance_total
            FROM spend_src AS s
            LEFT JOIN budget_mas AS b ON b.budgetcd = s.budgetcd
            " . $spendFilterSql,
            $spendParams
        );
        $summary['spend_total'] = isset($spendTotalRow['spend_total']) ? (float) $spendTotalRow['spend_total'] : 0;
        $summary['spend_budget_total'] = isset($spendTotalRow['spend_budget_total']) ? (float) $spendTotalRow['spend_budget_total'] : 0;
        $summary['spend_balance_total'] = isset($spendTotalRow['spend_balance_total']) ? (float) $spendTotalRow['spend_balance_total'] : 0;

        $loadingSummary = fetchOneRow(
            $conn,
            $loadingSourceCte . "
            SELECT
                COALESCE(SUM(loaded_kg), 0) AS loading_kg,
                COALESCE(SUM(box_qty), 0) AS loading_box,
                COALESCE(SUM(CASE WHEN load_type = 'export' THEN loaded_kg ELSE 0 END), 0) AS loading_export_kg,
                COALESCE(SUM(CASE WHEN load_type = 'domestic' THEN loaded_kg ELSE 0 END), 0) AS loading_domestic_kg
            FROM loading_source
            ",
            $params
        );
        $summary['loading_kg'] = isset($loadingSummary['loading_kg']) ? (float) $loadingSummary['loading_kg'] : 0;
        $summary['loading_box'] = isset($loadingSummary['loading_box']) ? (float) $loadingSummary['loading_box'] : 0;
        $summary['loading_export_kg'] = isset($loadingSummary['loading_export_kg']) ? (float) $loadingSummary['loading_export_kg'] : 0;
        $summary['loading_domestic_kg'] = isset($loadingSummary['loading_domestic_kg']) ? (float) $loadingSummary['loading_domestic_kg'] : 0;

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
            $spendCte . "
            SELECT
                s.depcd,
                COALESCE(NULLIF(c.divnm, ''), s.depcd) AS department_name,
                SUM(s.bg_amount - s.rv_amount + s.ug_amount) AS net_budget_amount,
                SUM(s.rcv_amount) AS total_amount,
                SUM(s.bg_amount - s.rv_amount + s.ug_amount - s.rcv_amount) AS balance_amount
            FROM spend_src AS s
            LEFT JOIN cdv AS c ON c.divcd = s.depcd
            LEFT JOIN budget_mas AS b ON b.budgetcd = s.budgetcd
            " . $spendFilterSql . "
              AND s.depcd IS NOT NULL
              AND LTRIM(RTRIM(s.depcd)) <> ''
            GROUP BY s.depcd, COALESCE(NULLIF(c.divnm, ''), s.depcd)
            ORDER BY total_amount DESC, net_budget_amount DESC
            ",
            $spendParams
        );

        $productionRows = fetchAllRows(
            $conn,
            $productionSourceCte . "
            SELECT
                MONTH(plordate) AS month_no,
                SUM(total_kg) AS total_kg,
                COUNT(DISTINCT plorno) AS order_count
            FROM production_source
            GROUP BY MONTH(plordate)
            ORDER BY month_no
            ",
            $params
        );
        $summary['production_qty'] = 0;
        foreach ($productionRows as $productionRow) {
            $summary['production_qty'] += (float) (isset($productionRow['total_kg']) ? $productionRow['total_kg'] : 0);
        }

        $hasCustomProdDates = false;
        if ($selectedProdStart !== '' && $selectedProdEnd !== '') {
            $customStart = DateTime::createFromFormat('Y-m-d', $selectedProdStart);
            $customEnd = DateTime::createFromFormat('Y-m-d', $selectedProdEnd);
            if ($customStart && $customStart->format('Y-m-d') === $selectedProdStart &&
                $customEnd && $customEnd->format('Y-m-d') === $selectedProdEnd) {
                $productionDailyStartDate = $selectedProdStart;
                $productionDailyEndDate = $selectedProdEnd;
                $hasCustomProdDates = true;
            }
        }

        if (!$hasCustomProdDates) {
            $productionLatestDateRow = fetchOneRow(
                $conn,
                $productionSourceCte . "
                SELECT MAX(plordate) AS latest_date
                FROM production_source
                ",
                $params
            );
            $productionLatestDate = isset($productionLatestDateRow['latest_date'])
                ? trim((string) $productionLatestDateRow['latest_date'])
                : '';

            if ($productionLatestDate !== '') {
                $latestProductionDateTime = new DateTime($productionLatestDate);
                $productionStartDateTime = clone $latestProductionDateTime;
                $productionStartDateTime->modify('-6 days');
                $productionDailyStartDate = $productionStartDateTime->format('Y-m-d');
                $productionDailyEndDate = $latestProductionDateTime->format('Y-m-d');
            }
        }

        if ($productionDailyStartDate !== '' && $productionDailyEndDate !== '') {
            $productionDailyParams = [
                ':start_date' => $productionDailyStartDate,
                ':end_date' => (new DateTime($productionDailyEndDate))->modify('+1 day')->format('Y-m-d'),
                ':production_start_date' => $productionDailyStartDate,
                ':production_end_date' => $productionDailyEndDate,
            ];
            $productionDailyRows = fetchAllRows(
                $conn,
                $productionSourceCte . "
                SELECT
                    CONVERT(varchar(10), plordate, 23) AS date_key,
                    plordate,
                    DAY(plordate) AS day_no,
                    COALESCE(NULLIF(LTRIM(RTRIM(wdesc)), ''), 'ไม่ระบุไลน์ผลิต') AS wdesc,
                    COALESCE(NULLIF(LTRIM(RTRIM(idesc1)), ''), 'ไม่ระบุสินค้า') AS idesc1,
                    COALESCE(NULLIF(LTRIM(RTRIM(unnm)), ''), '') AS unnm,
                    SUM(total_kg) AS total_kg
                FROM production_source
                WHERE plordate >= :production_start_date
                  AND plordate <= :production_end_date
                GROUP BY
                    CONVERT(varchar(10), plordate, 23),
                    plordate,
                    DAY(plordate),
                    COALESCE(NULLIF(LTRIM(RTRIM(wdesc)), ''), 'ไม่ระบุไลน์ผลิต'),
                    COALESCE(NULLIF(LTRIM(RTRIM(idesc1)), ''), 'ไม่ระบุสินค้า'),
                    COALESCE(NULLIF(LTRIM(RTRIM(unnm)), ''), '')
                ORDER BY plordate, total_kg DESC, wdesc, idesc1
                ",
                $productionDailyParams
            );
        }

        $loadingRows = fetchAllRows(
            $conn,
            $loadingSourceCte . "
            SELECT
                load_type,
                month_no,
                SUM(loaded_kg) AS total_kg,
                SUM(box_qty) AS total_box
            FROM loading_source
            GROUP BY load_type, month_no
            ORDER BY load_type, month_no
            ",
            $params
        );

        $loadingDetailRows = fetchAllRows(
            $conn,
            $loadingSourceCte . "
            SELECT
                load_type,
                date_key,
                do_cust,
                SUM(loaded_kg) AS total_kg,
                SUM(box_qty) AS total_box
            FROM loading_source
            GROUP BY load_type, date_key, do_cust
            ORDER BY load_type, date_key DESC, total_kg DESC, do_cust
            ",
            $params
        );

        $loadingTopProductRows = fetchAllRows(
            $conn,
            $loadingSourceCte . "
            SELECT
                load_type,
                iprod,
                idesc1,
                SUM(loaded_kg) AS total_kg,
                SUM(box_qty) AS total_box
            FROM loading_source
            GROUP BY load_type, iprod, idesc1
            ORDER BY load_type, total_kg DESC, total_box DESC, idesc1, iprod
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
$spendBudgetValues = [];
$spendBalanceValues = [];

foreach ($spendByDepartment as $row) {
    $label = trim((string) $row['depcd']) . ' - ' . trim((string) $row['department_name']);
    $spendLabels[] = $label;
    $spendValues[] = round((float) $row['total_amount'], 2);
    $spendBudgetValues[] = round((float) $row['net_budget_amount'], 2);
    $spendBalanceValues[] = round((float) $row['balance_amount'], 2);
}

$productionQtySeries = buildMonthlySeries($productionRows, 'total_kg');
$productionDailyDateKeys = [];
$productionDailyDateLabels = [];
$productionDailyRangeLabel = 'ไม่มีข้อมูล';
$productionDailyEndLabel = 'ไม่มีข้อมูล';

if ($productionDailyStartDate !== '' && $productionDailyEndDate !== '') {
    $rangeStartDate = new DateTime($productionDailyStartDate);
    $rangeEndDate = new DateTime($productionDailyEndDate);
    $cursorDate = clone $rangeStartDate;

    while ($cursorDate <= $rangeEndDate) {
        $dateKey = $cursorDate->format('Y-m-d');
        $productionDailyDateKeys[] = $dateKey;
        $monthNumber = (int) $cursorDate->format('n');
        $monthLabel = isset($thaiMonths[$monthNumber]) ? $thaiMonths[$monthNumber] : $cursorDate->format('M');
        $productionDailyDateLabels[] = $cursorDate->format('j') . ' ' . $monthLabel;
        $cursorDate->modify('+1 day');
    }

    if (!empty($productionDailyDateKeys)) {
        $rangeStartDisplay = new DateTime($productionDailyDateKeys[0]);
        $rangeEndDisplay = new DateTime($productionDailyDateKeys[count($productionDailyDateKeys) - 1]);
        $startMonthNumber = (int) $rangeStartDisplay->format('n');
        $endMonthNumber = (int) $rangeEndDisplay->format('n');
        $startMonthLabel = isset($thaiMonths[$startMonthNumber]) ? $thaiMonths[$startMonthNumber] : $rangeStartDisplay->format('M');
        $endMonthLabel = isset($thaiMonths[$endMonthNumber]) ? $thaiMonths[$endMonthNumber] : $rangeEndDisplay->format('M');
        $productionDailyRangeLabel = $rangeStartDisplay->format('j') . ' ' . $startMonthLabel
            . ' - ' . $rangeEndDisplay->format('j') . ' ' . $endMonthLabel;
        $productionDailyEndLabel = $rangeEndDisplay->format('j') . ' ' . $endMonthLabel;
    }
}

$productionDailyNumDays = count($productionDailyDateKeys);
$productionDailyProductRows = buildProductionDailyProductRows($productionDailyRows);
$productionDailyTotalSeries = buildProductionDailyTotalSeries($productionDailyRows, $productionDailyDateKeys);
$productionDailyHighlights = buildProductionDailyHighlights($productionDailyRows, $productionDailyDateKeys, 3);
$productionLineChart = buildProductionLineChart($productionDailyRows, $productionDailyDateKeys, $productionDailyDateLabels, 0);
$productionProductChart = buildProductionProductChart($productionDailyRows, $productionDailyDateKeys, $productionDailyDateLabels, 10);
$loadingDomesticKgSeries = buildMonthlySeriesByGroup($loadingRows, 'load_type', 'domestic', 'total_kg');
$loadingDomesticBoxSeries = buildMonthlySeriesByGroup($loadingRows, 'load_type', 'domestic', 'total_box');
$loadingExportKgSeries = buildMonthlySeriesByGroup($loadingRows, 'load_type', 'export', 'total_kg');
$loadingExportBoxSeries = buildMonthlySeriesByGroup($loadingRows, 'load_type', 'export', 'total_box');
$loadingDetailGroups = groupRowsByKey($loadingDetailRows, 'load_type');
$loadingDomesticDetailRows = isset($loadingDetailGroups['domestic']) ? $loadingDetailGroups['domestic'] : [];
$loadingExportDetailRows = isset($loadingDetailGroups['export']) ? $loadingDetailGroups['export'] : [];
$loadingTopProductGroups = groupRowsByKey($loadingTopProductRows, 'load_type');
$loadingDomesticTopProductRows = isset($loadingTopProductGroups['domestic']) ? $loadingTopProductGroups['domestic'] : [];
$loadingExportTopProductRows = isset($loadingTopProductGroups['export']) ? $loadingTopProductGroups['export'] : [];
$loadingDomesticTopProductChart = buildTopItemsChart(
    $loadingDomesticTopProductRows,
    function ($row) {
        return trim((string) $row['idesc1']);
    },
    'total_kg',
    10
);
$loadingExportTopProductChart = buildTopItemsChart(
    $loadingExportTopProductRows,
    function ($row) {
        return trim((string) $row['idesc1']);
    },
    'total_kg',
    10
);
$chartPayload = [
    'monthLabels' => $monthLabels,
    'spendLabels' => $spendLabels,
    'spendValues' => $spendValues,
    'spendBudgetValues' => $spendBudgetValues,
    'spendBalanceValues' => $spendBalanceValues,
    'productionQtySeries' => $productionQtySeries,
    'productionOrderSeries' => buildMonthlySeries($productionRows, 'order_count'),
    'productionDailyLabels' => $productionDailyDateLabels,
    'productionDailyTotalSeries' => $productionDailyTotalSeries,
    'productionDailyHighlights' => $productionDailyHighlights,
    'productionLineLabels' => $productionLineChart['labels'],
    'productionLineDatasets' => $productionLineChart['datasets'],
    'productionProductLabels' => $productionProductChart['labels'],
    'productionProductDatasets' => $productionProductChart['datasets'],
    'loadingDomesticKgSeries' => $loadingDomesticKgSeries,
    'loadingDomesticBoxSeries' => $loadingDomesticBoxSeries,
    'loadingExportKgSeries' => $loadingExportKgSeries,
    'loadingExportBoxSeries' => $loadingExportBoxSeries,
    'loadingDomesticTopProductLabels' => $loadingDomesticTopProductChart['labels'],
    'loadingDomesticTopProductKgSeries' => $loadingDomesticTopProductChart['values'],
    'loadingDomesticTopProductBoxSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['total_box']) ? $row['total_box'] : 0);
    }, $loadingDomesticTopProductChart['rows'])),
    'loadingExportTopProductLabels' => $loadingExportTopProductChart['labels'],
    'loadingExportTopProductKgSeries' => $loadingExportTopProductChart['values'],
    'loadingExportTopProductBoxSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['total_box']) ? $row['total_box'] : 0);
    }, $loadingExportTopProductChart['rows'])),
];

$spendTotalValue = isset($summary['spend_total']) ? (float) $summary['spend_total'] : 0;
$spendBudgetTotalValue = isset($summary['spend_budget_total']) ? (float) $summary['spend_budget_total'] : 0;
$spendBalanceTotalValue = isset($summary['spend_balance_total']) ? (float) $summary['spend_balance_total'] : 0;
if ($hasCustomProdDates) {
    $productionQtyValue = 0;
    foreach ($productionDailyRows as $row) {
        $productionQtyValue += (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
    }
} else {
    $productionQtyValue = isset($summary['production_qty']) ? (float) $summary['production_qty'] : 0;
}
$loadingKgValue = isset($summary['loading_kg']) ? (float) $summary['loading_kg'] : 0;
$loadingBoxValue = isset($summary['loading_box']) ? (float) $summary['loading_box'] : 0;
$loadingExportKgValue = isset($summary['loading_export_kg']) ? (float) $summary['loading_export_kg'] : 0;
$loadingDomesticKgValue = isset($summary['loading_domestic_kg']) ? (float) $summary['loading_domestic_kg'] : 0;
$prCountValue = isset($summary['pr_count']) ? (float) $summary['pr_count'] : 0;
$poCountValue = isset($summary['po_count']) ? (float) $summary['po_count'] : 0;
$spendDepartmentCount = count($spendByDepartment);
$spendChartHeight = max(360, (int) ($spendDepartmentCount * 42) + 120);
$generatedAt = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$generatedAtLabel = formatThaiDateTime($generatedAt, $thaiMonths);
$spendFilterTokens = ['ปี ' . formatDisplayYear($selectedYear)];
if ($selectedMonth >= 1 && isset($thaiMonths[$selectedMonth])) {
    $spendFilterTokens[] = 'เดือน ' . $thaiMonths[$selectedMonth];
}
if ($selectedBudgetCode !== '') {
    $spendFilterTokens[] = 'Budget Code ' . $selectedBudgetCode;
}
if ($selectedBudgetDesc !== '') {
    $spendFilterTokens[] = 'Budget Description ' . $selectedBudgetDesc;
}
$spendSummaryNote = count($spendFilterTokens) > 1
    ? 'รวมราคาจริงจากใบรับของตามตัวกรองที่เลือก'
    : 'รวมราคาจริงจากใบรับของทั้งปีที่เลือก';
$filterQuery = ['year' => $selectedYear];
if ($selectedMonth >= 1) {
    $filterQuery['month'] = $selectedMonth;
}
if ($selectedBudgetCode !== '') {
    $filterQuery['budgetcd'] = $selectedBudgetCode;
}
if ($selectedBudgetDesc !== '') {
    $filterQuery['budgetdesc'] = $selectedBudgetDesc;
}
if ($selectedProdStart !== '') {
    $filterQuery['prod_start'] = $selectedProdStart;
}
if ($selectedProdEnd !== '') {
    $filterQuery['prod_end'] = $selectedProdEnd;
}
$retryUrl = '?' . http_build_query($filterQuery);
$clearFiltersQuery = ['year' => $selectedYear];
$clearFiltersUrl = '?' . http_build_query($clearFiltersQuery);
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
                        <input type="hidden" name="month" value="<?php echo $selectedMonth >= 1 ? (int) $selectedMonth : ''; ?>">
                        <input type="hidden" name="budgetcd" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="budgetdesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="prod_start" value="<?php echo htmlspecialchars($selectedProdStart, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="prod_end" value="<?php echo htmlspecialchars($selectedProdEnd, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="filter-field">
                            <label for="year">เลือกปี</label>
                            <select name="year" id="year">
                                <?php foreach ($yearOptions as $yearRow): ?>
                                    <?php $yearValue = (int) $yearRow['year_value']; ?>
                                    <option value="<?php echo $yearValue; ?>" <?php echo $yearValue === $selectedYear ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(formatDisplayYear($yearValue), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" id="yearSubmitButton">อัปเดตข้อมูล</button>
                        </div>
                    </form>
                </div>
            </div>

            <section class="summary-strip" aria-label="สรุปตัวชี้วัดหลัก">
                <div class="summary-card summary-card-detailed">
                    <div class="summary-label">ยอดใช้จ่ายจริง</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="currency" data-counter-value="<?php echo htmlspecialchars((string) $spendTotalValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatCurrency($spendTotalValue); ?>
                        </span>
                    </div>
                    <div class="summary-note"><?php echo htmlspecialchars($spendSummaryNote, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="summary-detail-grid summary-detail-grid-two-up">
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">งบสุทธิ</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="currency" data-counter-value="<?php echo htmlspecialchars((string) $spendBudgetTotalValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatCurrency($spendBudgetTotalValue); ?>
                                </span>
                            </span>
                        </div>
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">งบคงเหลือ</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="currency" data-counter-value="<?php echo htmlspecialchars((string) $spendBalanceTotalValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatCurrency($spendBalanceTotalValue); ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">ปริมาณการผลิต</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="quantity" data-counter-value="<?php echo htmlspecialchars((string) $productionQtyValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatQuantity($productionQtyValue); ?>
                        </span>
                    </div>
                    <div class="summary-note">
                        <?php if ($hasCustomProdDates): ?>
                            รวมน้ำหนักผลิต (กก.) ในช่วงวันที่ <?php echo htmlspecialchars($productionDailyRangeLabel, ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            รวมน้ำหนักผลิต (กก.) จาก `iqty x iioq` ใน `vw_shop_in_out` ของปีที่เลือก
                        <?php endif; ?>
                    </div>
                </div>
                <div class="summary-card summary-card-detailed">
                    <div class="summary-label">การโหลดสินค้าออก</div>
                    <div class="summary-value">
                        <span class="counter-value" data-counter-format="quantity" data-counter-value="<?php echo htmlspecialchars((string) $loadingKgValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo formatQuantity($loadingKgValue); ?>
                        </span>
                    </div>
                    <div class="summary-note">รวมน้ำหนักโหลด (กก.) จาก `isih / isid` ของปีที่เลือก</div>
                    <div class="summary-detail-grid summary-detail-grid-two-up">
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">จำนวนกล่อง</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="quantity" data-counter-value="<?php echo htmlspecialchars((string) $loadingBoxValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatQuantity($loadingBoxValue); ?>
                                </span>
                            </span>
                        </div>
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">โหลดนอกประเทศ</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="quantity" data-counter-value="<?php echo htmlspecialchars((string) $loadingExportKgValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatQuantity($loadingExportKgValue); ?>
                                </span>
                                กก.
                            </span>
                        </div>
                        <div class="summary-detail-item">
                            <span class="summary-detail-label">โหลดในประเทศ</span>
                            <span class="summary-detail-value">
                                <span class="counter-value" data-counter-format="quantity" data-counter-value="<?php echo htmlspecialchars((string) $loadingDomesticKgValue, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo formatQuantity($loadingDomesticKgValue); ?>
                                </span>
                                กก.
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
                <article class="panel panel-full spend-panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="spendChartTitle">การใช้จ่ายจริงเทียบงบสุทธิแยกตามแผนก</h2>
                            <p class="panel-desc">เปรียบเทียบงบสุทธิหลังตัดงบและขอเพิ่ม กับราคาจริงจากใบรับของของทุกแผนกในช่วงที่กรอง</p>
                        </div>
                        <div class="chip">งบประมาณ</div>
                    </div>
                    <form class="chart-filter-form" id="spendFilterForm" method="get" aria-label="ตัวกรองกราฟการใช้จ่าย">
                        <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                        <input type="hidden" name="prod_start" value="<?php echo htmlspecialchars($selectedProdStart, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="prod_end" value="<?php echo htmlspecialchars($selectedProdEnd, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="filter-field">
                            <label for="chartMonth">เดือน</label>
                            <select name="month" id="chartMonth">
                                <option value="">ทั้งปี</option>
                                <?php foreach ($thaiMonths as $monthValue => $monthLabel): ?>
                                    <option value="<?php echo $monthValue; ?>" <?php echo $monthValue === $selectedMonth ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-field filter-field-wide">
                            <label for="chartBudgetCode">Budget Code</label>
                            <input type="text" name="budgetcd" id="chartBudgetCode" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>" placeholder="เช่น BG-001">
                        </div>
                        <div class="filter-field filter-field-wide">
                            <label for="chartBudgetDesc">Budget Description</label>
                            <input type="text" name="budgetdesc" id="chartBudgetDesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ค้นหาคำในคำอธิบายงบ">
                        </div>
                        <div class="filter-actions">
                            <button type="submit">อัปเดตกราฟ</button>
                            <a class="secondary-action secondary-action-quiet" href="<?php echo htmlspecialchars($clearFiltersUrl, ENT_QUOTES, 'UTF-8'); ?>">ล้างตัวกรอง</a>
                        </div>
                    </form>
                    <div class="chart-context" aria-label="ตัวกรองการใช้จ่าย">
                        <span class="chart-context-label">ตัวกรองที่ใช้</span>
                        <div class="filter-pill-row">
                            <?php foreach ($spendFilterTokens as $token): ?>
                                <span class="filter-pill"><?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="chart-wrap tall" style="height: <?php echo (int) $spendChartHeight; ?>px;">
                        <canvas id="spendChart" role="img" aria-labelledby="spendChartTitle" aria-describedby="spendChartDesc spendChartData"></canvas>
                    </div>
                    <p class="sr-only" id="spendChartDesc">กราฟแท่งแนวนอนเปรียบเทียบงบสุทธิและยอดใช้จ่ายจริงของทุกแผนกในช่วงที่กรอง โดยเรียงจากมากไปน้อยตามยอดใช้จ่ายจริง</p>
                    <ul class="sr-only" id="spendChartData">
                        <?php if (!empty($spendByDepartment)): ?>
                            <?php foreach ($spendByDepartment as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['depcd'] . ' - ' . (string) $row['department_name'] . ' งบสุทธิ ' . formatCurrency((float) $row['net_budget_amount']) . ' บาท ใช้จ่ายจริง ' . formatCurrency((float) $row['total_amount']) . ' บาท และงบคงเหลือ ' . formatCurrency((float) $row['balance_amount']) . ' บาท', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลการใช้จ่ายแยกตามแผนกในช่วงที่กรอง</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>แผนก</th>
                                        <th>งบสุทธิ</th>
                                        <th>ใช้จ่ายจริง</th>
                                        <th>งบคงเหลือ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($spendByDepartment)): ?>
                                        <?php foreach ($spendByDepartment as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['depcd'] . ' - ' . (string) $row['department_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatCurrency((float) $row['net_budget_amount']); ?></td>
                                                <td><?php echo formatCurrency((float) $row['total_amount']); ?></td>
                                                <td><?php echo formatCurrency((float) $row['balance_amount']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลตามตัวกรองที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <form id="productionDailyFilterForm" method="get" aria-label="ตัวกรองการผลิตรายวัน">
                    <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth >= 1 ? (int) $selectedMonth : ''; ?>">
                    <input type="hidden" name="budgetcd" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="budgetdesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="filter-field">
                        <label for="prodStart">วันที่เริ่มต้น</label>
                        <input type="date" name="prod_start" id="prodStart" min="<?php echo (int) $selectedYear; ?>-01-01" max="<?php echo (int) $selectedYear; ?>-12-31" value="<?php echo htmlspecialchars($productionDailyStartDate, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="prodEnd">วันที่สิ้นสุด</label>
                        <input type="date" name="prod_end" id="prodEnd" min="<?php echo (int) $selectedYear; ?>-01-01" max="<?php echo (int) $selectedYear; ?>-12-31" value="<?php echo htmlspecialchars($productionDailyEndDate, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit">กรองข้อมูล</button>
                        <?php
                        $clearProdDatesQuery = $filterQuery;
                        unset($clearProdDatesQuery['prod_start']);
                        unset($clearProdDatesQuery['prod_end']);
                        $clearProdDatesUrl = '?' . http_build_query($clearProdDatesQuery);
                        ?>
                        <a class="secondary-action secondary-action-quiet" href="<?php echo htmlspecialchars($clearProdDatesUrl, ENT_QUOTES, 'UTF-8'); ?>">ย้อนกลับ 7 วัน</a>
                    </div>
                </form>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="productionChartTitle">การผลิตรายเดือน</h2>
                            <p class="panel-desc">สรุปปริมาณผลิตเป็นกิโลกรัมจาก `iqty x iioq` ใน `vw_shop_in_out` แยกตามเดือนของปีที่เลือก</p>
                        </div>
                        <div class="chip">การผลิต</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="productionChart" role="img" aria-labelledby="productionChartTitle" aria-describedby="productionChartDesc productionChartData"></canvas>
                    </div>
                    <p class="sr-only" id="productionChartDesc">กราฟแสดงปริมาณการผลิตรวมรายเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="productionChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' ปริมาณผลิต ' . formatQuantity($productionQtySeries[$monthIndex]) . ' กก.', ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>ปริมาณผลิต (กก.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatQuantity($productionQtySeries[$monthIndex]); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="productionDailyChartTitle">การผลิตรายวัน</h2>
                            <p class="panel-desc">สรุปปริมาณผลิตเป็นกิโลกรัมจาก `iqty x iioq` ย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน โดยแยกตามสินค้า `idesc1` เพื่อดูว่าสินค้าไหนดันยอดผลิตในแต่ละวัน</p>
                        </div>
                        <div class="chip">การผลิต</div>
                    </div>
                    <div class="chart-context" aria-label="ตัวกรองการผลิตรายวัน">
                        <span class="chart-context-label">ข้อมูลที่กำลังแสดง</span>
                        <div class="filter-pill-row">
                            <span class="filter-pill">ปี <?php echo htmlspecialchars(formatDisplayYear($selectedYear), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">ช่วงวันที่ <?php echo htmlspecialchars($productionDailyRangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">กราฟแสดงยอดรวมต่อวัน พร้อมดูรายการสินค้าหลักของวันผ่าน hover</span>
                        </div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="productionDailyChart" role="img" aria-labelledby="productionDailyChartTitle" aria-describedby="productionDailyChartDesc productionDailyChartData"></canvas>
                    </div>
                    <p class="sr-only" id="productionDailyChartDesc">กราฟการผลิตรายวันย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน แสดงยอดรวมต่อวัน และสามารถ hover เพื่อดูสินค้าหลักของแต่ละวัน</p>
                    <ul class="sr-only" id="productionDailyChartData">
                        <?php if (!empty($productionDailyProductRows)): ?>
                            <?php foreach ($productionDailyProductRows as $row): ?>
                                <li><?php echo htmlspecialchars('วันที่ ' . (string) $row['date_key'] . ' สินค้า ' . (string) $row['idesc1'] . ' ปริมาณ ' . formatQuantity((float) $row['total_kg']) . ' ' . (string) $row['unnm'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลการผลิตรายวันย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>สินค้า</th>
                                        <th>ปริมาณผลิต (กก.)</th>
                                        <th>หน่วย</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($productionDailyProductRows)): ?>
                                        <?php foreach ($productionDailyProductRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['unnm'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลการผลิตรายวันย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="productionLineChartTitle">การผลิตรายวันแยกตามไลน์ผลิต</h2>
                            <p class="panel-desc">ติดตาม <?php echo (int) $productionDailyNumDays; ?> วันย้อนหลังตาม `ไลน์ผลิต / wdesc` แบบ stacked bar เพื่อดูว่าสายการผลิตไหนประกอบเป็นยอดรวมของแต่ละวัน</p>
                        </div>
                        <div class="chip">การผลิต</div>
                    </div>
                    <div class="chart-context" aria-label="ตัวกรองการผลิตรายวันแยกตามไลน์ผลิต">
                        <span class="chart-context-label">ข้อมูลที่กำลังแสดง</span>
                        <div class="filter-pill-row">
                            <span class="filter-pill">ปี <?php echo htmlspecialchars(formatDisplayYear($selectedYear), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">ช่วงวันที่ <?php echo htmlspecialchars($productionDailyRangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">กราฟ stacked bar แสดงไลน์ผลิตทั้งหมดของช่วง <?php echo (int) $productionDailyNumDays; ?> วันย้อนหลัง</span>
                        </div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="productionLineChart" role="img" aria-labelledby="productionLineChartTitle" aria-describedby="productionLineChartDesc productionLineChartData"></canvas>
                    </div>
                    <p class="sr-only" id="productionLineChartDesc">กราฟ stacked bar การผลิตรายวันย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน แสดงปริมาณผลิตแยกตามไลน์ผลิตหลักของช่วงเวลาเดียวกัน</p>
                    <ul class="sr-only" id="productionLineChartData">
                        <?php if (!empty($productionLineChart['datasets'])): ?>
                            <?php foreach ($productionLineChart['datasets'] as $dataset): ?>
                                <li><?php echo htmlspecialchars((string) $dataset['label'] . ' แสดงเป็น stacked bar ตาม ' . (int) $productionDailyNumDays . ' วันย้อนหลัง', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลการผลิตรายวันแยกตามไลน์ผลิตย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>ไลน์ผลิต</th>
                                        <th>สินค้า</th>
                                        <th>ปริมาณผลิต (กก.)</th>
                                        <th>หน่วย</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($productionDailyRows)): ?>
                                        <?php foreach ($productionDailyRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['wdesc'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['unnm'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">ไม่พบข้อมูลการผลิตรายวันแยกตามไลน์ผลิตย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="productionProductChartTitle">การผลิตรายวันแยกตามสินค้า</h2>
                            <p class="panel-desc">ติดตาม <?php echo (int) $productionDailyNumDays; ?> วันย้อนหลังตาม `สินค้า / idesc1` แบบ stacked bar โดยแสดงเฉพาะ Top 10 ตามปริมาณรวมบนกราฟ และคงข้อมูลทั้งหมดไว้ในตารางด้านล่าง</p>
                        </div>
                        <div class="chip">การผลิต</div>
                    </div>
                    <div class="chart-context" aria-label="ตัวกรองการผลิตรายวันแยกตามสินค้า">
                        <span class="chart-context-label">ข้อมูลที่กำลังแสดง</span>
                        <div class="filter-pill-row">
                            <span class="filter-pill">ปี <?php echo htmlspecialchars(formatDisplayYear($selectedYear), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">ช่วงวันที่ <?php echo htmlspecialchars($productionDailyRangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">กราฟ stacked bar แสดง Top 10 สินค้าของช่วง <?php echo (int) $productionDailyNumDays; ?> วันย้อนหลัง</span>
                            <span class="filter-pill">ตารางด้านล่างแสดงข้อมูลสินค้าครบทั้งหมด</span>
                        </div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="productionProductChart" role="img" aria-labelledby="productionProductChartTitle" aria-describedby="productionProductChartDesc productionProductChartData"></canvas>
                    </div>
                    <p class="sr-only" id="productionProductChartDesc">กราฟ stacked bar การผลิตรายวันย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน แสดงปริมาณผลิตแยกตามสินค้า Top 10 ของช่วงเวลาเดียวกัน และมีตารางข้อมูลครบทั้งหมดด้านล่าง</p>
                    <ul class="sr-only" id="productionProductChartData">
                        <?php if (!empty($productionProductChart['datasets'])): ?>
                            <?php foreach ($productionProductChart['datasets'] as $dataset): ?>
                                <li><?php echo htmlspecialchars((string) $dataset['label'] . ' แสดงเป็น stacked bar ตาม ' . (int) $productionDailyNumDays . ' วันย้อนหลัง', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลการผลิตรายวันแยกตามสินค้าย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>สินค้า</th>
                                        <th>ปริมาณผลิต (กก.)</th>
                                        <th>หน่วย</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($productionDailyProductRows)): ?>
                                        <?php foreach ($productionDailyProductRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['unnm'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลการผลิตรายวันแยกตามสินค้าย้อนหลัง <?php echo (int) $productionDailyNumDays; ?> วัน</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingOverviewChartTitle">ภาพรวมการโหลดสินค้า</h2>
                            <p class="panel-desc">รวมข้อมูลโหลดนอกประเทศและในประเทศไว้ในกราฟใหญ่เดียว เพื่อเทียบทั้งน้ำหนักโหลดและจำนวนกล่องรายเดือนของปีที่เลือก</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="loadingOverviewChart" role="img" aria-labelledby="loadingOverviewChartTitle" aria-describedby="loadingOverviewChartDesc loadingOverviewChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingOverviewChartDesc">กราฟแสดงน้ำหนักโหลดและจำนวนกล่องของงานโหลดนอกประเทศและในประเทศในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingOverviewChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars(
                                $monthLabels[$monthIndex]
                                . ' นอกประเทศ ' . formatQuantity($loadingExportKgSeries[$monthIndex]) . ' กก. '
                                . formatQuantity($loadingExportBoxSeries[$monthIndex]) . ' กล่อง'
                                . ' ในประเทศ ' . formatQuantity($loadingDomesticKgSeries[$monthIndex]) . ' กก. '
                                . formatQuantity($loadingDomesticBoxSeries[$monthIndex]) . ' กล่อง',
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลรายเดือน</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>นอกประเทศ (กก.)</th>
                                        <th>นอกประเทศ (กล่อง)</th>
                                        <th>ในประเทศ (กก.)</th>
                                        <th>ในประเทศ (กล่อง)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatQuantity($loadingExportKgSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatQuantity($loadingExportBoxSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatQuantity($loadingDomesticKgSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatQuantity($loadingDomesticBoxSeries[$monthIndex]); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                    <details class="data-details">
                        <summary>ดูข้อมูลรายละเอียด</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ประเภท</th>
                                        <th>วันที่</th>
                                        <th>ลูกค้า</th>
                                        <th>น้ำหนักโหลด (กก.)</th>
                                        <th>จำนวนกล่อง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($loadingDetailRows)): ?>
                                        <?php foreach ($loadingDetailRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['load_type'] === 'export' ? 'นอกประเทศ' : 'ในประเทศ', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['do_cust'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_box']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">ไม่พบข้อมูลการโหลดในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingExportChartTitle">การโหลดนอกประเทศ</h2>
                            <p class="panel-desc">ดูเฉพาะรายการที่ `intrefno` ขึ้นต้นด้วย `DO` เพื่อโฟกัสน้ำหนักโหลดและจำนวนกล่องของงานนอกประเทศ</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="loadingExportChart" role="img" aria-labelledby="loadingExportChartTitle" aria-describedby="loadingExportChartDesc loadingExportChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingExportChartDesc">กราฟแสดงน้ำหนักโหลดและจำนวนกล่องของงานโหลดนอกประเทศในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingExportChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' โหลด ' . formatQuantity($loadingExportKgSeries[$monthIndex]) . ' กก. และ ' . formatQuantity($loadingExportBoxSeries[$monthIndex]) . ' กล่อง', ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>ลูกค้า</th>
                                        <th>น้ำหนักโหลด (กก.)</th>
                                        <th>จำนวนกล่อง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($loadingExportDetailRows)): ?>
                                        <?php foreach ($loadingExportDetailRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['do_cust'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_box']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลการโหลดนอกประเทศในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingDomesticChartTitle">การโหลดในประเทศ</h2>
                            <p class="panel-desc">ดูเฉพาะรายการที่ไม่ได้ขึ้นต้นด้วย `DO` เพื่อแยกวิเคราะห์น้ำหนักโหลดและจำนวนกล่องของงานในประเทศ</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="loadingDomesticChart" role="img" aria-labelledby="loadingDomesticChartTitle" aria-describedby="loadingDomesticChartDesc loadingDomesticChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingDomesticChartDesc">กราฟแสดงน้ำหนักโหลดและจำนวนกล่องของงานโหลดในประเทศในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingDomesticChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' โหลด ' . formatQuantity($loadingDomesticKgSeries[$monthIndex]) . ' กก. และ ' . formatQuantity($loadingDomesticBoxSeries[$monthIndex]) . ' กล่อง', ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>ลูกค้า</th>
                                        <th>น้ำหนักโหลด (กก.)</th>
                                        <th>จำนวนกล่อง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($loadingDomesticDetailRows)): ?>
                                        <?php foreach ($loadingDomesticDetailRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['date_key'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string) $row['do_cust'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_box']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลการโหลดในประเทศในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingExportTopProductChartTitle">Top 10 สินค้านอกประเทศ</h2>
                            <p class="panel-desc">จัดอันดับสินค้าที่โหลดนอกประเทศสูงสุด 10 อันดับแรกของปีที่เลือก โดยเรียงตามน้ำหนักโหลดรวม</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="loadingExportTopProductChart" role="img" aria-labelledby="loadingExportTopProductChartTitle" aria-describedby="loadingExportTopProductChartDesc loadingExportTopProductChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingExportTopProductChartDesc">กราฟแสดงสินค้า Top 10 ของงานโหลดนอกประเทศ เรียงตามน้ำหนักโหลดรวมของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingExportTopProductChartData">
                        <?php if (!empty($loadingExportTopProductChart['rows'])): ?>
                            <?php foreach ($loadingExportTopProductChart['rows'] as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['idesc1'] . ' น้ำหนักโหลด ' . formatQuantity((float) $row['total_kg']) . ' กก. และ ' . formatQuantity((float) $row['total_box']) . ' กล่อง', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลสินค้าโหลดนอกประเทศในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>สินค้า</th>
                                        <th>น้ำหนักโหลด (กก.)</th>
                                        <th>จำนวนกล่อง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($loadingExportTopProductChart['rows'])): ?>
                                        <?php foreach ($loadingExportTopProductChart['rows'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_box']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">ไม่พบข้อมูลสินค้าโหลดนอกประเทศในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>

                <article class="panel panel-half">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="loadingDomesticTopProductChartTitle">Top 10 สินค้าในประเทศ</h2>
                            <p class="panel-desc">จัดอันดับสินค้าที่โหลดในประเทศสูงสุด 10 อันดับแรกของปีที่เลือก โดยเรียงตามน้ำหนักโหลดรวม</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="loadingDomesticTopProductChart" role="img" aria-labelledby="loadingDomesticTopProductChartTitle" aria-describedby="loadingDomesticTopProductChartDesc loadingDomesticTopProductChartData"></canvas>
                    </div>
                    <p class="sr-only" id="loadingDomesticTopProductChartDesc">กราฟแสดงสินค้า Top 10 ของงานโหลดในประเทศ เรียงตามน้ำหนักโหลดรวมของปีที่เลือก</p>
                    <ul class="sr-only" id="loadingDomesticTopProductChartData">
                        <?php if (!empty($loadingDomesticTopProductChart['rows'])): ?>
                            <?php foreach ($loadingDomesticTopProductChart['rows'] as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['idesc1'] . ' น้ำหนักโหลด ' . formatQuantity((float) $row['total_kg']) . ' กก. และ ' . formatQuantity((float) $row['total_box']) . ' กล่อง', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลสินค้าโหลดในประเทศในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>สินค้า</th>
                                        <th>น้ำหนักโหลด (กก.)</th>
                                        <th>จำนวนกล่อง</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($loadingDomesticTopProductChart['rows'])): ?>
                                        <?php foreach ($loadingDomesticTopProductChart['rows'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_kg']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_box']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">ไม่พบข้อมูลสินค้าโหลดในประเทศในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
            </section>
        <?php endif; ?>

        <div class="footer-note">ข้อมูลดึงจากฐาน M_FOOD ตามปีที่เลือก และอัปเดตเมื่อผู้ใช้เปลี่ยนปีหรือกดอัปเดตข้อมูล</div>
    </main>

    <script id="dashboard-data" type="application/json"><?php
        echo json_encode(
            $chartPayload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
    ?></script>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
