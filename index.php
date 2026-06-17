<?php
require_once 'connect.php';
require_once 'helpers.php';

$thaiMonths = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

function parseFilterDate($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    foreach (['Y-m-d', 'd/m/Y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if ($date && !$hasErrors && $date->format($format) === $value) {
            return $date;
        }
    }

    return false;
}

function normalizeFilterDateValue($value)
{
    $date = parseFilterDate($value);

    return $date ? $date->format('Y-m-d') : trim((string) $value);
}

function formatFilterDateInput($value)
{
    $date = parseFilterDate($value);

    return $date ? $date->format('d/m/Y') : '';
}

$yearOptions = [];
$selectedYear = (int) date('Y');
$selectedMonth = 0;
$selectedBudgetCode = '';
$selectedBudgetDesc = '';
$selectedProdStart = '';
$selectedProdEnd = '';
$selectedLoadStart = '';
$selectedLoadEnd = '';
$selectedPurchaseStart = '';
$selectedPurchaseEnd = '';
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
    'warehouse_receipt_qty' => 0.0,
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
$loadingStartDate = '';
$loadingEndDate = '';
$hasCustomLoadDates = false;
$purchaseStartDate = '';
$purchaseEndDate = '';
$hasCustomPurchaseDates = false;
$loadingRows = [];
$loadingDetailRows = [];
$loadingTopProductRows = [];
$warehouseReceiptRows = [];
$warehouseReceiptCustomerRows = [];
$warehouseReceiptProductRows = [];
$purchaseMonthlyRows = [];
$purchaseStatusRows = [];
$purchaseDepartmentRows = [];
$purchaseSupplierRows = [];
$purchaseProductRows = [];

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
            $selectedProdStart = normalizeFilterDateValue($_GET['prod_start']);
        }

        if (isset($_GET['prod_end'])) {
            $selectedProdEnd = normalizeFilterDateValue($_GET['prod_end']);
        }

        if (isset($_GET['load_start'])) {
            $selectedLoadStart = normalizeFilterDateValue($_GET['load_start']);
        }

        if (isset($_GET['load_end'])) {
            $selectedLoadEnd = normalizeFilterDateValue($_GET['load_end']);
        }

        if (isset($_GET['purchase_start'])) {
            $selectedPurchaseStart = normalizeFilterDateValue($_GET['purchase_start']);
        }

        if (isset($_GET['purchase_end'])) {
            $selectedPurchaseEnd = normalizeFilterDateValue($_GET['purchase_end']);
        }

        $startDate = sprintf('%04d-01-01', $selectedYear);
        $endDate = sprintf('%04d-01-01', $selectedYear + 1);
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];
        $loadingStartDate = $startDate;
        $loadingEndDate = (new DateTime($endDate))->modify('-1 day')->format('Y-m-d');
        if ($selectedLoadStart !== '' && $selectedLoadEnd !== '') {
            $customLoadStart = DateTime::createFromFormat('Y-m-d', $selectedLoadStart);
            $customLoadEnd = DateTime::createFromFormat('Y-m-d', $selectedLoadEnd);
            if ($customLoadStart && $customLoadStart->format('Y-m-d') === $selectedLoadStart &&
                $customLoadEnd && $customLoadEnd->format('Y-m-d') === $selectedLoadEnd &&
                (int) $customLoadStart->format('Y') === $selectedYear &&
                (int) $customLoadEnd->format('Y') === $selectedYear &&
                $customLoadStart <= $customLoadEnd) {
                $loadingStartDate = $selectedLoadStart;
                $loadingEndDate = $selectedLoadEnd;
                $hasCustomLoadDates = true;
            }
        }
        $loadingParams = [
            ':loading_start_date' => $loadingStartDate,
            ':loading_end_date' => (new DateTime($loadingEndDate))->modify('+1 day')->format('Y-m-d'),
        ];
        $purchaseStartDate = $startDate;
        $purchaseEndDate = (new DateTime($endDate))->modify('-1 day')->format('Y-m-d');
        if ($selectedPurchaseStart !== '' && $selectedPurchaseEnd !== '') {
            $customPurchaseStart = DateTime::createFromFormat('Y-m-d', $selectedPurchaseStart);
            $customPurchaseEnd = DateTime::createFromFormat('Y-m-d', $selectedPurchaseEnd);
            if ($customPurchaseStart && $customPurchaseStart->format('Y-m-d') === $selectedPurchaseStart &&
                $customPurchaseEnd && $customPurchaseEnd->format('Y-m-d') === $selectedPurchaseEnd &&
                (int) $customPurchaseStart->format('Y') === $selectedYear &&
                (int) $customPurchaseEnd->format('Y') === $selectedYear &&
                $customPurchaseStart <= $customPurchaseEnd) {
                $purchaseStartDate = $selectedPurchaseStart;
                $purchaseEndDate = $selectedPurchaseEnd;
                $hasCustomPurchaseDates = true;
            }
        }
        $purchaseParams = [
            ':purchase_start_date' => $purchaseStartDate,
            ':purchase_end_date' => (new DateTime($purchaseEndDate))->modify('+1 day')->format('Y-m-d'),
            ':purchase_po_start_date' => $purchaseStartDate,
            ':purchase_po_end_date' => (new DateTime($purchaseEndDate))->modify('+1 day')->format('Y-m-d'),
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
                  AND isih.isidate >= :loading_start_date
                  AND isih.isidate < :loading_end_date
            )
        ";
        $warehouseReceiptSourceCte = "
            WITH warehouse_receipt_source AS (
                SELECT
                    ipoh.ipono,
                    ipoh.ipodate,
                    MONTH(ipoh.ipodate) AS month_no,
                    ipod.iprod,
                    COALESCE(NULLIF(LTRIM(RTRIM(iim.idesc1)), ''), N'ไม่ระบุสินค้า') AS idesc1,
                    COALESCE(NULLIF(LTRIM(RTRIM(customer.custnme)), ''), N'ไม่ระบุลูกค้า') AS custnme,
                    COALESCE(NULLIF(LTRIM(RTRIM(unit.uncd)), ''), '') AS uncd,
                    COALESCE(iim.iioq, 1) AS iioq,
                    COALESCE(ili_tag.ReceiveQuantity, ipod.iqty, 0) AS rcv_qty,
                    CASE
                        WHEN UPPER(COALESCE(NULLIF(LTRIM(RTRIM(unit.uncd)), ''), '')) = 'KG'
                            THEN COALESCE(ili_tag.ReceiveQuantity, ipod.iqty, 0)
                        ELSE COALESCE(ili_tag.ReceiveQuantity, ipod.iqty, 0) * COALESCE(iim.iioq, 1)
                    END AS receipt_weight_kg
                FROM ipoh
                INNER JOIN ipod
                    ON ipoh.ipono = ipod.ipono
                   AND ipoh.ipoveninv = 'WR'
                INNER JOIN iim
                    ON ipod.iprod = iim.iprod
                LEFT JOIN unit
                    ON ipod.uncd = unit.uncd
                LEFT JOIN customer
                    ON ipoh.intcd = customer.custcd
                LEFT JOIN ili_tag
                    ON ipod.ipono = ili_tag.ipono
                   AND ipod.seqno = ili_tag.ipod_seqno
                   AND ili_tag.SeqNo < 800
                   AND ili_tag.lotrunningno > 0
                WHERE ipoh.ipostat <> 'X'
                  AND ipoh.ipodate IS NOT NULL
                  AND ipoh.ipodate >= :loading_start_date
                  AND ipoh.ipodate < :loading_end_date
            )
        ";
        $purchaseSourceCte = "
            WITH purchase_lines AS (
                SELECT
                    prh.prno,
                    prd.seqno,
                    prh.prdate,
                    prd.duedate,
                    prd.iprod,
                    COALESCE(NULLIF(LTRIM(RTRIM(iim.idesc1)), ''), N'ไม่ระบุสินค้า') AS idesc1,
                    COALESCE(prd.iqty, 0) AS iqty,
                    COALESCE(prd.pqty, 0) AS pqty,
                    prd.k01 AS dept,
                    COALESCE(NULLIF(LTRIM(RTRIM(cdp.depnm)), ''), N'ไม่ระบุหน่วยงาน') AS depnm
                FROM prh
                INNER JOIN prd
                    ON prh.prno = prd.prno
                   AND prh.prstat NOT IN ('N', 'X')
                LEFT JOIN iim ON iim.iprod = prd.iprod
                LEFT JOIN cdp ON cdp.depcd = prd.k01
                WHERE prh.prdate >= :purchase_start_date
                  AND prh.prdate < :purchase_end_date
            ),
            po_links AS (
                SELECT
                    impodt.prno,
                    impodt.docseq,
                    impodt.pono,
                    impohd.podate,
                    impohd.postat,
                    impohd.spprcd,
                    COALESCE(NULLIF(LTRIM(RTRIM(supplier.spprnme)), ''), N'ไม่ระบุ Supplier') AS spprnme
                FROM impodt
                INNER JOIN impohd
                    ON impohd.pono = impodt.pono
                   AND impohd.postat <> 'X'
                LEFT JOIN supplier ON supplier.spprcd = impohd.spprcd
            ),
            po_documents AS (
                SELECT
                    impohd.pono,
                    impohd.podate,
                    impohd.postat,
                    impohd.spprcd,
                    COALESCE(NULLIF(LTRIM(RTRIM(supplier.spprnme)), ''), N'ไม่ระบุ Supplier') AS spprnme
                FROM impohd
                LEFT JOIN supplier ON supplier.spprcd = impohd.spprcd
                WHERE impohd.podate >= :purchase_po_start_date
                  AND impohd.podate < :purchase_po_end_date
                  AND impohd.postat <> 'X'
            ),
            purchase_source AS (
                SELECT
                    pl.prno,
                    pl.seqno,
                    pl.prdate,
                    pl.duedate,
                    pl.iprod,
                    pl.idesc1,
                    pl.iqty,
                    pl.pqty,
                    pl.dept,
                    pl.depnm,
                    COUNT(DISTINCT po_links.pono) AS po_count,
                    MAX(CASE po_links.postat
                        WHEN 'R' THEN 4
                        WHEN 'T' THEN 3
                        WHEN 'N' THEN 2
                        WHEN 'E' THEN 1
                        ELSE 0
                    END) AS postat_rank
                FROM purchase_lines AS pl
                LEFT JOIN po_links
                    ON po_links.prno = pl.prno
                   AND po_links.docseq = pl.seqno
                GROUP BY
                    pl.prno,
                    pl.seqno,
                    pl.prdate,
                    pl.duedate,
                    pl.iprod,
                    pl.idesc1,
                    pl.iqty,
                    pl.pqty,
                    pl.dept,
                    pl.depnm
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
            $loadingParams
        );
        $summary['loading_kg'] = isset($loadingSummary['loading_kg']) ? (float) $loadingSummary['loading_kg'] : 0;
        $summary['loading_box'] = isset($loadingSummary['loading_box']) ? (float) $loadingSummary['loading_box'] : 0;
        $summary['loading_export_kg'] = isset($loadingSummary['loading_export_kg']) ? (float) $loadingSummary['loading_export_kg'] : 0;
        $summary['loading_domestic_kg'] = isset($loadingSummary['loading_domestic_kg']) ? (float) $loadingSummary['loading_domestic_kg'] : 0;

        $warehouseReceiptSummary = fetchOneRow(
            $conn,
            $warehouseReceiptSourceCte . "
            SELECT COALESCE(SUM(receipt_weight_kg), 0) AS warehouse_receipt_qty
            FROM warehouse_receipt_source
            ",
            $loadingParams
        );
        $summary['warehouse_receipt_qty'] = isset($warehouseReceiptSummary['warehouse_receipt_qty']) ? (float) $warehouseReceiptSummary['warehouse_receipt_qty'] : 0;

        $prCountRow = fetchOneRow(
            $conn,
            $purchaseSourceCte . "
            SELECT COUNT(DISTINCT prno) AS pr_count
            FROM purchase_source
            ",
            $purchaseParams
        );
        $summary['pr_count'] = isset($prCountRow['pr_count']) ? (int) $prCountRow['pr_count'] : 0;

        $poCountRow = fetchOneRow(
            $conn,
            $purchaseSourceCte . "
            SELECT COUNT(DISTINCT pono) AS po_count
            FROM po_documents
            ",
            $purchaseParams
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
            $loadingParams
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
            $loadingParams
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
            $loadingParams
        );

        $warehouseReceiptRows = fetchAllRows(
            $conn,
            $warehouseReceiptSourceCte . "
            SELECT
                month_no,
                SUM(receipt_weight_kg) AS total_weight,
                COUNT(DISTINCT ipono) AS doc_count,
                COUNT(*) AS line_count
            FROM warehouse_receipt_source
            GROUP BY month_no
            ORDER BY month_no
            ",
            $loadingParams
        );

        $warehouseReceiptCustomerRows = fetchAllRows(
            $conn,
            $warehouseReceiptSourceCte . "
            SELECT TOP 10
                custnme,
                SUM(receipt_weight_kg) AS total_weight,
                COUNT(DISTINCT ipono) AS doc_count,
                COUNT(*) AS line_count
            FROM warehouse_receipt_source
            GROUP BY custnme
            ORDER BY total_weight DESC, doc_count DESC, custnme
            ",
            $loadingParams
        );

        $warehouseReceiptProductRows = fetchAllRows(
            $conn,
            $warehouseReceiptSourceCte . "
            SELECT TOP 10
                iprod,
                idesc1,
                SUM(receipt_weight_kg) AS total_weight,
                COUNT(DISTINCT ipono) AS doc_count,
                COUNT(*) AS line_count
            FROM warehouse_receipt_source
            GROUP BY iprod, idesc1
            ORDER BY total_weight DESC, line_count DESC, idesc1, iprod
            ",
            $loadingParams
        );

        $purchaseMonthlyRows = fetchAllRows(
            $conn,
            $purchaseSourceCte . "
            SELECT
                month_no,
                SUM(pr_count) AS pr_count,
                SUM(po_count) AS po_count
            FROM (
                SELECT
                    MONTH(prdate) AS month_no,
                    COUNT(DISTINCT prno) AS pr_count,
                    0 AS po_count
                FROM purchase_lines
                GROUP BY MONTH(prdate)

                UNION ALL

                SELECT
                    MONTH(podate) AS month_no,
                    0 AS pr_count,
                    COUNT(DISTINCT pono) AS po_count
                FROM po_documents
                GROUP BY MONTH(podate)
            ) AS monthly_documents
            GROUP BY month_no
            ORDER BY month_no
            ",
            $purchaseParams
        );

        $purchaseStatusRows = fetchAllRows(
            $conn,
            $purchaseSourceCte . "
            SELECT
                CASE
                    WHEN po_count = 0 THEN 'NO_PO'
                    WHEN postat_rank = 4 THEN 'R'
                    WHEN postat_rank = 3 THEN 'T'
                    WHEN postat_rank = 2 THEN 'N'
                    WHEN postat_rank = 1 THEN 'E'
                    ELSE '-'
                END AS postat,
                CASE
                    WHEN po_count = 0 THEN N'ยังไม่เปิด P/O'
                    WHEN postat_rank = 4 THEN N'กำลังรับสินค้า'
                    WHEN postat_rank = 3 THEN N'รอรับ'
                    WHEN postat_rank = 2 THEN N'เปิดใหม่'
                    WHEN postat_rank = 1 THEN N'จบการรับ'
                    ELSE N'-'
                END AS postat_text,
                COUNT(*) AS line_count,
                SUM(iqty) AS requested_qty,
                SUM(pqty) AS purchased_qty
            FROM purchase_source
            GROUP BY
                CASE
                    WHEN po_count = 0 THEN 'NO_PO'
                    WHEN postat_rank = 4 THEN 'R'
                    WHEN postat_rank = 3 THEN 'T'
                    WHEN postat_rank = 2 THEN 'N'
                    WHEN postat_rank = 1 THEN 'E'
                    ELSE '-'
                END,
                CASE
                    WHEN po_count = 0 THEN N'ยังไม่เปิด P/O'
                    WHEN postat_rank = 4 THEN N'กำลังรับสินค้า'
                    WHEN postat_rank = 3 THEN N'รอรับ'
                    WHEN postat_rank = 2 THEN N'เปิดใหม่'
                    WHEN postat_rank = 1 THEN N'จบการรับ'
                    ELSE N'-'
                END
            ORDER BY
                MIN(CASE
                    WHEN po_count = 0 THEN 1
                    WHEN postat_rank = 2 THEN 2
                    WHEN postat_rank = 3 THEN 3
                    WHEN postat_rank = 4 THEN 4
                    WHEN postat_rank = 1 THEN 5
                    ELSE 6
                END)
            ",
            $purchaseParams
        );

        $purchaseDepartmentRows = fetchAllRows(
            $conn,
            $purchaseSourceCte . "
            SELECT TOP 10
                dept,
                depnm,
                COUNT(DISTINCT prno) AS pr_count,
                COUNT(*) AS line_count,
                SUM(CASE WHEN po_count > 0 THEN 1 ELSE 0 END) AS po_line_count,
                SUM(iqty) AS requested_qty,
                SUM(pqty) AS purchased_qty
            FROM purchase_source
            GROUP BY dept, depnm
            ORDER BY line_count DESC, requested_qty DESC, depnm
            ",
            $purchaseParams
        );

        $purchaseSupplierRows = fetchAllRows(
            $conn,
            $purchaseSourceCte . "
            SELECT TOP 10
                po_links.spprcd,
                po_links.spprnme,
                COUNT(DISTINCT po_links.pono) AS po_count,
                COUNT(DISTINCT pl.prno) AS pr_count,
                COUNT(*) AS line_count,
                SUM(pl.iqty) AS requested_qty,
                SUM(pl.pqty) AS purchased_qty
            FROM purchase_lines AS pl
            INNER JOIN po_links
                ON po_links.prno = pl.prno
               AND po_links.docseq = pl.seqno
            GROUP BY po_links.spprcd, po_links.spprnme
            ORDER BY po_count DESC, line_count DESC, po_links.spprnme
            ",
            $purchaseParams
        );

        $purchaseProductRows = fetchAllRows(
            $conn,
            $purchaseSourceCte . "
            SELECT TOP 10
                iprod,
                idesc1,
                COUNT(DISTINCT prno) AS pr_count,
                COUNT(*) AS line_count,
                SUM(CASE WHEN po_count > 0 THEN 1 ELSE 0 END) AS po_line_count,
                SUM(iqty) AS requested_qty,
                SUM(pqty) AS purchased_qty
            FROM purchase_source
            GROUP BY iprod, idesc1
            ORDER BY line_count DESC, pr_count DESC, requested_qty DESC, idesc1
            ",
            $purchaseParams
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
$warehouseReceiptWeightSeries = buildMonthlySeries($warehouseReceiptRows, 'total_weight');
$warehouseReceiptDocSeries = buildMonthlySeries($warehouseReceiptRows, 'doc_count');
$warehouseReceiptCustomerChart = buildTopItemsChart(
    $warehouseReceiptCustomerRows,
    function ($row) {
        return trim((string) $row['custnme']);
    },
    'total_weight',
    10
);
$warehouseReceiptProductChart = buildTopItemsChart(
    $warehouseReceiptProductRows,
    function ($row) {
        $productName = trim((string) (isset($row['idesc1']) ? $row['idesc1'] : ''));

        return $productName;
    },
    'total_weight',
    10
);
$purchaseMonthlyPrSeries = buildMonthlySeries($purchaseMonthlyRows, 'pr_count');
$purchaseMonthlyPoSeries = buildMonthlySeries($purchaseMonthlyRows, 'po_count');
$purchaseStatusLabels = [];
$purchaseStatusLineSeries = [];
$purchaseStatusQtySeries = [];
$purchaseStatusPurchasedQtySeries = [];
foreach ($purchaseStatusRows as $row) {
    $purchaseStatusLabels[] = trim((string) $row['postat_text']);
    $purchaseStatusLineSeries[] = (float) (isset($row['line_count']) ? $row['line_count'] : 0);
    $purchaseStatusQtySeries[] = (float) (isset($row['requested_qty']) ? $row['requested_qty'] : 0);
    $purchaseStatusPurchasedQtySeries[] = (float) (isset($row['purchased_qty']) ? $row['purchased_qty'] : 0);
}
$purchaseDepartmentLabels = [];
$purchaseDepartmentLineSeries = [];
$purchaseDepartmentQtySeries = [];
$purchaseDepartmentPoLineSeries = [];
foreach ($purchaseDepartmentRows as $row) {
    $departmentCode = trim((string) (isset($row['dept']) ? $row['dept'] : ''));
    $departmentName = trim((string) (isset($row['depnm']) ? $row['depnm'] : ''));
    $purchaseDepartmentLabels[] = $departmentCode !== ''
        ? $departmentCode . ' - ' . $departmentName
        : $departmentName;
    $purchaseDepartmentLineSeries[] = (float) (isset($row['line_count']) ? $row['line_count'] : 0);
    $purchaseDepartmentQtySeries[] = (float) (isset($row['requested_qty']) ? $row['requested_qty'] : 0);
    $purchaseDepartmentPoLineSeries[] = (float) (isset($row['po_line_count']) ? $row['po_line_count'] : 0);
}
$purchaseSupplierLabels = [];
$purchaseSupplierPoSeries = [];
$purchaseSupplierPrSeries = [];
$purchaseSupplierLineSeries = [];
foreach ($purchaseSupplierRows as $row) {
    $supplierCode = trim((string) (isset($row['spprcd']) ? $row['spprcd'] : ''));
    $supplierName = trim((string) (isset($row['spprnme']) ? $row['spprnme'] : ''));
    $purchaseSupplierLabels[] = $supplierCode !== ''
        ? $supplierCode . ' - ' . $supplierName
        : $supplierName;
    $purchaseSupplierPoSeries[] = (float) (isset($row['po_count']) ? $row['po_count'] : 0);
    $purchaseSupplierPrSeries[] = (float) (isset($row['pr_count']) ? $row['pr_count'] : 0);
    $purchaseSupplierLineSeries[] = (float) (isset($row['line_count']) ? $row['line_count'] : 0);
}
$purchaseProductLabels = [];
$purchaseProductLineSeries = [];
$purchaseProductPrSeries = [];
$purchaseProductPoLineSeries = [];
$purchaseProductQtySeries = [];
foreach ($purchaseProductRows as $row) {
    $productCode = trim((string) (isset($row['iprod']) ? $row['iprod'] : ''));
    $productName = trim((string) (isset($row['idesc1']) ? $row['idesc1'] : ''));
    $purchaseProductLabels[] = $productCode !== ''
        ? $productCode . ' - ' . $productName
        : $productName;
    $purchaseProductLineSeries[] = (float) (isset($row['line_count']) ? $row['line_count'] : 0);
    $purchaseProductPrSeries[] = (float) (isset($row['pr_count']) ? $row['pr_count'] : 0);
    $purchaseProductPoLineSeries[] = (float) (isset($row['po_line_count']) ? $row['po_line_count'] : 0);
    $purchaseProductQtySeries[] = (float) (isset($row['requested_qty']) ? $row['requested_qty'] : 0);
}
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
    'warehouseReceiptWeightSeries' => $warehouseReceiptWeightSeries,
    'warehouseReceiptDocSeries' => $warehouseReceiptDocSeries,
    'warehouseReceiptCustomerLabels' => $warehouseReceiptCustomerChart['labels'],
    'warehouseReceiptCustomerQtySeries' => $warehouseReceiptCustomerChart['values'],
    'warehouseReceiptCustomerDocSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['doc_count']) ? $row['doc_count'] : 0);
    }, $warehouseReceiptCustomerChart['rows'])),
    'warehouseReceiptCustomerLineSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['line_count']) ? $row['line_count'] : 0);
    }, $warehouseReceiptCustomerChart['rows'])),
    'warehouseReceiptProductLabels' => $warehouseReceiptProductChart['labels'],
    'warehouseReceiptProductQtySeries' => $warehouseReceiptProductChart['values'],
    'warehouseReceiptProductDocSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['doc_count']) ? $row['doc_count'] : 0);
    }, $warehouseReceiptProductChart['rows'])),
    'warehouseReceiptProductLineSeries' => array_values(array_map(function ($row) {
        return (float) (isset($row['line_count']) ? $row['line_count'] : 0);
    }, $warehouseReceiptProductChart['rows'])),
    'purchaseMonthlyPrSeries' => $purchaseMonthlyPrSeries,
    'purchaseMonthlyPoSeries' => $purchaseMonthlyPoSeries,
    'purchaseStatusLabels' => $purchaseStatusLabels,
    'purchaseStatusLineSeries' => $purchaseStatusLineSeries,
    'purchaseStatusQtySeries' => $purchaseStatusQtySeries,
    'purchaseStatusPurchasedQtySeries' => $purchaseStatusPurchasedQtySeries,
    'purchaseDepartmentLabels' => $purchaseDepartmentLabels,
    'purchaseDepartmentLineSeries' => $purchaseDepartmentLineSeries,
    'purchaseDepartmentQtySeries' => $purchaseDepartmentQtySeries,
    'purchaseDepartmentPoLineSeries' => $purchaseDepartmentPoLineSeries,
    'purchaseSupplierLabels' => $purchaseSupplierLabels,
    'purchaseSupplierPoSeries' => $purchaseSupplierPoSeries,
    'purchaseSupplierPrSeries' => $purchaseSupplierPrSeries,
    'purchaseSupplierLineSeries' => $purchaseSupplierLineSeries,
    'purchaseProductLabels' => $purchaseProductLabels,
    'purchaseProductLineSeries' => $purchaseProductLineSeries,
    'purchaseProductPrSeries' => $purchaseProductPrSeries,
    'purchaseProductPoLineSeries' => $purchaseProductPoLineSeries,
    'purchaseProductQtySeries' => $purchaseProductQtySeries,
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
$loadingRangeLabel = 'ทั้งปี ' . formatDisplayYear($selectedYear);
if ($loadingStartDate !== '' && $loadingEndDate !== '') {
    $loadingRangeStartDisplay = new DateTime($loadingStartDate);
    $loadingRangeEndDisplay = new DateTime($loadingEndDate);
    $loadingStartMonthNumber = (int) $loadingRangeStartDisplay->format('n');
    $loadingEndMonthNumber = (int) $loadingRangeEndDisplay->format('n');
    $loadingStartMonthLabel = isset($thaiMonths[$loadingStartMonthNumber]) ? $thaiMonths[$loadingStartMonthNumber] : $loadingRangeStartDisplay->format('M');
    $loadingEndMonthLabel = isset($thaiMonths[$loadingEndMonthNumber]) ? $thaiMonths[$loadingEndMonthNumber] : $loadingRangeEndDisplay->format('M');
    $loadingRangeLabel = $loadingRangeStartDisplay->format('j') . ' ' . $loadingStartMonthLabel
        . ' - ' . $loadingRangeEndDisplay->format('j') . ' ' . $loadingEndMonthLabel;
}
$purchaseRangeLabel = 'ทั้งปี ' . formatDisplayYear($selectedYear);
if ($purchaseStartDate !== '' && $purchaseEndDate !== '') {
    $purchaseRangeStartDisplay = new DateTime($purchaseStartDate);
    $purchaseRangeEndDisplay = new DateTime($purchaseEndDate);
    $purchaseStartMonthNumber = (int) $purchaseRangeStartDisplay->format('n');
    $purchaseEndMonthNumber = (int) $purchaseRangeEndDisplay->format('n');
    $purchaseStartMonthLabel = isset($thaiMonths[$purchaseStartMonthNumber]) ? $thaiMonths[$purchaseStartMonthNumber] : $purchaseRangeStartDisplay->format('M');
    $purchaseEndMonthLabel = isset($thaiMonths[$purchaseEndMonthNumber]) ? $thaiMonths[$purchaseEndMonthNumber] : $purchaseRangeEndDisplay->format('M');
    $purchaseRangeLabel = $purchaseRangeStartDisplay->format('j') . ' ' . $purchaseStartMonthLabel
        . ' - ' . $purchaseRangeEndDisplay->format('j') . ' ' . $purchaseEndMonthLabel;
}
$purchaseSummaryNote = $hasCustomPurchaseDates
    ? 'เอกสารในช่วงวันที่ ' . $purchaseRangeLabel
    : 'เอกสารที่เปิดในปี ' . $selectedYear;
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
if ($hasCustomLoadDates) {
    $filterQuery['load_start'] = $loadingStartDate;
    $filterQuery['load_end'] = $loadingEndDate;
}
if ($hasCustomPurchaseDates) {
    $filterQuery['purchase_start'] = $purchaseStartDate;
    $filterQuery['purchase_end'] = $purchaseEndDate;
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
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=20260616-standard-separation">
</head>
<body class="is-dashboard-loading" data-active-section="budget">
    <div class="section-ambient-gallery" aria-hidden="true">
        <div class="section-ambient-layer" data-section-art="budget"></div>
        <div class="section-ambient-layer" data-section-art="purchase"></div>
        <div class="section-ambient-layer" data-section-art="production"></div>
        <div class="section-ambient-layer" data-section-art="warehouse"></div>
    </div>
    <div class="dashboard-loading-overlay" id="dashboardLoadingOverlay" aria-hidden="false">
        <div class="dashboard-loading-card" role="status" aria-live="assertive" aria-atomic="true">
            <span class="dashboard-loading-spinner" aria-hidden="true"></span>
            <strong class="dashboard-loading-title" id="dashboardLoadingMessage">กำลังโหลดแดชบอร์ด</strong>
            <p class="dashboard-loading-copy" id="dashboardLoadingDetail">กรุณารอสักครู่ ระบบกำลังเตรียมข้อมูลล่าสุดเพื่อแสดงบนหน้าจอ</p>
        </div>
    </div>
    <main class="shell" id="dashboardShell" aria-busy="true">
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
                        <input type="hidden" name="load_start" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="load_end" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="purchase_start" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="purchase_end" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
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
            <section class="dashboard" aria-label="กราฟแยกตามส่วนงาน">
                <div class="chart-section-switcher" aria-label="เลือกหมวดกราฟ">
                    <div class="chart-section-tabs" role="tablist" aria-label="หมวดกราฟ">
                        <button type="button" class="chart-section-tab is-active" id="chartSectionBudgetTab" role="tab" aria-selected="true" aria-controls="chartSectionBudget" data-chart-section-target="budget">งบประมาณ</button>
                        <button type="button" class="chart-section-tab" id="chartSectionPurchaseTab" role="tab" aria-selected="false" aria-controls="chartSectionPurchase" data-chart-section-target="purchase">จัดซื้อ</button>
                        <button type="button" class="chart-section-tab" id="chartSectionProductionTab" role="tab" aria-selected="false" aria-controls="chartSectionProduction" data-chart-section-target="production">การผลิต</button>
                        <button type="button" class="chart-section-tab" id="chartSectionWarehouseTab" role="tab" aria-selected="false" aria-controls="chartSectionWarehouse" data-chart-section-target="warehouse">คลังสินค้า</button>
                    </div>
                </div>

                <div class="chart-section is-active" id="chartSectionBudget" role="tabpanel" aria-labelledby="chartSectionBudgetTab" data-chart-section="budget">
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
                        <input type="hidden" name="chart_section" value="budget">
                        <input type="hidden" name="prod_start" value="<?php echo htmlspecialchars($selectedProdStart, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="prod_end" value="<?php echo htmlspecialchars($selectedProdEnd, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="load_start" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="load_end" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="purchase_start" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="purchase_end" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
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
                </div>

                <div class="chart-section" id="chartSectionPurchase" role="tabpanel" aria-labelledby="chartSectionPurchaseTab" data-chart-section="purchase">
                <form id="purchaseDateFilterForm" method="get" aria-label="ตัวกรองวันที่จัดซื้อ">
                    <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                    <input type="hidden" name="chart_section" value="purchase">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth >= 1 ? (int) $selectedMonth : ''; ?>">
                    <input type="hidden" name="budgetcd" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="budgetdesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="prod_start" value="<?php echo htmlspecialchars($selectedProdStart, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="prod_end" value="<?php echo htmlspecialchars($selectedProdEnd, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="load_start" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="load_end" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="filter-field">
                        <label for="purchaseStart">วันที่เริ่มต้น</label>
                        <input type="text" name="purchase_start" id="purchaseStart" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($purchaseStartDate), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="purchaseEnd">วันที่สิ้นสุด</label>
                        <input type="text" name="purchase_end" id="purchaseEnd" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($purchaseEndDate), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit">กรองข้อมูล</button>
                        <?php
                        $clearPurchaseDatesQuery = $filterQuery;
                        unset($clearPurchaseDatesQuery['purchase_start']);
                        unset($clearPurchaseDatesQuery['purchase_end']);
                        $clearPurchaseDatesQuery['chart_section'] = 'purchase';
                        $clearPurchaseDatesUrl = '?' . http_build_query($clearPurchaseDatesQuery);
                        ?>
                        <a class="secondary-action secondary-action-quiet" href="<?php echo htmlspecialchars($clearPurchaseDatesUrl, ENT_QUOTES, 'UTF-8'); ?>">กลับค่าเริ่มต้น</a>
                    </div>
                </form>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="purchaseMonthlyChartTitle">PR และ PO รายเดือน</h2>
                            <p class="panel-desc">นับ PR จาก `prh.prdate` และ PO จาก `impohd.podate` ตามช่วงวันที่เลือก โดยตัดรายการที่ถูกยกเลิกออก</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-context" aria-label="ตัวกรองวันที่จัดซื้อ">
                        <span class="chart-context-label">ข้อมูลที่กำลังแสดง</span>
                        <div class="filter-pill-row">
                            <span class="filter-pill">ปี <?php echo htmlspecialchars(formatDisplayYear($selectedYear), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="filter-pill">ช่วงวันที่ <?php echo htmlspecialchars($purchaseRangeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="purchaseMonthlyChart" role="img" aria-labelledby="purchaseMonthlyChartTitle" aria-describedby="purchaseMonthlyChartDesc purchaseMonthlyChartData"></canvas>
                    </div>
                    <p class="sr-only" id="purchaseMonthlyChartDesc">กราฟแสดงจำนวนเอกสาร PR และ PO รายเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="purchaseMonthlyChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' PR ' . formatNumber($purchaseMonthlyPrSeries[$monthIndex]) . ' รายการ และ PO ' . formatNumber($purchaseMonthlyPoSeries[$monthIndex]) . ' รายการ', ENT_QUOTES, 'UTF-8'); ?></li>
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
                                            <td><?php echo formatNumber($purchaseMonthlyPrSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatNumber($purchaseMonthlyPoSeries[$monthIndex]); ?></td>
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
                            <h2 class="panel-title" id="purchaseStatusChartTitle">สถานะรายการจัดซื้อ</h2>
                            <p class="panel-desc">สรุปรายการ PR detail ตามสถานะ PO: เปิดใหม่, รอรับ, กำลังรับสินค้า, จบการรับ และรายการที่ยังไม่เปิด P/O</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="purchaseStatusChart" role="img" aria-labelledby="purchaseStatusChartTitle" aria-describedby="purchaseStatusChartDesc purchaseStatusChartData"></canvas>
                    </div>
                    <p class="sr-only" id="purchaseStatusChartDesc">กราฟแสดงจำนวนรายการจัดซื้อแยกตามสถานะ PO</p>
                    <ul class="sr-only" id="purchaseStatusChartData">
                        <?php if (!empty($purchaseStatusRows)): ?>
                            <?php foreach ($purchaseStatusRows as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['postat_text'] . ' ' . formatNumber((float) $row['line_count']) . ' รายการ จำนวน PR ' . formatQuantity((float) $row['requested_qty']) . ' และจำนวนเปิดซื้อ ' . formatQuantity((float) $row['purchased_qty']), ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลสถานะจัดซื้อในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>สถานะ</th>
                                        <th>รายการ</th>
                                        <th>จำนวน PR</th>
                                        <th>จำนวนเปิดซื้อ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchaseStatusRows)): ?>
                                        <?php foreach ($purchaseStatusRows as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['postat_text'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['requested_qty']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['purchased_qty']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลสถานะจัดซื้อในปีที่เลือก</td>
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
                            <h2 class="panel-title" id="purchaseDepartmentChartTitle">Top 10 หน่วยงานตามรายการ PR</h2>
                            <p class="panel-desc">จัดอันดับหน่วยงานจาก `prd.k01 / cdp.depnm` ตามจำนวนรายการ PR detail พร้อมดูจำนวน PR และจำนวนที่เปิดซื้อใน tooltip</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="purchaseDepartmentChart" role="img" aria-labelledby="purchaseDepartmentChartTitle" aria-describedby="purchaseDepartmentChartDesc purchaseDepartmentChartData"></canvas>
                    </div>
                    <p class="sr-only" id="purchaseDepartmentChartDesc">กราฟแสดง Top 10 หน่วยงานที่มีรายการ PR มากที่สุดในปีที่เลือก</p>
                    <ul class="sr-only" id="purchaseDepartmentChartData">
                        <?php if (!empty($purchaseDepartmentRows)): ?>
                            <?php foreach ($purchaseDepartmentRows as $row): ?>
                                <?php
                                    $deptLabel = trim((string) $row['dept']) !== ''
                                        ? trim((string) $row['dept']) . ' - ' . trim((string) $row['depnm'])
                                        : trim((string) $row['depnm']);
                                ?>
                                <li><?php echo htmlspecialchars($deptLabel . ' PR ' . formatNumber((float) $row['pr_count']) . ' เอกสาร ' . formatNumber((float) $row['line_count']) . ' รายการ จำนวน PR ' . formatQuantity((float) $row['requested_qty']) . ' และเปิดซื้อแล้ว ' . formatNumber((float) $row['po_line_count']) . ' รายการ', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลจัดซื้อแยกตามหน่วยงานในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>หน่วยงาน</th>
                                        <th>PR</th>
                                        <th>รายการ</th>
                                        <th>เปิด PO แล้ว</th>
                                        <th>จำนวน PR</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchaseDepartmentRows)): ?>
                                        <?php foreach ($purchaseDepartmentRows as $row): ?>
                                            <?php
                                                $deptLabel = trim((string) $row['dept']) !== ''
                                                    ? trim((string) $row['dept']) . ' - ' . trim((string) $row['depnm'])
                                                    : trim((string) $row['depnm']);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($deptLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatNumber((float) $row['pr_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['po_line_count']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['requested_qty']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">ไม่พบข้อมูลจัดซื้อแยกตามหน่วยงานในปีที่เลือก</td>
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
                            <h2 class="panel-title" id="purchaseSupplierChartTitle">Top 10 Supplier ตามจำนวน PO</h2>
                            <p class="panel-desc">จัดอันดับ supplier จาก `impohd.spprcd / supplier.spprnme` ตามจำนวน PO ที่ผูกกับรายการ PR ในปีที่เลือก</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="purchaseSupplierChart" role="img" aria-labelledby="purchaseSupplierChartTitle" aria-describedby="purchaseSupplierChartDesc purchaseSupplierChartData"></canvas>
                    </div>
                    <p class="sr-only" id="purchaseSupplierChartDesc">กราฟแสดง Top 10 Supplier ที่มีจำนวน PO มากที่สุดในปีที่เลือก</p>
                    <ul class="sr-only" id="purchaseSupplierChartData">
                        <?php if (!empty($purchaseSupplierRows)): ?>
                            <?php foreach ($purchaseSupplierRows as $row): ?>
                                <?php
                                    $supplierLabel = trim((string) $row['spprcd']) !== ''
                                        ? trim((string) $row['spprcd']) . ' - ' . trim((string) $row['spprnme'])
                                        : trim((string) $row['spprnme']);
                                ?>
                                <li><?php echo htmlspecialchars($supplierLabel . ' PO ' . formatNumber((float) $row['po_count']) . ' ใบ PR ' . formatNumber((float) $row['pr_count']) . ' เอกสาร และรายการ ' . formatNumber((float) $row['line_count']), ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูล Supplier ในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Supplier</th>
                                        <th>PO</th>
                                        <th>PR</th>
                                        <th>รายการ</th>
                                        <th>จำนวนเปิดซื้อ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchaseSupplierRows)): ?>
                                        <?php foreach ($purchaseSupplierRows as $row): ?>
                                            <?php
                                                $supplierLabel = trim((string) $row['spprcd']) !== ''
                                                    ? trim((string) $row['spprcd']) . ' - ' . trim((string) $row['spprnme'])
                                                    : trim((string) $row['spprnme']);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($supplierLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatNumber((float) $row['po_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['pr_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['purchased_qty']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">ไม่พบข้อมูล Supplier ในปีที่เลือก</td>
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
                            <h2 class="panel-title" id="purchaseProductChartTitle">Top 10 สินค้าที่สั่งบ่อย</h2>
                            <p class="panel-desc">จัดอันดับสินค้าจาก `prd.iprod / iim.idesc1` ตามจำนวนรายการ PR detail เพื่อดูสินค้าที่ถูกขอซื้อซ้ำบ่อยที่สุด</p>
                        </div>
                        <div class="chip">จัดซื้อ</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="purchaseProductChart" role="img" aria-labelledby="purchaseProductChartTitle" aria-describedby="purchaseProductChartDesc purchaseProductChartData"></canvas>
                    </div>
                    <p class="sr-only" id="purchaseProductChartDesc">กราฟแสดง Top 10 สินค้าที่ถูกสั่งบ่อยที่สุดตามจำนวนรายการ PR detail</p>
                    <ul class="sr-only" id="purchaseProductChartData">
                        <?php if (!empty($purchaseProductRows)): ?>
                            <?php foreach ($purchaseProductRows as $row): ?>
                                <?php
                                    $productLabel = trim((string) $row['iprod']) !== ''
                                        ? trim((string) $row['iprod']) . ' - ' . trim((string) $row['idesc1'])
                                        : trim((string) $row['idesc1']);
                                ?>
                                <li><?php echo htmlspecialchars($productLabel . ' ' . formatNumber((float) $row['line_count']) . ' รายการ PR ' . formatNumber((float) $row['pr_count']) . ' เอกสาร จำนวนที่ขอ ' . formatQuantity((float) $row['requested_qty']), ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลสินค้าที่สั่งในปีที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>สินค้า</th>
                                        <th>รายการ</th>
                                        <th>PR</th>
                                        <th>เปิด PO แล้ว</th>
                                        <th>จำนวน PR</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($purchaseProductRows)): ?>
                                        <?php foreach ($purchaseProductRows as $row): ?>
                                            <?php
                                                $productLabel = trim((string) $row['iprod']) !== ''
                                                    ? trim((string) $row['iprod']) . ' - ' . trim((string) $row['idesc1'])
                                                    : trim((string) $row['idesc1']);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($productLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['pr_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['po_line_count']); ?></td>
                                                <td><?php echo formatQuantity((float) $row['requested_qty']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5">ไม่พบข้อมูลสินค้าที่สั่งในปีที่เลือก</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
                </div>

                <div class="chart-section" id="chartSectionProduction" role="tabpanel" aria-labelledby="chartSectionProductionTab" data-chart-section="production">
                <form id="productionDailyFilterForm" method="get" aria-label="ตัวกรองการผลิตรายวัน">
                    <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                    <input type="hidden" name="chart_section" value="production">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth >= 1 ? (int) $selectedMonth : ''; ?>">
                    <input type="hidden" name="budgetcd" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="budgetdesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="load_start" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="load_end" value="<?php echo htmlspecialchars($hasCustomLoadDates ? $loadingEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="purchase_start" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="purchase_end" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <div class="filter-field">
                        <label for="prodStart">วันที่เริ่มต้น</label>
                        <input type="text" name="prod_start" id="prodStart" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($productionDailyStartDate), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="prodEnd">วันที่สิ้นสุด</label>
                        <input type="text" name="prod_end" id="prodEnd" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($productionDailyEndDate), ENT_QUOTES, 'UTF-8'); ?>">
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
                </div>

                <div class="chart-section" id="chartSectionWarehouse" role="tabpanel" aria-labelledby="chartSectionWarehouseTab" data-chart-section="warehouse">
                <form id="loadingDateFilterForm" method="get" aria-label="ตัวกรองวันที่คลังสินค้า">
                    <input type="hidden" name="year" value="<?php echo (int) $selectedYear; ?>">
                    <input type="hidden" name="chart_section" value="warehouse">
                    <input type="hidden" name="month" value="<?php echo $selectedMonth >= 1 ? (int) $selectedMonth : ''; ?>">
                    <input type="hidden" name="budgetcd" value="<?php echo htmlspecialchars($selectedBudgetCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="budgetdesc" value="<?php echo htmlspecialchars($selectedBudgetDesc, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="prod_start" value="<?php echo htmlspecialchars($selectedProdStart, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="prod_end" value="<?php echo htmlspecialchars($selectedProdEnd, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="purchase_start" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseStartDate : '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="purchase_end" value="<?php echo htmlspecialchars($hasCustomPurchaseDates ? $purchaseEndDate : '', ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="filter-field">
                        <label for="loadStart">วันที่เริ่มต้น</label>
                        <input type="text" name="load_start" id="loadStart" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($loadingStartDate), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="loadEnd">วันที่สิ้นสุด</label>
                        <input type="text" name="load_end" id="loadEnd" autocomplete="off" placeholder="dd/MM/yyyy" data-date-picker="thai" readonly aria-haspopup="dialog" value="<?php echo htmlspecialchars(formatFilterDateInput($loadingEndDate), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit">กรองข้อมูล</button>
                        <?php
                        $clearLoadDatesQuery = $filterQuery;
                        unset($clearLoadDatesQuery['load_start']);
                        unset($clearLoadDatesQuery['load_end']);
                        $clearLoadDatesQuery['chart_section'] = 'warehouse';
                        $clearLoadDatesUrl = '?' . http_build_query($clearLoadDatesQuery);
                        ?>
                        <a class="secondary-action secondary-action-quiet" href="<?php echo htmlspecialchars($clearLoadDatesUrl, ENT_QUOTES, 'UTF-8'); ?>">กลับค่าเริ่มต้น</a>
                    </div>
                </form>

                <article class="panel panel-full">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="warehouseReceiptMonthlyChartTitle">น้ำหนักรับเข้าคลังรายเดือน</h2>
                            <p class="panel-desc">คำนวณน้ำหนักรับเข้าคลังจาก `rcv_qty` โดยถ้า `unit.uncd = KG` ใช้ค่าเดิม และถ้าไม่ใช่ให้นำไปคูณ `iioq` เพื่อแปลงเป็นกิโลกรัม</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="warehouseReceiptMonthlyChart" role="img" aria-labelledby="warehouseReceiptMonthlyChartTitle" aria-describedby="warehouseReceiptMonthlyChartDesc warehouseReceiptMonthlyChartData"></canvas>
                    </div>
                    <p class="sr-only" id="warehouseReceiptMonthlyChartDesc">กราฟแสดงน้ำหนักรับเข้าคลังและจำนวนเอกสารรับเข้าคลังสินค้าในแต่ละเดือนของปีที่เลือก</p>
                    <ul class="sr-only" id="warehouseReceiptMonthlyChartData">
                        <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                            <li><?php echo htmlspecialchars($monthLabels[$monthIndex] . ' น้ำหนักรับเข้า ' . formatQuantity($warehouseReceiptWeightSeries[$monthIndex]) . ' กก. และเอกสาร ' . formatNumber($warehouseReceiptDocSeries[$monthIndex]) . ' ใบ', ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endfor; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลรายเดือน</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>เดือน</th>
                                        <th>น้ำหนักรับเข้า (กก.)</th>
                                        <th>เอกสาร</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($monthIndex = 0; $monthIndex < count($monthLabels); $monthIndex++): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($monthLabels[$monthIndex], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo formatQuantity($warehouseReceiptWeightSeries[$monthIndex]); ?></td>
                                            <td><?php echo formatNumber($warehouseReceiptDocSeries[$monthIndex]); ?></td>
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
                            <h2 class="panel-title" id="warehouseReceiptCustomerChartTitle">Top 10 ลูกค้าที่ฝากตามน้ำหนัก</h2>
                            <p class="panel-desc">จัดอันดับลูกค้าตามน้ำหนักรับเข้าคลังรวมในช่วงวันที่ที่เลือก</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="warehouseReceiptCustomerChart" role="img" aria-labelledby="warehouseReceiptCustomerChartTitle" aria-describedby="warehouseReceiptCustomerChartDesc warehouseReceiptCustomerChartData"></canvas>
                    </div>
                    <p class="sr-only" id="warehouseReceiptCustomerChartDesc">กราฟแสดงลูกค้า Top 10 ที่ฝากสินค้าสูงสุด เรียงตามน้ำหนักรับเข้าคลังรวม</p>
                    <ul class="sr-only" id="warehouseReceiptCustomerChartData">
                        <?php if (!empty($warehouseReceiptCustomerChart['rows'])): ?>
                            <?php foreach ($warehouseReceiptCustomerChart['rows'] as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['custnme'] . ' น้ำหนักรับเข้า ' . formatQuantity((float) $row['total_weight']) . ' กก. เอกสาร ' . formatNumber((float) $row['doc_count']) . ' ใบ', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลลูกค้าที่ฝากในช่วงวันที่ที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ลูกค้า</th>
                                        <th>น้ำหนักรับเข้า (กก.)</th>
                                        <th>เอกสาร</th>
                                        <th>รายการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($warehouseReceiptCustomerChart['rows'])): ?>
                                        <?php foreach ($warehouseReceiptCustomerChart['rows'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['custnme'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_weight']); ?></td>
                                                <td><?php echo formatNumber((float) $row['doc_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลลูกค้าที่ฝากในช่วงวันที่ที่เลือก</td>
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
                            <h2 class="panel-title" id="warehouseReceiptProductChartTitle">Top 10 สินค้าที่ฝากตามน้ำหนัก</h2>
                            <p class="panel-desc">จัดอันดับสินค้าตามน้ำหนักรับเข้าคลังรวมในช่วงวันที่ที่เลือก</p>
                        </div>
                        <div class="chip">คลังสินค้า</div>
                    </div>
                    <div class="chart-wrap tall">
                        <canvas id="warehouseReceiptProductChart" role="img" aria-labelledby="warehouseReceiptProductChartTitle" aria-describedby="warehouseReceiptProductChartDesc warehouseReceiptProductChartData"></canvas>
                    </div>
                    <p class="sr-only" id="warehouseReceiptProductChartDesc">กราฟแสดงสินค้า Top 10 ที่ฝากสูงสุด เรียงตามน้ำหนักรับเข้าคลังรวม</p>
                    <ul class="sr-only" id="warehouseReceiptProductChartData">
                        <?php if (!empty($warehouseReceiptProductChart['rows'])): ?>
                            <?php foreach ($warehouseReceiptProductChart['rows'] as $row): ?>
                                <li><?php echo htmlspecialchars((string) $row['idesc1'] . ' น้ำหนักรับเข้า ' . formatQuantity((float) $row['total_weight']) . ' กก. เอกสาร ' . formatNumber((float) $row['doc_count']) . ' ใบ', ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li>ไม่พบข้อมูลสินค้าที่ฝากในช่วงวันที่ที่เลือก</li>
                        <?php endif; ?>
                    </ul>
                    <details class="data-details">
                        <summary>ดูข้อมูลเป็นตาราง</summary>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>สินค้า</th>
                                        <th>น้ำหนักรับเข้า (กก.)</th>
                                        <th>เอกสาร</th>
                                        <th>รายการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($warehouseReceiptProductChart['rows'])): ?>
                                        <?php foreach ($warehouseReceiptProductChart['rows'] as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) $row['idesc1'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatQuantity((float) $row['total_weight']); ?></td>
                                                <td><?php echo formatNumber((float) $row['doc_count']); ?></td>
                                                <td><?php echo formatNumber((float) $row['line_count']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">ไม่พบข้อมูลสินค้าที่ฝากในช่วงวันที่ที่เลือก</td>
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
                </div>
            </section>
        <?php endif; ?>

        <div class="footer-note">ข้อมูลดึงจากฐาน M_FOOD ตามปีที่เลือก และรีเฟรชอัตโนมัติทุก 10 นาที หรือเมื่อผู้ใช้เปลี่ยนปีและกดอัปเดตข้อมูล</div>
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
    <script src="assets/js/dashboard.js?v=20260616-loading-overlay"></script>
</body>
</html>
