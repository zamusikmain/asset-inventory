// Общие помощники интерфейса
window.App = {
  async api(url, opts = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      ...opts,
      headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
        ...(opts.body ? {'Content-Type': 'application/json'} : {}), ...(opts.headers || {})},
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* не JSON */ }
    if (!res.ok) {
      const msg = (data && (data.message || data.error)) || ('Ошибка ' + res.status);
      const err = new Error(msg); err.status = res.status; err.data = data; throw err;
    }
    return data;
  },
  esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
  // Ввод с USB-сканера (режим клавиатуры): поле в фокусе, Enter завершает код
  bindScanner(input, onCode) {
    const keepFocus = () => { if (document.activeElement === document.body || !document.activeElement) input.focus(); };
    input.focus();
    document.addEventListener('click', e => { if (!e.target.closest('input,select,textarea,button,a,label')) setTimeout(keepFocus, 0); });
    setInterval(keepFocus, 1500);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        const v = input.value.trim();
        input.value = '';
        if (v) onCode(v);
      }
    });
  },
  modal(id, open = true) { document.getElementById(id)?.classList.toggle('open', open); },
};
document.addEventListener('click', e => {
  const t = e.target.closest('[data-modal]');
  if (t) { e.preventDefault(); App.modal(t.dataset.modal, true); }
  const c = e.target.closest('[data-close]');
  if (c || e.target.classList.contains('modal')) { (c ? c.closest('.modal') : e.target).classList.remove('open'); }
});
document.addEventListener('submit', e => {
  const msg = e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});
