<?php
require_once 'config/database.php';
requireLogin();

/* ============================================================
   FILTER PERIODE
   ============================================================ */
$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

/* Validasi format tanggal */
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
   CEK APAKAH KOLOM metode_pembayaran ADA DI TABEL
   (kompatibel dengan DB lama & baru)
   ============================================================ */
$hasMetodeCol = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'metode_pembayaran'")->fetchAll();
    $hasMetodeCol = !empty($cols);
} catch (Exception $e) {
    $hasMetodeCol = false;
}

/* ============================================================
   DAFTAR TRANSAKSI
   ============================================================ */
$kolomMetode = $hasMetodeCol ? ', t.metode_pembayaran' : '';
$stmt = $pdo->prepare("
    SELECT t.*, u.nama AS kasir $kolomMetode,
           (SELECT COUNT(*) FROM transaksi_detail WHERE transaksi_id = t.id) AS jml_item
    FROM transaksi t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.jenis = 'penjualan' AND DATE(t.tanggal) BETWEEN ? AND ?
    ORDER BY t.id DESC
");
$stmt->execute([$dari, $sampai]);
$rows = $stmt->fetchAll();

$totalOmzet    = array_sum(array_column($rows, 'total'));
$totalTransaksi = count($rows);
$totalItem      = array_sum(array_column($rows, 'jml_item'));
$rataTransaksi  = $totalTransaksi > 0 ? $totalOmzet / $totalTransaksi : 0;

/* ============================================================
   HELPER: DETEKSI METODE PEMBAYARAN
   Prioritas:
   1. Kolom metode_pembayaran / metode / dll (jika ada di DB)
   2. Parse dari keterangan "Metode: QRIS" / "Metode: Tunai"
   3. Kata "hutang" / "dp " di keterangan → Hutang
   4. Kolom sisa > 0 → Hutang
   5. Fallback: Tunai
   ============================================================ */
function detectMetode(array $trx): string {
    // 1. Kolom langsung
    foreach (['metode_pembayaran', 'metode', 'payment_method', 'metode_bayar', 'tipe_bayar'] as $k) {
        if (!empty($trx[$k])) return trim((string)$trx[$k]);
    }

    $ket = strtolower((string)($trx['keterangan'] ?? ''));

    // 2. Parse "Metode: XXX"
    if ($ket !== '' && preg_match('/metode\s*[:=]\s*([a-z0-9\-\/\s]+)/i', $ket, $m)) {
        $val = trim(preg_split('/[\|\n]/', $m[1])[0]);
        if ($val !== '') return $val;
    }

    // 3. Deteksi hutang dari keterangan
    if (str_contains($ket, 'hutang') || str_contains($ket, 'dp ')) return 'Hutang';

    // 4. Kolom sisa
    if (!empty($trx['sisa']) && (float)$trx['sisa'] > 0) return 'Hutang';

    return 'Tunai';
}

/* ============================================================
   HELPER: BADGE METODE (label + class + icon)
   ============================================================ */
function metodeBadge(string $metode): array {
    $map = [
        'tunai'         => ['label' => 'TUNAI',       'class' => 'bayar-tunai',    'icon' => '💵'],
        'cash'          => ['label' => 'TUNAI',       'class' => 'bayar-tunai',    'icon' => '💵'],
        'qris'          => ['label' => 'QRIS',        'class' => 'bayar-qris',     'icon' => '📱'],
        'qr'            => ['label' => 'QRIS',        'class' => 'bayar-qris',     'icon' => '📱'],
        'transfer'      => ['label' => 'TRANSFER',    'class' => 'bayar-transfer', 'icon' => '🏦'],
        'bank'          => ['label' => 'TRANSFER',    'class' => 'bayar-transfer', 'icon' => '🏦'],
        'debit'         => ['label' => 'KARTU DEBIT', 'class' => 'bayar-kartu',    'icon' => '💳'],
        'kredit'        => ['label' => 'KARTU KREDIT','class' => 'bayar-kartu',    'icon' => '💳'],
        'kartu'         => ['label' => 'KARTU',       'class' => 'bayar-kartu',    'icon' => '💳'],
        'hutang'        => ['label' => 'HUTANG',      'class' => 'bayar-hutang',   'icon' => '📝'],
        'kredit/hutang' => ['label' => 'HUTANG',      'class' => 'bayar-hutang',   'icon' => '📝'],
        'ewallet'       => ['label' => 'E-WALLET',    'class' => 'bayar-ewallet',  'icon' => '📲'],
        'e-wallet'      => ['label' => 'E-WALLET',    'class' => 'bayar-ewallet',  'icon' => '📲'],
        'gopay'         => ['label' => 'GO-PAY',      'class' => 'bayar-ewallet',  'icon' => '📲'],
        'ovo'           => ['label' => 'OVO',         'class' => 'bayar-ewallet',  'icon' => '📲'],
        'dana'          => ['label' => 'DANA',        'class' => 'bayar-ewallet',  'icon' => '📲'],
        'shopeepay'     => ['label' => 'SHOPEEPAY',   'class' => 'bayar-ewallet',  'icon' => '📲'],
    ];
    $key = strtolower(trim($metode));
    return $map[$key] ?? [
        'label' => strtoupper($metode ?: 'TUNAI'),
        'class' => 'bayar-lain',
        'icon'  => '💰',
    ];
}

/* ============================================================
   DETAIL PER TRANSAKSI (untuk expand)
   ============================================================ */
$detailByTransaksi = [];
if ($rows) {
    $trxIds = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($trxIds), '?'));

    $stmt = $pdo->prepare("
        SELECT d.*, p.nama AS nama_produk, p.kode AS kode_produk, p.satuan
        FROM transaksi_detail d
        JOIN produk p ON p.id = d.produk_id
        WHERE d.transaksi_id IN ($placeholders)
        ORDER BY d.id ASC
    ");
    $stmt->execute($trxIds);
    foreach ($stmt->fetchAll() as $row) {
        $detailByTransaksi[$row['transaksi_id']][] = $row;
    }
}

