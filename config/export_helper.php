<?php
/**
 * config/export_helper.php
 * Helper untuk export CSV & XLS
 */

/**
 * Validasi periode dari & sampai (pass-by-reference)
 */
function export_validate_periode(&$dari, &$sampai) {
    $dari   = $_GET['dari']   ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');

    $d1 = DateTime::createFromFormat('Y-m-d', $dari);
    $d2 = DateTime::createFromFormat('Y-m-d', $sampai);

    if (!$d1 || $d1->format('Y-m-d') !== $dari) {
        $dari = date('Y-m-01');
    }
    if (!$d2 || $d2->format('Y-m-d') !== $sampai) {
        $sampai = date('Y-m-d');
    }

    if (strtotime($dari) > strtotime($sampai)) {
        [$dari, $sampai] = [$sampai, $dari];
    }
}

/**
 * Ambil format dari URL, default xls
 */
function export_format() {
    $f = strtolower($_GET['format'] ?? 'xls');
    return in_array($f, ['csv', 'xls']) ? $f : 'xls';
}

/**
 * Kirim HTTP header untuk download
 */
function export_send_headers($filename, $format) {
    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    } else {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    }
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Mulai output CSV (dengan BOM UTF-8)
 */
function csv_start() {
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
    return $fh;
}

/**
 * Tulis satu baris CSV
 */
function csv_row($fh, array $row) {
    fputcsv($fh, $row);
}