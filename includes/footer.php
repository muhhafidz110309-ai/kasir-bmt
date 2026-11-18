          <!-- ⬆️ ISI HALAMAN BERAKHIR DI SINI ⬆️ -->
          </div><!-- /.content -->

      </main><!-- /.main -->

  </div><!-- /.app -->

  <script>
  /* ============================================================
    SIDEBAR TOGGLE — DESKTOP / TABLET / MOBILE
    - Desktop (>1024px) : collapse lebar sidebar
    - Tablet  (≤1024px) : geser sidebar ke kiri
    - Mobile  (≤768px)  : slide-in + overlay
    ============================================================ */
  (function () {
      const toggle   = document.getElementById('sidebarToggle');
      const sidebar  = document.getElementById('sidebar');
      const overlay  = document.getElementById('sidebarOverlay');
      const closeBtn = document.getElementById('sidebarCloseBtn');
      if (!toggle || !sidebar || !overlay) return;

      const mobileQuery = window.matchMedia('(max-width: 768px)');
      const tabletQuery = window.matchMedia('(max-width: 1024px)');

      function openMobileSidebar() {
          sidebar.classList.add('open');
          overlay.classList.add('show');
          toggle.setAttribute('aria-expanded', 'true');
          document.body.classList.add('sidebar-mobile-open');
          document.body.style.overflow = 'hidden';
      }
      function closeMobileSidebar() {
          sidebar.classList.remove('open');
          overlay.classList.remove('show');
          toggle.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('sidebar-mobile-open');
          document.body.style.overflow = '';
      }
      function toggleCollapsed() {
          const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
          toggle.setAttribute('aria-expanded', String(!isCollapsed));
      }
      function toggleSidebar() {
          if (mobileQuery.matches) {
              sidebar.classList.contains('open') ? closeMobileSidebar() : openMobileSidebar();
          } else {
              toggleCollapsed(); // tablet & desktop sama-sama pakai class .sidebar-collapsed
          }
      }

      toggle.addEventListener('click', toggleSidebar);
      overlay.addEventListener('click', closeMobileSidebar);
      if (closeBtn) {
          closeBtn.addEventListener('click', () => {
              if (mobileQuery.matches) closeMobileSidebar();
              else { document.body.classList.add('sidebar-collapsed'); toggle.setAttribute('aria-expanded', 'false'); }
          });
      }
      sidebar.querySelectorAll('.nav-item').forEach(link => {
          link.addEventListener('click', () => { if (mobileQuery.matches) closeMobileSidebar(); });
      });

      function handleBreakpointChange() {
          closeMobileSidebar();
          document.body.classList.remove('sidebar-collapsed');
          toggle.setAttribute('aria-expanded', 'false');
      }
      if (mobileQuery.addEventListener) {
          mobileQuery.addEventListener('change', handleBreakpointChange);
          tabletQuery.addEventListener('change', handleBreakpointChange);
      } else {
          mobileQuery.addListener(handleBreakpointChange);
          tabletQuery.addListener(handleBreakpointChange);
      }

      window.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileSidebar(); });
  })();

  /* ============================================================
    TOGGLE KERANJANG — SEMUA DEVICE
    ============================================================ */
  (function () {
      const cartToggleBtn = document.getElementById('cartToggleBtn');
      if (!cartToggleBtn) return;
      cartToggleBtn.addEventListener('click', function (e) {
          e.preventDefault();
          document.body.classList.toggle('cart-open');
          cartToggleBtn.setAttribute('aria-expanded',
              String(document.body.classList.contains('cart-open')));
      });
      window.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
              document.body.classList.remove('cart-open');
              cartToggleBtn.setAttribute('aria-expanded', 'false');
          }
      });
  })();

  /* ============================================================
    JAM REAL-TIME
    ============================================================ */
  (function () {
      const bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
      const el = document.getElementById('liveClockText');
      if (!el) return;
      function updateClock() {
          const now = new Date();
          const d  = String(now.getDate()).padStart(2, '0');
          const b  = bulan[now.getMonth()];
          const y  = now.getFullYear();
          const hh = String(now.getHours()).padStart(2, '0');
          const mm = String(now.getMinutes()).padStart(2, '0');
          const ss = String(now.getSeconds()).padStart(2, '0');
          el.textContent = `${d} ${b} ${y} ${hh}:${mm}:${ss}`;
      }
      updateClock();
      setInterval(updateClock, 1000);
  })();
  </script>
  </body>
  </html>