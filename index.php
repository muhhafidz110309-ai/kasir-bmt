<?php
require_once 'config/database.php';
requireLogin();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Ringkasan aktivitas BMT hari ini';

/* ============================================================
   QUERY — dirapihkan, tanpa duplikasi
   ============================================================ */
$totalProduk    = (int)$pdo->query("SELECT COUNT(*) FROM produk")->fetchColumn();
$stokKritis     = (int)$pdo->query("SELECT COUNT(*) FROM produk WHERE stok <= 10")->fetchColumn();
$totalNilaiStok = (float)$pdo->query("SELECT COALESCE(SUM(harga_jual * stok),0) FROM produk")->fetchColumn();

$trxHariIni = $pdo->query("
    SELECT COUNT(*) AS jml, COALESCE(SUM(total),0) AS nominal
    FROM transaksi
    WHERE jenis='penjualan' AND DATE(tanggal)=CURDATE()
")->fetch();

$pendapatan = (float)$trxHariIni['nominal'];

$beban = (float)$pdo->query("
    SELECT COALESCE(SUM(nominal),0) FROM pengeluaran
    WHERE DATE(tanggal) = CURDATE()
")->fetchColumn();

$labaRugi = $pendapatan - $beban;
$isLaba   = $labaRugi >= 0;

$trxTerbaru = $pdo->query("
    SELECT t.*, u.nama AS kasir
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.jenis='penjualan'
    ORDER BY t.id DESC LIMIT 6
")->fetchAll();

$produkLaris = $pdo->query("
    SELECT p.nama, SUM(d.qty) AS terjual, SUM(d.subtotal) AS omzet
    FROM transaksi_detail d
    JOIN produk p ON p.id = d.produk_id
    JOIN transaksi t ON t.id = d.transaksi_id
    WHERE t.jenis='penjualan'
    GROUP BY p.id ORDER BY terjual DESC LIMIT 5
")->fetchAll();

$penjualan7Hari = $pdo->query("
    SELECT DATE(tanggal) AS tgl, COALESCE(SUM(total),0) AS nominal
    FROM transaksi
    WHERE jenis='penjualan'
      AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(tanggal)
    ORDER BY tgl ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chartLabels = [];
$chartData   = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i day"));
    $chartLabels[] = date('d M', strtotime($tgl));
    $chartData[]   = (float)($penjualan7Hari[$tgl] ?? 0);
}

$pengeluaranKategori = $pdo->query("
    SELECT kategori, SUM(nominal) AS total
    FROM pengeluaran
    WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY kategori
    ORDER BY total DESC
")->fetchAll();

$pengeluaranLabels = array_column($pengeluaranKategori, 'kategori');
$pengeluaranData   = array_map(fn($p) => (float)$p['total'], $pengeluaranKategori);

$hutangGroup = $pdo->query("
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

$totalHutang = array_sum(array_map(fn($h) => (float)$h['total_sisa'], $hutangGroup));

$detailByKonsumen = [];
if ($hutangGroup) {
    $namaList     = array_column($hutangGroup, 'nama');
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

require_once 'includes/header.php';
?>

<style>
  /* ============================================================
     DASHBOARD STYLES — modern, clean, animated
     ============================================================ */
  :root {
    --dash-radius: 14px;
    --dash-shadow: 0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
    --dash-shadow-hover: 0 8px 24px rgba(15,23,42,.10), 0 2px 6px rgba(15,23,42,.06);
    --dash-transition: all .25s cubic-bezier(.4,0,.2,1);
  }

  /* ---------- STAT CARDS (glassmorphism + gradient accent) ---------- */
  .stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }
  .stat {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--dash-radius);
    padding: 20px 22px;
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
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 8px;
  }
  .stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -.02em;
    line-height: 1.15;
  }
  .stat-sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 6px;
  }

  /* ---------- CARD ENHANCEMENT ---------- */
  .card {
    border-radius: var(--dash-radius);
    box-shadow: var(--dash-shadow);
    transition: var(--dash-transition);
    margin-bottom: 20px;
  }
  .card:hover { box-shadow: var(--dash-shadow-hover); }
  .card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
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

  .chart-box { height: 340px; position: relative; padding: 16px 20px; }

  /* ---------- LABA RUGI CARD ---------- */
  .laba-rugi-table td { padding: 12px 8px; }
  .laba-rugi-table tr.summary-row {
    border-top: 2px solid var(--border);
    background: linear-gradient(90deg, var(--g25, #f0fdf4), transparent);
  }
  .laba-rugi-table tr.summary-row td { padding: 14px 8px; }

  /* ---------- HUTANG ROW INTERACTION ---------- */
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
  .toggle-icon.open { transform: rotate(90deg); color: var(--g700, #047857); }

  .row-detail > td {
    padding: 0 !important;
    background: #fafafa;
    border-bottom: 1px solid var(--border);
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
    max-height: 1200px;
    opacity: 1;
    transform: translateY(0);
  }
  .detail-content { padding: 14px 20px 16px 50px; }

  .hutang-detail-wrap {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  .hutang-detail-wrap thead tr { background: #f1f5f9; }
  .hutang-detail-wrap thead th {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #475569;
  }

  /* ---------- TRANSAKSI & PRODUK LARIS ---------- */
  .trx-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--g500, #10b981), var(--g600, #059669));
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .03em;
    box-shadow: 0 2px 6px rgba(16,185,129,.25);
  }
  .rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    font-size: 11px;
    font-weight: 800;
  }
  .rank-badge.rank-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
  .rank-badge.rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
  .rank-badge.rank-3 { background: linear-gradient(135deg, #f97316, #ea580c); color: #fff; }

  /* ---------- GRID LAYOUT ---------- */
  .grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
  }
  @media (max-width: 900px) {
    .grid-2 { grid-template-columns: 1fr; }
  }

  /* ---------- EMPTY STATE ---------- */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 14px;
  }
  .empty-state .emoji { font-size: 32px; display: block; margin-bottom: 8px; }
</style>

<!-- ================= STAT CARDS ================= -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Penjualan Hari Ini</div>
    <div class="stat-value"><?= rupiah($trxHariIni['nominal']) ?></div>
    <div class="stat-sub"><?= (int)$trxHariIni['jml'] ?> transaksi</div>
  </div>
  <div class="stat">
    <div class="stat-label">Nilai Stok Produk</div>
    <div class="stat-value"><?= rupiah($totalNilaiStok) ?></div>
    <div class="stat-sub"><?= number_format($totalProduk) ?> jenis produk</div>
  </div>
  <div class="stat">
    <div class="stat-label">Produk</div>
    <div class="stat-value"><?= number_format($totalProduk) ?></div>
    <div class="stat-sub"><?= $stokKritis ?> produk stok menipis</div>
  </div>
  <div class="stat">
    <div class="stat-label">Transaksi Terbaru</div>
    <div class="stat-value"><?= count($trxTerbaru) ?></div>
    <div class="stat-sub">6 transaksi terakhir</div>
  </div>
</div>

<!-- ================= GRAFIK ================= -->
<div class="grid-2">
  <div class="card">
    <div class="card-head">
      <h2>📈 Tren Penjualan 7 Hari Terakhir</h2>
    </div>
    <div class="card-body chart-box">
      <canvas id="chartPenjualan"></canvas>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>🥧 Komposisi Pengeluaran (30 Hari)</h2>
    </div>
    <div class="card-body chart-box">
      <canvas id="chartPengeluaran"></canvas>
    </div>
  </div>
</div>

<!-- ================= LABA RUGI ================= -->
<div class="card">
  <div class="card-head">
    <h2>💰 Laba / Rugi Hari Ini</h2>
    <span class="tag <?= $isLaba ? 'tag-green' : 'tag-red' ?>">
      <?= $isLaba ? 'LABA' : 'RUGI' ?>
    </span>
  </div>
  <div class="card-body">
    <table class="laba-rugi-table">
      <tbody>
        <tr>
          <td>Pendapatan Penjualan</td>
          <td class="text-right text-green strong"><?= rupiah($pendapatan) ?></td>
        </tr>
        <tr>
          <td>Beban Operasional</td>
          <td class="text-right text-danger strong">− <?= rupiah($beban) ?></td>
        </tr>
        <tr class="summary-row">
          <td><strong>Laba / Rugi Bersih</strong></td>
          <td class="text-right strong"
              style="color: <?= $isLaba ? 'var(--g700, #047857)' : 'var(--danger, #dc2626)' ?>;">
            <?= rupiah($labaRugi) ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= HUTANG ================= -->
<div class="card">
  <div class="card-head">
    <h2>📝 Daftar Hutang</h2>
    <span class="tag <?= $totalHutang > 0 ? 'tag-red' : 'tag-green' ?>">
      <?= $totalHutang > 0 ? 'Sisa: ' . rupiah($totalHutang) : 'Semua Lunas 🎉' ?>
    </span>
  </div>
  <div class="card-body">
    <p class="text-muted" style="margin-bottom:14px; font-size:13px;">
      ℹ️ Hutang baru dicatat melalui halaman <a href="kasir.php"><strong>Kasir</strong></a> saat uang bayar kurang.
      Klik baris konsumen untuk melihat detail item.
    </p>

    <?php if (!$hutangGroup): ?>
      <div class="empty-state">
        <span class="emoji">🎉</span>
        Tidak ada data hutang.
      </div>
    <?php else: ?>
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
            </tr>
          </thead>
          <tbody>
          <?php foreach ($hutangGroup as $h):
            $sisa    = (float)$h['total_sisa'];
            $lunas   = ($sisa <= 0);
            $details = $detailByKonsumen[$h['nama']] ?? [];
            $rowId   = 'dash-hutang-' . md5($h['nama']);
          ?>
            <tr class="row-konsumen <?= $lunas ? 'row-lunas' : '' ?>"
                data-target="<?= $rowId ?>">
              <td class="text-center">
                <span class="toggle-icon" id="icon-<?= $rowId ?>">▶</span>
              </td>
              <td class="strong">
                <?= e($h['nama']) ?>
                <small class="text-muted" style="font-weight:400">
                  (<?= count($details) ?> item)
                </small>
              </td>
              <td class="text-center"><?= (int)$h['jml_item'] ?> produk</td>
              <td class="text-right"><?= rupiah($h['total_belanja']) ?></td>
              <td class="text-right text-green"><?= rupiah($h['total_dibayar']) ?></td>
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
            </tr>

            <tr class="row-detail" id="<?= $rowId ?>">
              <td colspan="7">
                <div class="detail-inner" id="inner-<?= $rowId ?>">
                  <div class="detail-content">
                    <div class="hutang-detail-wrap">
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
                          </tr>
                        </thead>
                        <tbody>
                        <?php if (!$details): ?>
                          <tr><td colspan="8" class="empty-state">Tidak ada detail.</td></tr>
                        <?php else: foreach ($details as $d):
                          $subtotal  = (float)$d['harga'] * (int)$d['qty'];
                          $dibayar   = (float)$d['dibayar'];
                          $sisaItem  = (float)$d['sisa'];
                          $isLunas   = ($sisaItem <= 0);
                        ?>
                          <tr <?= $isLunas ? 'style="background:#f0fdf4;opacity:.85"' : '' ?>>
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
                          </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ================= GRID BAWAH: TRANSAKSI + PRODUK LARIS ================= -->
<div class="grid-2">
  <div class="card">
    <div class="card-head">
      <h2>🧾 Transaksi Terbaru</h2>
      <a href="transaksi.php" class="btn btn-outline btn-sm">Lihat Semua</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Kode</th><th>Kasir</th><th>Waktu</th><th class="text-right">Total</th></tr>
        </thead>
        <tbody>
        <?php if (!$trxTerbaru): ?>
          <tr><td colspan="4" class="empty-state">Belum ada transaksi.</td></tr>
        <?php else: foreach ($trxTerbaru as $t): ?>
          <tr>
            <td><span class="trx-badge"><?= e($t['kode']) ?></span></td>
            <td><?= e($t['kasir'] ?? '-') ?></td>
            <td class="text-muted"><?= tanggalIndo($t['tanggal']) ?></td>
            <td class="text-right strong"><?= rupiah($t['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>🔥 Produk Terlaris</h2>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th style="width:40px">#</th><th>Produk</th><th class="text-center">Terjual</th><th class="text-right">Omzet</th></tr>
        </thead>
        <tbody>
        <?php if (!$produkLaris): ?>
          <tr><td colspan="4" class="empty-state">Belum ada penjualan.</td></tr>
        <?php else: foreach ($produkLaris as $i => $p): ?>
          <tr>
            <td class="text-center">
              <span class="rank-badge rank-<?= $i+1 ?>"><?= $i+1 ?></span>
            </td>
            <td class="strong"><?= e($p['nama']) ?></td>
            <td class="text-center"><span class="tag tag-blue"><?= (int)$p['terjual'] ?></span></td>
            <td class="text-right strong"><?= rupiah($p['omzet']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============ SCRIPT CHART.JS ============ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const rupiah = (n) => 'Rp ' + Number(n).toLocaleString('id-ID');

  /* ============================================================
     TOGGLE DETAIL HUTANG (accordion + animasi smooth)
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
     1. LINE CHART — Tren Penjualan 7 Hari
     ============================================================ */
  const canvasPenjualan = document.getElementById('chartPenjualan');
  if (canvasPenjualan) {
    const ctx = canvasPenjualan.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 340);
    gradient.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
    gradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
          label: 'Penjualan',
          data: <?= json_encode($chartData) ?>,
          borderColor: '#10b981',
          backgroundColor: gradient,
          borderWidth: 2.5,
          fill: true,
          tension: 0.4,
          pointBackgroundColor: '#fff',
          pointBorderColor: '#10b981',
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 7,
          pointHoverBackgroundColor: '#10b981'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0f172a',
            padding: 12,
            cornerRadius: 8,
            titleFont: { size: 12, weight: '600' },
            bodyFont: { size: 13, weight: '700' },
            displayColors: false,
            callbacks: { label: (ctx) => rupiah(ctx.parsed.y) }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              color: '#94a3b8',
              font: { size: 11 },
              callback: (v) => {
                if (v >= 1000000) return 'Rp ' + (v/1000000).toFixed(1) + 'jt';
                if (v >= 1000)    return 'Rp ' + (v/1000).toFixed(0) + 'rb';
                return 'Rp ' + v;
              }
            },
            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }
          },
          x: {
            ticks: { color: '#94a3b8', font: { size: 11 } },
            grid: { display: false }
          }
        }
      }
    });
  }

  /* ============================================================
     2. PLUGIN — Persen di Slice
     ============================================================ */
  const persenPlugin = {
    id: 'persenPlugin',
    afterDatasetsDraw(chart) {
      const { ctx } = chart;
      const meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data.length) return;

      const data  = chart.data.datasets[0].data;
      const total = data.reduce((a, b) => a + b, 0);
      if (total <= 0) return;

      meta.data.forEach((arc, i) => {
        const persen = (data[i] / total) * 100;
        if (persen < 5) return;

        const pos = arc.tooltipPosition();
        ctx.save();
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 13px Segoe UI, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,.4)';
        ctx.shadowBlur = 3;
        ctx.fillText(persen.toFixed(0) + '%', pos.x, pos.y);
        ctx.restore();
      });
    }
  };

  /* ============================================================
     3. DOUGHNUT — Pengeluaran per Kategori
     ============================================================ */
  const canvasPie = document.getElementById('chartPengeluaran');
  if (canvasPie) {
    const ctxPie = canvasPie.getContext('2d');
    let pieLabels = <?= json_encode($pengeluaranLabels) ?>;
    let pieData   = <?= json_encode($pengeluaranData) ?>;

    if (pieData.length === 0) {
      pieLabels = ['Belum ada pengeluaran'];
      pieData   = [1];
    }

    const pieColors = [
      '#10b981', '#f59e0b', '#3b82f6', '#8b5cf6',
      '#ec4899', '#ef4444', '#14b8a6', '#f97316'
    ];

    new Chart(ctxPie, {
      type: 'doughnut',
      plugins: [persenPlugin],
      data: {
        labels: pieLabels,
        datasets: [{
          data: pieData,
          backgroundColor: (pieData.length === 1 && pieLabels[0] === 'Belum ada pengeluaran')
                           ? ['#e2e8f0'] : pieColors,
          borderColor: '#ffffff',
          borderWidth: 3,
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '58%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 14,
              font: { size: 12, weight: '600' },
              color: '#334155',
              usePointStyle: true,
              pointStyle: 'circle'
            }
          },
          tooltip: {
            backgroundColor: '#0f172a',
            padding: 12,
            cornerRadius: 8,
            callbacks: {
              label: (ctx) => {
                if (ctx.label === 'Belum ada pengeluaran') {
                  return ' Belum ada pengeluaran tercatat';
                }
                const total  = ctx.dataset.data.reduce((a, b) => a + b, 0);
                const persen = ((ctx.parsed / total) * 100).toFixed(1);
                return ' ' + ctx.label + ': ' + rupiah(ctx.parsed) + ' (' + persen + '%)';
              }
            }
          }
        }
      }
    });
  }
</script>

<?php require_once 'includes/footer.php'; ?>
<?php require_once 'includes/tab.php'; ?>