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
<meta name="theme-color" content="#0891b2">
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
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="<?= htmlspecialchars($_seo_title) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?>
<meta name="twitter:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<?php if ($absolute_og): ?>
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<?php endif; ?>
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<?php endif; ?>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<style>
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}

/* ── New Header ─────────────────────────────────────── */
.topbar {
  position: sticky; top: 0; z-index: 1000;
  background: linear-gradient(135deg, #0c4a6e 0%, #0e7490 50%, #0891b2 100%);
  border-bottom: 3px solid #075985;
  box-shadow: 0 4px 0 #0c4a6e, 0 6px 20px rgba(8,145,178,0.3);
  padding: 0 14px;
  height: auto;
  min-height: 64px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0;
  overflow: visible;
}

/* Row 1: logo + action buttons */
.topbar__row1 {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  height: 52px;
}

/* Row 2: balance pills */
.topbar__row2 {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 10px;
  width: 100%;
}

.topbar__logo {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 900;
  font-size: 20px;
  color: #fff;
  text-decoration: none;
  flex-shrink: 0;
  letter-spacing: -0.5px;
  text-shadow: 0 2px 0 rgba(0,0,0,0.2);
}
.topbar__logo-icon {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  border: 2.5px solid rgba(255,255,255,0.4);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  box-shadow: 0 3px 0 rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.3);
  flex-shrink: 0;
}
.topbar__logo span em {
  font-style: normal;
  color: #fde68a;
}

.topbar__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* Notification bell */
.topbar__bell {
  position: relative;
  width: 38px; height: 38px;
  background: rgba(255,255,255,0.12);
  border: 2px solid rgba(255,255,255,0.2);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #fff;
  text-decoration: none;
  font-size: 18px;
  transition: background 0.15s;
  backdrop-filter: blur(4px);
}
.topbar__bell:hover, .topbar__bell:active { background: rgba(255,255,255,0.22); }
.notif-dot {
  position: absolute;
  top: 4px; right: 4px;
  display: none;
  background: #f97316;
  color: #fff;
  font-size: 9px;
  font-weight: 900;
  min-width: 16px; height: 16px;
  border-radius: 10px;
  padding: 0 3px;
  border: 2px solid #0c4a6e;
  align-items: center; justify-content: center;
  line-height: 1;
}

/* Avatar */
.topbar__avatar {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  color: #0c4a6e;
  border: 2.5px solid rgba(255,255,255,0.3);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900;
  font-size: 16px;
  text-decoration: none;
  box-shadow: 0 3px 0 rgba(0,0,0,0.2);
  transition: transform 0.1s;
}
.topbar__avatar:active { transform: translateY(2px); box-shadow: none; }

