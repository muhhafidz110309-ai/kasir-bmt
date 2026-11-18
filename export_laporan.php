<?php
require_once 'config/database.php';
require_once 'config/export_helper.php';
requireLogin();

export_validate_periode($dari, $sampai);
$format = export_format();

/* ============================================================
   QUERY DATA
   ============================================================ */

/* ===== REKAP PENJUALAN ===== */
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS jml, COALESCE(SUM(total),0) AS omzet,
           COALESCE(AVG(total),0) AS rata
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$rekapJual = $stmt->fetch();

/* ===== LABA KOTOR ===== */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM((d.harga - p.harga_beli) * d.qty),0) AS laba
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$labaKotor = (float)$stmt->fetchColumn();

/* ===== DETAIL PRODUK TERJUAL ===== */
$stmt = $pdo->prepare("
    SELECT p.id, p.kode, p.nama, p.kategori,
           SUM(d.qty) AS qty, SUM(d.subtotal) AS omzet,
           SUM((d.harga - p.harga_beli) * d.qty) AS laba
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY p.id, p.kode, p.nama, p.kategori
    ORDER BY omzet DESC
");
$stmt->execute([$dari, $sampai]);
$detailProduk = $stmt->fetchAll();

/* ===== REKAP HARIAN ===== */
$stmt = $pdo->prepare("
    SELECT DATE(tanggal) AS tgl, COUNT(*) AS jml, SUM(total) AS omzet
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
    GROUP BY DATE(tanggal)
    ORDER BY tgl DESC
");
$stmt->execute([$dari, $sampai]);
$harian = $stmt->fetchAll();

/* ===== DAFTAR TRANSAKSI ===== */
$stmt = $pdo->prepare("
    SELECT t.kode AS kode_trx, t.tanggal, u.nama AS kasir, t.metode_pembayaran,
           t.total AS total_trx, t.bayar, t.kembalian, t.keterangan,
           GROUP_CONCAT(CONCAT(d.qty, 'x ', p.nama) ORDER BY p.nama SEPARATOR '\n') AS daftar_item
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    JOIN transaksi_detail d ON d.transaksi_id = t.id
    JOIN produk p ON p.id = d.produk_id
    WHERE t.jenis='penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY t.id, t.kode, t.tanggal, u.nama, t.metode_pembayaran, t.total, t.bayar, t.kembalian, t.keterangan
    ORDER BY t.tanggal DESC
");
$stmt->execute([$dari, $sampai]);
$daftarTransaksi = $stmt->fetchAll();

/* ===== PENGELUARAN ===== */
$totalPengeluaran = 0.0;
$rekapKeluar = ['total' => 0, 'jml' => 0];
$pengeluaranByKategori = [];
$daftarPengeluaran = [];

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(jumlah),0) AS total, COUNT(*) AS jml
        FROM pengeluaran
        WHERE DATE(tanggal) BETWEEN ? AND ?
    ");
    $stmt->execute([$dari, $sampai]);
    $rekapKeluar = $stmt->fetch();
    $totalPengeluaran = (float)($rekapKeluar['total'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT kategori, COUNT(*) AS jml, COALESCE(SUM(jumlah),0) AS total
        FROM pengeluaran
        WHERE DATE(tanggal) BETWEEN ? AND ?
        GROUP BY kategori
        ORDER BY total DESC
    ");
    $stmt->execute([$dari, $sampai]);
    $pengeluaranByKategori = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT p.*, u.nama AS petugas
        FROM pengeluaran p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE DATE(p.tanggal) BETWEEN ? AND ?
        ORDER BY p.tanggal DESC, p.id DESC
        LIMIT 100
    ");
    $stmt->execute([$dari, $sampai]);
    $daftarPengeluaran = $stmt->fetchAll();
} catch (Exception $e) {}

$labaBersih = $labaKotor - $totalPengeluaran;

/* ===== LAPORAN HUTANG (per konsumen) ===== */
$stmt = $pdo->prepare("
    SELECT nama,
           COUNT(*)         AS jml_item,
           SUM(qty)         AS total_qty,
           SUM(harga * qty) AS total_belanja,
           SUM(dibayar)     AS total_dibayar,
           SUM(sisa)        AS total_sisa,
           MIN(tanggal)     AS tgl_awal,
           MAX(tanggal)     AS tgl_akhir
    FROM hutang
    WHERE DATE(tanggal) BETWEEN ? AND ?
    GROUP BY nama
    ORDER BY
      CASE WHEN SUM(sisa) > 0 THEN 0 ELSE 1 END,
      MAX(tanggal) DESC,
      nama ASC
");
$stmt->execute([$dari, $sampai]);
$hutangGroup = $stmt->fetchAll();
$totalHutang = array_sum(array_map(fn($h) => (float)$h['total_sisa'], $hutangGroup));

$detailByKonsumen = [];
if ($hutangGroup) {
    $namaList = array_column($hutangGroup, 'nama');
    $placeholders = implode(',', array_fill(0, count($namaList), '?'));
    $stmt = $pdo->prepare("
        SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
        FROM hutang h
        JOIN produk p ON p.id = h.produk_id
        WHERE h.nama IN ($placeholders)
          AND DATE(h.tanggal) BETWEEN ? AND ?
        ORDER BY h.nama ASC, h.sisa > 0 DESC, h.id DESC
    ");
    $stmt->execute(array_merge($namaList, [$dari, $sampai]));
    foreach ($stmt->fetchAll() as $row) {
        $detailByKonsumen[$row['nama']][] = $row;
    }
}

/* ============================================================
   OUTPUT CSV
   ============================================================ */
if ($format === 'csv') {
    export_send_headers("Laporan_Penjualan_{$dari}_sd_{$sampai}", 'csv');
    $fh = csv_start();

    csv_row($fh, ['LAPORAN PENJUALAN & PENGELUARAN']);
    csv_row($fh, ['Periode', date('d/m/Y', strtotime($dari)) . ' s/d ' . date('d/m/Y', strtotime($sampai))]);
    csv_row($fh, []);

    /* ----- RINGKASAN ----- */
    csv_row($fh, ['RINGKASAN']);
    csv_row($fh, ['Keterangan', 'Nilai']);
    csv_row($fh, ['Total Omzet',       (float)$rekapJual['omzet']]);
    csv_row($fh, ['Total Transaksi',   (int)$rekapJual['jml']]);
    csv_row($fh, ['Rata-rata / Trx',   round($rekapJual['rata'])]);
    csv_row($fh, ['Laba Kotor',        round($labaKotor)]);
    csv_row($fh, ['Total Pengeluaran', round($totalPengeluaran)]);
    csv_row($fh, ['Laba Bersih',       round($labaBersih)]);
    csv_row($fh, []);

    /* ----- DETAIL PRODUK ----- */
    if ($detailProduk) {
        csv_row($fh, ['DETAIL PRODUK TERJUAL']);
        csv_row($fh, ['Kode', 'Nama Produk', 'Kategori', 'Qty', 'Omzet', 'Laba']);
        $tQty = 0; $tOmzP = 0; $tLaba = 0;
        foreach ($detailProduk as $dp) {
            $tQty  += (int)$dp['qty'];
            $tOmzP += (float)$dp['omzet'];
            $tLaba += (float)$dp['laba'];
            csv_row($fh, [
                $dp['kode'], $dp['nama'], $dp['kategori'] ?? '-',
                (int)$dp['qty'], (float)$dp['omzet'], (float)$dp['laba'],
            ]);
        }
        csv_row($fh, ['TOTAL', '', '', $tQty, $tOmzP, $tLaba]);
        csv_row($fh, []);
    }

    /* ----- PENGELUARAN ----- */
    if ($daftarPengeluaran) {
        csv_row($fh, ['DAFTAR PENGELUARAN']);
        csv_row($fh, ['Tanggal', 'Kategori', 'Keterangan', 'Petugas', 'Jumlah']);
        foreach ($daftarPengeluaran as $p) {
            csv_row($fh, [
                date('d/m/Y', strtotime($p['tanggal'])),
                $p['kategori'], $p['keterangan'], $p['petugas'] ?? '-',
                (float)$p['jumlah'],
            ]);
        }
        csv_row($fh, ['TOTAL', '', '', '', $totalPengeluaran]);
        csv_row($fh, []);
    }

    /* ----- REKAP PENGELUARAN PER KATEGORI ----- */
    if ($pengeluaranByKategori) {
        csv_row($fh, ['REKAP PENGELUARAN PER KATEGORI']);
        csv_row($fh, ['Kategori', 'Jumlah Catatan', 'Total']);
        foreach ($pengeluaranByKategori as $pk) {
            csv_row($fh, [$pk['kategori'], (int)$pk['jml'], (float)$pk['total']]);
        }
        csv_row($fh, []);
    }

    /* ----- HUTANG ----- */
    if ($hutangGroup) {
        csv_row($fh, ['DAFTAR HUTANG (per Konsumen)']);
        csv_row($fh, ['Nama Konsumen', 'Jml Item', 'Total Belanja', 'Sudah Dibayar', 'Sisa Hutang']);
        foreach ($hutangGroup as $h) {
            csv_row($fh, [
                $h['nama'], (int)$h['jml_item'],
                (float)$h['total_belanja'], (float)$h['total_dibayar'], (float)$h['total_sisa'],
            ]);
        }
        csv_row($fh, ['TOTAL HUTANG', '', '', '', $totalHutang]);
        csv_row($fh, []);

        csv_row($fh, ['DETAIL HUTANG']);
        csv_row($fh, ['Nama Konsumen', 'Kode', 'Produk', 'Qty', 'Harga', 'Total', 'Dibayar', 'Sisa', 'Tanggal']);
        foreach ($hutangGroup as $h) {
            $details = $detailByKonsumen[$h['nama']] ?? [];
            foreach ($details as $d) {
                $subtotal = (float)$d['harga'] * (int)$d['qty'];
                csv_row($fh, [
                    $h['nama'], $d['kode_produk'], $d['nama_produk'],
                    (int)$d['qty'], (float)$d['harga'],
                    $subtotal, (float)$d['dibayar'], (float)$d['sisa'],
                    date('d/m/Y', strtotime($d['tanggal'])),
                ]);
            }
        }
        csv_row($fh, []);
    }

    /* ----- HARIAN ----- */
    csv_row($fh, ['REKAP HARIAN']);
    csv_row($fh, ['Tanggal', 'Jumlah Transaksi', 'Omzet', 'Rata-rata / Transaksi']);
    $tJml = 0; $tOmz = 0;
    foreach ($harian as $h) {
        $tJml += (int)$h['jml'];
        $tOmz += (float)$h['omzet'];
        $avg  = $h['jml'] > 0 ? $h['omzet'] / $h['jml'] : 0;
        csv_row($fh, [
            date('d/m/Y', strtotime($h['tgl'])),
            (int)$h['jml'], (float)$h['omzet'], round($avg),
        ]);
    }
    csv_row($fh, ['TOTAL', $tJml, $tOmz, $tJml ? round($tOmz / $tJml) : 0]);
    csv_row($fh, []);

    /* ----- TRANSAKSI ----- */
    csv_row($fh, ['DAFTAR TRANSAKSI']);
    csv_row($fh, ['Kode TRX', 'Tanggal', 'Kasir', 'Metode', 'Item yang Dibeli', 'Total', 'Bayar', 'Kembalian', 'Keterangan']);
    $tO = 0; $tB = 0; $tK = 0;
    foreach ($daftarTransaksi as $d) {
        $tO += (float)$d['total_trx'];
        $tB += (float)$d['bayar'];
        $tK += (float)$d['kembalian'];
        csv_row($fh, [
            $d['kode_trx'], date('d/m/Y H:i', strtotime($d['tanggal'])),
            $d['kasir'] ?? '-', ucfirst($d['metode_pembayaran'] ?? 'Tunai'),
            $d['daftar_item'], (float)$d['total_trx'],
            (float)$d['bayar'], (float)$d['kembalian'], $d['keterangan'] ?: '-',
        ]);
    }
    csv_row($fh, ['TOTAL', '', '', '', '', $tO, $tB, $tK, '']);

    fclose($fh);
    exit;
}

/* ============================================================
   OUTPUT XLS (HTML table) — 8 KOLOM
   ============================================================ */
export_send_headers("Laporan_Penjualan_{$dari}_sd_{$sampai}", 'xls');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>Laporan Penjualan</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
     <x:Print>
      <x:ValidPrinterInfo/>
      <x:PaperSizeIndex>9</x:PaperSizeIndex>
      <x:Scale>85</x:Scale>
      <x:FitWidth>1</x:FitWidth>
     </x:Print>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1e293b; }
  table { border-collapse: collapse; width: 100%; table-layout: fixed; }

  th, td {
    border: 2px solid #000000;
    padding: 5px 7px;
    vertical-align: top;
    word-wrap: break-word;
  }

  th {
    background: #065f46; color: #fff; font-weight: 700;
    text-align: center; font-size: 11.5px; padding: 7px 6px;
    border: 2px solid #000000;
  }

  .title {
    font-size: 22px; font-weight: 900; text-align: center;
    background: #065f46; color: #fff; padding: 16px 12px;
    border: 3px solid #000000; letter-spacing: 1px;
    text-transform: uppercase;
  }
  .subtitle {
    font-size: 13px; text-align: center;
    background: #d1fae5; color: #065f46; font-weight: bold;
    border: 2px solid #000000; padding: 8px;
  }

  .section {
    font-size: 22px; font-weight: 900;
    color: #000000; background: #d1fae5;
    padding: 14px 16px;
    border: 3px solid #000000;
    text-transform: uppercase; letter-spacing: 1.5px;
  }

  /* CSS UNTUK GARIS BATAS TOTAL */
  .total-row td {
    background: #ecfdf5; font-weight: bold;
    border: 2px solid #000000;
    border-top: 4px solid #000000; 
  }

  .spacer td { border: none; height: 8px; }

  .num    { text-align: right; mso-number-format: "#,##0"; }
  .center { text-align: center; }
  .text-green { color: #047857; font-weight: bold; }
  .text-red   { color: #b91c1c; font-weight: bold; }
  .item-list  { white-space: pre-wrap; font-family: monospace; font-size: 10px; line-height: 1.3; }

  .ringkasan-label { font-weight: bold; background: #f8fafc; }
  .ringkasan-value { mso-number-format: "#,##0"; font-weight: bold; }

  .konsumen-row td {
    background: #f0fdf4; font-weight: bold; color: #065f46;
    border: 2px solid #000000;
  }

  .col-wide { width: 28%; }
  .col-mid  { width: 16%; }
  .col-sm   { width: 10%; }
</style>
</head>
<body>

<table>
  <!-- ========== HEADER DOKUMEN ========== -->
  <tr><td colspan="8" class="title">LAPORAN PENJUALAN &amp; PENGELUARAN</td></tr>
  <tr>
    <td colspan="8" class="subtitle">
      Periode: <?= date('d/m/Y', strtotime($dari)) ?> s/d <?= date('d/m/Y', strtotime($sampai)) ?>
    </td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <!-- ========== RINGKASAN ========== -->
  <tr><td colspan="8" class="section">Ringkasan</td></tr>
  <tr>
    <th colspan="5">Keterangan</th>
    <th colspan="3">Nilai</th>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Total Omzet</td>
    <td colspan="3" class="ringkasan-value"><?= $rekapJual['omzet'] ?></td>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Total Transaksi</td>
    <td colspan="3" class="center"><?= $rekapJual['jml'] ?> transaksi</td>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Rata-rata per Transaksi</td>
    <td colspan="3" class="ringkasan-value"><?= round($rekapJual['rata']) ?></td>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Laba Kotor</td>
    <td colspan="3" class="ringkasan-value text-green"><?= round($labaKotor) ?></td>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Total Pengeluaran</td>
    <td colspan="3" class="ringkasan-value text-red"><?= round($totalPengeluaran) ?></td>
  </tr>
  <tr>
    <td colspan="5" class="ringkasan-label">Laba Bersih</td>
    <td colspan="3" class="ringkasan-value <?= $labaBersih < 0 ? 'text-red' : 'text-green' ?>">
      <?= round($labaBersih) ?>
    </td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

    <!-- ========== DETAIL PRODUK TERJUAL ========== -->
  <?php if ($detailProduk): ?>
  <tr><td colspan="8" class="section">Detail Produk Terjual</td></tr>
  <tr>
    <!-- Header disesuaikan agar pas 8 kolom -->
    <th class="col-sm">Kode</th>
    <th class="col-wide">Nama Produk</th>
    <th class="col-mid">Kategori</th>
    <th class="col-sm">Qty</th>
    <th class="col-mid">Omzet</th>
    <th colspan="3" class="col-mid">Laba</th> 
  </tr>
  <?php
  $totQty = 0; $totOmzet = 0; $totLaba = 0;
  foreach ($detailProduk as $dp):
      $totQty   += (int)$dp['qty'];
      $totOmzet += (float)$dp['omzet'];
      $totLaba  += (float)$dp['laba'];
  ?>
  <tr>
    <td class="center"><?= htmlspecialchars($dp['kode']) ?></td>
    <td><?= htmlspecialchars($dp['nama']) ?></td>
    <td class="center"><?= htmlspecialchars($dp['kategori'] ?? '-') ?></td>
    <td class="center"><?= (int)$dp['qty'] ?></td>
    <td class="num"><?= $dp['omzet'] ?></td>
    <!-- Data Laba dibuat colspan 3 agar mentok ke kanan -->
    <td colspan="3" class="num text-green" style="text-align: left; padding-left: 7px;"><?= $dp['laba'] ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="3" class="center">TOTAL</td>
    <td class="center"><?= $totQty ?></td>
    <td class="num"><?= $totOmzet ?></td>
    <!-- Total Laba juga dibuat colspan 3 -->
    <td colspan="3" class="num text-green" style="text-align: left; padding-left: 7px;"><?= $totLaba ?></td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>
  <?php endif; ?>

  <!-- ========== LAPORAN PENGELUARAN ========== -->
  <?php if ($daftarPengeluaran): ?>
  <tr><td colspan="8" class="section">Laporan Pengeluaran</td></tr>
  <tr>
    <th class="col-sm">Tanggal</th>
    <th class="col-mid">Kategori</th>
    <th class="col-wide">Keterangan</th>
    <th class="col-mid">Petugas</th>
    <th class="col-mid">Jumlah</th>
    <th colspan="3"></th>
  </tr>
  <?php foreach ($daftarPengeluaran as $p): ?>
  <tr>
    <td class="center"><?= date('d/m/Y', strtotime($p['tanggal'])) ?></td>
    <td class="center"><?= htmlspecialchars($p['kategori']) ?></td>
    <td><?= htmlspecialchars($p['keterangan']) ?></td>
    <td><?= htmlspecialchars($p['petugas'] ?? '-') ?></td>
    <td class="num text-red"><?= $p['jumlah'] ?></td>
    <td colspan="3"></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="4" class="center">TOTAL PENGELUARAN</td>
    <td class="num text-red"><?= $totalPengeluaran ?></td>
    <td colspan="3"></td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <!-- REKAP PENGELUARAN PER KATEGORI -->
  <?php if ($pengeluaranByKategori): ?>
  <tr><td colspan="8" class="section">Rekap Pengeluaran per Kategori</td></tr>
  <tr>
    <th colspan="3">Kategori</th>
    <th class="center">Jumlah Catatan</th>
    <th class="num" colspan="2">Total</th>
    <th colspan="2"></th>
  </tr>
  <?php foreach ($pengeluaranByKategori as $pk): ?>
  <tr>
    <td colspan="3"><?= htmlspecialchars($pk['kategori']) ?></td>
    <td class="center"><?= (int)$pk['jml'] ?>x</td>
    <td class="num text-red" colspan="2"><?= $pk['total'] ?></td>
    <td colspan="2"></td>
  </tr>
  <?php endforeach; ?>
  <tr class="spacer"><td colspan="8"></td></tr>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ========== DAFTAR HUTANG ========== -->
  <?php if ($hutangGroup): ?>
  <tr><td colspan="8" class="section">Daftar Hutang (per Konsumen)</td></tr>
  <tr>
    <th colspan="3">Nama Konsumen</th>
    <th class="center">Jml Item</th>
    <th class="num">Total Belanja</th>
    <th class="num">Sudah Dibayar</th>
    <th class="num text-red" colspan="2">Sisa Hutang</th>
  </tr>
  <?php foreach ($hutangGroup as $h):
      $lunas = ((float)$h['total_sisa'] <= 0);
  ?>
  <tr>
    <td colspan="3"><?= htmlspecialchars($h['nama']) ?></td>
    <td class="center"><?= (int)$h['jml_item'] ?> item</td>
    <td class="num"><?= $h['total_belanja'] ?></td>
    <td class="num text-green"><?= $h['total_dibayar'] ?></td>
    <td class="num <?= $lunas ? 'text-green' : 'text-red' ?>" colspan="2">
      <?= $lunas ? 'LUNAS' : $h['total_sisa'] ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="6" class="center">TOTAL HUTANG</td>
    <td class="num text-red" colspan="2"><?= $totalHutang ?></td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <!-- DETAIL HUTANG -->
  <tr><td colspan="8" class="section">Detail Hutang</td></tr>
  <tr>
    <th class="col-sm">Kode</th>
    <th class="col-wide">Produk</th>
    <th class="col-sm">Qty</th>
    <th class="num">Harga</th>
    <th class="num">Total</th>
    <th class="num">Sisa</th>
    <th class="center" colspan="2">Tgl</th>
  </tr>
  <?php foreach ($hutangGroup as $h):
      $details = $detailByKonsumen[$h['nama']] ?? [];
      if (!$details) continue;
  ?>
    <tr class="konsumen-row">
      <td colspan="8">👤 <?= htmlspecialchars($h['nama']) ?></td>
    </tr>
    <?php foreach ($details as $d):
        $subtotal = (float)$d['harga'] * (int)$d['qty'];
        $isLunas  = ((float)$d['sisa'] <= 0);
    ?>
    <tr>
      <td class="center"><?= htmlspecialchars($d['kode_produk']) ?></td>
      <td><?= htmlspecialchars($d['nama_produk']) ?></td>
      <td class="center"><?= (int)$d['qty'] ?> <?= htmlspecialchars($d['satuan']) ?></td>
      <td class="num"><?= $d['harga'] ?></td>
      <td class="num"><?= $subtotal ?></td>
      <td class="num <?= $isLunas ? 'text-green' : 'text-red' ?>">
        <?= $isLunas ? '0' : $d['sisa'] ?>
      </td>
      <td class="center" colspan="2"><?= date('d/m/Y', strtotime($d['tanggal'])) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <tr class="spacer"><td colspan="8"></td></tr>
  <?php endif; ?>

  <!-- ========== REKAP HARIAN ========== -->
  <tr><td colspan="8" class="section">Rekap Harian</td></tr>
  <tr>
    <th colspan="3">Tanggal</th>
    <th class="center">Jumlah Transaksi</th>
    <th class="num">Omzet</th>
    <th class="num" colspan="3">Rata-rata / Transaksi</th>
  </tr>
  <?php
  $totHarianJml = 0; $totHarianOmzet = 0;
  foreach ($harian as $h):
      $totHarianJml   += (int)$h['jml'];
      $totHarianOmzet += (float)$h['omzet'];
      $avg = $h['jml'] > 0 ? $h['omzet'] / $h['jml'] : 0;
  ?>
  <tr>
    <td colspan="3"><?= date('d/m/Y', strtotime($h['tgl'])) ?></td>
    <td class="center"><?= $h['jml'] ?></td>
    <td class="num"><?= $h['omzet'] ?></td>
    <td class="num" colspan="3"><?= round($avg) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="3" class="center">TOTAL</td>
    <td class="center"><?= $totHarianJml ?></td>
    <td class="num"><?= $totHarianOmzet ?></td>
    <td class="num" colspan="3"><?= $totHarianJml ? round($totHarianOmzet / $totHarianJml) : 0 ?></td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <!-- ========== DAFTAR TRANSAKSI ========== -->
  <tr><td colspan="8" class="section">Daftar Transaksi</td></tr>
  <tr>
    <th class="col-sm">Kode TRX</th>
    <th class="col-sm">Tanggal</th>
    <th class="col-mid">Kasir</th>
    <th class="col-sm">Metode</th>
    <th class="col-wide">Item yang Dibeli</th>
    <th class="num">Total</th>
    <th class="num">Bayar</th>
    <th class="num">Kembalian</th>
  </tr>
  <?php
  $totO = 0; $totB = 0; $totK = 0;
  foreach ($daftarTransaksi as $d):
      $totO += (float)$d['total_trx'];
      $totB += (float)$d['bayar'];
      $totK += (float)$d['kembalian'];
      $items = str_replace("\n", "<br>", htmlspecialchars($d['daftar_item']));
  ?>
  <tr>
    <td class="center"><?= htmlspecialchars($d['kode_trx']) ?></td>
    <td class="center"><?= date('d/m/Y H:i', strtotime($d['tanggal'])) ?></td>
    <td><?= htmlspecialchars($d['kasir'] ?? '-') ?></td>
    <td class="center"><?= htmlspecialchars(ucfirst($d['metode_pembayaran'] ?? 'Tunai')) ?></td>
    <td class="item-list"><?= $items ?></td>
    <td class="num"><?= $d['total_trx'] ?></td>
    <td class="num"><?= $d['bayar'] ?></td>
    <td class="num"><?= $d['kembalian'] ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="5" class="center">TOTAL</td>
    <td class="num"><?= $totO ?></td>
    <td class="num"><?= $totB ?></td>
    <td class="num"><?= $totK ?></td>
  </tr>
</table>

</body>
</html>