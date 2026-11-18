<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   AKSI: BAYAR HUTANG KONSUMEN (LUNAS / SEBAGIAN)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bayar') {
    $nama       = trim($_POST['nama'] ?? '');
    $nominal    = (float)($_POST['nominal'] ?? 0);
    $keterangan = trim($_POST['keterangan'] ?? '');

    if ($nama === '') {
        flash('error', 'Nama konsumen tidak valid.');
        redirect('hutang.php');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT h.*, p.nama AS nama_produk
            FROM hutang h
            JOIN produk p ON p.id = h.produk_id
            WHERE h.nama = ? AND h.sisa > 0
            ORDER BY h.id ASC
            FOR UPDATE
        ");
        $stmt->execute([$nama]);
        $items = $stmt->fetchAll();

        if (!$items) throw new Exception('Tidak ada sisa hutang atas nama ' . $nama . '.');

        $totalSisa = 0;
        foreach ($items as $it) {
            $totalSisa += (float)$it['sisa'];
        }
        $totalSisa = round($totalSisa, 2);

        if ($totalSisa <= 0) {
            throw new Exception('Hutang ' . $nama . ' sudah lunas.');
        }

        if ($nominal <= 0 || $nominal >= $totalSisa) {
            $bayar = $totalSisa;
        } else {
            $bayar = $nominal;
        }

        $isLunas = ($bayar >= $totalSisa);

        $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');
        $ket  = $isLunas
                ? 'Pelunasan hutang dari ' . $nama
                : 'Cicilan hutang dari ' . $nama;

        $pdo->prepare("
            INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, tanggal)
            VALUES (?, ?, 'penjualan', ?, ?, 0, ?, NOW())
        ")->execute([
            $kode,
            user()['id'],
            $bayar,
            $bayar,
            $ket . ($keterangan ? ' - ' . $keterangan : '')
        ]);
        $trxId = (int)$pdo->lastInsertId();

        $insDet = $pdo->prepare("
            INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        $sisaBayar = $bayar;

        foreach ($items as $it) {
            if ($sisaBayar <= 0) break;

            $sisaItem = (float)$it['sisa'];
            if ($sisaItem <= 0) continue;

            if ($sisaBayar >= $sisaItem) {
                $insDet->execute([$trxId, $it['produk_id'], $it['qty'], $it['harga'], $sisaItem]);
                $sisaBayar -= $sisaItem;

                $pdo->prepare("UPDATE hutang SET dibayar = harga * qty, sisa = 0 WHERE id = ?")
                    ->execute([$it['id']]);
            } else {
                $insDet->execute([$trxId, $it['produk_id'], $it['qty'], $it['harga'], $sisaBayar]);

                $newSisa    = $sisaItem - $sisaBayar;
                $newDibayar = (float)$it['dibayar'] + $sisaBayar;

                $pdo->prepare("UPDATE hutang SET dibayar = ?, sisa = ? WHERE id = ?")
                    ->execute([$newDibayar, $newSisa, $it['id']]);

                $sisaBayar = 0;
            }
        }

        $pdo->commit();

        if ($isLunas) {
            flash('success', 'Hutang ' . $nama . ' sebesar ' . rupiah($totalSisa) . ' LUNAS. Transaksi ' . $kode . ' tercatat.');
        } else {
            $sisaAkhir = $totalSisa - $bayar;
            flash('success', 'Cicilan ' . rupiah($bayar) . ' dari ' . $nama . ' tercatat. Sisa hutang: ' . rupiah($sisaAkhir) . '.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Gagal: ' . $e->getMessage());
    }

    redirect('hutang.php');
}

