<?php
require_once 'config/database.php';
requireLogin();

$id         = (int)($_GET['id'] ?? 0);
$modeStruk  = $_GET['mode'] ?? '';
$hutangInfo = $_SESSION['hutang_info'] ?? null;

$isHutang = ($modeStruk === 'hutang' && $hutangInfo);

if ($isHutang) {
    /* ===== MODE STRUK HUTANG ===== */
    unset($_SESSION['hutang_info']);

    $trx   = null;
    $items = array_map(function ($it) {
        return [
            'nama'     => $it['nama'],
            'qty'      => $it['qty'],
            'subtotal' => $it['subtotal'],
        ];
    }, $hutangInfo['items']);

    $pageTitle    = 'Struk Hutang';
    $pageSubtitle = 'Bukti transaksi hutang';

    $metode = 'Hutang';
} else {
    /* ===== MODE STRUK TUNAI / QRIS (DEFAULT) ===== */
    $stmt = $pdo->prepare("
        SELECT t.*, u.nama AS kasir
        FROM transaksi t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $trx = $stmt->fetch();

    if (!$trx) {
        flash('error', 'Transaksi tidak ditemukan.');
        redirect('transaksi.php');
    }

    $det = $pdo->prepare("
        SELECT d.*, p.nama
        FROM transaksi_detail d
        LEFT JOIN produk p ON p.id = d.produk_id
        WHERE d.transaksi_id = ?
    ");
    $det->execute([$id]);
    $items = $det->fetchAll();

    $pageTitle    = 'Struk Transaksi';
    $pageSubtitle = 'Bukti transaksi ' . $trx['kode'];

    /* ============================================================
       DETEKSI METODE PEMBAYARAN
       Prioritas:
       1. Kolom metode langsung (jika ada di DB)
       2. Parse dari kolom `keterangan` — karena kasir.php menulis
          "Metode: Tunai" / "Metode: QRIS" / "... | Metode: QRIS"
       3. Deteksi hutang dari kata "hutang"/"DP" di keterangan
       4. Fallback: Tunai
       ============================================================ */
    $metode   = null;
    $ketLower = strtolower((string)($trx['keterangan'] ?? ''));

    // 1. Cek kolom metode langsung
    foreach (['metode_pembayaran', 'metode', 'payment_method', 'metode_bayar', 'tipe_bayar'] as $kolom) {
        if (!empty($trx[$kolom])) {
            $metode = trim((string)$trx[$kolom]);
            break;
        }
    }

    // 2. Parse dari keterangan: "Metode: QRIS" / "Metode: Tunai"
    if ($metode === null && $ketLower !== '') {
        if (preg_match('/metode\s*[:=]\s*([a-z0-9\-\/\s]+)/i', $ketLower, $m)) {
            $metode = trim($m[1]);
            // Ambil hanya kata pertama (hindari trailing "| ...")
            $metode = preg_split('/[\|\n]/', $metode)[0];
            $metode = trim($metode);
        }
    }

    // 3. Deteksi hutang dari keterangan
    if ($metode === null) {
        if (str_contains($ketLower, 'hutang') || str_contains($ketLower, 'dp ')) {
            $metode = 'Hutang';
        }
    }

    // 4. Cek kolom sisa (jika ada) → indikasi hutang
    if ($metode === null && !empty($trx['sisa']) && (float)$trx['sisa'] > 0) {
        $metode = 'Hutang';
    }

    // 5. Fallback
    if ($metode === null || $metode === '') {
        $metode = 'Tunai';
    }
}

/* ============================================================
   MAPPING METODE → BADGE (label + class + icon)
   ============================================================ */
$metodeKey = strtolower(trim((string)$metode));
$metodeMap = [
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
$badge = $metodeMap[$metodeKey] ?? [
    'label' => strtoupper((string)$metode ?: 'TUNAI'),
    'class' => 'bayar-lain',
    'icon'  => '💰',
];

require_once 'includes/header.php';
?>

<style>
  /* ============================================================
     STRUK STYLES — thermal receipt, clean & modern
     ============================================================ */
  :root {
    --struk-green: #166534;
    --struk-green-light: #dcfce7;
    --struk-amber: #92400e;
    --struk-amber-light: #fef3c7;
    --struk-red: #b91c1c;
    --struk-red-light: #fef2f2;
  }

  .struk-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px 16px;
  }

  .struk {
    max-width: 360px;
    width: 100%;
    padding: 22px 20px;
    background: #fff;
    color: #1e293b;
    font-family: 'Courier New', 'Consolas', monospace;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.08), 0 1px 3px rgba(15, 23, 42, 0.04);
    position: relative;
    overflow: hidden;
  }

  .struk::before {
    content: '';
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
    background: linear-gradient(90deg, var(--struk-green), #059669);
  }

  /* ---------- HEADER ---------- */
  .struk-head {
    text-align: center;
    padding: 8px 0 12px;
    line-height: 1.5;
  }
  .struk-head h2 {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 3px;
    margin: 0 0 4px;
    color: var(--struk-green);
    font-family: 'Segoe UI', Arial, sans-serif;
  }
  .struk-head strong {
    font-size: 10.5px;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #475569;
    display: block;
    margin-bottom: 6px;
  }
  .struk-head p {
    font-size: 10.5px;
    margin: 4px 0;
    line-height: 1.6;
    color: #64748b;
  }
  .struk-telp {
    font-weight: 700;
    letter-spacing: .5px;
    color: #334155 !important;
  }

  /* ---------- DIVIDER ---------- */
  .struk-line {
    border-top: 1px dashed #cbd5e1;
    margin: 10px 0;
  }

  /* ---------- BANNER HUTANG ---------- */
  .hutang-banner {
    background: linear-gradient(135deg, var(--struk-amber-light), #fffbeb);
    border: 1.5px solid #f59e0b;
    border-radius: 8px;
    padding: 12px 14px;
    margin: 12px 0;
    text-align: center;
  }
  .hutang-banner .label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--struk-amber);
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
  }
  .hutang-banner .nama {
    font-size: 18px;
    font-weight: 800;
    color: var(--struk-amber);
    margin: 6px 0;
    word-break: break-word;
    font-family: 'Segoe UI', Arial, sans-serif;
  }
  .hutang-banner .sisa-label {
    font-size: 9.5px;
    color: var(--struk-amber);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 4px;
    font-weight: 700;
  }
  .hutang-banner .sisa {
    font-size: 16px;
    font-weight: 800;
    color: var(--struk-red);
    background: #fff;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 5px 12px;
    display: inline-block;
    margin-top: 3px;
    font-family: 'Segoe UI', Arial, sans-serif;
  }

  /* ---------- META ---------- */
  .struk-meta {
    padding: 10px 0;
    border-bottom: 1px dashed #cbd5e1;
  }
  .struk-meta div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 11.5px;
    margin: 6px 0;
    align-items: center;
  }
  .struk-meta span {
    color: #64748b;
    flex-shrink: 0;
  }
  .struk-meta strong {
    text-align: right;
    color: #1e293b;
    word-break: break-word;
  }

  /* ---------- BADGE METODE PEMBAYARAN ---------- */
  .bayar-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    font-family: 'Segoe UI', Arial, sans-serif;
    white-space: nowrap;
  }
  .bayar-tunai    { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
  .bayar-qris     { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
  .bayar-transfer { background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; }
  .bayar-kartu    { background: #cffafe; color: #155e75; border: 1px solid #67e8f9; }
  .bayar-hutang   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .bayar-ewallet  { background: #fce7f3; color: #9d174d; border: 1px solid #f9a8d4; }
  .bayar-lain     { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

  /* Baris khusus metode di meta */
  .meta-metode {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    padding: 7px 9px !important;
    margin-top: 8px !important;
  }
  .meta-metode span:first-child {
    font-weight: 700;
    color: #334155 !important;
  }

  /* ---------- TABLE ---------- */
  .struk-table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0;
    font-size: 11.5px;
  }
  .struk-table th {
    text-align: left;
    padding: 8px 3px;
    border-bottom: 1px dashed #cbd5e1;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    font-weight: 700;
  }
  .struk-table td {
    padding: 7px 3px;
    vertical-align: top;
    color: #1e293b;
  }
  .struk-table th:nth-child(2),
  .struk-table td:nth-child(2) {
    text-align: center;
  }
  .struk-table th:last-child,
  .struk-table td:last-child {
    text-align: right;
  }
  .struk-table tbody tr {
    border-bottom: 1px dotted #f1f5f9;
  }
  .struk-table tbody tr:last-child {
    border-bottom: none;
  }

  /* ---------- TOTAL ---------- */
  .struk-total {
    border-top: 1px dashed #cbd5e1;
    padding-top: 10px;
  }
  .struk-total div {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 11.5px;
    margin: 6px 0;
    align-items: center;
  }
  .struk-total span:first-child {
    color: #64748b;
  }
  .struk-total span:last-child {
    color: #1e293b;
    font-weight: 600;
  }
  .struk-total .grand {
    font-size: 16px;
    font-weight: 800;
    color: var(--struk-green);
    padding-bottom: 10px;
    border-bottom: 1px dashed #cbd5e1;
    margin-bottom: 10px;
    font-family: 'Segoe UI', Arial, sans-serif;
  }
  .struk-total .grand span:first-child {
    color: var(--struk-green);
    letter-spacing: 1px;
  }
  .struk-total .sisa-row {
    font-size: 14px;
    font-weight: 800;
    color: var(--struk-red);
    padding: 8px 10px;
    background: var(--struk-red-light);
    border: 1px solid #fecaca;
    border-radius: 6px;
    margin-top: 8px;
  }
  .struk-total .sisa-row span:first-child {
    color: var(--struk-red);
    letter-spacing: .5px;
  }
  .struk-total .sisa-row span:last-child {
    color: var(--struk-red);
    font-weight: 800;
  }

  /* Baris metode pembayaran di blok total */
  .metode-row {
    margin-top: 10px !important;
    padding-top: 8px !important;
    border-top: 1px dashed #cbd5e1;
    align-items: center;
  }
  .metode-row span:first-child {
    font-weight: 700;
    color: #334155 !important;
    letter-spacing: .5px;
  }

  /* ---------- FOOTER ---------- */
  .struk-foot {
    text-align: center;
    border-top: 1px dashed #cbd5e1;
    margin-top: 16px;
    padding-top: 14px;
    font-size: 11px;
    line-height: 1.7;
    color: #475569;
  }
  .struk-foot strong {
    color: var(--struk-green);
    font-size: 12.5px;
    display: block;
    margin-bottom: 4px;
  }
  .struk-foot em {
    font-size: 10px;
    color: #94a3b8;
    display: block;
    margin-top: 6px;
    font-style: italic;
  }

  /* ---------- ACTION BUTTONS ---------- */
  .struk-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    max-width: 480px;
    margin: 24px auto 0;
  }
  .struk-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 13px;
    transition: all .2s ease;
  }
  .struk-actions .btn-primary {
    box-shadow: 0 4px 12px -4px rgba(16, 185, 129, 0.5);
  }
  .struk-actions .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -6px rgba(16, 185, 129, 0.7);
  }
  .struk-actions .btn-outline:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
  }

  /* ---------- PRINT STYLE ---------- */
  @media print {
    @page {
      size: 58mm auto;
      margin: 2mm;
    }

    body {
      background: #fff !important;
    }

    body * {
      visibility: hidden;
    }

    #struk,
    #struk * {
      visibility: visible;
    }

    #struk {
      position: absolute;
      left: 0;
      top: 0;
      width: 54mm;
      max-width: none;
      margin: 0;
      padding: 2mm;
      border: none;
      border-radius: 0;
      box-shadow: none;
      box-sizing: border-box;
      font-family: Arial, sans-serif;
      background: #fff;
    }

    #struk::before { display: none; }

    .hutang-banner {
      background: #fef3c7 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .struk-total .sisa-row {
      background: #fef2f2 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    /* Pastikan badge metode tetap terlihat di print */
    .bayar-badge {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      border: 1px solid #64748b !important;
    }

    .no-print {
      display: none !important;
    }
  }
