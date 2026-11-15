// Мобильная PWA-инвентаризация (п. 11 ТЗ).
// IndexedDB: packs — offline-пакеты кампаний; ops — операции сканирования с UUID и статусами:
// local (сохранено локально) → pending (ожидает синхронизации) → synced | conflict | error.
(() => {
  const STATUS = {local: ['Сохранено локально', 'gray'], pending: ['Ожидает синхронизации', 'amber'],
    synced: ['Синхронизировано', 'green'], conflict: ['Конфликт', 'red'], error: ['Ошибка', 'red']};
  const $ = id => document.getElementById(id);
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g,
    c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)));
  const deviceId = localStorage.getItem('device-id') || (() => { const d = uuid(); localStorage.setItem('device-id', d); return d; })();

  // ---------------------------------------------------------------- IndexedDB
  let dbp;
  function db() {
    return dbp ??= new Promise((res, rej) => {
      const r = indexedDB.open('inventory', 1);
      r.onupgradeneeded = () => {
        const d = r.result;
        d.createObjectStore('packs', {keyPath: 'campaign.id'});
        const ops = d.createObjectStore('ops', {keyPath: 'op_id'});
        ops.createIndex('campaign', 'campaign_id');
        ops.createIndex('status', 'status');
      };
      r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error);
    });
  }
  async function tx(store, mode, fn) {
    const d = await db();
    return new Promise((res, rej) => {
      const t = d.transaction(store, mode), s = t.objectStore(store);
      const out = fn(s);
      t.oncomplete = () => res(out && 'result' in out ? out.result : out); t.onerror = () => rej(t.error);
    });
  }
  const put = (store, v) => tx(store, 'readwrite', s => s.put(v));
  const get = (store, k) => tx(store, 'readonly', s => s.get(k));
  const all = (store) => tx(store, 'readonly', s => s.getAll());
  const del = (store, k) => tx(store, 'readwrite', s => s.delete(k));

  // ---------------------------------------------------------------- состояние
  let current = null; // offline-пакет текущей кампании
  let index = new Map(); // код → актив
  let lastCode = '', lastAt = 0;

  function setNet() {
    $('net').textContent = navigator.onLine ? 'online' : 'offline';
    $('net').className = 'badge ' + (navigator.onLine ? 'green' : 'red');
  }
  async function refreshQueue() {
    const ops = await all('ops');
    const pending = ops.filter(o => ['local', 'pending', 'error'].includes(o.status)).length;
    $('queue').textContent = pending; $('queue').className = 'badge ' + (pending ? 'amber' : 'gray');
    if (current) renderOps(ops.filter(o => o.campaign_id === current.campaign.id));
  }

  // ---------------------------------------------------------------- кампании
  async function loadCampaigns() {
    const packs = await all('packs');
    $('offline-list').innerHTML = packs.map(p => `<div class="card"><b>${App.esc(p.campaign.number)}</b> ${App.esc(p.campaign.name)}
      <div class="small muted">загружено ${new Date(p.generated_at).toLocaleString()} · активов ${p.assets.length} · помещений ${p.rooms.length}</div>
      <div class="actions"><button class="btn primary small" data-open="${p.campaign.id}">Сканировать</button>
      <button class="btn small" data-dl="${p.campaign.id}">Обновить</button><button class="btn small danger" data-rm="${p.campaign.id}">Удалить</button></div></div>`).join('')
      || '<p class="muted small">Нет загруженных кампаний. Загрузите кампанию, пока есть сеть.</p>';
    if (!navigator.onLine) { $('campaign-list').innerHTML = '<p class="muted small">Нет сети — используйте загруженные кампании.</p>'; return; }
    try {
      const d = await App.api('/api/v1/inventory/campaigns');
      $('campaign-list').innerHTML = d.items.map(c => `<div class="card"><b>${App.esc(c.number)}</b> ${App.esc(c.name)} <span class="badge blue">${App.esc(c.status_label)}</span>
        <div class="actions"><button class="btn primary small" data-dl="${c.id}">Загрузить для offline</button></div></div>`).join('') || '<p class="muted">Нет активных кампаний.</p>';
    } catch (e) {
      $('campaign-list').innerHTML = e.status === 401 ? '<p>Сессия истекла. <a href="/login">Войти</a></p>' : `<p class="muted">${App.esc(e.message)}</p>`;
    }
  }
  async function download(id) {
    const pack = await App.api(`/api/v1/inventory/${id}/pack`);
    await put('packs', pack);
    await loadCampaigns();
    return pack;
  }
  async function open(id) {
    current = await get('packs', +id);
    if (!current) return;
    index = new Map();
    for (const a of current.assets) { index.set(a.inv.toUpperCase(), a); if (a.bc) index.set(a.bc.toUpperCase(), a); }
    $('c-title').textContent = current.campaign.number;
    $('room').innerHTML = '<option value="">— без привязки —</option>' + current.rooms.map(r => `<option value="${r.id}">${App.esc(r.name)}</option>`).join('');
    $('room').value = localStorage.getItem('room-' + id) || '';
    $('v-campaigns').hidden = true; $('v-scan').hidden = false;
    location.hash = 'c=' + id;
    refreshQueue();
  }

  // ---------------------------------------------------------------- сканирование
  async function onCode(raw, source) {
    const code = raw.trim().replace(/[\r\n\t]/g, '').toUpperCase();
    if (!code || !current) return;
    if (source === 'camera') {
      if (code === lastCode && Date.now() - lastAt < 3000) return; // повтор кадра камеры
      lastCode = code; lastAt = Date.now();
    }

    const asset = index.get(code);
    const ops = (await all('ops')).filter(o => o.campaign_id === current.campaign.id);
    const first = asset && ops.find(o => o.asset_id === asset.id && !o.duplicate);
    const roomId = $('room').value ? +$('room').value : null;
    const flagEl = document.querySelector('input[name=flag]:checked');

    let local;
    if (!/^[\x20-\x7E]{1,64}$/.test(code)) local = ['Некорректный код', 'red'];
    else if (first) local = [`Уже отсканирован ${new Date(first.scanned_at).toLocaleString()} на этом устройстве`, 'gray'];
    else if (!asset) local = ['Нет в списке кампании (неизвестный / другой офис)', 'amber'];
    else if (['written_off', 'archived'].includes(asset.st)) local = ['Обнаружен списанный объект', 'red'];
    else if (roomId && asset.loc && asset.loc !== roomId) local = ['Найден в другом помещении', 'amber'];
    else local = ['Найден', 'green'];

    // повторный скан блокируется: операция не создается
    if (first) { showLast(code, asset, local); navigator.vibrate?.([60, 60, 60]); return; }

    const op = {
      op_id: uuid(), campaign_id: current.campaign.id, code, asset_id: asset?.id ?? null, location_id: roomId,
      expected_location_id: asset ? (asset.loc ?? null) : undefined,
      scanned_at: new Date().toISOString(), source, flag: flagEl.value || null, comment: $('comment').value.trim() || null,
      status: 'local', local_result: local[0], name: asset?.name ?? null,
    };
    await put('ops', op);
    $('comment').value = ''; document.querySelector('input[name=flag][value=""]').checked = true;
    showLast(code, asset, local);
    navigator.vibrate?.(local[1] === 'green' ? 40 : [80, 40, 80]);
    refreshQueue();
    if (navigator.onLine) sync();
  }
  function showLast(code, asset, [label, color]) {
    $('last').innerHTML = `<div class="scan-result ${color}"><b>${App.esc(label)}</b> — <span class="mono">${App.esc(code)}</span>${asset ? '<div class="small">' + App.esc(asset.name) + '</div>' : ''}</div>`;
  }
  function renderOps(ops) {
    ops.sort((a, b) => b.scanned_at.localeCompare(a.scanned_at));
    $('ops').innerHTML = ops.slice(0, 200).map(o => {
      const [l, c] = STATUS[o.status];
      return `<div class="op"><div><span class="mono">${App.esc(o.code)}</span> <span class="small">${App.esc(o.name || '')}</span>
        <div class="small muted">${new Date(o.scanned_at).toLocaleTimeString()} · ${App.esc(o.server_result || o.local_result)}${o.message ? ' · ' + App.esc(o.message) : ''}</div></div>
        <span class="badge ${c}">${l}</span></div>`;
    }).join('') || '<p class="muted small">Сканов нет</p>';
  }

  // ---------------------------------------------------------------- синхронизация
  let syncing = false;
  async function sync() {
    if (syncing || !navigator.onLine) return;
    syncing = true;
    try {
      const queue = (await all('ops')).filter(o => ['local', 'pending', 'error'].includes(o.status));
      if (!queue.length) return;
      for (const o of queue) { o.status = 'pending'; await put('ops', o); }
      refreshQueue();
      for (let i = 0; i < queue.length; i += 100) {
        const batch = queue.slice(i, i + 100);
        const r = await App.api('/api/v1/inventory/sync', {
          method: 'POST', headers: {'X-Client': 'pwa', 'X-Background-Sync': '1'},
          body: JSON.stringify({device_id: deviceId, ops: batch.map(({status, local_result, name, asset_id, server_result, message, ...rest}) => rest)}),
        });
        for (const res of r.results) {
          const o = batch.find(b => b.op_id === res.op_id); if (!o) continue;
          o.status = res.sync_status === 'synced' ? 'synced' : (res.sync_status === 'conflict' ? 'conflict' : 'error');
          o.server_result = res.result_label || null; o.message = res.duplicate_of ? res.message : (res.sync_status !== 'synced' ? res.message : null);
          await put('ops', o);
        }
      }
    } catch (e) {
      // нет сети / сессия истекла — операции остаются в очереди и не теряются
      if (e.status === 401) $('last').innerHTML = '<div class="scan-result amber">Сессия истекла — сканы сохранены на устройстве. <a href="/login">Войдите</a> для синхронизации.</div>';
    } finally { syncing = false; refreshQueue(); }
  }

  // ---------------------------------------------------------------- камера
  let stream = null, detector = null, zxing = null, raf = 0;
  async function startCamera() {
    if (stream) return stopCamera();
    try {
      stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment', width: {ideal: 1280}}});
    } catch (e) { alert('Нет доступа к камере: ' + e.message + '\nКамера доступна только по HTTPS.'); return; }
    const video = $('video'); video.srcObject = stream; await video.play();
    $('cam-wrap').hidden = false; $('cam-btn').textContent = '■ Стоп';
    if ('BarcodeDetector' in window) {
      detector = new BarcodeDetector({formats: ['code_128']});
      const tick = async () => {
        if (!stream) return;
        try { const codes = await detector.detect(video); if (codes.length) onCode(codes[0].rawValue, 'camera'); } catch (e) {}
        raf = setTimeout(tick, 200);
      };
      tick();
    } else if (window.ZXing) {
      zxing = new ZXing.BrowserMultiFormatReader(new Map([[ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.CODE_128]]]));
      zxing.decodeFromVideoElementContinuously(video, (res) => { if (res) onCode(res.getText(), 'camera'); });
    } else {
      $('last').innerHTML = '<div class="scan-result amber">Браузер не поддерживает распознавание штрихкодов камерой. Используйте ручной ввод или USB/Bluetooth-сканер.</div>';
    }
  }
  function stopCamera() {
    clearTimeout(raf); zxing?.reset?.(); zxing = null;
    stream?.getTracks().forEach(t => t.stop()); stream = null;
    $('cam-wrap').hidden = true; $('cam-btn').textContent = '📷 Камера';
  }

  // ---------------------------------------------------------------- события
  document.addEventListener('click', async e => {
    const t = e.target;
    if (t.dataset.dl) { t.disabled = true; try { await download(t.dataset.dl); } catch (err) { alert(err.message); } t.disabled = false; }
    if (t.dataset.open) open(t.dataset.open);
    if (t.dataset.rm) {
      const pending = (await all('ops')).filter(o => o.campaign_id === +t.dataset.rm && o.status !== 'synced');
      if (pending.length && !confirm(`Есть ${pending.length} несинхронизированных сканов. Удалить пакет? (сканы останутся)`)) return;
      await del('packs', +t.dataset.rm); loadCampaigns();
    }
  });
  $('back').onclick = () => { stopCamera(); current = null; location.hash = ''; $('v-scan').hidden = true; $('v-campaigns').hidden = false; loadCampaigns(); };
  $('room').onchange = () => current && localStorage.setItem('room-' + current.campaign.id, $('room').value);
  $('cam-btn').onclick = startCamera;
  $('sync-btn').onclick = sync;
  $('manual').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const v = e.target.value; e.target.value = ''; onCode(v, 'manual'); } });
  window.addEventListener('online', () => { setNet(); sync(); loadCampaigns(); });
  window.addEventListener('offline', setNet);
  setInterval(sync, 30000);

  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js', {scope: '/'}).catch(() => {});

  (async () => {
    setNet(); await loadCampaigns(); refreshQueue();
    const m = location.hash.match(/c=(\d+)/);
    if (m) { if (!(await get('packs', +m[1])) && navigator.onLine) { try { await download(m[1]); } catch (e) {} } open(m[1]); }
    sync();
  })();
})();
