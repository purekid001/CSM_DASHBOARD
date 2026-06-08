<?php

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

function buildMonthlySeriesByGroup($rows, $groupKey, $groupValue, $valueKey)
{
    $series = array_fill(1, 12, 0);

    foreach ($rows as $row) {
        $rowGroupValue = isset($row[$groupKey]) ? (string) $row[$groupKey] : '';
        if ($rowGroupValue !== (string) $groupValue) {
            continue;
        }

        $month = isset($row['month_no']) ? (int) $row['month_no'] : 0;
        if ($month >= 1 && $month <= 12) {
            $series[$month] += (float) (isset($row[$valueKey]) ? $row[$valueKey] : 0);
        }
    }

    return array_values($series);
}

function groupRowsByKey($rows, $groupKey)
{
    $grouped = array();

    foreach ($rows as $row) {
        $groupValue = isset($row[$groupKey]) ? (string) $row[$groupKey] : '';
        if (!isset($grouped[$groupValue])) {
            $grouped[$groupValue] = array();
        }

        $grouped[$groupValue][] = $row;
    }

    return $grouped;
}

function buildTopItemsChart($rows, $labelBuilder, $valueKey, $limit)
{
    $labels = array();
    $values = array();
    $topRows = array_slice($rows, 0, $limit);

    foreach ($topRows as $row) {
        $labels[] = (string) $labelBuilder($row);
        $values[] = (float) (isset($row[$valueKey]) ? $row[$valueKey] : 0);
    }

    return array(
        'labels' => $labels,
        'values' => $values,
        'rows' => $topRows,
    );
}

function formatCurrency($amount)
{
    return number_format($amount, 2);
}

function formatNumber($amount)
{
    return number_format($amount, 0);
}

function formatQuantity($amount)
{
    $formatted = number_format((float) $amount, 2, '.', ',');

    return rtrim(rtrim($formatted, '0'), '.');
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

function buildProductionDailyTotalSeries($rows, $dateKeys)
{
    $totals = array_fill_keys($dateKeys, 0);

    foreach ($rows as $row) {
        $dateKey = isset($row['date_key']) ? (string) $row['date_key'] : '';
        if ($dateKey !== '' && isset($totals[$dateKey])) {
            $totals[$dateKey] += (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
        }
    }

    return array_values($totals);
}

function buildProductionDailyHighlights($rows, $dateKeys, $limitPerDay)
{
    $grouped = array();

    foreach ($rows as $row) {
        $dateKey = isset($row['date_key']) ? (string) $row['date_key'] : '';
        if ($dateKey === '') {
            continue;
        }

        $productLabel = trim((string) (isset($row['idesc1']) ? $row['idesc1'] : ''));
        $productName = $productLabel !== '' ? $productLabel : 'ไม่ระบุสินค้า';

        if (!isset($grouped[$dateKey])) {
            $grouped[$dateKey] = array();
        }

        $grouped[$dateKey][$productName] = (isset($grouped[$dateKey][$productName]) ? $grouped[$dateKey][$productName] : 0)
            + (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
    }

    $highlights = array();

    foreach ($dateKeys as $dateKey) {
        $dayHighlights = array();

        if (isset($grouped[$dateKey])) {
            arsort($grouped[$dateKey]);
            $topItems = array_slice($grouped[$dateKey], 0, $limitPerDay, true);

            foreach ($topItems as $label => $totalQty) {
                $dayHighlights[] = $label . ' ' . formatQuantity($totalQty) . ' กก.';
            }
        }

        $highlights[] = $dayHighlights;
    }

    return $highlights;
}

function buildProductionDailyProductRows($rows)
{
    $grouped = array();

    foreach ($rows as $row) {
        $dateKey = isset($row['date_key']) ? (string) $row['date_key'] : '';
        $productLabel = trim((string) (isset($row['idesc1']) ? $row['idesc1'] : ''));
        $unitLabel = trim((string) (isset($row['unnm']) ? $row['unnm'] : ''));
        $productName = $productLabel !== '' ? $productLabel : 'ไม่ระบุสินค้า';
        $groupKey = $dateKey . '|' . $productName . '|' . $unitLabel;

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = array(
                'date_key' => $dateKey,
                'day_no' => isset($row['day_no']) ? (int) $row['day_no'] : 0,
                'idesc1' => $productName,
                'unnm' => $unitLabel,
                'total_kg' => 0,
            );
        }

        $grouped[$groupKey]['total_kg'] += (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
    }

    usort($grouped, function ($left, $right) {
        if ($left['date_key'] === $right['date_key']) {
            if ($left['total_kg'] === $right['total_kg']) {
                return strcmp($left['idesc1'], $right['idesc1']);
            }

            return ($left['total_kg'] < $right['total_kg']) ? 1 : -1;
        }

        return strcmp($left['date_key'], $right['date_key']);
    });

    return array_values($grouped);
}

function buildProductionStackedChart($rows, $dateKeys, $dateLabels, $labelKey, $fallbackLabel, $maxDatasets)
{
    $labelTotals = array();

    foreach ($rows as $row) {
        $rawLabel = trim((string) (isset($row[$labelKey]) ? $row[$labelKey] : ''));
        $normalizedLabel = $rawLabel !== '' ? $rawLabel : $fallbackLabel;
        $labelTotals[$normalizedLabel] = (isset($labelTotals[$normalizedLabel]) ? $labelTotals[$normalizedLabel] : 0)
            + (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
    }

    arsort($labelTotals);
    $topLabels = array_keys($labelTotals);
    if ($maxDatasets > 0) {
        $topLabels = array_slice($topLabels, 0, $maxDatasets);
    }
    $datasets = array();

    foreach ($topLabels as $datasetLabel) {
        $seriesMap = array_fill_keys($dateKeys, 0);

        foreach ($rows as $row) {
            $rowLabel = trim((string) (isset($row[$labelKey]) ? $row[$labelKey] : ''));
            $normalizedRowLabel = $rowLabel !== '' ? $rowLabel : $fallbackLabel;
            $dateKey = isset($row['date_key']) ? (string) $row['date_key'] : '';

            if ($normalizedRowLabel === $datasetLabel && isset($seriesMap[$dateKey])) {
                $seriesMap[$dateKey] += (float) (isset($row['total_kg']) ? $row['total_kg'] : 0);
            }
        }

        $datasets[] = array(
            'label' => $datasetLabel,
            'data' => array_values($seriesMap),
        );
    }

    return array(
        'labels' => $dateLabels,
        'datasets' => $datasets,
    );
}

function buildProductionLineChart($rows, $dateKeys, $dateLabels, $maxDatasets)
{
    return buildProductionStackedChart($rows, $dateKeys, $dateLabels, 'wdesc', 'ไม่ระบุไลน์ผลิต', $maxDatasets);
}

function buildProductionProductChart($rows, $dateKeys, $dateLabels, $maxDatasets)
{
    return buildProductionStackedChart($rows, $dateKeys, $dateLabels, 'idesc1', 'ไม่ระบุสินค้า', $maxDatasets);
}
