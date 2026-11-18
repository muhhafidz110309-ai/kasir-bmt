<?php
require_once 'config/database.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart       = json_decode($_POST['cart'] ?? '[]', true) ?: [];
    $bayar_raw  = $_POST['bayar'] ?? '0';
    $bayar      = (float)str_replace('.', '', $bayar_raw);
    $keterangan = trim($_POST['keterangan'] ?? '');
    $mode       = $_POST['mode'] ?? 'tunai';
    $namaHutang = trim($_POST['nama_hutang'] ?? '');
    $redirect   = $_POST['redirect'] ?? 'cetak';

    if (!$cart) { flash('error', 'Keranjang masih kosong.'); redirect('kasir.php'); }
    if ($mode === 'hutang' && $namaHutang === '') { flash('error', 'Nama orang untuk hutang wajib diisi.'); redirect('kasir.php'); }

    try {
        $pdo->beginTransaction();
        $ids  = array_map(fn($i) => (int)$i['id'], $cart);
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, nama, harga_jual, stok FROM produk WHERE id IN ($in) FOR UPDATE");
        $stmt->execute($ids);
        $produkDb = [];
        foreach ($stmt->fetchAll() as $p) $produkDb[(int)$p['id']] = $p;

        $total = 0;
        foreach ($cart as $item) {
            $pid = (int)$item['id']; $qty = (int)$item['qty'];
            if (!isset($produkDb[$pid])) throw new Exception('Produk tidak ditemukan.');
            if ($qty < 1) throw new Exception('Jumlah item tidak valid.');
            if ($produkDb[$pid]['stok'] < $qty) throw new Exception('Stok "' . $produkDb[$pid]['nama'] . '" tidak mencukupi.');
            $total += (float)$produkDb[$pid]['harga_jual'] * $qty;
        }

        if ($mode === 'qris') $bayar = $total;
        if ($mode === 'tunai' && $bayar < $total) throw new Exception('Nominal pembayaran kurang dari total tagihan.');

        $hasMetodeCol = false;
        try { $cols = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'metode_pembayaran'")->fetchAll(); $hasMetodeCol = !empty($cols); } catch (Exception $e) {}

        if ($mode === 'hutang') {
            $bayarSekarang = max(0, min($bayar, $total));
            $sisaHutang    = $total - $bayarSekarang;
            if ($sisaHutang <= 0) throw new Exception('Jika uang cukup, gunakan mode Tunai.');

            if ($bayarSekarang > 0) {
                $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');
                if ($hasMetodeCol) {
                    $pdo->prepare("INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, metode_pembayaran) VALUES (?,?,'penjualan',?,?,0,?,?)")
                        ->execute([$kode, user()['id'], $bayarSekarang, $bayarSekarang, 'DP hutang dari ' . $namaHutang, 'Hutang']);
                } else {
                    $pdo->prepare("INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan) VALUES (?,?,'penjualan',?,?,0,?)")
                        ->execute([$kode, user()['id'], $bayarSekarang, $bayarSekarang, 'Metode: Hutang | DP hutang dari ' . $namaHutang]);
                }
                $trxId = (int)$pdo->lastInsertId();
                $insDet = $pdo->prepare("INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal) VALUES (?,?,?,?,?)");
                foreach ($cart as $item) {
                    $pid = (int)$item['id']; $qty = (int)$item['qty']; $harga = (float)$produkDb[$pid]['harga_jual'];
                    $insDet->execute([$trxId, $pid, $qty, $harga, $qty * $harga]);
                }
            }

            $updStok = $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
            foreach ($cart as $item) $updStok->execute([(int)$item['qty'], (int)$item['id']]);

            $insHutang = $pdo->prepare("INSERT INTO hutang (nama, produk_id, qty, harga, dibayar, sisa, keterangan, tanggal) VALUES (?,?,?,?,?,?,?,CURDATE())");
            $ketHutang = ($keterangan ? $keterangan . ' | ' : '') . 'DP total: ' . rupiah($bayarSekarang);
            foreach ($cart as $item) {
                $pid = (int)$item['id']; $qty = (int)$item['qty']; $harga = (float)$produkDb[$pid]['harga_jual'];
                $subtotalItem = $harga * $qty;
                $porsiDibayar = ($total > 0) ? ($subtotalItem / $total) * $bayarSekarang : 0;
                $porsiDibayar = round($porsiDibayar, 2); $sisaItem = $subtotalItem - $porsiDibayar;
                $insHutang->execute([$namaHutang, $pid, $qty, $harga, $porsiDibayar, $sisaItem, $ketHutang]);
            }
            $pdo->commit();
            flash('success', ($bayarSekarang > 0 ? 'DP ' . rupiah($bayarSekarang) . ' tercatat. ' : '') . 'Sisa hutang ' . rupiah($sisaHutang) . ' atas nama ' . $namaHutang . '.');
            redirect('kasir.php');
        }

        $kode = generateKode('TRX', $pdo, 'transaksi', 'kode');
        $metodeLabel = ($mode === 'qris') ? 'QRIS' : 'Tunai';
        $ketFinal    = trim(($keterangan ? $keterangan . ' | ' : '') . 'Metode: ' . $metodeLabel);
        $kembalian   = ($mode === 'qris') ? 0 : ($bayar - $total);

        if ($hasMetodeCol) {
            $ins = $pdo->prepare("INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan, metode_pembayaran) VALUES (?,?,'penjualan',?,?,?,?,?)");
            $ins->execute([$kode, user()['id'], $total, $bayar, $kembalian, $ketFinal, $metodeLabel]);
        } else {
            $ins = $pdo->prepare("INSERT INTO transaksi (kode, user_id, jenis, total, bayar, kembalian, keterangan) VALUES (?,?,'penjualan',?,?,?,?)");
            $ins->execute([$kode, user()['id'], $total, $bayar, $kembalian, $ketFinal]);
        }
        $trxId = (int)$pdo->lastInsertId();
        $insDet  = $pdo->prepare("INSERT INTO transaksi_detail (transaksi_id, produk_id, qty, harga, subtotal) VALUES (?,?,?,?,?)");
        $updStok = $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?");
        foreach ($cart as $item) {
            $pid = (int)$item['id']; $qty = (int)$item['qty']; $harga = (float)$produkDb[$pid]['harga_jual'];
            $insDet->execute([$trxId, $pid, $qty, $harga, $qty * $harga]);
            $updStok->execute([$qty, $pid]);
        }
        $pdo->commit();
        if ($redirect === 'simpan') { flash('success', 'Transaksi disimpan.'); redirect('kasir.php'); }
        redirect('struk.php?id=' . $trxId);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
        redirect('kasir.php');
    }
}