/* Balance pills */
.bal-pill {
  display: flex;
  align-items: center;
  gap: 5px;
  background: rgba(255,255,255,0.12);
  border: 1.5px solid rgba(255,255,255,0.2);
  border-radius: 20px;
  padding: 4px 10px 4px 6px;
  cursor: pointer;
  position: relative;
  transition: background 0.15s;
  backdrop-filter: blur(4px);
}
.bal-pill:hover, .bal-pill:active { background: rgba(255,255,255,0.22); }
.bal-pill__icon {
  width: 22px; height: 22px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
}
.bal-pill--wd .bal-pill__icon { background: rgba(16,185,129,0.25); }
.bal-pill--dep .bal-pill__icon { background: rgba(59,130,246,0.25); }
.bal-pill__label {
  font-size: 10px;
  font-weight: 700;
  color: rgba(255,255,255,0.7);
  line-height: 1;
}
.bal-pill__val {
  font-size: 12px;
  font-weight: 900;
  color: #fff;
  line-height: 1;
}
.bal-pill__texts {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

/* Dropdown from pills */
.bal-dropdown { position: relative; }
.bal-dropdown__panel {
  display: none;
  position: absolute;
  left: 0; top: calc(100% + 8px);
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 14px;
  box-shadow: 0 6px 0 #0c4a6e, 0 10px 30px rgba(8,145,178,0.2);
  min-width: 200px;
  z-index: 9999;
  overflow: hidden;
  animation: bdFadeIn .15s ease;
}
.bal-dropdown__panel.open { display: block; }
@keyframes bdFadeIn { from { opacity:0; transform:translateY(-6px) } to { opacity:1; transform:none } }
.bal-dropdown__row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 14px;
  border-bottom: 1.5px solid #e0f9ff;
  font-size: 12px;
}
.bal-dropdown__row--wd { background: #d1fae5; }
.bal-dropdown__row--dep { background: #e0f2fe; }
.bal-dropdown__lbl { font-weight: 700; color: #444; display: flex; align-items: center; gap: 4px; }
.bal-dropdown__val { font-weight: 900; color: #0c4a6e; }
</style>
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <!-- Row 1: Logo + Actions -->
    <div class="topbar__row1">
      <a href="/home" class="topbar__logo">
        <div class="topbar__logo-icon">
          <?php if ($_favicon): ?>
            <img src="<?= htmlspecialchars($fav_url) ?>" alt="" style="width:22px;height:22px;object-fit:contain;">
          <?php else: ?>
            <i class="ph-fill ph-film-strip" style="color:#0c4a6e;font-size:18px"></i>
          <?php endif; ?>
        </div>
        <span>Melo<em>ton</em></span>
      </a>

      <?php if (!empty($user)): ?>
      <div class="topbar__actions">
        <a href="/notifications" class="topbar__bell" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-bold ph-bell"></i>
          <span id="notif-badge" class="notif-dot"></span>
        </a>
        <a href="/profile" class="topbar__avatar" title="Profil">
          <?= strtoupper(substr($user['username'], 0, 1)) ?>
        </a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($user)): ?>
    <?php
    function fmt_short(float $n): string {
      if ($n >= 1_000_000) return 'Rp ' . number_format($n/1_000_000, 1, '.', '') . 'jt';
      if ($n >= 1_000)     return 'Rp ' . number_format($n/1_000, 1, '.', '') . 'rb';
      return 'Rp ' . (string)(int)$n;
    }
    ?>
    <!-- Row 2: Balance pills -->
    <div class="topbar__row2">
      <!-- Withdraw Balance -->
      <div class="bal-dropdown" id="bal-dropdown-wd">
        <button type="button" class="bal-pill bal-pill--wd" onclick="toggleBal('wd', event)" aria-label="Saldo penarikan">
          <div class="bal-pill__icon"><i class="ph-bold ph-arrow-circle-up" style="color:#10b981;font-size:14px"></i></div>
          <div class="bal-pill__texts">
            <span class="bal-pill__label">Saldo Pencairan</span>
            <span class="bal-pill__val"><?= fmt_short((float)$user['balance_wd']) ?></span>
          </div>
        </button>
        <div class="bal-dropdown__panel" id="bal-panel-wd">
          <div class="bal-dropdown__row bal-dropdown__row--wd">
            <span class="bal-dropdown__lbl"><i class="ph-bold ph-money" style="color:#10b981;font-size:14px"></i> Saldo Penarikan</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_wd']) ?></span>
          </div>
        </div>
      </div>

      <!-- Deposit Balance -->
      <div class="bal-dropdown" id="bal-dropdown-dep">
        <button type="button" class="bal-pill bal-pill--dep" onclick="toggleBal('dep', event)" aria-label="Saldo beli">
          <div class="bal-pill__icon"><i class="ph-bold ph-bank" style="color:#3b82f6;font-size:14px"></i></div>
          <div class="bal-pill__texts">
            <span class="bal-pill__label">Saldo Beli</span>
            <span class="bal-pill__val"><?= fmt_short((float)$user['balance_dep']) ?></span>
          </div>
        </button>
        <div class="bal-dropdown__panel" id="bal-panel-dep">
          <div class="bal-dropdown__row bal-dropdown__row--dep">
            <span class="bal-dropdown__lbl"><i class="ph-bold ph-bank" style="color:#3b82f6;font-size:14px"></i> Saldo Beli</span>
            <span class="bal-dropdown__val"><?= format_rp((float)$user['balance_dep']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var _justOpened = {};
      window.toggleBal = function(key, e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var panel = document.getElementById('bal-panel-' + key);
        if (!panel) return;
        var opening = !panel.classList.contains('open');
        // Close all first
        ['wd','dep'].forEach(function(k) {
          var p = document.getElementById('bal-panel-' + k);
          if (p) p.classList.remove('open');
        });
        if (opening) {
          panel.classList.add('open');
          _justOpened[key] = true;
          setTimeout(function(){ _justOpened[key] = false; }, 150);
        }
      };
      document.addEventListener('click', function(e) {
        ['wd','dep'].forEach(function(key) {
          if (_justOpened[key]) return;
          var wrap = document.getElementById('bal-dropdown-' + key);
          if (wrap && wrap.contains(e.target)) return;
          var panel = document.getElementById('bal-panel-' + key);
          if (panel) panel.classList.remove('open');
        });
      });
      document.addEventListener('touchend', function(e) {
        ['wd','dep'].forEach(function(key) {
          if (_justOpened[key]) return;
          var wrap = document.getElementById('bal-dropdown-' + key);
          if (wrap && wrap.contains(e.target)) return;
          var panel = document.getElementById('bal-panel-' + key);
          if (panel) panel.classList.remove('open');
        });
      }, {passive: true});
    })();
    </script>
    <?php endif; ?>
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
