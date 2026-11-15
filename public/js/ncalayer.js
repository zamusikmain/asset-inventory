// Прототип подписания ЭЦП НУЦ РК через NCALayer (п. 13.3 ТЗ).
// Формирует CMS (CAdES, detached) над PDF-актом. Сначала пробует модуль
// kz.gov.pki.knca.basics, при отсутствии — устаревший commonUtils.createCAdESFromBase64.
window.NCALayer = {
  url: 'wss://127.0.0.1:13579/',
  _call(payload, timeoutMs = 180000) {
    return new Promise((resolve, reject) => {
      let ws;
      try { ws = new WebSocket(this.url); } catch (e) { reject(new Error('NCALayer недоступен')); return; }
      const timer = setTimeout(() => { ws.close(); reject(new Error('Истекло время ожидания NCALayer')); }, timeoutMs);
      let greeted = false;
      ws.onerror = () => { clearTimeout(timer); reject(new Error('Не удалось подключиться к NCALayer. Убедитесь, что NCALayer запущен.')); };
      ws.onopen = () => ws.send(JSON.stringify(payload));
      ws.onmessage = (ev) => {
        let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
        // первое сообщение NCALayer — версия
        if (!greeted && msg.result && msg.result.version) { greeted = true; return; }
        clearTimeout(timer); ws.close(); resolve(msg);
      };
    });
  },
  async signCms(base64Data) {
    const basics = await this._call({
      module: 'kz.gov.pki.knca.basics', method: 'sign',
      args: {
        allowedStorages: ['AKKaztokenStore', 'AKKZIDCardStore', 'AKEToken72KStore', 'AKJaCartaStore', 'PKCS12'],
        format: 'cms', data: base64Data,
        signingParams: { decode: true, encapsulate: false, digested: false, tsaProfile: {} },
        signerParams: { extKeyUsageOids: ['1.3.6.1.5.5.7.3.4'], chain: [] },
        locale: 'ru',
      },
    });
    if (basics && basics.status === true && basics.body && basics.body.result) {
      return Array.isArray(basics.body.result) ? basics.body.result[0] : basics.body.result;
    }
    if (basics && basics.status === false && basics.code !== 'MODULE_NOT_FOUND' && !(basics.message || '').includes('module')) {
      if ((basics.message || '').toLowerCase().includes('cancel') || basics.code === 'CANCEL') throw new Error('Подписание отменено');
      if (basics.message) throw new Error('NCALayer: ' + basics.message);
    }
    // Фолбэк: commonUtils
    const legacy = await this._call({
      module: 'kz.gov.pki.knca.commonUtils', method: 'createCAdESFromBase64',
      args: ['PKCS12', 'SIGNATURE', base64Data, false],
    });
    if (legacy && String(legacy.code) === '200' && legacy.responseObject) return legacy.responseObject;
    throw new Error('NCALayer: ' + (legacy && (legacy.message || legacy.code) || 'неизвестная ошибка'));
  },
};
