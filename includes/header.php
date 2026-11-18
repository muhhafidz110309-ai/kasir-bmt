<?php
        require_once __DIR__ . '/../config/database.php';
        requireLogin();

        $currentPage = basename($_SERVER['PHP_SELF']);
        $u = user();

        // Menu: [file, label, bootstrap-icon-class]
        $menu = [
            ['index.php',       'Dashboard',   'bi bi-house'],
            ['kasir.php',       'Kasir',       'bi bi-cart3'],
            ['transaksi.php',   'Transaksi',   'bi bi-receipt'],
            ['hutang.php',      'Hutang',      'bi bi-cash-stack'],
            ['pengeluaran.php', 'Pengeluaran', 'bi bi-wallet2'],
            ['produk.php',      'Produk',      'bi bi-box-seam'],
            ['laporan.php',     'Laporan',     'bi bi-bar-chart-line'],
        ];
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
        <meta charset="UTF-8">
        <!-- Viewport disetel agar tampilan HP pas dan tidak bisa di-zoom sembarangan -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title><?= isset($pageTitle) ? e($pageTitle) . ' · ' : '' ?>Kasir BMT</title>
        <link rel="stylesheet" href="assets/style.css">
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <style>
        /* ============================================================
        HEADER STYLES
        ============================================================ */
        :root {
        --header-height: 64px;
        --header-shadow: 0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04);
        --header-transition: all .25s cubic-bezier(.4,0,.2,1);
        }

        /* Ikon sidebar pakai Bootstrap Icons */
        .nav-item i {
            font-size: 18px;
            width: 20px;
            text-align: center;
            line-height: 1;
            flex: 0 0 20px;
        }

        /* Topbar default */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: nowrap; /* Mencegah elemen turun ke bawah */
            padding: 0 20px;
            height: var(--header-height);
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: var(--header-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 0 0 auto;
        }

        /* TOMBOL KASIR */
        .btn-cart-toggle {
            position: relative;
            z-index: 100001;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-width: 86px;
            height: 38px;
            padding: 0 14px;
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            color: var(--g700, #047857);
            font-family: inherit;
            font-weight: 700;
            font-size: 13px;
            line-height: 1;
            white-space: nowrap;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
            transition: var(--header-transition);
            pointer-events: auto;
        }
        .btn-cart-toggle:hover {
            background: #f0fdf4;
            border-color: var(--g500, #10b981);
        }
        .btn-cart-toggle i { font-size: 18px; flex: 0 0 18px; line-height: 1; }
        .btn-cart-label { display: inline; margin: 0; font-size: 13px; font-weight: 700; }

        .cart-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            display: none;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            border: 2px solid #fff;
            z-index: 1;
        }
        .cart-badge.show { display: flex; }

        .badge-date {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 7px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            white-space: nowrap;
        }
        .badge-date i { font-size: 13px; }
        
        /* Beri lebar stabil agar detik tidak bikin layout geser */
        #liveClock {
            display: inline-block;
            min-width: 210px;
            text-align: left;
            font-variant-numeric: tabular-nums;
        }

        /* ALERT */
        .alert {
            margin: 0 20px 16px; padding: 12px 16px; border-radius: 10px;
            font-size: 13.5px; font-weight: 500; display: flex; align-items: flex-start; gap: 10px;
            border: 1px solid transparent;
        }
        .alert-success { background: #f0fdf4; color: #065f46; border-color: #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        /* ============================================================
        PERBAIKAN TAMPILAN MOBILE (HEADER & TOPBAR)
        ============================================================ */
        @media (max-width: 768px) {
            /* 1. Topbar tetap lebar, tapi kita atur jaraknya */
            .topbar {
                padding: 0 12px !important; 
                gap: 8px;
            }

            /* 2. Tombol Kasir jadi ikon bulat kecil */
            .btn-cart-toggle {
                min-width: 34px !important; 
                width: 34px !important; 
                height: 34px !important;
                padding: 0 !important; 
                background: var(--g700, #047857); 
                color: #fff; 
                border: none;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .btn-cart-toggle i { color: #fff; font-size: 16px; }
            .btn-cart-label { display: none; } /* Sembunyikan tulisan Kasir */

            /* 3. Area Judul di tengah */
            .topbar-info {
                flex: 1;
                min-width: 0;
                margin: 0;
            }
            .topbar-info h1 {
                font-size: 15px !important;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                margin: 0;
                display: none; /* Sembunyikan judul "Kasir" di HP untuk menghemat tempat */
            }
            .topbar-info .subtitle {
                display: none !important;
            }

            /* 4. TANGGAL & JAM MINI (Tetap di atas, tidak turun ke bawah) */
            .badge-date {
                padding: 5px 8px !important;
                font-size: 10px !important;
                gap: 4px !important;
                border-radius: 6px !important;
                background: #f1f5f9 !important;
                flex-shrink: 0;
                margin-left: auto; /* Dorong jam ke kanan */
            }
            .badge-date i {
                font-size: 11px !important;
            }
            #liveClock {
                min-width: 0 !important;
                font-size: 10px !important;
            }

            /* 5. Tombol hamburger menu */
            .sidebar-toggle {
                width: 34px !important;
                height: 34px !important;
                margin-right: 4px !important;
                flex-shrink: 0;
            }
        }

        /* ============================================================
        AUTO-SHORTEN LAYOUT SAAT KERANJANG TERBUKA (TABLET/MOBILE)
        ============================================================ */
        @media (max-width: 1024px) {
            .pos-layout {
                display: flex !important;
                flex-direction: row !important;
                gap: 10px;
                transition: all 0.3s ease;
                align-items: flex-start;
            }

            .pos-layout > .card:first-child {
                flex: 1 1 100% !important;
                max-width: 100% !important;
                transition: all 0.3s ease;
            }

            #cartPanel {
                display: none !important;
                flex: 0 0 0 !important;
                opacity: 0;
                transition: all 0.3s ease;
                position: relative !important;
                height: auto !important;
                max-height: calc(100vh - 90px);
                overflow-y: auto;
                box-shadow: none !important;
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
                display: block !important;
                flex: 1 1 45% !important;
                max-width: 45% !important;
                opacity: 1;
                box-shadow: -2px 0 10px rgba(0,0,0,0.05) !important;
            }

            #cartOverlay {
                display: none !important;
            }

            /* Tombol close untuk Tablet (posisinya di kanan atas panel) */
            .cart-close-btn {
                display: flex !important;
                position: absolute; 
                top: 10px; 
                right: 10px;
                width: 28px; 
                height: 28px;
                background: #f1f5f9; 
                border: none; 
                border-radius: 50%;
                color: #475569; 
                font-size: 20px; 
                font-weight: bold;
                cursor: pointer; 
                z-index: 10; 
                align-items: center; 
                justify-content: center;
            }
        }

        /* ============================================================ */
        /* Untuk layar sangat kecil (HP), keranjang full screen         */
        /* ============================================================ */
        @media (max-width: 600px) {
            body.cart-open .pos-layout > .card:first-child {
                display: none !important;
            }
            body.cart-open #cartPanel {
                flex: 1 1 100% !important;
                max-width: 100% !important;
                position: fixed !important;
                top: 0 !important; right: 0 !important; bottom: 0 !important;
                width: 100% !important;
                height: 100vh !important;
                z-index: 99999 !important;
                border-radius: 0;
                padding-top: 50px; 
            }

            /* Posisi Tombol Close khusus di HP (Mobile) */
            .cart-close-btn {
                position: absolute !important; /* Kunci posisi di dalam panel, bukan di layar */
                top: 12px !important;    
                right: 65px !important;  /* Geser ke KIRI agar tidak menabrak tombol merah */
                width: 28px !important;  
                height: 28px !important; 
                font-size: 16px !important; 
                background: #f1f5f9 !important; 
                color: #64748b !important; 
                border: none !important;
                box-shadow: none !important;
            }   
        }
                /* Paksa pindahkan tombol merah ke kiri (khusus HP) */
        @media (max-width: 600px) {
            /* Coba targetkan berdasarkan class umum bootstrap atau custom */
            .btn-danger, 
            .btn-hapus, 
            .btn-clear, 
            #cartPanel .btn-danger {
                position: absolute !important;
                right: 70px !important; /* Geser ke kiri */
                top: 15px !important;   /* Sesuaikan tingginya */
                z-index: 1000 !important;
            }

            /* Sembunyikan tombol close lama jika tidak terpakai */
            .cart-close-btn {
                display: none !important;
            }
        }   
        </style>
        </head>
        <body>

        <div class="app">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-logo-wrap"><img src="assets/loge.png" alt="Logo" class="brand-logo"></div>
            <div class="brand-text"><strong>Asvie Mart</strong><span class="brand-sub">Baitul Maal wat Tamwil</span></div>
        </div>
        <nav class="nav">
            <?php foreach ($menu as $m): ?>
                <a href="<?= $m[0] ?>" class="nav-item <?= $currentPage === $m[0] ? 'active' : '' ?>">
                    <i class="<?= $m[2] ?>" aria-hidden="true"></i>
                    <span><?= $m[1] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip">
                <div class="avatar"><?= strtoupper(substr($u['nama'], 0, 1)) ?></div>
                <div><strong><?= e($u['nama']) ?></strong><small><?= e(ucfirst($u['role'])) ?></small></div>
            </div>
            <a href="logout.php" class="btn-logout">Keluar</a>
        </div>
        </aside>

        <!-- MAIN -->
        <main class="main">
        <header class="topbar">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Buka Menu">
                <i class="bi bi-list" style="font-size:22px;"></i>
            </button>
            
            <div class="topbar-info">
                <h1><?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?></h1>
                <p class="subtitle"><?= isset($pageSubtitle) ? e($pageSubtitle) : 'Sistem Informasi Kasir' ?></p>
            </div>
            
            <div class="topbar-right">
                <?php if ($currentPage === 'kasir.php'): ?>
                    <button type="button" class="btn-cart-toggle" id="cartToggleBtn" aria-label="Buka Keranjang">
                        <i class="bi bi-cart3" aria-hidden="true"></i>
                        <span class="btn-cart-label">Kasir</span>
                    </button>
                <?php endif; ?>
                <span class="badge-date">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <span id="liveClock"><?= tanggalIndo(date('Y-m-d H:i:s')) ?></span>
                </span>
            </div>
        </header>

        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <?php if ($msg = flash('error')): ?><div class="alert alert-error"><?= e($msg) ?></div><?php endif; ?>

        <div id="cartOverlay"></div>
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="content">
        <!-- ISI HALAMAN -->

        <!-- ============================================================
            LIVE CLOCK SCRIPT — TANGGAL + JAM IKUT UPDATE
            ============================================================ -->
        <script>
        (function () {
            const SERVER_TS_MS   = <?= json_encode((int)(microtime(true) * 1000)) ?>;
            const BROWSER_TS_MS  = Date.now();
            const CLOCK_DRIFT_MS = SERVER_TS_MS - BROWSER_TS_MS;

            const hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
            const bulan = ['Januari','Februari','Maret','April','Mei','Juni',
                        'Juli','Agustus','September','Oktober','November','Desember'];

            const el = document.getElementById('liveClock');
            if (!el) return;

            const pad = n => String(n).padStart(2, '0');

            function updateClock() {
                const now = new Date(Date.now() + CLOCK_DRIFT_MS);

                const d     = now.getDate();
                const day   = hari[now.getDay()];
                const month = bulan[now.getMonth()];
                const year  = now.getFullYear();
                const hh    = pad(now.getHours());
                const mm    = pad(now.getMinutes());
                const ss    = pad(now.getSeconds());

                el.textContent = `${day}, ${d} ${month} ${year} ${hh}:${mm}:${ss}`;
            }

            updateClock();
            setInterval(updateClock, 1000);
        })();
        </script>

        </body>
        </html>