/* ============================================================
   AKSI: BAYAR 1 ITEM HUTANG
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'bayar_item') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT h.*, p.nama AS nama_produk
                FROM hutang h
                JOIN produk p ON p.id = h.produk_id
                WHERE h.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $h = $stmt->fetch();

            if (!$h) throw new Exception('Hutang tidak ditemukan.');

            $sisaItem = (float)$h['sisa'];

            if ($sisaItem <= 0) {
                throw new Exception('Item ini sudah lunas.');
            }

            $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');

            $pdo->prepare("
                INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, tanggal)
                VALUES (?, ?, 'penjualan', ?, ?, 0, ?, NOW())
            ")->execute([
                $kode,
                user()['id'],
                $sisaItem,
                $sisaItem,
                'Pelunasan hutang item: ' . $h['nama_produk'] . ' (' . $h['nama'] . ')'
            ]);
            $trxId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$trxId, $h['produk_id'], $h['qty'], $h['harga'], $sisaItem]);

            $pdo->prepare("UPDATE hutang SET dibayar = harga * qty, sisa = 0 WHERE id = ?")->execute([$id]);

            $pdo->commit();
            flash('success', 'Item "' . $h['nama_produk'] . '" atas nama ' . $h['nama'] . ' LUNAS. Transaksi ' . $kode . ' tercatat.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Gagal: ' . $e->getMessage());
        }
    }
    redirect('hutang.php');
}

/* ============================================================
   AKSI: HAPUS 1 ITEM HUTANG
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hapus_item') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->prepare("DELETE FROM hutang WHERE id = ?")->execute([$id]);
            flash('success', 'Item hutang berhasil dihapus.');
        } catch (PDOException $e) {
            flash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
    redirect('hutang.php');
}

/* ============================================================
   AKSI: HAPUS SEMUA HUTANG KONSUMEN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'hapus_konsumen') {
    $nama = trim($_POST['nama'] ?? '');
    if ($nama !== '') {
        try {
            $pdo->prepare("DELETE FROM hutang WHERE nama = ?")->execute([$nama]);
            flash('success', 'Semua hutang atas nama ' . $nama . ' dihapus.');
        } catch (PDOException $e) {
            flash('error', 'Gagal menghapus: ' . $e->getMessage());
        }
    }
    redirect('hutang.php');
}

/* ============================================================
   DATA
   ============================================================ */
$statusFilter = $_GET['status'] ?? 'semua';

