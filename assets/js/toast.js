/**
 * TontonKuy — Neobrutalism Toast System
 * Usage: nToast('Pesan kamu', 'success'|'error'|'info'|'warn')
 *        nToast.copy('teks') — copy + toast otomatis
 */
(function() {
  let _container = null;

  function getContainer() {
    if (_container) return _container;
    _container = document.createElement('div');
    _container.id = 'nb-toast-container';
    _container.style.cssText = [
      'position:fixed','top:20px','left:50%',
      'transform:translateX(-50%)',
      'z-index:99999',
      'display:flex','flex-direction:column','align-items:center',
      'gap:8px','pointer-events:none','width:calc(100% - 32px)','max-width:360px'
    ].join(';');
    document.body.appendChild(_container);
    return _container;
  }

  const ICONS = { success:'✅', error:'❌', warn:'⚠️', info:'ℹ️', copy:'📋' };
  const BG    = {
    success: '#BBFCD0',
    error:   '#FFD6D6',
    warn:    '#FFF3CC',
    info:    '#D6EEFF',
    copy:    '#BBFCD0'
  };

  window.nToast = function(msg, type='info', duration=2800) {
    const el   = document.createElement('div');
    const icon = ICONS[type] || ICONS.info;
    const bg   = BG[type]   || BG.info;

    el.style.cssText = [
      'background:' + bg,
      'border:2.5px solid #1A1A1A',
      'border-radius:12px',
      'box-shadow:4px 4px 0 #1A1A1A',
      'padding:12px 16px',
      'display:flex','align-items:center','gap:10px',
      'font-family:Nunito,Inter,sans-serif',
      'font-size:13px','font-weight:800',
      'color:#1A1A1A',
      'pointer-events:auto',
      'width:100%',
      'opacity:0',
      'transform:translateY(-12px)',
      'transition:opacity .2s ease, transform .2s ease',
    ].join(';');

    el.innerHTML = '<span style="font-size:18px;flex-shrink:0">' + icon + '</span>'
                 + '<span style="flex:1;line-height:1.4">' + msg + '</span>';

    getContainer().appendChild(el);

    // Animate in
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        el.style.opacity   = '1';
        el.style.transform = 'translateY(0)';
      });
    });

    // Animate out
    setTimeout(() => {
      el.style.opacity   = '0';
      el.style.transform = 'translateY(-12px)';
      setTimeout(() => el.remove(), 220);
    }, duration);
  };

  // Convenience: copy to clipboard + show toast
  nToast.copy = function(text, label) {
    const display = label || text;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text)
        .then(() => nToast('Disalin: ' + display, 'copy'))
        .catch(() => _fallbackCopy(text, display));
    } else {
      _fallbackCopy(text, display);
    }
  };

  function _fallbackCopy(text, display) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:-999px;opacity:0';
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand('copy');
      nToast('Disalin: ' + display, 'copy');
    } catch(e) {
      nToast('Salin manual: ' + text, 'warn', 5000);
    }
    document.body.removeChild(ta);
  }
})();