</style>

<div class="struk-wrapper">
  <div class="struk" id="struk">

    <!-- HEADER -->
    <div class="struk-head">
      <h2>KASIR BMT</h2>
      <div class="struk-line"></div>
      <strong>Baitul Maal wat Tamwil</strong>
      <p>
        Jl. Bukit Duri Selatan No.29, Bukit Duri,<br>
        Tebet, Jakarta Selatan 12840
      </p>
      <p class="struk-telp">Telp. 021-8319994</p>
      <div class="struk-line"></div>
    </div>

    <?php if ($isHutang): ?>
    <!-- BANNER HUTANG -->
    <div class="hutang-banner">
      <div class="label">⚠️ Transaksi Hutang</div>
      <div class="nama"><?= e($hutangInfo['nama']) ?></div>
      <div class="sisa-label">Sisa Hutang</div>
      <div class="sisa"><?= rupiah($hutangInfo['sisa']) ?></div>
    </div>
    <?php endif; ?>

    <!-- META -->
    <div class="struk-meta">
      <?php if ($isHutang): ?>
        <?php if (!empty($hutangInfo['trx_id'])): ?>
        <div>
          <span>No. Transaksi</span>
          <strong>DP-<?= (int)$hutangInfo['trx_id'] ?></strong>
        </div>
        <?php else: ?>
        <div>
          <span>No. Transaksi</span>
          <strong>HUTANG-<?= date('YmdHis') ?></strong>
        </div>
        <?php endif; ?>
        <div>
          <span>Tanggal</span>
          <strong><?= tanggalIndo($hutangInfo['tanggal']) ?></strong>
        </div>
        <div>
          <span>Kasir</span>
          <strong><?= e($hutangInfo['kasir']) ?></strong>
        </div>
        <?php if (!empty($hutangInfo['keterangan'])): ?>
        <div>
          <span>Keterangan</span>
          <strong><?= e($hutangInfo['keterangan']) ?></strong>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div>
          <span>No. Transaksi</span>
          <strong><?= e($trx['kode']) ?></strong>
        </div>
        <div>
          <span>Tanggal</span>
          <strong><?= tanggalIndo($trx['tanggal']) ?></strong>
        </div>
        <div>
          <span>Kasir</span>
          <strong><?= e($trx['kasir'] ?? '-') ?></strong>
        </div>
      <?php endif; ?>

      <!-- ✅ BARIS METODE PEMBAYARAN -->
      <div class="meta-metode">
        <span>Metode</span>
        <strong>
          <span class="bayar-badge <?= e($badge['class']) ?>">
            <?= e($badge['icon']) ?> <?= e($badge['label']) ?>
          </span>
        </strong>
      </div>
    </div>

    <!-- ITEMS -->
    <table class="struk-table">
      <thead>
        <tr>
          <th>Item</th>
          <th>Qty</th>
          <th>Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['nama'] ?? '-') ?></td>
            <td><?= (int)$it['qty'] ?></td>
            <td><?= rupiah($it['subtotal']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- TOTAL -->
    <div class="struk-total">
      <?php if ($isHutang): ?>
        <div class="grand">
          <span>TOTAL</span>
          <span><?= rupiah($hutangInfo['total']) ?></span>
        </div>
        <div>
          <span>DP / Dibayar</span>
          <span><?= rupiah($hutangInfo['dibayar']) ?></span>
        </div>
        <div class="sisa-row">
          <span>SISA HUTANG</span>
          <span><?= rupiah($hutangInfo['sisa']) ?></span>
        </div>
      <?php else: ?>
        <div class="grand">
          <span>TOTAL</span>
          <span><?= rupiah($trx['total']) ?></span>
        </div>
        <div>
          <span>Bayar</span>
          <span><?= rupiah($trx['bayar']) ?></span>
        </div>
        <div>
          <span>Kembalian</span>
          <span><?= rupiah($trx['kembalian']) ?></span>
        </div>
      <?php endif; ?>

      <!-- ✅ BARIS METODE PEMBAYARAN DI TOTAL -->
      <div class="metode-row">
        <span>Metode Bayar</span>
        <span>
          <span class="bayar-badge <?= e($badge['class']) ?>">
            <?= e($badge['icon']) ?> <?= e($badge['label']) ?>
          </span>
        </span>
      </div>
    </div>

    <!-- FOOTER -->
    <div class="struk-foot">
      <?php if ($isHutang): ?>
        <strong>Hutang Tercatat</strong>
        Mohon lunasi sisa hutang.<br>
        <em>Simpan struk ini sebagai bukti.</em>
      <?php elseif (strcasecmp($metode, 'qris') === 0): ?>
        <strong>Terima Kasih!</strong>
        Pembayaran QRIS diterima.<br>
        <em>Simpan struk ini sebagai bukti pembayaran.</em>
      <?php else: ?>
        <strong>Terima Kasih!</strong>
        Telah berbelanja di Kasir BMT.<br>
        <em>Barang yang sudah dibeli tidak dapat ditukar.</em>
      <?php endif; ?>
    </div>

  </div>

  <!-- ACTION BUTTONS -->
  <div class="struk-actions no-print">
    <button class="btn btn-primary" onclick="window.print()">
      🖨️ Cetak Struk
    </button>

    <a href="kasir.php" class="btn btn-outline">
      ➕ Transaksi Baru
    </a>

    <a href="transaksi.php" class="btn btn-outline">
      📋 Daftar Transaksi
    </a>

    <?php if ($isHutang): ?>
    <a href="hutang.php" class="btn btn-outline">
      📝 Daftar Hutang
    </a>
    <?php endif; ?>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>