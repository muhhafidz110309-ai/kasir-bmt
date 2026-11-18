<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   DAFTAR KATEGORI BAKU + IKON BOOTSTRAP
   ============================================================ */
$KATEGORI_LIST = [
    'Operasional' => 'bi-gear-wide-connected',
    'Listrik'     => 'bi-lightning-charge-fill',
    'Gaji'        => 'bi-people-fill',
    'Transport'   => 'bi-truck',
    'ATK'         => 'bi-pencil-square',
    'Konsumsi'    => 'bi-cup-hot-fill',
    'Promosi'     => 'bi-megaphone-fill',
];

/* Sub-kategori khusus untuk Konsumsi */
$KONSUMSI_SUB = [
    'Makanan' => 'bi-egg-fried',
    'Minuman' => 'bi-cup-straw',
    'Es Krim' => 'bi-snow',
];

$KATEGORI_KEYS = array_keys($KATEGORI_LIST);

// Ambil kategori unik dari database agar kategori buatan user muncul di filter
$stmt = $pdo->query("SELECT DISTINCT kategori FROM pengeluaran WHERE kategori != ''");
$kategori_db = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($kategori_db as $kd) {
    if (!in_array($kd, $KATEGORI_KEYS)) {
        $KATEGORI_LIST[$kd] = 'bi-tag-fill'; // Ikon default untuk kategori buatan user
        $KATEGORI_KEYS[] = $kd;
    }
}

/* ============================================================
   SUMBER DANA — hanya dari hasil penjualan
   ============================================================ */
$SUMBER_DANA = [
    'key'   => 'kas_penjualan',
    'label' => 'Kas Penjualan',
    'icon'  => 'bi-cash-coin',
    'desc'  => 'Uang tunai hasil jual produk (makanan, minuman, es krim, ATK)',
    'color' => '#10b981',
];

/* ============================================================
   SIMPAN / UPDATE PENGELUARAN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['act'] ?? '', ['save', 'update'], true)) {
    $act            = $_POST['act'];
    $id             = (int)($_POST['id'] ?? 0);
    $tanggal        = trim($_POST['tanggal'] ?? '');
    $kategori       = trim($_POST['kategori'] ?? '');
    $subKategori    = trim($_POST['sub_kategori'] ?? '');
    $keterangan     = trim($_POST['keterangan'] ?? '');

    $nominal_raw = $_POST['nominal'] ?? '0';
    $nominal     = (float)str_replace(['.', ','], ['', '.'], $nominal_raw);

    $errors = [];

    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    if (!$d || $d->format('Y-m-d') !== $tanggal) {
        $errors[] = 'Format tanggal tidak valid.';
    }

    if ($kategori === '') {
        $errors[] = 'Kategori wajib diisi.';
    } elseif (mb_strlen($kategori) > 50) {
        $errors[] = 'Kategori maksimal 50 karakter.';
    }

    if ($nominal <= 0) {
        $errors[] = 'Nominal harus lebih dari 0.';
    } elseif ($nominal > 1000000000000) {
        $errors[] = 'Nominal terlalu besar.';
    }

    if (mb_strlen($keterangan) > 255) {
        $errors[] = 'Keterangan maksimal 255 karakter.';
    }

    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect('pengeluaran.php?' . http_build_query([
            'dari'   => $_POST['dari']   ?? date('Y-m-01'),
            'sampai' => $_POST['sampai'] ?? date('Y-m-d'),
        ]));
    }

    try {
        if ($act === 'save') {
            $kode = generateKode('OUT', $pdo, 'pengeluaran', 'kode');
            $stmt = $pdo->prepare("INSERT INTO pengeluaran
                                   (kode, tanggal, kategori, sumber_dana, sumber_keterangan,
                                    keterangan, nominal, user_id)
                                   VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $kode,
                $tanggal . ' ' . date('H:i:s'),
                $kategori,
                $SUMBER_DANA['key'],
                $subKategori !== '' ? $subKategori : null,
                $keterangan,
                $nominal,
                user()['id'],
            ]);
            flash('success', 'Pengeluaran ' . $kode . ' berhasil disimpan (dari Kas Penjualan).');
        } else {
            if ($id <= 0) {
                flash('error', 'ID pengeluaran tidak valid.');
                redirect('pengeluaran.php');
            }
            $stmt = $pdo->prepare("UPDATE pengeluaran
                                   SET tanggal = ?, kategori = ?, sumber_keterangan = ?, keterangan = ?, nominal = ?
                                   WHERE id = ?");
            $stmt->execute([
                $tanggal . ' ' . date('H:i:s'),
                $kategori,
                $subKategori !== '' ? $subKategori : null,
                $keterangan,
                $nominal,
                $id,
            ]);
            flash('success', 'Pengeluaran berhasil diperbarui.');
        }
    } catch (PDOException $e) {
        flash('error', 'Gagal menyimpan: ' . $e->getMessage());
    }

    redirect('pengeluaran.php?' . http_build_query([
        'dari'   => $_POST['dari']   ?? date('Y-m-01'),
        'sampai' => $_POST['sampai'] ?? date('Y-m-d'),
    ]));
}

/* ============================================================
   HAPUS PENGELUARAN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM pengeluaran WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Data pengeluaran berhasil dihapus.');
    } catch (PDOException $e) {
        flash('error', 'Gagal menghapus: ' . $e->getMessage());
    }
    redirect('pengeluaran.php?' . http_build_query([
        'dari'   => $_POST['dari']   ?? date('Y-m-01'),
        'sampai' => $_POST['sampai'] ?? date('Y-m-d'),
    ]));
}

/* ============================================================
   EXPORT EXCEL (.xls)
   ============================================================ */