$rekapKonsumen = $pdo->query("
    SELECT nama,
           COUNT(*)         AS jml_item,
           SUM(qty)         AS total_qty,
           SUM(harga * qty) AS total_belanja,
           SUM(dibayar)     AS total_dibayar,
           SUM(sisa)        AS total_sisa,
           MIN(tanggal)     AS tgl_awal,
           MAX(tanggal)     AS tgl_akhir
    FROM hutang
    GROUP BY nama
    ORDER BY
      CASE WHEN SUM(sisa) > 0 THEN 0 ELSE 1 END,
      MAX(tanggal) DESC,
      nama ASC
")->fetchAll();

$grandTotalSisa = 0;
$grandTotalDibayar = 0;
foreach ($rekapKonsumen as $r) {
    $grandTotalSisa    += (float)$r['total_sisa'];
    $grandTotalDibayar += (float)$r['total_dibayar'];
}

/* Detail semua konsumen (untuk expand inline) */
$detailByKonsumen = [];
if ($rekapKonsumen) {
    $namaList     = array_column($rekapKonsumen, 'nama');
    $placeholders = implode(',', array_fill(0, count($namaList), '?'));

    $stmt = $pdo->prepare("
        SELECT h.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
        FROM hutang h
        JOIN produk p ON p.id = h.produk_id
        WHERE h.nama IN ($placeholders)
        ORDER BY h.nama ASC, h.sisa > 0 DESC, h.id DESC
    ");
    $stmt->execute($namaList);

    foreach ($stmt->fetchAll() as $row) {
        $detailByKonsumen[$row['nama']][] = $row;
    }
}

$pageTitle    = 'Hutang';
$pageSubtitle = 'Daftar & pelunasan hutang konsumen';
require_once 'includes/header.php';
?>

<style>
  /* ============================================================
     HUTANG STYLES — modern & clean
     ============================================================ */
  :root {
    --dash-radius: 14px;
    --dash-shadow: 0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
    --dash-shadow-hover: 0 8px 24px rgba(15,23,42,.10), 0 2px 6px rgba(15,23,42,.06);
    --dash-transition: all .25s cubic-bezier(.4,0,.2,1);
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
  .stat:nth-child(2)::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
  .stat:nth-child(3)::before { background: linear-gradient(90deg, #3b82f6, #2563eb); }
  .stat:nth-child(4)::before { background: linear-gradient(90deg, #f59e0b, #d97706); }

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

  /* ---------- FILTER TABS ---------- */
  .status-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border, #e5e7eb);
    background: #f8fafc;
  }
  .status-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 999px;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    color: #475569;
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    transition: var(--dash-transition);
    line-height: 1;
  }
  .status-tab:hover {
    border-color: var(--g500, #10b981);
    background: #f0fdf4;
    color: var(--g700, #047857);
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.15);
  }
  .status-tab.active {
    background: var(--g500, #10b981);
    border-color: var(--g500, #10b981);
    color: #fff;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
  }

  /* ---------- HUTANG ROW ---------- */
  .row-konsumen {
    cursor: pointer;
    transition: background-color .25s ease, box-shadow .25s ease;
  }
  .row-konsumen:hover {
    background: #f0fdf4 !important;
    box-shadow: inset 4px 0 0 var(--g500, #10b981);
  }
  .row-konsumen:active { background: #dcfce7 !important; }
  .row-konsumen.active {
    background: #dcfce7 !important;
    box-shadow: inset 4px 0 0 var(--g600, #059669);
  }
  .row-lunas { background: #f0fdf4 !important; }

  .toggle-icon {
    display: inline-block;
    transition: transform .3s cubic-bezier(.4,0,.2,1), color .2s ease;
    color: var(--g600, #059669);
    font-size: 12px;
    font-weight: 700;
  }
  .row-konsumen:hover .toggle-icon { color: var(--g700, #047857); }
  .toggle-icon.open {
    transform: rotate(90deg);
    color: var(--g700, #047857);
  }

  .row-detail > td {
    padding: 0 !important;
    background: #fafafa;
    border-bottom: 1px solid var(--border, #e5e7eb);
  }

  .detail-inner {
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transform: translateY(-8px);
    transition:
      max-height .4s cubic-bezier(.4,0,.2,1),
      opacity .3s ease .05s,
      transform .3s ease .05s;
  }
  .detail-inner.open {
    max-height: 2000px;
    opacity: 1;
    transform: translateY(0);
  }

  .detail-content { padding: 16px 20px 20px 50px; }

  /* ---------- PANEL PELUNASAN ---------- */
  .pelunasan-panel {
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 10px;
    padding: 16px 18px;
    margin-bottom: 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  .pelunasan-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  @media (max-width: 768px) {
    .pelunasan-grid { grid-template-columns: 1fr; }
  }
  .pelunasan-section h4 {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .pelunasan-section.lunas h4 { color: var(--g700, #047857); }
  .pelunasan-section.cicil h4 { color: #d97706; }
  .pelunasan-section p {
    margin: 0 0 10px;
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
  }
  .cicilan-form {
    display: flex;
    gap: 6px;
    align-items: stretch;
  }
  .cicilan-form input {
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    padding: 8px 10px;
    font-size: 12.5px;
    transition: var(--dash-transition);
    min-width: 0;
  }
  .cicilan-form input:focus {
    border-color: #d97706;
    box-shadow: 0 0 0 3px rgba(217,119,6,.12);
    outline: none;
  }
  .cicilan-form input[name="nominal"] {
    flex: 1;
    font-weight: 700;
  }
  .cicilan-form input[name="keterangan"] {
    flex: 1.2;
  }
  .btn-cicil {
    background: #fff;
    border: 1.5px solid #d97706;
    color: #d97706;
    font-weight: 700;
    white-space: nowrap;
    transition: var(--dash-transition);
  }
  .btn-cicil:hover {
    background: #d97706;
    color: #fff;
  }

  /* ---------- DETAIL TABLE ---------- */
  .detail-table-wrap {
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  .detail-table-wrap thead tr { background: #f1f5f9; }
  .detail-table-wrap thead th {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #475569;
  }
  .detail-table-wrap tbody tr { border-bottom: 1px solid #f1f5f9; }
  .detail-table-wrap tbody tr:hover { background: #f8fafc; }
  .detail-table-wrap tbody tr.lunas-row {
    background: #f0fdf4;
    opacity: .9;
  }
  .detail-table-wrap tbody tr.lunas-row:hover { background: #dcfce7; }

  /* ---------- EMPTY STATE ---------- */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 13.5px;
  }
  .empty-state .emoji { font-size: 32px; display: block; margin-bottom: 8px; }

  /* ---------- MODAL ---------- */
  .modal-info {
    margin-top: 14px;
    padding: 14px 16px;
    background: #f8fafc;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 10px;
    font-size: 13px;
  }
  .modal-info > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }
  .modal-info span { color: #64748b; font-size: 12px; }
  .modal-info strong { color: #0f172a; font-size: 15px; }
  .modal-warn {
    margin-top: 14px;
    padding: 10px 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    font-size: 12px;
    color: #92400e;
    line-height: 1.5;
  }
  .modal-warn.danger {
    color: #b91c1c;
    background: #fef2f2;
    border-color: #fecaca;
  }
  .modal-kode {
    margin-top: 8px;
    padding: 10px 14px;
    background: linear-gradient(135deg, var(--g50, #ecfdf5), #f0fdf4);
    border: 1.5px solid #a7f3d0;
    border-radius: 8px;
    font-weight: 700;
    color: var(--g700, #047857);
    text-align: center;
    font-size: 15px;
  }

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
</style>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Konsumen Berhutang</div>
    <div class="stat-value">
      <?php
        $jmlBelum = 0;
        foreach ($rekapKonsumen as $r) if ((float)$r['total_sisa'] > 0) $jmlBelum++;
        echo $jmlBelum;
      ?>
    </div>
    <div class="stat-sub">dari <?= count($rekapKonsumen) ?> total konsumen</div>
  </div>
  <div class="stat">
    <div class="stat-label">Total Sisa Hutang</div>
    <div class="stat-value text-danger"><?= rupiah($grandTotalSisa) ?></div>
    <div class="stat-sub">belum lunas</div>
  </div>
  <div class="stat">
    <div class="stat-label">Total Sudah Dibayar</div>
    <div class="stat-value text-green"><?= rupiah($grandTotalDibayar) ?></div>
    <div class="stat-sub">akumulasi DP & cicilan</div>
  </div>
  <div class="stat">
    <div class="stat-label">Status</div>
    <div class="stat-value">
      <?php if ($grandTotalSisa > 0): ?>
        <span class="tag tag-red">BELUM LUNAS</span>
      <?php else: ?>
        <span class="tag tag-green">SEMUA LUNAS</span>
      <?php endif; ?>
    </div>
    <div class="stat-sub">kondisi saat ini</div>
  </div>
</div>

<!-- ===== REKAP + DETAIL PER KONSUMEN ===== -->
<div class="card">
  <div class="card-head">
    <h2>👥 Rekap Hutang per Konsumen</h2>
    <?php if ($grandTotalSisa > 0): ?>
      <span class="tag tag-red">Sisa Total: <?= rupiah($grandTotalSisa) ?></span>
    <?php else: ?>
      <span class="tag tag-green">Semua Lunas 🎉</span>
    <?php endif; ?>
  </div>

  <!-- Status filter tabs -->
  <div class="status-tabs">
    <a href="hutang.php?status=semua"
       class="status-tab <?= $statusFilter === 'semua' ? 'active' : '' ?>">
      📋 Semua
    </a>
    <a href="hutang.php?status=belum"
       class="status-tab <?= $statusFilter === 'belum' ? 'active' : '' ?>">
      ⏳ Belum Lunas
    </a>
    <a href="hutang.php?status=lunas"
       class="status-tab <?= $statusFilter === 'lunas' ? 'active' : '' ?>">
      ✅ Sudah Lunas
    </a>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:30px"></th>
          <th>Nama Konsumen</th>
          <th class="text-center">Jml Item</th>
          <th class="text-right">Total Belanja</th>
          <th class="text-right">Sudah Dibayar</th>
          <th class="text-right">Sisa Hutang</th>
          <th class="text-center">Status</th>
          <th class="text-center" style="width:120px">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rekapKonsumen): ?>
        <tr><td colspan="8">
          <div class="empty-state">
            <span class="emoji">🎉</span>
            Tidak ada data hutang.
          </div>
        </td></tr>
      <?php else:
        $adaData = false;
        foreach ($rekapKonsumen as $r):
          $sisa   = (float)$r['total_sisa'];
          $lunas  = ($sisa <= 0);

          if ($statusFilter === 'belum' && $lunas) continue;
          if ($statusFilter === 'lunas' && !$lunas) continue;

          $adaData = true;
          $details = $detailByKonsumen[$r['nama']] ?? [];
          $rowId   = 'detail-' . md5($r['nama']);
      ?>
        <tr class="row-konsumen <?= $lunas ? 'row-lunas' : '' ?>"
            data-target="<?= $rowId ?>">
          <td class="text-center">
            <span class="toggle-icon" id="icon-<?= $rowId ?>">▶</span>
          </td>
          <td class="strong">
            <?= e($r['nama']) ?>
            <small class="text-muted" style="font-weight:400">
              (<?= count($details) ?> item)
            </small>
          </td>
          <td class="text-center"><?= (int)$r['jml_item'] ?> produk</td>
          <td class="text-right"><?= rupiah($r['total_belanja']) ?></td>
          <td class="text-right text-green"><?= rupiah($r['total_dibayar']) ?></td>
          <td class="text-right strong <?= $lunas ? 'text-green' : 'text-danger' ?>">
            <?= $lunas ? '✓ LUNAS' : rupiah($sisa) ?>
          </td>
          <td class="text-center">
            <?php if ($lunas): ?>
              <span class="tag tag-green">LUNAS</span>
            <?php else: ?>
              <span class="tag tag-red">BELUM LUNAS</span>
            <?php endif; ?>
          </td>
          <td class="text-center" style="white-space:nowrap" onclick="event.stopPropagation()">
            <?php if (!$lunas): ?>
              <button type="button" class="btn btn-primary btn-sm"
                      onclick="bukaBayarLunas('<?= e(addslashes($r['nama'])) ?>', <?= (int)$sisa ?>)"
                      title="Lunasi Semua">
                ✓
              </button>
            <?php endif; ?>

            <button type="button" class="btn btn-danger btn-sm"
                    onclick="bukaHapusKonsumen('<?= e(addslashes($r['nama'])) ?>', <?= (int)$r['jml_item'] ?>)"
                    title="Hapus Semua Riwayat">
              🗑
            </button>
          </td>
        </tr>

        <tr class="row-detail" id="<?= $rowId ?>">
          <td colspan="8">
            <div class="detail-inner" id="inner-<?= $rowId ?>">
              <div class="detail-content">

                <?php if (!$lunas): ?>
                <!-- PANEL PELUNASAN -->
                <div class="pelunasan-panel">
                  <div class="pelunasan-grid">

                    <div class="pelunasan-section lunas">
                      <h4>✓ Bayar LUNAS (Full)</h4>
                      <p>Lunasi semua sisa <strong><?= rupiah($sisa) ?></strong> sekaligus.</p>
                      <form method="post"
                            onsubmit="return confirm('Lunasi SEMUA hutang <?= e($r['nama']) ?>?\n\nTotal: <?= rupiah($sisa) ?>')">
                        <input type="hidden" name="act" value="bayar">
                        <input type="hidden" name="nama" value="<?= e($r['nama']) ?>">
                        <input type="hidden" name="nominal" value="0">
                        <button class="btn btn-primary btn-sm">✓ LUNAS — <?= rupiah($sisa) ?></button>
                      </form>
                    </div>

                    <div class="pelunasan-section cicil">
                      <h4>💰 Bayar Sebagian (Cicilan)</h4>
                      <p>Masukkan nominal cicilan (min. Rp 100).</p>
                      <form method="post" class="cicilan-form"
                            onsubmit="return confirm('Catat cicilan untuk <?= e($r['nama']) ?>?')">
                        <input type="hidden" name="act" value="bayar">
                        <input type="hidden" name="nama" value="<?= e($r['nama']) ?>">
                        <input type="number" name="nominal" min="100" max="<?= (int)$sisa ?>"
                               step="100" placeholder="Nominal" required>
                        <input type="text" name="keterangan" placeholder="Ket. (opsional)">
                        <button class="btn btn-cicil btn-sm">💰 Cicil</button>
                      </form>
                    </div>

                  </div>
                </div>
                <?php endif; ?>

                <!-- TABEL DETAIL ITEM -->
                <div class="detail-table-wrap">
                  <table style="margin:0">
                    <thead>
                      <tr>
                        <th>Kode</th>
                        <th>Produk</th>
                        <th class="text-center">Qty</th>
                        <th class="text-right">Harga</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Dibayar</th>
                        <th class="text-right">Sisa</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" style="width:110px">Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php if (!$details): ?>
                      <tr><td colspan="9">
                        <div class="empty-state">Tidak ada detail.</div>
                      </td></tr>
                    <?php else: foreach ($details as $d):
                      $subtotal  = (float)$d['harga'] * (int)$d['qty'];
                      $dibayar   = (float)$d['dibayar'];
                      $sisaItem  = (float)$d['sisa'];
                      $isLunas   = ($sisaItem <= 0);
                    ?>
                      <tr class="<?= $isLunas ? 'lunas-row' : '' ?>">
                        <td><span class="tag tag-gray"><?= e($d['kode_produk']) ?></span></td>
                        <td><?= e($d['nama_produk']) ?></td>
                        <td class="text-center"><?= (int)$d['qty'] ?> <?= e($d['satuan']) ?></td>
                        <td class="text-right"><?= rupiah($d['harga']) ?></td>
                        <td class="text-right"><?= rupiah($subtotal) ?></td>
                        <td class="text-right text-green"><?= rupiah($dibayar) ?></td>
                        <td class="text-right strong <?= $isLunas ? 'text-green' : 'text-danger' ?>">
                          <?= $isLunas ? 'Rp 0' : rupiah($sisaItem) ?>
                        </td>
                        <td class="text-center">
                          <?php if ($isLunas): ?>
                            <span class="tag tag-green">✓ LUNAS</span>
                          <?php else: ?>
                            <span class="tag tag-red">BELUM</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-center" style="white-space:nowrap">
                          <?php if (!$isLunas): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('Lunasi item ini?\n\n<?= e($d['nama_produk']) ?> — Sisa: <?= rupiah($sisaItem) ?>')">
                              <input type="hidden" name="act" value="bayar_item">
                              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                              <button class="btn btn-primary btn-sm" title="Lunasi Item">✓</button>
                            </form>
                          <?php endif; ?>

                          <button type="button" class="btn btn-danger btn-sm"
                                  onclick="bukaHapusItem('<?= (int)$d['id'] ?>', '<?= e(addslashes($d['nama_produk'])) ?>', '<?= e(addslashes($r['nama'])) ?>', <?= (int)$d['qty'] ?>)"
                                  title="Hapus Item">
                            🗑
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </td>
        </tr>
      <?php endforeach;
        if (!$adaData): ?>
        <tr><td colspan="8">
          <div class="empty-state">
            <span class="emoji">🔍</span>
            Tidak ada data untuk filter yang dipilih.
          </div>
        </td></tr>
      <?php endif; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===== MODAL BAYAR LUNAS ===== -->
<div class="modal-overlay no-print" id="modalBayarLunas">
  <div class="modal-box">
    <div class="modal-head">
      <h3>💳 Lunasi Hutang?</h3>
      <button type="button" class="modal-close" onclick="tutupModalBayar()">×</button>
    </div>
    <div class="modal-body">
      <p style="margin:0 0 8px; font-size:13px; color:#64748b;">Lunasi <strong>semua sisa hutang</strong> atas nama:</p>
      <p class="modal-kode" id="modalBayarNama">-</p>

      <div class="modal-info">
        <div>
          <span>Total Sisa</span>
          <strong id="modalBayarTotal">Rp 0</strong>
        </div>
      </div>

      <p class="modal-warn">⚠️ Transaksi pelunasan akan otomatis tercatat.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline" onclick="tutupModalBayar()">Batal</button>
      <form method="post" style="display:inline">
        <input type="hidden" name="act" value="bayar">
        <input type="hidden" name="nama" id="modalBayarNamaInput">
        <input type="hidden" name="nominal" value="0">
        <button type="submit" class="btn btn-primary">✓ Ya, Lunasi</button>
      </form>
    </div>
  </div>
</div>

<!-- ===== MODAL HAPUS ===== -->
<div class="modal-overlay no-print" id="modalHapus">
  <div class="modal-box">
    <div class="modal-head">
      <h3 style="color: var(--danger, #dc2626);">Hapus Data?</h3>
      <button type="button" class="modal-close" onclick="tutupModalHapus()">×</button>
    </div>
    <div class="modal-body">
      <p id="modalHapusTeks" style="margin:0 0 4px; font-size:13px; color:#334155;">Anda akan menghapus data ini.</p>

      <div class="modal-info">
        <div>
          <span id="modalHapusLabel">Target:</span>
          <strong id="modalHapusNama" style="font-size:16px;">-</strong>
        </div>
        <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0;">
          <span id="modalHapusDetail" style="color:#64748b;">-</span>
        </div>
      </div>

      <p class="modal-warn danger">⚠️ Data yang dihapus tidak dapat dikembalikan.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline" onclick="tutupModalHapus()">Batal</button>
      <form method="post" style="display:inline" id="formHapus">
        <input type="hidden" name="act" id="hapusAct">
        <input type="hidden" name="nama" id="hapusNamaInput">
        <input type="hidden" name="id" id="hapusIdInput">
        <button type="submit" class="btn btn-danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</div>

<script>
/* ============================================================
   TOGGLE DETAIL PER KONSUMEN (accordion + animasi smooth)
   ============================================================ */
const closeDetail = (inner) => {
  inner.classList.remove('open');
  const id = inner.id.replace('inner-', '');
  document.querySelector(`.row-konsumen[data-target="${id}"]`)?.classList.remove('active');
  document.getElementById('icon-' + id)?.classList.remove('open');
};

document.querySelectorAll('.row-konsumen').forEach(row => {
  row.addEventListener('click', function () {
    const targetId = this.dataset.target;
    const inner = document.getElementById('inner-' + targetId);
    if (!inner) return;

    const isOpen = inner.classList.contains('open');

    document.querySelectorAll('.detail-inner.open').forEach(el => {
      if (el !== inner) closeDetail(el);
    });

    if (isOpen) {
      closeDetail(inner);
    } else {
      inner.classList.add('open');
      this.classList.add('active');
      document.getElementById('icon-' + targetId)?.classList.add('open');
      setTimeout(() => this.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 350);
    }
  });
});

/* ============================================================
   MODAL BAYAR LUNAS
   ============================================================ */
function bukaBayarLunas(nama, total) {
  document.getElementById('modalBayarNama').textContent = nama;
  document.getElementById('modalBayarTotal').textContent =
    'Rp ' + Number(total).toLocaleString('id-ID');
  document.getElementById('modalBayarNamaInput').value = nama;
  document.getElementById('modalBayarLunas').classList.add('show');
}

function tutupModalBayar() {
  document.getElementById('modalBayarLunas').classList.remove('show');
}

document.getElementById('modalBayarLunas').addEventListener('click', function (e) {
  if (e.target === this) tutupModalBayar();
});

/* ============================================================
   MODAL HAPUS
   ============================================================ */
function bukaHapusKonsumen(nama, jmlItem) {
  document.getElementById('modalHapusTeks').textContent = 'Anda akan menghapus riwayat penjualan hutang konsumen ini:';
  document.getElementById('modalHapusLabel').textContent = 'Nama Konsumen:';
  document.getElementById('modalHapusNama').textContent = nama;
  document.getElementById('modalHapusDetail').innerHTML = 'Total <strong>' + jmlItem + ' item</strong> akan dihapus.';

  document.getElementById('hapusAct').value = 'hapus_konsumen';
  document.getElementById('hapusNamaInput').value = nama;
  document.getElementById('hapusIdInput').value = '';

  document.getElementById('modalHapus').classList.add('show');
}

function bukaHapusItem(id, namaProduk, namaKonsumen, qty) {
  document.getElementById('modalHapusTeks').textContent = 'Anda akan menghapus 1 item hutang dari daftar:';
  document.getElementById('modalHapusLabel').textContent = 'Produk:';
  document.getElementById('modalHapusNama').textContent = namaProduk;
  document.getElementById('modalHapusDetail').innerHTML = 'Atas nama: <strong>' + namaKonsumen + '</strong> (Qty: ' + qty + ')';

  document.getElementById('hapusAct').value = 'hapus_item';
  document.getElementById('hapusNamaInput').value = '';
  document.getElementById('hapusIdInput').value = id;

  document.getElementById('modalHapus').classList.add('show');
}

function tutupModalHapus() {
  document.getElementById('modalHapus').classList.remove('show');
}

document.getElementById('modalHapus').addEventListener('click', function (e) {
  if (e.target === this) tutupModalHapus();
});

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    tutupModalBayar();
    tutupModalHapus();
  }
});
</script>

<?php require_once 'includes/footer.php'; ?>