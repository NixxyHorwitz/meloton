document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('select.custom-logo-select').forEach(function(select) {
    // Sembunyikan select asli
    select.style.display = 'none';

    // Buat wrapper
    const wrap = document.createElement('div');
    wrap.className = 'custom-select-wrap';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);

    // Buat trigger button
    const trigger = document.createElement('div');
    trigger.className = 'custom-select-trigger';
    
    // Default val
    let defaultHTML = '— Pilih Bank / E-Wallet —';
    const selectedOpt = select.querySelector('option:checked') || select.options[0];
    if (selectedOpt && selectedOpt.value) {
      const logo = selectedOpt.getAttribute('data-logo');
      if (logo) {
        defaultHTML = `<div class="sel-val"><img src="${logo}" alt=""> <span>${selectedOpt.text}</span></div>`;
      } else {
        defaultHTML = `<div class="sel-val"><span>${selectedOpt.text}</span></div>`;
      }
    }
    
    trigger.innerHTML = `${defaultHTML}
      <svg width="12" height="8" viewBox="0 0 12 8" fill="none" stroke="#1A1A1A" stroke-width="2" stroke-linecap="round"><path d="M1 1l5 5 5-5"/></svg>`;
    wrap.appendChild(trigger);

    // Buat options dropdown
    const optionsContainer = document.createElement('div');
    optionsContainer.className = 'custom-select-options';

    Array.from(select.children).forEach(child => {
      if (child.tagName === 'OPTGROUP') {
        const og = document.createElement('div');
        og.className = 'custom-optgroup';
        og.textContent = child.label;
        optionsContainer.appendChild(og);

        Array.from(child.children).forEach(opt => {
          optionsContainer.appendChild(createOptionEl(opt));
        });
      } else {
        if(child.value !== '') {
          optionsContainer.appendChild(createOptionEl(child));
        }
      }
    });

    const scrollSpacer = document.createElement('div');
    scrollSpacer.style.height = '80px';
    scrollSpacer.style.width = '100%';
    scrollSpacer.style.flexShrink = '0';
    optionsContainer.appendChild(scrollSpacer);

    wrap.appendChild(optionsContainer);

    function createOptionEl(opt) {
      const div = document.createElement('div');
      div.className = 'custom-option';
      const logo = opt.getAttribute('data-logo');
      if (logo) {
        div.innerHTML = `<img src="${logo}" alt="" loading="lazy"> <span>${opt.text}</span>`;
      } else {
        div.innerHTML = `<span>${opt.text}</span>`;
      }
      div.addEventListener('click', function() {
        select.value = opt.value;
        trigger.innerHTML = `<div class="sel-val">${div.innerHTML}</div> <svg width="12" height="8" viewBox="0 0 12 8" fill="none" stroke="#1A1A1A" stroke-width="2" stroke-linecap="round"><path d="M1 1l5 5 5-5"/></svg>`;
        optionsContainer.classList.remove('open');
        trigger.classList.remove('open');
        select.dispatchEvent(new Event('change'));
      });
      return div;
    }

    // Toggle dropdown
    trigger.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = optionsContainer.classList.contains('open');
      document.querySelectorAll('.custom-select-options').forEach(el => el.classList.remove('open'));
      document.querySelectorAll('.custom-select-trigger').forEach(el => el.classList.remove('open'));
      if (!isOpen) {
        const rect = trigger.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        
        // Jika ruang di bawah kurang dari 290px dan ruang di atas lebih luas
        if (spaceBelow < 290 && rect.top > spaceBelow) {
          optionsContainer.style.top = 'auto';
          optionsContainer.style.bottom = 'calc(100% + 8px)';
          optionsContainer.style.transformOrigin = 'bottom center';
        } else {
          optionsContainer.style.top = 'calc(100% + 8px)';
          optionsContainer.style.bottom = 'auto';
          optionsContainer.style.transformOrigin = 'top center';
        }

        optionsContainer.classList.add('open');
        trigger.classList.add('open');
      }
    });
  });

  // Close when clicking outside
  document.addEventListener('click', function() {
    document.querySelectorAll('.custom-select-options').forEach(el => el.classList.remove('open'));
    document.querySelectorAll('.custom-select-trigger').forEach(el => el.classList.remove('open'));
  });
});
