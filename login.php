<?php
require_once 'config/database.php';

if (isLogin()) redirect('index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        if ($u && password_verify($password, $u['password'])) {
            $_SESSION['user_id']   = $u['id'];
            $_SESSION['user_nama'] = $u['nama'];
            $_SESSION['user_role'] = $u['role'];
            redirect('index.php');
        } else {
            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Kasir BMT</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        min-height: 100vh;
        font-family: 'Segoe UI', Arial, sans-serif;
        color: #17251b;
        display: flex;
        flex-direction: column;
        align-items: center;
        /* Padding body dihilangkan agar running text bisa 100% rapat ke layar */
        background-color: #052e1b;
        background-image:
            radial-gradient(circle at 15% 20%, rgba(32, 189, 114, 0.25) 0%, transparent 50%),
            radial-gradient(circle at 85% 80%, rgba(19, 117, 71, 0.3) 0%, transparent 50%),
            linear-gradient(135deg, #052e1b 0%, #04140e 100%);
    }

    /* ===== RUNNING TEXT (RAPAT KIRI KANAN) ===== */
    .running-text {
        width: 100%;
        margin: 0;
        padding: 10px 0;
        background: linear-gradient(90deg,
            rgba(32, 189, 114, 0.10) 0%,
            rgba(32, 189, 114, 0.18) 50%,
            rgba(32, 189, 114, 0.10) 100%);
        border-top: 1px solid rgba(32, 189, 114, 0.40);
        border-bottom: 1px solid rgba(32, 189, 114, 0.40);
        border-left: none;
        border-right: none;
        border-radius: 0; /* Rata pinggir */
        overflow: hidden;
        white-space: nowrap;
        position: relative;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.30);
        z-index: 10;
    }

    /* Fade tipis di ujung kiri & kanan */
    .running-text::before,
    .running-text::after {
        content: "";
        position: absolute;
        top: 0;
        bottom: 0;
        width: 40px;
        z-index: 3;
        pointer-events: none;
    }
    .running-text::before {
        left: 0;
        background: linear-gradient(to right, #052e1b 0%, transparent 100%);
    }
    .running-text::after {
        right: 0;
        background: linear-gradient(to left, #052e1b 0%, transparent 100%);
    }

    .running-text .marquee-track {
        display: inline-flex;
        animation: marquee 45s linear infinite;
        will-change: transform;
    }

    .running-text:hover .marquee-track {
        animation-play-state: paused;
    }

    .running-text .marquee-track span {
        display: inline-block;
        padding-right: 2.5rem;
        color: #20bd72;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.5px;
        line-height: 1.5;
        text-shadow: 0 0 12px rgba(32, 189, 114, 0.40);
    }

    .running-text .marquee-track .highlight {
        color: #ffd166;
        text-shadow: 0 0 12px rgba(255, 209, 102, 0.45);
    }

    .running-text .marquee-track .sep {
        color: rgba(32, 189, 114, 0.55);
        padding: 0 0.25rem;
    }

    @keyframes marquee {
        from { transform: translateX(0); }
        to   { transform: translateX(-50%); }
    }

    @media (prefers-reduced-motion: reduce) {
        .running-text .marquee-track { animation: none; padding-left: 16px; }
        .running-text { overflow-x: auto; }
    }

    /* ===== CONTAINER TENGAH (Agar Card Login Tetap di Tengah) ===== */
    .main-container {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px; /* Padding dipindah ke sini agar card tidak mepet layar */
        width: 100%;
    }

    /* ===== LOGIN CARD ===== */
    .login-card {
        width: 100%;
        max-width: 400px;
        background: #ffffff;
        border-radius: 20px;
        padding: 32px 26px 0 26px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        animation: masuk 0.4s ease both;
        overflow: hidden;
    }

    @keyframes masuk {
        from { opacity: 0; transform: translateY(12px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        margin-bottom: 26px;
    }

    .brand-logo {
        width: 110px;
        height: 110px;
        border-radius: 50%;
        background: linear-gradient(135deg, #137547, #20bd72);
        border: 4px solid #e1f5e8;
        box-shadow: 0 10px 28px rgba(21, 153, 87, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        margin-bottom: 16px;
    }

    .brand-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        border-radius: 50%;
        filter: brightness(1.05);
    }

    .brand h1 {
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .brand p {
        font-size: 10px;
        font-weight: 700;
        color: #159957;
        letter-spacing: 1.5px;
        margin-top: 3px;
    }

    .form-title {
        font-size: 22px;
        font-weight: 800;
        text-align: center;
        letter-spacing: -0.5px;
        margin-bottom: 6px;
        min-height: 30px;
        white-space: nowrap;
        overflow: hidden;
    }

    .typewriter {
        border-right: 2px solid #20bd72;
        animation: blink 0.7s step-end infinite;
    }

    @keyframes blink {
        50% { border-color: transparent; }
    }

    .form-desc {
        font-size: 12px;
        color: #748279;
        text-align: center;
        line-height: 1.6;
        margin-bottom: 22px;
    }

    .alert {
        padding: 11px 13px;
        margin-bottom: 18px;
        border-radius: 10px;
        font-size: 12px;
        background: #fff4f5;
        border: 1px solid #f4c7cc;
        color: #a92333;
    }

    .form-group { margin-bottom: 16px; }

    .form-label {
        display: block;
        margin-bottom: 7px;
        font-size: 11px;
        font-weight: 700;
        color: #26372c;
    }

    .input-wrapper { position: relative; }

    .form-input {
        width: 100%;
        height: 48px;
        padding: 0 44px;
        border: 1px solid #e2e9e4;
        border-radius: 11px;
        outline: none;
        background: #fbfdfb;
        color: #17251b;
        font-size: 14px;
        transition: all 0.2s ease;
    }

    .form-input:focus {
        border-color: #20bd72;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(32, 189, 114, 0.15);
    }

    .input-icon {
        position: absolute;
        left: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #8a9b90;
        pointer-events: none;
        display: flex;
    }

    .input-icon svg { width: 18px; height: 18px; }

    .password-toggle {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: #819188;
        cursor: pointer;
    }

    .password-toggle:hover { background: #eaf7ef; color: #137547; }
    .password-toggle svg { width: 18px; height: 18px; }

    .login-button {
        width: 100%;
        height: 50px;
        margin-top: 6px;
        border: none;
        border-radius: 11px;
        background: linear-gradient(115deg, #137547, #20bd72);
        color: #ffffff;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        transition: 0.2s;
        box-shadow: 0 8px 20px rgba(21, 153, 87, 0.3);
    }

    .login-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 25px rgba(21, 153, 87, 0.4);
    }

    .login-button:disabled {
        opacity: 0.7;
        cursor: wait;
        transform: none;
    }

    .form-footer {
        margin-top: 22px;
        text-align: center;
        font-size: 10px;
        color: #9aa69e;
        padding-bottom: 20px;
    }

    /* ===== MOBILE ===== */
    @media (max-width: 480px) {
        .main-container { padding: 16px; }
        .login-card { padding: 26px 20px 0 20px; border-radius: 16px; }
        .form-title { font-size: 18px; }
        .brand-logo { width: 96px; height: 96px; padding: 14px; border-width: 3px; }
        .brand h1 { font-size: 22px; }
        
        .running-text { padding: 8px 0; }
        .running-text .marquee-track span { font-size: 11px; letter-spacing: 0.3px; padding-right: 1.5rem; }
        .running-text::before,
        .running-text::after { width: 24px; }
        .running-text .marquee-track .sep { padding: 0 0.15rem; }
    }
</style>
</head>
<body>

    <!-- RUNNING TEXT FULL WIDTH -->
    <div class="running-text" role="marquee" aria-label="Informasi Kasir BMT">
        <div class="marquee-track">
            <!-- SALINAN 1 -->
            <span>
                <span class="highlight">Assalamu'alaikum</span> — Selamat datang di
                <span class="highlight">Kasir BMT Baitul Maal Wat Tanwil</span>
                SMK As Syafi'iyah 01 Jakarta
                <span class="sep">•</span>
                Jl. Bukit Duri Selatan No.29, Bukit Duri, Tebet, Jakarta Selatan 12840
                <span class="sep">•</span>
                <span class="highlight">Jam Operasional:</span> Senin–Jumat 07.30–15.30 WIB
                <span class="sep">•</span>
                <span class="highlight">Kontak:</span> 0812-3456-7890 (WhatsApp)
                <span class="sep">•</span>
                <span class="highlight">Email:</span> kasirbmt@assyafiiyah01.sch.id
                <span class="sep">•</span>
                "Berkah, Amanah, Profesional"
                <span class="sep">•</span>
            </span>
            <!-- SALINAN 2 (Wajib sama persis) -->
            <span>
                <span class="highlight">Assalamu'alaikum</span> — Selamat datang di
                <span class="highlight">Kasir BMT Baitul Maal Wat Tanwil</span>
                SMK As Syafi'iyah 01 Jakarta
                <span class="sep">•</span>
                Jl. Bukit Duri Selatan No.29, Bukit Duri, Tebet, Jakarta Selatan 12840
                <span class="sep">•</span>
                <span class="highlight">Jam Operasional:</span> Senin–Jumat 07.30–15.30 WIB
                <span class="sep">•</span>
                <span class="highlight">Kontak:</span> 0812-3456-7890 (WhatsApp)
                <span class="sep">•</span>
                <span class="highlight">Email:</span> kasirbmt@assyafiiyah01.sch.id
                <span class="sep">•</span>
                "Berkah, Amanah, Profesional"
                <span class="sep">•</span>
            </span>
        </div>
    </div>

    <!-- CONTAINER TENGAH UNTUK CARD LOGIN -->
    <div class="main-container">
        <main class="login-card">

            <div class="brand">
                <div class="brand-logo">
                    <img src="assets/loge.png" alt="Logo Kasir BMT">
                </div>
                <h1>Kasir BMT</h1>
                <p>BAITUL MAAL WAT TAMWIL</p>
            </div>

            <h2 class="form-title"><span id="typewriter" class="typewriter"></span></h2>
            <p class="form-desc">Masuk untuk mengakses dashboard kasir.</p>

            <?php if ($error): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form id="loginForm" method="POST" autocomplete="on">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="8" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>
                            </svg>
                        </span>
                        <input class="form-input" type="text" name="username" id="username" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input class="form-input" type="password" name="password" id="password" placeholder="Masukkan password" required>
                        <button class="password-toggle" type="button" id="togglePassword" aria-label="Tampilkan password">
                            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="login-button" type="submit" id="loginButton">
                    <span id="buttonText">Masuk ke Dashboard</span>
                </button>
            </form>

            <footer class="form-footer">
                &copy; <?= date('Y') ?> Kasir BMT By : XII RPL 
            </footer>

        </main>
    </div>

<script>
    /* ===== EFEK MENGETIK UNTUK JUDUL ===== */
    (function () {
        const target = document.getElementById('typewriter');
        const text = 'Selamat datang kembali';
        let i = 0;

        function ketik() {
            if (i <= text.length) {
                target.textContent = text.slice(0, i);
                i++;
                setTimeout(ketik, 70);
            }
        }

        ketik();
    })();

    /* ===== TOGGLE PASSWORD ===== */
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    const togglePassword = document.getElementById('togglePassword');
    const loginButton = document.getElementById('loginButton');
    const buttonText = document.getElementById('buttonText');

    togglePassword.addEventListener('click', function () {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        eyeIcon.innerHTML = isHidden
            ? '<path d="M3 3l18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.2"/><path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4-.8"/>'
            : '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>';
    });

    document.getElementById('loginForm').addEventListener('submit', function () {
        loginButton.disabled = true;
        buttonText.textContent = 'Memproses...';
    });
</script>
</body>
</html>         