$pageTitle    = 'Riwayat Transaksi';
$pageSubtitle = 'Daftar transaksi penjualan';
require_once 'includes/header.php';
?>

<style>
  /* ============================================================
     TRANSAKSI STYLES — modern & clean
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
  .filter-form input {
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    padding: 9px 12px;
    font-size: 13px;
    transition: var(--dash-transition);
  }
  .filter-form input:focus {
    border-color: var(--g500, #10b981);
    box-shadow: 0 0 0 3px rgba(16,185,129,.12);
    outline: none;
  }
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
    flex-wrap: wrap;
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

  /* ---------- BADGE METODE PEMBAYARAN ---------- */
  .bayar-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .bayar-tunai    { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .bayar-qris     { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
  .bayar-transfer { background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; }
  .bayar-kartu    { background: #cffafe; color: #155e75; border: 1px solid #67e8f9; }
  .bayar-hutang   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .bayar-ewallet  { background: #fce7f3; color: #9d174d; border: 1px solid #f9a8d4; }
  .bayar-lain     { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

  /* ---------- TRANSAKSI ROW ---------- */
  .row-transaksi {
    cursor: pointer;
    transition: background-color .25s ease, box-shadow .25s ease;
  }
  .row-transaksi:hover {
    background: #f0fdf4 !important;
    box-shadow: inset 4px 0 0 var(--g500, #10b981);
  }
  .row-transaksi:active { background: #dcfce7 !important; }
  .row-transaksi.active {
    background: #dcfce7 !important;
    box-shadow: inset 4px 0 0 var(--g600, #059669);
  }

  .toggle-icon {
    display: inline-block;
    transition: transform .3s cubic-bezier(.4,0,.2,1), color .2s ease;
    color: var(--g600, #059669);
    font-size: 12px;
    font-weight: 700;
  }
  .row-transaksi:hover .toggle-icon { color: var(--g700, #047857); }
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
    max-height: 1500px;
    opacity: 1;
    transform: translateY(0);
  }

  .detail-content { padding: 14px 20px 16px 50px; }

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
  .detail-table-wrap tfoot tr {
    background: #ecfdf5;
    border-top: 2px solid var(--border, #e5e7eb);
  }
  .detail-table-wrap tfoot td {
    padding: 12px 14px;
    font-weight: 700;
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
  .table-wrap tbody tr { border-bottom: 1px solid #f1f5f9; }

  /* ---------- EMPTY STATE ---------- */
  .empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 13.5px;
  }
  .empty-state .emoji { font-size: 32px; display: block; margin-bottom: 8px; }

  /* ---------- KETERANGAN NOTE ---------- */
  .trx-note {
    margin-top: 10px;
    padding: 10px 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    font-size: 12px;
    color: #92400e;
    line-height: 1.5;
  }

  /* ---------- RESPONSIVE ---------- */
  @media (max-width: 900px) {
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form .btn { width: 100%; justify-content: center; }
    .detail-content { padding-left: 20px; }
  }
</style>

<!-- ===== FILTER ===== -->
<div class="card filter-card">
  <div class="card-head">
    <h2>🔎 Filter Periode</h2>
    <span class="tag tag-green">Omzet: <?= rupiah($totalOmzet) ?></span>
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
      <button class="btn btn-primary">🔍 Tampilkan</button>
      <a href="transaksi.php" class="btn btn-outline">Reset</a>
      <a href="export_transaksi.php?dari=<?= e($dari) ?>&sampai=<?= e($sampai) ?>"
         class="btn btn-export-xls">📥 Excel</a>
    </form>
  </div>
</div>

<!-- ===== STATS ===== -->
<div class="stats">
  <div class="stat">
    <div class="stat-label">Total Omzet</div>
    <div class="stat-value"><?= rupiah($totalOmzet) ?></div>
    <div class="stat-sub">periode terpilih</div>
  </div>
  <div class="stat">
    <div class="stat-label">Jumlah Transaksi</div>
    <div class="stat-value"><?= number_format($totalTransaksi) ?></div>
    <div class="stat-sub"><?= date('d/m/Y', strtotime($dari)) ?> – <?= date('d/m/Y', strtotime($sampai)) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Total Item Terjual</div>
    <div class="stat-value"><?= number_format($totalItem) ?></div>
    <div class="stat-sub">unit produk</div>
  </div>
  <div class="stat">
    <div class="stat-label">Rata-rata / Transaksi</div>
    <div class="stat-value"><?= rupiah($rataTransaksi) ?></div>
    <div class="stat-sub">nilai belanja rata-rata</div>
  </div>
</div>

<!-- ===== DAFTAR TRANSAKSI ===== -->
<div class="card">
  <div class="card-head">
    <h2>🧾 Daftar Transaksi (<?= count($rows) ?>)</h2>
  </div>
  <div class="card-body">
    <p class="text-muted" style="margin-bottom:14px; font-size:13px;">
      ℹ️ Klik baris transaksi untuk melihat detail item yang dibeli.
    </p>

    <?php if (!$rows): ?>
      <div class="empty-state">
        <span class="emoji">📭</span>
        Tidak ada transaksi pada periode ini.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:30px"></th>
              <th>Kode</th>
              <th>Tanggal</th>
              <th>Kasir</th>
              <th class="text-center">Metode</th>
              <th class="text-center">Item</th>
              <th class="text-right">Total</th>
              <th class="text-right">Bayar</th>
              <th class="text-right">Kembali</th>
              <th class="text-center" style="width:80px">Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $details = $detailByTransaksi[$r['id']] ?? [];
            $rowId   = 'trx-' . (int)$r['id'];
            $metode  = detectMetode($r);
            $badge   = metodeBadge($metode);
          ?>
            <tr class="row-transaksi" data-target="<?= $rowId ?>">
              <td class="text-center">
                <span class="toggle-icon" id="icon-<?= $rowId ?>">▶</span>
              </td>
              <td><span class="tag tag-green"><?= e($r['kode']) ?></span></td>
              <td class="text-muted"><?= tanggalIndo($r['tanggal']) ?></td>
              <td class="text-muted"><?= e($r['kasir'] ?? '-') ?></td>
              <td class="text-center">
                <span class="bayar-badge <?= e($badge['class']) ?>">
                  <?= e($badge['icon']) ?> <?= e($badge['label']) ?>
                </span>
              </td>
              <td class="text-center"><?= (int)$r['jml_item'] ?></td>
              <td class="text-right strong"><?= rupiah($r['total']) ?></td>
              <td class="text-right"><?= rupiah($r['bayar']) ?></td>
              <td class="text-right"><?= rupiah($r['kembalian']) ?></td>
              <td class="text-center" style="white-space:nowrap" onclick="event.stopPropagation()">
                <a href="struk.php?id=<?= (int)$r['id'] ?>"
                   class="btn btn-outline btn-sm"
                   title="Lihat Struk">🧾</a>
              </td>
            </tr>

            <!-- DETAIL EXPAND -->
            <tr class="row-detail" id="<?= $rowId ?>">
              <td colspan="10">
                <div class="detail-inner" id="inner-<?= $rowId ?>">
                  <div class="detail-content">
                    <div class="detail-table-wrap">
                      <table style="margin:0">
                        <thead>
                          <tr>
                            <th>Kode</th>
                            <th>Produk</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Harga</th>
                            <th class="text-right">Subtotal</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php if (!$details): ?>
                          <tr><td colspan="5">
                            <div class="empty-state">Tidak ada detail.</div>
                          </td></tr>
                        <?php else: foreach ($details as $d): ?>
                          <tr>
                            <td><span class="tag tag-gray"><?= e($d['kode_produk']) ?></span></td>
                            <td><?= e($d['nama_produk']) ?></td>
                            <td class="text-center"><?= (int)$d['qty'] ?> <?= e($d['satuan']) ?></td>
                            <td class="text-right"><?= rupiah($d['harga']) ?></td>
                            <td class="text-right strong"><?= rupiah($d['subtotal']) ?></td>
                          </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if ($details): ?>
                        <tfoot>
                          <tr>
                            <td colspan="4" class="text-right">Total Transaksi</td>
                            <td class="text-right" style="color:var(--g700, #047857);">
                              <?= rupiah($r['total']) ?>
                            </td>
                          </tr>
                        </tfoot>
                        <?php endif; ?>
                      </table>
                    </div>

                    <!-- INFO METODE + KETERANGAN -->
                    <div class="trx-note" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                      <span>
                        <strong>Metode:</strong>
                        <span class="bayar-badge <?= e($badge['class']) ?>">
                          <?= e($badge['icon']) ?> <?= e($badge['label']) ?>
                        </span>
                      </span>
                      <?php if (!empty($r['keterangan'])): ?>
                        <span style="border-left:1px solid #fcd34d; padding-left:8px;">
                          📝 <strong>Keterangan:</strong> <?= e($r['keterangan']) ?>
                        </span>
                      <?php endif; ?>
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

<script>
/* ============================================================
   TOGGLE EXPAND TRANSAKSI (accordion + animasi smooth)
   ============================================================ */
const closeDetail = (inner) => {
  inner.classList.remove('open');
  const id = inner.id.replace('inner-', '');
  document.querySelector(`.row-transaksi[data-target="${id}"]`)?.classList.remove('active');
  document.getElementById('icon-' + id)?.classList.remove('open');
};

document.querySelectorAll('.row-transaksi').forEach(row => {
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
</script>

<?php require_once 'includes/footer.php'; ?>