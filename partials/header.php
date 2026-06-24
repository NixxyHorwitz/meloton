<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
// SEO settings (read once)
$_seo_title  = setting($pdo, 'seo_title', 'Meloton');
$_seo_desc   = setting($pdo, 'seo_description', '');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og       = setting($pdo, 'seo_og_image', '');
$_seo_twcard   = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author   = setting($pdo, 'seo_author', 'Meloton');
$_seo_og_title = setting($pdo, 'seo_og_title', '');
$_seo_og_desc  = setting($pdo, 'seo_og_description', '');
$_seo_og_type  = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFDE00">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php
$absolute_og = $_seo_og ? (preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'))) : '';
$fav_url = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<?php endif; ?>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<style>
/* Base Neo-brutalism icon alignment helper */
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
</style>
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <a href="/home" class="topbar__logo">
      <?php if ($_favicon): ?>
        <img src="<?= htmlspecialchars($_favicon) ?>" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:6px;border:1.5px solid #1A1A1A;flex-shrink:0">
      <?php else: ?>
        <i class="ph-fill ph-film-strip" style="font-size:24px;color:var(--brand)"></i>
      <?php endif; ?>
      <span style="white-space:nowrap">Melo<strong>ton</strong></span>
    </a>
    <div class="topbar__right">
      <?php if (!empty($user)): ?>
      <?php
      function fmt_short(float $n): string {
        if ($n >= 1_000_000) return number_format($n/1_000_000, 1, '.', '') . 'jt';
        if ($n >= 1_000)     return number_format($n/1_000, 1, '.', '') . 'rb';
        return (string)(int)$n;
      }
      ?>

      <!-- Balance Dropdown -->
      <div class="bal-dropdown" id="bal-dropdown">
        <button type="button" class="bal-dropdown__trigger" onclick="toggleBalDropdown(event)" aria-label="Lihat saldo">
          <i class="ph-bold ph-wallet bal-dropdown__icon" style="font-size:16px;"></i>
          <span class="bal-dropdown__text"><?= fmt_short((float)$user['balance_wd']) ?></span>
          <i class="ph-bold ph-caret-down bal-dropdown__caret" style="font-size:12px;"></i>
        </button>
        <div class="bal-dropdown__panel" id="bal-panel">
          <div class="bal-dropdown__row bal-dropdown__row--wd">
            <span class="bal-dropdown__lbl" style="display:flex;align-items:center;gap:4px;"><i class="ph-bold ph-money" style="font-size:16px;color:var(--green)"></i> Saldo Penarikan</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_wd']) ?></span>
          </div>
          <div class="bal-dropdown__row bal-dropdown__row--dep">
            <span class="bal-dropdown__lbl" style="display:flex;align-items:center;gap:4px;"><i class="ph-bold ph-bank" style="font-size:16px;color:var(--blue)"></i> Saldo Beli</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_dep']) ?></span>
          </div>
          <?php if (setting($pdo, 'plinko_enabled', '1') === '1'): ?>
          <div class="bal-dropdown__row bal-dropdown__row--coin">
            <span class="bal-dropdown__lbl" style="display:flex;align-items:center;gap:4px;"><i class="ph-bold ph-coin" style="font-size:16px;color:#d97706"></i> Koin Plinko</span>
            <span class="bal-dropdown__val" id="user-coins"><?= number_format((int)$user['plinko_coins']) ?></span>
          </div>
          <?php endif; ?>
          <a href="/plinko-shop" class="bal-dropdown__link">🛒 Lapak Koin →</a>
        </div>
      </div>

      <style>
      .bal-dropdown{position:relative;z-index:9999;}
      .topbar__right{overflow:visible!important;}
      .bal-dropdown__trigger{display:flex;align-items:center;gap:4px;background:var(--yellow);border:2px solid var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink);padding:5px 10px;font-weight:900;font-size:12px;color:var(--ink);cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;}
      .bal-dropdown__caret{font-size:9px;transition:transform .2s;display:inline-block;}
      .bal-dropdown__caret.open{transform:rotate(180deg);}
      .bal-dropdown__panel{display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:2.5px solid var(--ink);border-radius:10px;box-shadow:4px 4px 0 var(--ink);min-width:220px;z-index:9999;overflow:hidden;}
      .bal-dropdown__panel.open{display:block;animation:bdFadeIn .15s ease;}
      @keyframes bdFadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
      .bal-dropdown__row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1.5px solid #eee;font-size:12px;}
      .bal-dropdown__row--wd{background:var(--mint);}
      .bal-dropdown__row--dep{background:#f5f8ff;}
      .bal-dropdown__row--coin{background:var(--yellow);}
      .bal-dropdown__lbl{font-weight:700;color:#444;}
      .bal-dropdown__val{font-weight:900;color:var(--ink);}
      .bal-dropdown__link{display:block;padding:10px 14px;font-size:11px;font-weight:900;color:var(--brand);text-decoration:none;text-align:center;background:#fafafa;}
      .bal-dropdown__link:hover,.bal-dropdown__link:active{background:var(--yellow);}
      </style>
      <script>
      (function(){
        /* Flag: true for 150ms after open so document-level close doesn't immediately re-close on mobile */
        var _justOpened = false;

        function balOpen() {
          var panel = document.getElementById('bal-panel');
          var caret = document.querySelector('.bal-dropdown__caret');
          if (!panel) return;
          var opening = !panel.classList.contains('open');
          panel.classList.toggle('open');
          if (caret) caret.classList.toggle('open', opening);
          if (opening) {
            _justOpened = true;
            setTimeout(function(){ _justOpened = false; }, 150);
          }
        }

        function balClose(fromTarget) {
          if (_justOpened) return;
          var wrap = document.getElementById('bal-dropdown');
          if (wrap && wrap.contains(fromTarget)) return;
          var panel = document.getElementById('bal-panel');
          var caret = document.querySelector('.bal-dropdown__caret');
          if (panel) panel.classList.remove('open');
          if (caret) caret.classList.remove('open');
        }

        /* Expose for inline onclick */
        window.toggleBalDropdown = function(e) {
          if (e) { e.preventDefault(); e.stopPropagation(); }
          balOpen();
        };

        /* Desktop close-on-outside-click */
        document.addEventListener('click', function(e){ balClose(e.target); });
        /* Mobile close-on-outside-touch */
        document.addEventListener('touchend', function(e){ balClose(e.target); }, {passive: true});
      })();
      </script>

      <a href="/notifications" class="topbar__avatar" title="Notifikasi"
         id="notif-bell-btn"
         style="background:var(--lavender);text-decoration:none;position:relative">
        <i class="ph-bold ph-bell" style="font-size:18px;"></i>
        <span id="notif-badge" style="
          display:none;position:absolute;top:-4px;right:-4px;
          background:var(--brand);color:#fff;
          font-size:9px;font-weight:900;min-width:16px;height:16px;
          border-radius:10px;padding:0 3px;
          border:1.5px solid var(--ink);
          display:inline-flex;align-items:center;justify-content:center;
          line-height:1
        "></span>
      </a>
      <a href="/profile" class="topbar__avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></a>
      <?php endif; ?>
    </div>
  </header>
  <main class="page-content">
<?php if (!empty($user)): ?>
<script>
(function() {
  function fetchNotifCount() {
    fetch('/notif_action?action=count')
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (data.count > 0) {
          badge.textContent = data.count > 9 ? '9+' : data.count;
          badge.style.display = 'inline-flex';
        } else {
          badge.style.display = 'none';
        }
      })
      .catch(() => {});
  }
  fetchNotifCount();
  setInterval(fetchNotifCount, 60000);
})();
</script>
<?php endif; ?>