$pageTitle = 'Kasir'; $pageSubtitle = 'Transaksi penjualan tunai';
$produk = $pdo->query("SELECT * FROM produk ORDER BY nama ASC")->fetchAll();
require_once 'includes/header.php';
?>

<style>
    .floating-info { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-20px); background: #1f2937; color: #fff; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000001; opacity: 0; visibility: hidden; transition: all 0.3s ease; text-align: center; max-width: 90%; } 
    .floating-info.show { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
    .floating-info.error { background: #dc2626; } .floating-info.success { background: #16a34a; } .floating-info.info { background: #2563eb; }
    
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(17, 24, 39, 0.6); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; z-index: 1000000; opacity: 0; visibility: hidden; transition: all 0.2s ease; }
    .modal-overlay.show { opacity: 1; visibility: visible; }
    .modal-box { background: #fff; border-radius: 12px; width: 100%; max-width: 420px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); transform: scale(0.95); transition: all 0.2s ease; overflow: hidden; }
    .modal-overlay.show .modal-box { transform: scale(1); }
    .modal-header { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; }
    .modal-title { font-size: 18px; font-weight: 600; color: #dc2626; }
    .modal-title.qris-title { color: #2563eb; }
    .modal-close { background: transparent; border: none; font-size: 20px; color: #9ca3af; cursor: pointer; line-height: 1; }
    .modal-body { padding: 20px; }
    .modal-desc { font-size: 14px; color: #4b5563; margin-bottom: 16px; }
    .summary-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    .summary-row-custom { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 10px; }
    .summary-row-custom:last-child { margin-bottom: 0; border-top: 1px solid #f3f4f6; padding-top: 10px; }
    .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; display: flex; gap: 8px; font-size: 13px; color: #991b1b; }
    .warning-box.qris { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
    .modal-actions { padding: 16px 20px; background: #f9fafb; display: flex; gap: 10px; justify-content: flex-end; }
    .modal-actions .btn { padding: 8px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; }
    .modal-actions .btn-cancel { background: #fff; border: 1px solid #d1d5db; color: #374151; }
    .modal-actions .btn-confirm { background: #16a34a; color: #fff; }
    .modal-actions .btn-confirm.qris-btn { background: #2563eb; }
    .btn-mode-qris { background: #fff; border: 1.5px solid #2563eb; color: #2563eb; }
    .btn-mode-qris.active { background: #2563eb; border-color: #2563eb; color: #fff; }
    .qris-info-box { background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1.5px solid #93c5fd; border-radius: 10px; padding: 14px 16px; margin-top: 10px; display: flex; align-items: center; gap: 12px; }
    .qris-info-box .qris-icon { font-size: 28px; }
    .qris-info-box .qris-text { font-size: 12.5px; color: #1e40af; line-height: 1.5; }
    .qris-info-box .qris-text strong { display: block; font-size: 13.5px; }

    /* ============================================================
    POS LAYOUT — MEMANJANG KE BAWAH
    ============================================================ */
    :root {
        --pos-height: calc(100vh - 120px); 
    }

    .pos-layout {
        display: flex;
        gap: 16px;
        align-items: stretch;
        height: var(--pos-height);
        min-height: 500px;
        margin-top: 16px;
    }

    .pos-layout > .card {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        overflow: hidden;
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        border: 1px solid #e2e8f0;
    }
    .pos-layout > .card:first-child { flex: 1 1 auto; min-width: 0; }
    #cartPanel { flex: 0 0 400px; max-width: 400px; }

    .pos-layout > .card > .card-head { flex: 0 0 auto; padding: 16px 20px; }

    /* Area Produk */
    .produk-area {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
    }
    .produk-area .filter-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        padding: 12px 20px;
        border-bottom: 1px solid #e5e7eb;
        background: #fff;
        flex: 0 0 auto;
    }

    /* Scroll Produk */
    .produk-area .card-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: 16px !important;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .produk-area .card-body::-webkit-scrollbar { width: 6px; }
    .produk-area .card-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

    /* Panel Keranjang */
    #cartPanel .card-body {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
        padding: 0 16px 16px;
    }
    #cartPanel .cart-list {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: 8px 0;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    #cartPanel .cart-list::-webkit-scrollbar { width: 5px; }
    #cartPanel .cart-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

    #cartPanel .summary {
        flex: 0 0 auto;
        border-top: 1px solid #e5e7eb;
        padding-top: 14px;
        margin-top: 8px;
    }

    /* ============================================================
    GRID PRODUK
    ============================================================ */
    .produk-grid {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)) !important;
        gap: 14px !important;
    }
    .produk-item {
        position: relative;
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: 12px;
        padding: 10px;
        cursor: pointer;
        transition: all .18s ease;
        display: flex;
        flex-direction: column;
        user-select: none;
        -webkit-tap-highlight-color: transparent;
    }
    .produk-item:hover {
        border-color: #10b981;
        box-shadow: 0 6px 16px rgba(16,185,129,.12);
        transform: translateY(-2px);
    }
    .produk-item:active { transform: scale(.97); }
    .produk-item.out {
        opacity: .55;
        cursor: not-allowed;
        filter: grayscale(.6);
    }
    .produk-item.out::after {
        content: "Habis";
        position: absolute; top: 8px; right: 8px;
        background: #ef4444; color: #fff;
        font-size: 10px; font-weight: 700;
        padding: 2px 7px; border-radius: 999px;
        letter-spacing: .3px;
    }

    .produk-img {
        width: 100%;
        aspect-ratio: 1 / 1;
        overflow: hidden;
        border-radius: 8px;
        background: #f8fafc;
        margin-bottom: 8px;
    }
    .produk-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .produk-item .pname {
        font-weight: 700;
        font-size: 13.5px;
        line-height: 1.3;
        color: #0f172a;
        margin-bottom: 4px;
        min-height: 2.6em;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .produk-item .pprice {
        font-weight: 800;
        font-size: 14px;
        color: #047857;
        margin-bottom: 4px;
    }
    .produk-item .pstock {
        font-size: 11.5px;
        color: #64748b;
        font-weight: 600;
    }
    .produk-item .pstock.low { color: #dc2626; }

    .produk-item .quick-add {
        position: absolute;
        bottom: 8px;
        right: 8px;
        width: 28px; height: 28px;
        border-radius: 50%;
        background: #10b981;
        color: #fff;
        border: none;
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
        display: none;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 6px rgba(16,185,129,.4);
        transition: all .15s;
    }
    .produk-item:hover .quick-add { display: flex; }
    .produk-item .quick-add:hover { background: #059669; }

    /* ============================================================
    TOMBOL "X" KERANJANG
    ============================================================ */
    .cart-close-btn {
        display: none;
        position: absolute;
        top: 14px;
        right: 14px;
        width: 32px;
        height: 32px;
        background: #f1f5f9;
        border: none;
        border-radius: 50%;
        color: #475569;
        font-size: 20px;
        font-weight: bold;
        cursor: pointer;
        z-index: 20;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        line-height: 1;
    }
    .cart-close-btn:hover {
        background: #ef4444;
        color: #fff;
        transform: rotate(90deg);
    }

    /* ============================================================
    RESPONSIVE
    ============================================================ */
    @media (max-width: 1024px) {
        :root { --pos-height: calc(100vh - 140px); }

        .pos-layout {
            display: flex !important;
            flex-direction: row !important;
            gap: 12px;
            height: var(--pos-height);
            margin-top: 12px;
        }
        .pos-layout > .card:first-child {
            flex: 1 1 100% !important;
            max-width: 100% !important;
        }
        #cartPanel {
            display: none !important;
            flex: 0 0 0 !important;
            opacity: 0;
            position: relative !important;
            height: 100% !important;
            max-height: var(--pos-height);
            border-left: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            margin: 0;
        }
        body.cart-open .pos-layout > .card:first-child {
            flex: 1 1 55% !important;
            max-width: 55% !important;
        }
        body.cart-open #cartPanel {
            display: flex !important;
            flex-direction: column;
            flex: 1 1 45% !important;
            max-width: 45% !important;
            opacity: 1;
            box-shadow: -4px 0 15px rgba(0,0,0,.08) !important;
        }
        #cartOverlay { display: none !important; }
        .cart-close-btn { display: flex !important; }
    }

    @media (max-width: 600px) {
        :root { --pos-height: calc(100vh - 110px); }

        .pos-layout { gap: 0; margin-top: 8px; }

        body.cart-open .pos-layout > .card:first-child { display: none !important; }

        body.cart-open #cartPanel {
            position: fixed !important;
            inset: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            height: 100vh !important;
            max-height: 100vh !important;
            border-radius: 0;
            z-index: 99999 !important;
            padding-bottom: env(safe-area-inset-bottom, 0);
            display: flex !important;
            flex-direction: column;
        }
        .cart-close-btn {
            position: absolute !important; /* Menempel di dalam panel */
            top: 12px !important;    /* Posisi dari atas */
            right: 15px !important;  /* Posisi dari kanan */
            width: 32px !important;  
            height: 32px !important; 
            /* Warna Merah Gradient */
            background: linear-gradient(135deg, #ef4444, #b91c1c) !important; 
            color: #fff !important; 
            font-size: 20px !important;
            font-weight: bold !important;
            border: none !important;
            border-radius: 50% !important;
            /* Tambahkan shadow agar lebih elegan */
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.4) !important;
            z-index: 1000 !important;
            display: flex !important;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .cart-close-btn:hover,
        .cart-close-btn:active {
            transform: scale(0.95);
            box-shadow: 0 2px 5px rgba(239, 68, 68, 0.6) !important;
        }

        .produk-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
        }
        .produk-item { padding: 8px; }
        .produk-item .pname { font-size: 12.5px; }
        .produk-item .pprice { font-size: 13px; }
        .produk-item .pstock { font-size: 10.5px; }
        .produk-item .quick-add { display: flex; width: 24px; height: 24px; font-size: 16px; }
    }

    @media (max-width: 768px) {
        .btn { min-height: 42px; font-size: 14px; }
        .filter-kategori { padding: 6px 12px !important; font-size: 12px !important; }
        .card-head { flex-wrap: wrap; gap: 8px; padding: 12px 16px !important; }
        .card-head input[type="text"] { max-width: 100% !important; width: 100%; }
        .produk-area .filter-row { padding: 10px 16px !important; }
    }
    @media (max-width: 400px) {
        .produk-grid { gap: 6px !important; }
        .produk-item .pname { font-size: 11.5px; }
    }

    #bayarInput { font-size: 16px !important; }

    @media (max-width: 480px) {
        .btn-mode { font-size: 12px !important; padding: 8px 4px !important; min-width: 0 !important; }
    }
</style>

<div id="floatingInfo" class="floating-info"></div>

<div id="confirmModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Konfirmasi Transaksi</div>
            <button type="button" class="modal-close" id="btnCloseModal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-desc" id="modalDesc">Pastikan detail transaksi sudah benar.</div>
            <div class="summary-box" id="confirmModalBody"></div>
            <div class="warning-box" id="warningBox"><span>⚠️</span><span>Transaksi tidak dapat dibatalkan.</span></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-cancel" id="btnCancelConfirm">Batal</button>
            <button type="button" class="btn btn-confirm" id="btnOkConfirm">Ya, Simpan</button>
        </div>
    </div>
</div>

<form method="post" id="formKasir">
<input type="hidden" name="cart" id="cartInput">
<input type="hidden" name="mode" id="modeInput" value="tunai">
<input type="hidden" name="redirect" id="redirectInput" value="cetak">

<div class="pos-layout">
    <!-- PRODUK -->
    <div class="card mb-0">
        <div class="card-head">
            <h2>Daftar Produk</h2>
            <input type="text" id="searchProduk" placeholder="Cari produk..." style="max-width:230px">
        </div>
        <div class="produk-area">
            <div class="filter-row">
                <button type="button" class="btn btn-sm btn-primary filter-kategori" data-kategori="">Semua</button>
                <?php foreach (['Minuman', 'Makanan', 'ATK', 'Es Krim'] as $kb): ?>
                <button type="button" class="btn btn-sm btn-outline filter-kategori" data-kategori="<?= e($kb) ?>"><?= e($kb) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="card-body">
                <div class="produk-grid" id="produkGrid">
                <?php foreach ($produk as $p): ?>
                    <div class="produk-item <?= $p['stok'] <= 0 ? 'out' : '' ?>"
                        data-id="<?= (int)$p['id'] ?>"
                        data-nama="<?= e($p['nama']) ?>"
                        data-kategori="<?= e($p['kategori'] ?? '') ?>"
                        data-harga="<?= (float)$p['harga_jual'] ?>"
                        data-stok="<?= (int)$p['stok'] ?>"
                        title="<?= e($p['nama']) ?> — <?= rupiah($p['harga_jual']) ?>">
                        <div class="produk-img"><img src="<?= e(produkImage($p['gambar'])) ?>" alt="<?= e($p['nama']) ?>" loading="lazy"></div>
                        <div class="pname"><?= e($p['nama']) ?></div>
                        <div class="pprice"><?= rupiah($p['harga_jual']) ?></div>
                        <div class="pstock <?= $p['stok'] <= 10 ? 'low' : '' ?>">Stok: <?= (int)$p['stok'] ?> <?= e($p['satuan']) ?></div>
                        <?php if ($p['stok'] > 0): ?>
                        <button type="button" class="quick-add" data-id="<?= (int)$p['id'] ?>" aria-label="Tambah <?= e($p['nama']) ?>">+</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- KERANJANG -->
    <div class="card mb-0" id="cartPanel">
        <button type="button" class="cart-close-btn" id="cartCloseBtn">&times;</button>
        <div class="card-head"><h2>Keranjang</h2><span class="tag tag-green" id="cartCount">0 item</span></div>
        <div class="card-body">
            <div class="cart-list" id="cartList"><div class="empty" style="padding:26px 0">Belum ada item dipilih</div></div>
            <div class="summary">
                <div class="summary-row"><span>Total Tagihan</span><span class="val" id="totalText">Rp 0</span></div>
                <div class="form-group"><label>Nominal Bayar</label><input type="text" name="bayar" id="bayarInput" inputmode="numeric" placeholder="0" autocomplete="off"></div>
                <div class="form-group" style="margin-top:10px">
                    <label>Mode Pembayaran</label>
                    <div style="display:flex; gap:8px; margin-top:6px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-primary btn-mode" data-mode="tunai" style="flex:1; min-width:80px;">💵 Tunai</button>
                        <button type="button" class="btn btn-outline btn-mode" data-mode="hutang" style="flex:1; min-width:80px;">📝 Hutang</button>
                        <button type="button" class="btn btn-outline btn-mode btn-mode-qris" data-mode="qris" style="flex:1; min-width:80px;">📱 QRIS</button>
                    </div>
                </div>
                <div class="form-group" id="namaHutangGroup" style="display:none; margin-top:10px">
                    <label>Nama Orang (Hutang) <span class="req">*</span></label>
                    <input type="text" name="nama_hutang" id="namaHutangInput" placeholder="Contoh: Budi">
                </div>
                <div class="qris-info-box" id="qrisInfoBox" style="display:none;">
                    <div class="qris-icon">📱</div>
                    <div class="qris-text"><strong>Pembayaran QRIS</strong>Nominal otomatis = Total Tagihan.</div>
                </div>
                <div class="kembalian-box" id="kembalianBox"><span id="kembalianLabel">Kembalian</span><span id="kembalianText">Rp 0</span></div>
                <div class="form-group"><label>Keterangan</label><input type="text" name="keterangan" placeholder="Opsional..."></div>
                <div style="display:flex; gap:8px; margin-top:14px;">
                    <button type="button" class="btn btn-outline btn-block" style="flex:1" onclick="submitForm('simpan')">💾 Simpan Saja</button>
                    <button type="button" class="btn btn-primary btn-block" style="flex:1.5" onclick="submitForm('cetak')">🖨️ Simpan & Cetak</button>
                </div>
                <button type="button" class="btn btn-outline btn-block" style="margin-top:8px" id="resetBtn">Kosongkan Keranjang</button>
            </div>
        </div>
    </div>
</div>
</form>

<script>
// ===== SCRIPT UTAMA KASIR =====
const cart = {};
const grid = document.getElementById('produkGrid');
const cartList = document.getElementById('cartList');
const cartInput = document.getElementById('cartInput');
const totalText = document.getElementById('totalText');
const bayarIn = document.getElementById('bayarInput');
const kembaliTx = document.getElementById('kembalianText');
const kembaliLb = document.getElementById('kembalianLabel');
const cartCount = document.getElementById('cartCount');
const modeInput = document.getElementById('modeInput');
const namaGroup = document.getElementById('namaHutangGroup');
const namaInput = document.getElementById('namaHutangInput');
const redirectInput = document.getElementById('redirectInput');
const qrisInfoBox = document.getElementById('qrisInfoBox');
const floatingInfo = document.getElementById('floatingInfo');
const confirmModal = document.getElementById('confirmModal');
const confirmBody = document.getElementById('confirmModalBody');
const btnCancel = document.getElementById('btnCancelConfirm');
const btnOk = document.getElementById('btnOkConfirm');
const btnClose = document.getElementById('btnCloseModal');
const modalTitle = document.getElementById('modalTitle');
const modalDesc = document.getElementById('modalDesc');
const warningBox = document.getElementById('warningBox');

let floatingTimeout;
let currentMode = 'tunai';
let isConfirmed = false;
const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
let filterKategori = '', filterCari = '';

function showFloatingInfo(pesan, tipe = 'info', durasi = 2500) {
    clearTimeout(floatingTimeout);
    floatingInfo.textContent = pesan;
    floatingInfo.className = 'floating-info show ' + tipe;
    floatingTimeout = setTimeout(() => floatingInfo.classList.remove('show'), durasi);
}
function formatAngka(angka) { let val = angka.toString().replace(/\D/g, ''); return val === '' ? '' : parseInt(val, 10).toLocaleString('id-ID'); }
function getAngkaMurni(str) { return !str ? 0 : parseFloat(str.replace(/\./g, '')) || 0; }

bayarIn.addEventListener('keyup', function() {
    let cursorPos = this.selectionStart, oldLength = this.value.length;
    this.value = formatAngka(this.value);
    this.setSelectionRange(cursorPos + (this.value.length - oldLength), cursorPos + (this.value.length - oldLength));
    hitungKembalian();
});

grid.addEventListener('click', e => {
    const el = e.target.closest('.produk-item'); if (!el) return;
    if (el.classList.contains('out')) return showFloatingInfo('Stok habis.', 'error');
    const id = el.dataset.id;
    if (!cart[id]) cart[id] = { id, nama: el.dataset.nama, harga: parseFloat(el.dataset.harga), qty: 0, stok: parseInt(el.dataset.stok) };
    if (cart[id].qty >= cart[id].stok) return showFloatingInfo('Stok tidak mencukupi.', 'error');
    cart[id].qty++;
    render();
    showFloatingInfo(`+1 ${el.dataset.nama}`, 'success', 1200);
    el.style.borderColor = '#10b981';
    el.style.transform = 'scale(.96)';
    setTimeout(() => { el.style.transform = ''; el.style.borderColor = ''; }, 160);
});

cartList.addEventListener('click', e => {
    const id = e.target.dataset.id; if (!id) return;
    if (e.target.classList.contains('plus')) { if (cart[id].qty < cart[id].stok) cart[id].qty++; }
    if (e.target.classList.contains('minus')) { cart[id].qty--; if (cart[id].qty <= 0) delete cart[id]; }
    if (e.target.classList.contains('del')) delete cart[id];
    render();
});

document.getElementById('resetBtn').addEventListener('click', () => {
    if (confirm('Kosongkan keranjang?')) { for (const k in cart) delete cart[k]; bayarIn.value = ''; render(); }
});

document.querySelectorAll('.btn-mode').forEach(btn => {
    btn.addEventListener('click', function() {
        currentMode = this.dataset.mode; modeInput.value = currentMode;
        document.querySelectorAll('.btn-mode').forEach(b => { b.classList.remove('btn-primary', 'active', 'btn-mode-qris'); b.classList.add('btn-outline'); });
        if (currentMode === 'tunai') { this.classList.remove('btn-outline'); this.classList.add('btn-primary'); }
        else if (currentMode === 'hutang') { this.classList.remove('btn-outline'); this.classList.add('btn-primary'); }
        else if (currentMode === 'qris') { this.classList.remove('btn-outline'); this.classList.add('btn-mode-qris', 'active'); }

        if (currentMode === 'hutang') {
            namaGroup.style.display = ''; namaInput.required = true; qrisInfoBox.style.display = 'none';
            kembaliLb.textContent = 'Sisa Hutang'; bayarIn.placeholder = '0 (kosong = full hutang)'; bayarIn.disabled = false;
        } else if (currentMode === 'qris') {
            namaGroup.style.display = 'none'; namaInput.required = false; qrisInfoBox.style.display = '';
            kembaliLb.textContent = 'Kembalian'; bayarIn.placeholder = 'Otomatis'; bayarIn.value = ''; bayarIn.disabled = true;
        } else {
            namaGroup.style.display = 'none'; namaInput.required = false; qrisInfoBox.style.display = 'none';
            kembaliLb.textContent = 'Kembalian'; bayarIn.placeholder = '0'; bayarIn.disabled = false;
        }
        hitungKembalian();
    });
});

function render() {
    const items = Object.values(cart);
    cartList.innerHTML = !items.length ? '<div class="empty" style="padding:26px 0">Belum ada item dipilih</div>' :
        items.map(i => `<div class="cart-row"><div style="flex:1"><div class="cname">${i.nama}</div><div class="cprice">${rp(i.harga)} × ${i.qty} = <strong>${rp(i.harga * i.qty)}</strong></div></div><div class="qty-ctrl"><button type="button" class="minus" data-id="${i.id}">−</button><span>${i.qty}</span><button type="button" class="plus" data-id="${i.id}">+</button></div><button type="button" class="del" data-id="${i.id}">×</button></div>`).join('');
    const total = items.reduce((s, i) => s + i.harga * i.qty, 0);
    totalText.textContent = rp(total); cartCount.textContent = items.reduce((s, i) => s + i.qty, 0) + ' item';
    cartInput.value = JSON.stringify(items.map(i => ({ id: i.id, qty: i.qty })));
    hitungKembalian();
}

function hitungKembalian() {
    const total = Object.values(cart).reduce((s, i) => s + i.harga * i.qty, 0);
    if (currentMode === 'qris') { kembaliTx.textContent = rp(0); return; }
    const bayar = getAngkaMurni(bayarIn.value);
    if (currentMode === 'hutang') { const sisa = total - bayar; kembaliTx.textContent = rp(sisa > 0 ? sisa : 0); kembaliTx.style.color = '#dc2626'; }
    else { const kembali = bayar - total; kembaliTx.textContent = rp(kembali > 0 ? kembali : 0); kembaliTx.style.color = kembali < 0 ? '#dc2626' : ''; }
}

function applyFilter() {
    const q = filterCari.toLowerCase();
    grid.querySelectorAll('.produk-item').forEach(el => {
        el.style.display = (el.dataset.nama.toLowerCase().includes(q) && (filterKategori === '' || el.dataset.kategori === filterKategori)) ? '' : 'none';
    });
}
document.getElementById('searchProduk').addEventListener('input', function () { filterCari = this.value; applyFilter(); });
document.querySelectorAll('.filter-kategori').forEach(btn => {
    btn.addEventListener('click', function () {
        filterKategori = this.dataset.kategori;
        document.querySelectorAll('.filter-kategori').forEach(b => { b.classList.remove('btn-primary'); b.classList.add('btn-outline'); });
        this.classList.remove('btn-outline'); this.classList.add('btn-primary'); applyFilter();
    });
});

function submitForm(action) {
    redirectInput.value = action;
    const form = document.getElementById('formKasir');
    const event = new Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(event);
}

document.getElementById('formKasir').addEventListener('submit', e => {
    if (isConfirmed) return;
    e.preventDefault();
    if (!Object.keys(cart).length) return showFloatingInfo('Keranjang masih kosong.', 'error');
    const total = Object.values(cart).reduce((s, i) => s + i.harga * i.qty, 0);
    const bayar = getAngkaMurni(bayarIn.value);

    if (currentMode === 'qris') {
        modalTitle.textContent = 'Konfirmasi QRIS'; modalTitle.className = 'modal-title qris-title';
        modalDesc.textContent = 'Pastikan pembayaran QRIS sudah diterima.';
        confirmBody.innerHTML = `<div class="summary-row-custom"><span>Metode:</span><strong style="color:#2563eb;">📱 QRIS</strong></div><div class="summary-row-custom"><span>Total:</span><strong>${rp(total)}</strong></div><div class="summary-row-custom"><span>Dibayar:</span><strong>${rp(total)}</strong></div>`;
        warningBox.className = 'warning-box qris'; warningBox.innerHTML = '<span>ℹ️</span><span>Pastikan notifikasi pembayaran masuk.</span>';
        btnOk.textContent = 'Ya, Konfirmasi'; btnOk.classList.add('qris-btn'); confirmModal.classList.add('show'); return;
    }
    if (currentMode === 'tunai' && bayar < total) return showFloatingInfo('Uang bayar kurang.', 'error');
    if (currentMode === 'hutang') {
        if (bayar >= total) return showFloatingInfo('Uang cukup. Gunakan mode Tunai.', 'error');
        if (!namaInput.value.trim()) { showFloatingInfo('Nama wajib diisi.', 'error'); return namaInput.focus(); }
        modalTitle.textContent = 'Konfirmasi Hutang'; modalTitle.className = 'modal-title';
        modalDesc.textContent = 'Pastikan detail hutang benar.';
        confirmBody.innerHTML = `<div class="summary-row-custom"><span>Nama:</span><strong>${namaInput.value}</strong></div><div class="summary-row-custom"><span>Total:</span><strong>${rp(total)}</strong></div><div class="summary-row-custom"><span>DP:</span><strong>${rp(bayar)}</strong></div><div class="summary-row-custom"><span>Sisa:</span><strong style="color:#dc2626;">${rp(total - bayar)}</strong></div>`;
        warningBox.className = 'warning-box'; warningBox.innerHTML = '<span>⚠️</span><span>Transaksi tidak dapat dibatalkan.</span>';
        btnOk.textContent = 'Ya, Simpan'; btnOk.classList.remove('qris-btn'); confirmModal.classList.add('show'); return;
    }
    isConfirmed = true; document.getElementById('formKasir').submit();
});

const closeModal = () => { confirmModal.classList.remove('show'); isConfirmed = false; };
btnCancel.addEventListener('click', closeModal); btnClose.addEventListener('click', closeModal);
btnOk.addEventListener('click', () => { confirmModal.classList.remove('show'); isConfirmed = true; document.getElementById('formKasir').submit(); });

document.addEventListener('DOMContentLoaded', function() {
    const cartToggleBtn = document.getElementById('cartToggleBtn');
    const cartCloseBtn  = document.getElementById('cartCloseBtn');
    const cartOverlay   = document.getElementById('cartOverlay');

    const openCart  = () => document.body.classList.add('cart-open');
    const closeCart = () => document.body.classList.remove('cart-open');

    if (cartToggleBtn) cartToggleBtn.addEventListener('click', e => { e.preventDefault(); openCart(); });
    if (cartCloseBtn)  cartCloseBtn.addEventListener('click',  e => { e.preventDefault(); closeCart(); });
    if (cartOverlay)   cartOverlay.addEventListener('click', closeCart);

    let mq = window.matchMedia('(min-width: 1025px)');
    const handleMQ = e => { if (e.matches) closeCart(); };
    if (mq.addEventListener) mq.addEventListener('change', handleMQ);
    else mq.addListener(handleMQ);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeCart();
            if (confirmModal.classList.contains('show')) {
                confirmModal.classList.remove('show');
                isConfirmed = false;
                btnOk.classList.remove('qris-btn');
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>