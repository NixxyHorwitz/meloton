/**
 * Meloton — Casual Game Style Toast System
 * Usage: nToast('Pesan kamu', 'success'|'error'|'info'|'warn')
 *        nToast.copy('teks') — copy + toast otomatis
 */
(function() {
  let _container = null;

  function getContainer() {
    if (_container) return _container;
    _container = document.createElement('div');
    _container.id = 'cg-toast-container';
    _container.style.cssText = [
      'position:fixed','top:20px','left:50%',
      'transform:translateX(-50%)',
      'z-index:99999',
      'display:flex','flex-direction:column','align-items:center',
      'gap:10px','pointer-events:none','width:calc(100% - 32px)','max-width:340px'
    ].join(';');
    document.body.appendChild(_container);
    return _container;
  }

  const ICONS = { success:'✨', error:'💥', warn:'⚠️', info:'💡', copy:'📋' };
  const BGS   = {
    success: 'linear-gradient(135deg, #34d399, #10b981)',
    error:   'linear-gradient(135deg, #ef4444, #dc2626)',
    warn:    'linear-gradient(135deg, #fde047, #f59e0b)',
    info:    'linear-gradient(135deg, #38bdf8, #0ea5e9)',
    copy:    'linear-gradient(135deg, #34d399, #10b981)'
  };
  const BORDERS = {
    success: '#059669',
    error:   '#b91c1c',
    warn:    '#d97706',
    info:    '#0284c7',
    copy:    '#059669'
  };
  const SHADOWS = {
    success: '0 6px 0 #047857',
    error:   '0 6px 0 #991b1b',
    warn:    '0 6px 0 #b45309',
    info:    '0 6px 0 #0369a1',
    copy:    '0 6px 0 #047857'
  };

  window.nToast = function(msg, type='info', duration=2800) {
    const el   = document.createElement('div');
    const icon = ICONS[type] || ICONS.info;
    const bg   = BGS[type]   || BGS.info;
    const bd   = BORDERS[type] || BORDERS.info;
    const sh   = SHADOWS[type] || SHADOWS.info;

    el.style.cssText = [
      'background:' + bg,
      'border:3px solid ' + bd,
      'border-radius:18px',
      'box-shadow:' + sh,
      'padding:12px 18px',
      'display:flex','align-items:center','gap:12px',
      'font-family:Nunito,Inter,sans-serif',
      'font-size:13px','font-weight:700',
      'line-height:1.4',
      'color:#ffffff',
      'pointer-events:auto',
      'width:100%',
      'opacity:0',
      'transform:translateY(-20px) scale(0.9)',
      'transition:opacity .3s ease, transform .4s cubic-bezier(0.34, 1.56, 0.64, 1)',
    ].join(';');

    el.innerHTML = '<span style="font-size:24px;flex-shrink:0;filter:drop-shadow(0 2px 1px rgba(0,0,0,0.3))">' + icon + '</span>'
                 + '<span style="flex:1;line-height:1.3">' + msg + '</span>';

    getContainer().appendChild(el);

    // Animate in
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        el.style.opacity   = '1';
        el.style.transform = 'translateY(0) scale(1)';
      });
    });

    // Animate out
    setTimeout(() => {
      el.style.opacity   = '0';
      el.style.transform = 'translateY(-15px) scale(0.9)';
      el.style.transition = 'opacity .2s ease, transform .2s ease'; // faster exit, no bounce
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