if (($_GET['export'] ?? '') === 'xls') {
    $dari   = $_GET['dari']   ?? date('Y-m-01');
    $sampai = $_GET['sampai'] ?? date('Y-m-d');

    $fKategori = trim($_GET['kategori'] ?? '');
    $q         = trim($_GET['q'] ?? '');

    $where  = "DATE(tanggal) BETWEEN ? AND ?";
    $params = [$dari, $sampai];
    if ($fKategori !== '') {
        $where .= " AND kategori = ?";
        $params[] = $fKategori;
    }
    if ($q !== '') {
        $where .= " AND (keterangan LIKE ? OR kode LIKE ? OR kategori LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, u.nama AS input_by
        FROM pengeluaran p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE $where
        ORDER BY p.tanggal ASC, p.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT kategori, COUNT(*) AS jml, SUM(nominal) AS total
        FROM pengeluaran
        WHERE $where
        GROUP BY kategori
        ORDER BY total DESC
    ");
    $stmt->execute($params);
    $perKategori = $stmt->fetchAll();

    $totalSemua = array_sum(array_column($rows, 'nominal'));

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total),0) FROM transaksi
        WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
    ");
    $stmt->execute([$dari, $sampai]);
    $omzetPeriode = (float)$stmt->fetchColumn();
    $saldoKasJual = $omzetPeriode - $totalSemua;

    $filename = 'Laporan_Pengeluaran_' . $dari . '_sd_' . $sampai . '.xls';

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
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
    <x:Name>Laporan Pengeluaran</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
     <x:Print>
      <x:ValidPrinterInfo/>
      <x:PaperSizeIndex>9</x:PaperSizeIndex>
      <x:Scale>75</x:Scale>
     </x:Print>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111827; }
  table { border-collapse: collapse; }
  th, td { border: 1px solid #7c7c7c; padding: 5px 8px; vertical-align: middle; }
  th { background: #10b981; color: #ffffff; font-weight: bold; text-align: center; font-size: 11px; }
  .num { text-align: right; mso-number-format: "\#\,\#\#0"; }
  .center { text-align: center; }
  .title { font-size: 16px; font-weight: bold; text-align: center; background: #065f46; color: #ffffff; padding: 10px; border: 1px solid #065f46; }
  .subtitle { text-align: center; font-size: 11px; background: #d1fae5; border: 1px solid #7c7c7c; }
  .section { font-size: 12px; font-weight: bold; background: #d1fae5; color: #065f46; padding: 6px 8px; border: 1px solid #7c7c7c; }
  .total-row td { background: #ecfdf5; font-weight: bold; }
  .spacer td { border: none; height: 8px; }
  .text-green { color: #047857; font-weight: bold; }
  .text-danger { color: #b91c1c; font-weight: bold; }
  .badge { background: #d1fae5; color: #065f46; padding: 2px 8px; font-weight: bold; }
</style>
</head>
<body>

<table>
  <tr><td colspan="8" class="title">LAPORAN PENGELUARAN</td></tr>
  <tr><td colspan="8" class="subtitle">
    Periode: <?= date('d/m/Y', strtotime($dari)) ?> s/d <?= date('d/m/Y', strtotime($sampai)) ?>
  </td></tr>
  <tr><td colspan="8" class="subtitle">
    Sumber Dana: <strong><?= htmlspecialchars($SUMBER_DANA['label']) ?></strong>
    — <?= htmlspecialchars($SUMBER_DANA['desc']) ?>
  </td></tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <tr><td colspan="8" class="section">RINGKASAN</td></tr>
  <tr><th style="width:220px">Keterangan</th><th colspan="7">Nilai</th></tr>
  <tr><td>Total Omzet Penjualan (Periode)</td><td colspan="7" class="num text-green"><?= $omzetPeriode ?></td></tr>
  <tr><td>Total Pengeluaran (Periode)</td><td colspan="7" class="num text-danger"><?= $totalSemua ?></td></tr>
  <tr><td>Jumlah Catatan Pengeluaran</td><td colspan="7" class="center"><?= count($rows) ?></td></tr>
  <tr><td><strong>Saldo Kas Penjualan</strong></td>
      <td colspan="7" class="num <?= $saldoKasJual < 0 ? 'text-danger' : 'text-green' ?>"><?= $saldoKasJual ?></td></tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <tr><td colspan="8" class="section">REKAP PER KATEGORI</td></tr>
  <tr><th>Kategori</th><th class="center">Jumlah Catatan</th><th class="num">Total</th><th class="num">Porsi (%)</th><th colspan="4"></th></tr>
  <?php if (!$perKategori): ?>
    <tr><td colspan="8" class="center">Belum ada pengeluaran.</td></tr>
  <?php else: foreach ($perKategori as $k):
    $porsi = $totalSemua > 0 ? ($k['total'] / $totalSemua) * 100 : 0;
  ?>
  <tr>
    <td><?= htmlspecialchars($k['kategori']) ?></td>
    <td class="center"><?= (int)$k['jml'] ?></td>
    <td class="num"><?= $k['total'] ?></td>
    <td class="num"><?= number_format($porsi, 1) ?>%</td>
    <td colspan="4"></td>
  </tr>
  <?php endforeach; endif; ?>
  <tr class="total-row">
    <td>TOTAL</td><td class="center"><?= count($rows) ?></td>
    <td class="num"><?= $totalSemua ?></td><td class="num">100%</td><td colspan="4"></td>
  </tr>
  <tr class="spacer"><td colspan="8"></td></tr>

  <tr><td colspan="8" class="section">DETAIL PENGELUARAN</td></tr>
  <tr>
    <th style="width:120px">Kode</th>
    <th style="width:130px">Tanggal</th>
    <th>Kategori</th><th>Sub</th><th>Keterangan</th><th>Input by</th>
    <th class="num">Nominal</th><th>Sumber Dana</th>
  </tr>
  <?php if (!$rows): ?>
    <tr><td colspan="8" class="center">Belum ada data pada periode ini.</td></tr>
  <?php else: foreach ($rows as $p): ?>
  <tr>
    <td class="center"><?= htmlspecialchars($p['kode']) ?></td>
    <td class="center"><?= date('d/m/Y', strtotime($p['tanggal'])) ?></td>
    <td><?= htmlspecialchars($p['kategori']) ?></td>
    <td><?= htmlspecialchars($p['sumber_keterangan'] ?? '-') ?></td>
    <td><?= htmlspecialchars($p['keterangan'] ?: '-') ?></td>
    <td><?= htmlspecialchars($p['input_by'] ?? '-') ?></td>
    <td class="num"><?= $p['nominal'] ?></td>
    <td class="center"><span class="badge"><?= htmlspecialchars($SUMBER_DANA['label']) ?></span></td>
  </tr>
  <?php endforeach; endif; ?>
  <tr class="total-row">
    <td colspan="6">TOTAL PENGELUARAN</td>
    <td class="num"><?= $totalSemua ?></td><td></td>
  </tr>
</table>

</body>
</html>
    <?php
    exit;
}

/* ============================================================
   FILTER PERIODE + KATEGORI + PENCARIAN
   ============================================================ */
$dari      = $_GET['dari']      ?? date('Y-m-01');
$sampai    = $_GET['sampai']    ?? date('Y-m-d');
$fKategori = trim($_GET['kategori'] ?? '');
$q         = trim($_GET['q'] ?? '');

foreach (['dari' => &$dari, 'sampai' => &$sampai] as &$v) {
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        $v = date('Y-m-d');
    }
}
unset($v, $d);

if ($dari > $sampai) {
    [$dari, $sampai] = [$sampai, $dari];
}

/* ============================================================
   REKAP
   ============================================================ */
$where  = "DATE(tanggal) BETWEEN ? AND ?";
$params = [$dari, $sampai];

if ($fKategori !== '') {
    $where .= " AND kategori = ?";
    $params[] = $fKategori;
}
if ($q !== '') {
    $where .= " AND (keterangan LIKE ? OR kode LIKE ? OR kategori LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS jml, COALESCE(SUM(nominal),0) AS total
    FROM pengeluaran
    WHERE $where
");
$stmt->execute($params);
$rekap = $stmt->fetch();

/* Rekap per kategori (tanpa filter kategori) */
$stmt = $pdo->prepare("
    SELECT kategori, COUNT(*) AS jml, SUM(nominal) AS total
    FROM pengeluaran
    WHERE DATE(tanggal) BETWEEN ? AND ?
    GROUP BY kategori
    ORDER BY total DESC
");
$stmt->execute([$dari, $sampai]);
$perKategori = $stmt->fetchAll();

$grandTotalKategori = 0;
foreach ($perKategori as $k) $grandTotalKategori += (float)$k['total'];

/* ============================================================
   SALDO KAS PENJUALAN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total),0) FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$dari, $sampai]);
$totalOmzetPeriode = (float)$stmt->fetchColumn();

$saldoKasPenjualan = $totalOmzetPeriode - $grandTotalKategori;

/* ============================================================
   RINGKASAN TAMBAHAN
   ============================================================ */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM pengeluaran WHERE DATE(tanggal) = CURDATE()");
$stmt->execute();
$totalHariIni = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(nominal),0) FROM pengeluaran WHERE YEAR(tanggal)=YEAR(CURDATE()) AND MONTH(tanggal)=MONTH(CURDATE())");
$stmt->execute();
$totalBulanIni = (float)$stmt->fetchColumn();

$hariPeriode = max(1, (int)((strtotime($sampai) - strtotime($dari)) / 86400) + 1);
$rataHarian  = $rekap['jml'] > 0 ? $rekap['total'] / $hariPeriode : 0;

/* ============================================================
   DAFTAR PENGELUARAN
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT p.*, u.nama AS input_by
    FROM pengeluaran p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE $where
    ORDER BY p.tanggal DESC, p.id DESC
    LIMIT 500
");
$stmt->execute($params);
$list = $stmt->fetchAll();

$pageTitle    = 'Pengeluaran';
$pageSubtitle = 'Catat & pantau pengeluaran dari Kas Penjualan';
require_once 'includes/header.php';
?>

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
  /* ============================================================
     PENGELUARAN STYLES — modern & clean
     ============================================================ */
  :root {
    --dash-radius: 14px;
    --dash-shadow: 0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
    --dash-shadow-hover: 0 8px 24px rgba(15,23,42,.10), 0 2px 6px rgba(15,23,42,.06);
    --dash-transition: all .25s cubic-bezier(.4,0,.2,1);
  }

  /* ---------- FILTER CARD ---------- */
  .filter-card .card-body { padding: 18px 20px; }
  .filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
  }
  .filter-form .form-group { margin: 0; }
  .filter-form .form-group label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 4px;
    display: block;
  }
  .filter-form input,
  .filter-form select {
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    padding: 9px 12px;
    font-size: 13px;
    transition: var(--dash-transition);
  }
  .filter-form input:focus,
  .filter-form select:focus {
    border-color: var(--g500, #10b981);
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
    outline: none;
  }

  /* ---------- STAT CARDS ---------- */
  .stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }
  .stat {
    position: relative;
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: var(--dash-radius);
    padding: 18px 20px;
    overflow: hidden;
    box-shadow: var(--dash-shadow);
    transition: var(--dash-transition);
  }
  .stat:hover {
    transform: translateY(-3px);
    box-shadow: var(--dash-shadow-hover);
    border-color: var(--g300, #86efac);
  }
  .stat::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: linear-gradient(90deg, var(--g500, #10b981), var(--g600, #059669));
    opacity: .85;
  }
  .stat:nth-child(2)::before { background: linear-gradient(90deg, #3b82f6, #2563eb); }
  .stat:nth-child(3)::before { background: linear-gradient(90deg, #f59e0b, #d97706); }
  .stat:nth-child(4)::before { background: linear-gradient(90deg, #8b5cf6, #7c3aed); }

  .stat-label {
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 6px;
  }
  .stat-value {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -.02em;
    line-height: 1.15;
  }
  .stat-sub {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 5px;
  }

  /* Saldo card */
  .saldo-card { border-width: 1.5px !important; }
  .saldo-card.positive { border-color: #10b981 !important; background: linear-gradient(135deg, #ecfdf5, #fff); }
  .saldo-card.negative { border-color: #ef4444 !important; background: linear-gradient(135deg, #fef2f2, #fff); }

  /* ---------- CARD ---------- */
  .card {
    border-radius: var(--dash-radius);
    box-shadow: var(--dash-shadow);
    transition: var(--dash-transition);
    margin-bottom: 20px;
  }
  .card:hover { box-shadow: var(--dash-shadow-hover); }
  .card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border, #e5e7eb);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .card-head h2 {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* ---------- KATEGORI PILLS (DIPERBESAR) ---------- */
  .kategori-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
  }
  .kategori-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 12px;
    border: 1.8px solid #e2e8f0;
    background: #fff;
    color: #475569;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--dash-transition);
    user-select: none;
    line-height: 1;
    min-height: 44px;
  }
  .kategori-pill:hover {
    border-color: var(--g500, #10b981);
    background: #f0fdf4;
    color: var(--g700, #047857);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.18);
  }
  .kategori-pill.active {
    background: linear-gradient(135deg, var(--g500, #10b981), var(--g600, #059669));
    border-color: var(--g500, #10b981);
    color: #fff;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
  }
  .kategori-pill.active::before {
    content: '✓';
    font-size: 13px;
    font-weight: 700;
  }
  .kategori-pill .bi { font-size: 18px; line-height: 1; }

  /* ---------- SUB-KATEGORI (KONSUMSI) ---------- */
  .subkategori-wrap {
    margin-top: 12px;
    padding: 14px 16px;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1.5px solid #fcd34d;
    border-radius: 12px;
    display: none;
    animation: slideDown .25s ease;
  }
  .subkategori-wrap.show { display: block; }
  @keyframes slideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .subkategori-wrap label {
    font-size: 12.5px;
    font-weight: 700;
    color: #92400e;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .subkategori-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .subkategori-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    border-radius: 999px;
    border: 1.5px solid #fcd34d;
    background: #fff;
    color: #92400e;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--dash-transition);
    user-select: none;
    line-height: 1;
  }
  .subkategori-pill:hover {
    background: #fef3c7;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(245, 158, 11, 0.25);
  }
  .subkategori-pill.active {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    border-color: #f59e0b;
    color: #fff;
    box-shadow: 0 3px 10px rgba(245, 158, 11, 0.4);
  }
  .subkategori-pill.active::before {
    content: '✓';
    font-size: 11px;
    font-weight: 700;
  }
  .subkategori-pill .bi { font-size: 14px; }

  .kategori-custom-wrap { margin-top: 12px; display: none; }
  .kategori-custom-wrap.show { display: block; }
  .kategori-custom-wrap input {
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    padding: 10px 14px;
    font-size: 13.5px;
    width: 100%;
    transition: var(--dash-transition);
  }
  .kategori-custom-wrap input:focus {
    border-color: var(--g500, #10b981);
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
    outline: none;
  }

  .kategori-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
  }
  .kategori-badge .bi { font-size: 12px; color: var(--g600, #059669); }
  .sub-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #fef3c7;
    color: #92400e;
    font-size: 10.5px;
    font-weight: 600;
    margin-left: 4px;
    white-space: nowrap;
  }
  .sub-badge .bi { font-size: 10px; }

  /* ---------- SUMBER DANA BOX ---------- */
  .sumber-info-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 6px;
    padding: 12px 14px;
    background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
    border: 1.5px solid #a7f3d0;
    border-radius: 10px;
    font-size: 12.5px;
    color: #065f46;
  }
  .sumber-info-box .bi {
    font-size: 20px;
    color: #10b981;
    flex-shrink: 0;
    margin-top: 1px;
  }
  .sumber-info-box strong {
    color: #047857;
    display: block;
    margin-bottom: 2px;
    font-size: 13px;
  }

  /* ---------- FORM ---------- */
  .form-grid { display: grid; gap: 12px; }
  .form-group label {
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 5px;
    display: block;
  }
  .form-group .req { color: #ef4444; }
  .form-group input,
  .form-group textarea,
  .form-group select {
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    padding: 9px 12px;
    font-size: 13px;
    transition: var(--dash-transition);
    width: 100%;
  }
  .form-group input:focus,
  .form-group textarea:focus,
  .form-group select:focus {
    border-color: var(--g500, #10b981);
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
    outline: none;
  }
  .form-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border, #e5e7eb);
  }
  .form-actions .btn { flex: 1; justify-content: center; }

  /* ---------- REKAP PROGRESS ---------- */
  .progress-track {
    height: 8px;
    background: var(--g50, #ecfdf5);
    border-radius: 99px;
    overflow: hidden;
    border: 1px solid var(--border, #e5e7eb);
  }
  .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--g500, #10b981), var(--g700, #047857));
    transition: width .6s cubic-bezier(.4,0,.2,1);
  }

  /* ---------- EXPORT BUTTON ---------- */
  .btn-export-xls {
    background: linear-gradient(135deg, #10b981, #047857);
    color: #fff !important;
    border: none;
    box-shadow: 0 4px 12px -4px rgba(16, 185, 129, 0.6);
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .btn-export-xls:hover {
    filter: brightness(1.08);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -6px rgba(16, 185, 129, 0.75);
  }
  .btn-export-xls .bi { font-size: 15px; }

  /* ---------- TABLE ---------- */
  .table-wrap { overflow-x: auto; }
  .table-wrap table { margin: 0; }
  .table-wrap thead th {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #64748b;
    background: #f8fafc;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border, #e5e7eb);
  }
  .table-wrap tbody td { padding: 12px 14px; }
  .table-wrap tbody tr {
    transition: background-color .15s ease;
    border-bottom: 1px solid #f1f5f9;
  }
  .table-wrap tbody tr:hover { background: #f8fafc; }

  /* ---------- EMPTY STATE ---------- */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 13.5px;
  }
  .empty-state .emoji { font-size: 32px; display: block; margin-bottom: 8px; }

  /* ---------- GRID 2 ---------- */
  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
  }
  @media (max-width: 900px) {
    .grid-2 { grid-template-columns: 1fr; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form .btn { width: 100%; justify-content: center; }
    .kategori-pill { padding: 10px 16px; font-size: 13px; }
  }

  /* =========================================
     CSS UNTUK MODAL KONFIRMASI KUSTOM
     ========================================= */
  .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(17, 24, 39, 0.6);
      backdrop-filter: blur(3px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10000;
      opacity: 0;
      visibility: hidden;
      transition: all 0.2s ease;
  }
  .modal-overlay.show {
      opacity: 1;
      visibility: visible;
  }
  .modal-box {
      background: #fff;
      border-radius: 12px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.1);
      transform: scale(0.95);
      transition: all 0.2s ease;
      overflow: hidden;
  }
  .modal-overlay.show .modal-box {
      transform: scale(1);
  }
  .modal-header {
      padding: 16px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #f3f4f6;
  }
  .modal-title {
      font-size: 18px;
      font-weight: 600;
      color: #dc2626;
  }
  .modal-close {
      background: transparent;
      border: none;
      font-size: 20px;
      color: #9ca3af;
      cursor: pointer;
      line-height: 1;
  }
  .modal-close:hover { color: #374151; }
  
  .modal-body-custom {
      padding: 20px;
  }
  .modal-desc {
      font-size: 14px;
      color: #4b5563;
      margin-bottom: 16px;
  }
  .summary-box {
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 16px;
  }
  .summary-row-custom {
      display: flex;
      justify-content: space-between;
      font-size: 14px;
      margin-bottom: 10px;
  }
  .summary-row-custom:last-child {
      margin-bottom: 0;
  }
  .summary-row-custom strong {
      color: #111827;
  }
  .warning-box {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 8px;
      padding: 12px;
      display: flex;
      gap: 8px;
      font-size: 13px;
      color: #991b1b;
  }
  
  .modal-actions {
      padding: 16px 20px;
      background: #f9fafb;
      display: flex;
      gap: 10px;
      justify-content: flex-end;
  }
  .modal-actions .btn {
      padding: 8px 20px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      border: none;
      transition: all 0.2s;
  }
  .modal-actions .btn-cancel {
      background: #fff;
      border: 1px solid #d1d5db;
      color: #374151;
  }
  .modal-actions .btn-cancel:hover { background: #f3f4f6; }
  .modal-actions .btn-confirm-delete {
      background: #dc2626; /* Merah untuk hapus */
      color: #fff;
  }
  .modal-actions .btn-confirm-delete:hover { background: #b91c1c; }
</style>

<!-- MODAL KONFIRMASI HAPUS -->
<div id="confirmModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Hapus Data?</div>
            <button type="button" class="modal-close" id="btnCloseModal">&times;</button>
        </div>
        <div class="modal-body-custom">
            <div class="modal-desc">Anda akan menghapus riwayat pengeluaran ini:</div>
            <div class="summary-box" id="confirmModalBody">
                <!-- Diisi oleh Javascript -->
            </div>
            <div class="warning-box">
                <span>⚠️</span>
                <span>Data yang dihapus tidak dapat dikembalikan.</span>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-cancel" id="btnCancelConfirm">Batal</button>
            <button type="button" class="btn btn-confirm-delete" id="btnOkConfirm">Ya, Hapus</button>
        </div>
    </div>
</div>

<!-- ===== FILTER ===== -->
<div class="card filter-card">
  <div class="card-head">
    <h2>🔎 Filter & Pencarian</h2>
  </div>
  <div class="card-body">
    <form method="get" class="filter-form">
      <div class="form-group">
        <label>Dari Tanggal</label>
        <input type="date" name="dari" value="<?= e($dari) ?>">
      </div>
      <div class="form-group">
        <label>Sampai Tanggal</label>
        <input type="date" name="sampai" value="<?= e($sampai) ?>">
      </div>
      <div class="form-group">
        <label>Kategori</label>
        <select name="kategori">
          <option value="">— Semua Kategori —</option>
          <?php foreach ($KATEGORI_KEYS as $k): ?>
            <option value="<?= e($k) ?>" <?= $fKategori === $k ? 'selected' : '' ?>>
              <?= e($k) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1; min-width:180px;">
        <label>Cari (kode / keterangan)</label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="cth: listrik, OUT-…">
      </div>
      <button class="btn btn-primary">🔍 Tampilkan</button>
      <a href="pengeluaran.php" class="btn btn-outline">Reset</a>
      <a href="pengeluaran.php?<?= http_build_query([
            'dari' => $dari, 'sampai' => $sampai,
            'kategori' => $fKategori, 'q' => $q, 'export' => 'xls'
          ]) ?>" class="btn btn-export-xls">
        <i class="bi bi-file-earmark-excel-fill"></i> Excel
      </a>
    </form>
  </div>
</div>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Total Pengeluaran</div>
    <div class="stat-value"><?= rupiah($rekap['total']) ?></div>
    <div class="stat-sub"><?= (int)$rekap['jml'] ?> catatan (periode)</div>
  </div>
  <div class="stat">
    <div class="stat-label">Hari Ini</div>
    <div class="stat-value"><?= rupiah($totalHariIni) ?></div>
    <div class="stat-sub"><?= date('d/m/Y') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Bulan Ini</div>
    <div class="stat-value"><?= rupiah($totalBulanIni) ?></div>
    <div class="stat-sub"><?= date('F Y') ?></div>
  </div>
  <div class="stat saldo-card <?= $saldoKasPenjualan < 0 ? 'negative' : 'positive' ?>">
    <div class="stat-label">💵 Saldo Kas Penjualan</div>
    <div class="stat-value <?= $saldoKasPenjualan < 0 ? 'text-danger' : 'text-green' ?>">
      <?= rupiah($saldoKasPenjualan) ?>
    </div>
    <div class="stat-sub">
      Omzet <?= rupiah($totalOmzetPeriode) ?> − Keluar <?= rupiah($grandTotalKategori) ?>
    </div>
  </div>
</div>

<div class="grid-2">

  <!-- ===== FORM INPUT / EDIT ===== -->
  <div class="card">
    <div class="card-head">
      <h2 id="formTitle">➕ Tambah Pengeluaran</h2>
      <button type="button" id="resetEditBtn" class="btn btn-outline btn-sm" style="display:none;">Batal Edit</button>
    </div>
    <div class="card-body">
      <form method="post" id="formPengeluaran">
        <input type="hidden" name="act" value="save" id="formAct">
        <input type="hidden" name="id" value="0" id="formId">
        <input type="hidden" name="dari" value="<?= e($dari) ?>">
        <input type="hidden" name="sampai" value="<?= e($sampai) ?>">

        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
          <div class="form-group">
            <label>Tanggal <span class="req">*</span></label>
            <input type="date" name="tanggal" id="fTanggal"
                   value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Nominal (Rp) <span class="req">*</span></label>
            <input type="text" name="nominal" id="fNominal" inputmode="numeric"
                   placeholder="0" required autocomplete="off">
          </div>
        </div>

        <!-- KATEGORI -->
        <div class="form-group" style="margin-top:14px;">
          <label>Kategori <span class="req">*</span></label>
          <input type="hidden" name="kategori" id="fKategori" value="">
          <input type="hidden" name="sub_kategori" id="fSubKategori" value="">

          <div class="kategori-pills" id="kategoriPills">
            <?php foreach ($KATEGORI_LIST as $nama => $icon): ?>
              <button type="button"
                      class="kategori-pill"
                      data-kategori="<?= e($nama) ?>">
                <i class="bi <?= e($icon) ?>"></i>
                <?= e($nama) ?>
              </button>
            <?php endforeach; ?>
          </div>

          <!-- SUB-KATEGORI KHUSUS KONSUMSI -->
          <div class="subkategori-wrap" id="subkategoriWrap">
            <label><i class="bi bi-tag-fill"></i> Pilih Jenis Konsumsi</label>
            <div class="subkategori-pills" id="subkategoriPills">
              <?php foreach ($KONSUMSI_SUB as $sub => $icon): ?>
                <button type="button"
                        class="subkategori-pill"
                        data-sub="<?= e($sub) ?>">
                  <i class="bi <?= e($icon) ?>"></i>
                  <?= e($sub) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="kategori-custom-wrap" id="kategoriCustomWrap">
            <input type="text" id="kategoriCustomInput" name="kategori_manual"
                   placeholder="Ketik nama kategori baru..."
                   maxlength="50">
            <div style="display:flex; gap:8px; margin-top:8px;">
              <button type="button" class="btn btn-outline btn-sm" id="btnBatalCustom">Batal</button>
            </div>
          </div>
        </div>

        <!-- SUMBER DANA (INFO STATIS) -->
        <div class="form-group" style="margin-top:14px;">
          <label>Sumber Dana</label>
          <div class="sumber-info-box">
            <i class="bi <?= e($SUMBER_DANA['icon']) ?>"></i>
            <div>
              <strong><?= e($SUMBER_DANA['label']) ?></strong>
              <?= e($SUMBER_DANA['desc']) ?>
            </div>
          </div>
        </div>

        <div class="form-group" style="margin-top:14px;">
          <label>Keterangan</label>
          <textarea name="keterangan" id="fKeterangan" maxlength="255" rows="3"
                    placeholder="Opsional, maks 255 karakter..."></textarea>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit" id="submitBtn">
            💾 Simpan Pengeluaran
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== REKAP PER KATEGORI ===== -->
  <div class="card">
    <div class="card-head">
      <h2>📊 Rekap per Kategori</h2>
      <span class="tag tag-green"><?= count($perKategori) ?> kategori</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Kategori</th>
            <th class="text-center" style="width:60px">Jml</th>
            <th class="text-right" style="width:110px">Total</th>
            <th style="width:30%;">Porsi</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$perKategori): ?>
          <tr><td colspan="4">
            <div class="empty-state">
              <span class="emoji">📭</span>
              Belum ada pengeluaran.
            </div>
          </td></tr>
        <?php else: foreach ($perKategori as $k):
            $porsi = $grandTotalKategori > 0 ? ($k['total'] / $grandTotalKategori) * 100 : 0;
            $icon  = $KATEGORI_LIST[$k['kategori']] ?? 'bi-tag-fill';
        ?>
          <tr>
            <td class="strong">
              <i class="bi <?= e($icon) ?>" style="color:var(--g600, #059669); margin-right:4px;"></i>
              <?= e($k['kategori']) ?>
            </td>
            <td class="text-center"><span class="tag tag-blue"><?= (int)$k['jml'] ?></span></td>
            <td class="text-right strong"><?= rupiah($k['total']) ?></td>
            <td>
              <div class="progress-track">
                <div class="progress-fill" style="width:<?= number_format($porsi, 2, '.', '') ?>%;"></div>
              </div>
              <small class="text-muted" style="display:block; margin-top:4px;"><?= number_format($porsi, 1) ?>%</small>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ===== DAFTAR PENGELUARAN ===== -->
<div class="card">
  <div class="card-head">
    <h2>🧾 Daftar Pengeluaran (<?= count($list) ?>)</h2>
    <?php if ($fKategori !== '' || $q !== ''): ?>
      <span class="tag tag-amber">Filter aktif</span>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Kode</th>
          <th>Tanggal</th>
          <th>Kategori</th>
          <th>Keterangan</th>
          <th>Input by</th>
          <th class="text-right">Nominal</th>
          <th class="text-center">Sumber</th>
          <th class="text-center" style="width:140px">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="8">
          <div class="empty-state">
            <span class="emoji">📭</span>
            Belum ada data pada periode ini.
          </div>
        </td></tr>
      <?php else: foreach ($list as $p):
        $icon = $KATEGORI_LIST[$p['kategori']] ?? 'bi-tag-fill';
        $sub  = $p['sumber_keterangan'] ?? '';
        $subIcon = $KONSUMSI_SUB[$sub] ?? 'bi-tag';
      ?>
        <tr>
          <td><span class="tag tag-gray"><?= e($p['kode']) ?></span></td>
          <td class="text-muted"><?= tanggalIndo($p['tanggal']) ?></td>
          <td>
            <span class="kategori-badge">
              <i class="bi <?= e($icon) ?>"></i>
              <?= e($p['kategori']) ?>
            </span>
            <?php if ($sub !== ''): ?>
              <span class="sub-badge" title="Jenis konsumsi">
                <i class="bi <?= e($subIcon) ?>"></i> <?= e($sub) ?>
              </span>
            <?php endif; ?>
          </td>
          <td><?= e($p['keterangan'] ?: '-') ?></td>
          <td class="text-muted"><?= e($p['input_by'] ?? '-') ?></td>
          <td class="text-right strong"><?= rupiah($p['nominal']) ?></td>
          <td class="text-center">
            <span class="tag tag-green" title="<?= e($SUMBER_DANA['desc']) ?>">
              <i class="bi <?= e($SUMBER_DANA['icon']) ?>"></i>
              <?= e($SUMBER_DANA['label']) ?>
            </span>
          </td>
          <td class="text-center" style="white-space:nowrap;">
            <button type="button" class="btn btn-outline btn-sm"
                    onclick='editRow(<?= json_encode([
                        "id"          => (int)$p["id"],
                        "tanggal"     => substr($p["tanggal"], 0, 10),
                        "kategori"    => $p["kategori"],
                        "sub_kategori"=> $p["sumber_keterangan"] ?? "",
                        "keterangan"  => $p["keterangan"],
                        "nominal"     => (float)$p["nominal"],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️</button>

            <!-- Form hapus tidak lagi menggunakan onsubmit alert, melainkan memanggil fungsi JS -->
            <form method="post" style="display:inline" id="formHapus_<?= (int)$p['id'] ?>"
                  onsubmit="event.preventDefault(); showConfirmDelete(this, '<?= e($p['kode']) ?>');">
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="dari" value="<?= e($dari) ?>">
              <input type="hidden" name="sampai" value="<?= e($sampai) ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const KATEGORI_KEYS = <?= json_encode($KATEGORI_KEYS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const form        = document.getElementById('formPengeluaran');
  const formTitle   = document.getElementById('formTitle');
  const formAct     = document.getElementById('formAct');
  const formId      = document.getElementById('formId');
  const fTanggal    = document.getElementById('fTanggal');
  const fNominal    = document.getElementById('fNominal');
  const fKategori   = document.getElementById('fKategori');
  const fSubKategori= document.getElementById('fSubKategori');
  const fKatManual  = document.getElementById('kategoriCustomInput');
  const fKeterangan = document.getElementById('fKeterangan');
  const submitBtn   = document.getElementById('submitBtn');
  const resetBtn    = document.getElementById('resetEditBtn');

  const kategoriPills     = document.getElementById('kategoriPills');
  const kategoriCustomWrap= document.getElementById('kategoriCustomWrap');
  const btnBatalCustom    = document.getElementById('btnBatalCustom');

  const subkategoriWrap   = document.getElementById('subkategoriWrap');
  const subkategoriPills  = document.getElementById('subkategoriPills');

  /* ===== KATEGORI PILL CLICK ===== */
  kategoriPills.addEventListener('click', function (e) {
    const pill = e.target.closest('.kategori-pill');
    if (!pill) return;

    kategoriPills.querySelectorAll('.kategori-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    fKategori.value = pill.dataset.kategori;

    // Reset sub-kategori
    fSubKategori.value = '';
    subkategoriPills.querySelectorAll('.subkategori-pill').forEach(p => p.classList.remove('active'));

    // Tampilkan sub-kategori jika Konsumsi
    if (pill.dataset.kategori === 'Konsumsi') {
      subkategoriWrap.classList.add('show');
    } else {
      subkategoriWrap.classList.remove('show');
    }

    kategoriCustomWrap.classList.remove('show');
    fKatManual.value = '';
  });

  /* ===== SUB-KATEGORI PILL CLICK ===== */
  subkategoriPills.addEventListener('click', function (e) {
    const pill = e.target.closest('.subkategori-pill');
    if (!pill) return;

    const isActive = pill.classList.contains('active');

    subkategoriPills.querySelectorAll('.subkategori-pill').forEach(p => p.classList.remove('active'));

    if (isActive) {
      fSubKategori.value = '';
    } else {
      pill.classList.add('active');
      fSubKategori.value = pill.dataset.sub;
    }
  });

  /* ===== CUSTOM KATEGORI ===== */
  btnBatalCustom.addEventListener('click', function () {
    kategoriCustomWrap.classList.remove('show');
    fKatManual.value = '';
    fKategori.value = '';
    subkategoriWrap.classList.remove('show');
    fSubKategori.value = '';
  });

  fKatManual.addEventListener('input', function () {
    const val = this.value.trim();
    kategoriPills.querySelectorAll('.kategori-pill').forEach(p => p.classList.remove('active'));
    subkategoriWrap.classList.remove('show');
    fSubKategori.value = '';

    if (val === '') { fKategori.value = ''; return; }

    const existing = Array.from(kategoriPills.querySelectorAll('.kategori-pill'))
      .find(p => p.dataset.kategori && p.dataset.kategori.toLowerCase() === val.toLowerCase());

    if (existing) existing.classList.add('active');
    fKategori.value = val;
  });

  fKatManual.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      this.blur();
      kategoriCustomWrap.classList.remove('show');
    }
  });

  /* ===== SET KATEGORI VALUE (untuk edit) ===== */
  function setKategoriValue(val, subVal) {
    fKategori.value = val || '';
    fSubKategori.value = '';
    kategoriPills.querySelectorAll('.kategori-pill').forEach(p => p.classList.remove('active'));
    subkategoriPills.querySelectorAll('.subkategori-pill').forEach(p => p.classList.remove('active'));
    subkategoriWrap.classList.remove('show');

    if (!val) {
      kategoriCustomWrap.classList.remove('show');
      fKatManual.value = '';
      return;
    }

    const pill = Array.from(kategoriPills.querySelectorAll('.kategori-pill'))
      .find(p => p.dataset.kategori && p.dataset.kategori === val);

    if (pill) {
      pill.classList.add('active');
      kategoriCustomWrap.classList.remove('show');
      fKatManual.value = '';

      // Jika Konsumsi, tampilkan sub & set jika ada
      if (val === 'Konsumsi') {
        subkategoriWrap.classList.add('show');
        if (subVal) {
          const subPill = Array.from(subkategoriPills.querySelectorAll('.subkategori-pill'))
            .find(p => p.dataset.sub === subVal);
          if (subPill) {
            subPill.classList.add('active');
            fSubKategori.value = subVal;
          }
        }
      }
    } else {
      kategoriCustomWrap.classList.add('show');
      fKatManual.value = val;
    }
  }

  /* ===== FORMAT NOMINAL ===== */
  function formatAngka(angka) {
    let val = angka.toString().replace(/\D/g, '');
    if (val === '') return '';
    return parseInt(val, 10).toLocaleString('id-ID');
  }

  fNominal.addEventListener('input', function () {
    let cursorPos = this.selectionStart;
    let oldLength = this.value.length;
    this.value = formatAngka(this.value);
    let newLength = this.value.length;
    cursorPos = cursorPos + (newLength - oldLength);
    this.setSelectionRange(cursorPos, cursorPos);
  });

  /* ===== EDIT ROW ===== */
  window.editRow = function (data) {
    formAct.value = 'update';
    formId.value = data.id;
    fTanggal.value = data.tanggal;
    fNominal.value = formatAngka(Math.round(data.nominal));
    fKeterangan.value = data.keterangan || '';

    setKategoriValue(data.kategori, data.sub_kategori || '');

    formTitle.textContent = '✏️ Edit Pengeluaran';
    submitBtn.textContent = '💾 Simpan Perubahan';
    resetBtn.style.display = 'inline-flex';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    fNominal.focus();
  };

  /* ===== RESET EDIT ===== */
  window.resetEdit = function () {
    form.reset();
    formAct.value = 'save';
    formId.value = 0;
    fTanggal.value = '<?= date('Y-m-d') ?>';
    formTitle.textContent = '➕ Tambah Pengeluaran';
    submitBtn.textContent = '💾 Simpan Pengeluaran';
    resetBtn.style.display = 'none';
    setKategoriValue('', '');
  };

  resetBtn.addEventListener('click', window.resetEdit);

  fNominal.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
  });

  /* ===== VALIDASI SUBMIT ===== */
  form.addEventListener('submit', function (e) {
    if (!fKategori.value.trim()) {
      e.preventDefault();
      alert('Silakan pilih kategori terlebih dahulu.');
      return;
    }

    // Jika Konsumsi, sub-kategori wajib dipilih
    if (fKategori.value === 'Konsumsi' && fSubKategori.value.trim() === '') {
      e.preventDefault();
      alert('Untuk kategori Konsumsi, silakan pilih jenisnya (Makanan / Minuman / Es Krim).');
      subkategoriWrap.classList.add('show');
      return;
    }
  });
})();

/* =========================================================
   LOGIKA UNTUK MODAL KONFIRMASI HAPUS (GAYA KUSTOM)
   ========================================================= */
const confirmModal = document.getElementById('confirmModal');
const confirmBody  = document.getElementById('confirmModalBody');
const btnCancel    = document.getElementById('btnCancelConfirm');
const btnClose     = document.getElementById('btnCloseModal');
const btnOk        = document.getElementById('btnOkConfirm');
let formToSubmit   = null;

// Fungsi yang dipanggil dari atribut onsubmit form hapus
window.showConfirmDelete = function(formElement, kode) {
    formToSubmit = formElement;
    
    // Isi konten modal dengan kode pengeluaran
    confirmBody.innerHTML = `
        <div class="summary-row-custom">
            <span>Kode Pengeluaran:</span>
            <strong>${kode}</strong>
        </div>
    `;
    
    // Tampilkan modal
    confirmModal.classList.add('show');
};

// Tutup Modal (Tombol X atau Batal)
const closeModal = () => {
    confirmModal.classList.remove('show');
    formToSubmit = null;
};
btnCancel.addEventListener('click', closeModal);
btnClose.addEventListener('click', closeModal);

// Tombol "Ya, Hapus"
btnOk.addEventListener('click', () => {
    if (formToSubmit) {
        formToSubmit.submit(); // Kirim form secara manual karena onsubmit di-preventDefault
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>