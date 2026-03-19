(function (win, doc) {
  'use strict';

  function getQueryParam(name) {
    try {
      return new URLSearchParams(win.location.search).get(name) || '';
    } catch { return ''; }
  }

  function initGCLIDTracking() {
    try {
      const gclid = getQueryParam('gclid');
      if (gclid) {
        const existingSession = storage.get('conversion_session_marker');
        if (!existingSession) {
          postJSON('/app/maike/api/conversion/create-session', { gclid }, { timeout: 2000 })
            .then(data => {
              if (data && data.success && data.session_marker) {
                storage.set('conversion_session_marker', data.session_marker);
                storage.set('conversion_gclid', gclid);
                storage.set('conversion_expires_at', data.expires_at);
              }
            })
            .catch(e => logError(e, { phase: 'gclid-init' }));
        }
      }
    } catch (e) {
      logError(e, { phase: 'gclid-tracking-init' });
    }
  }

  function recordConversion(conversionValue = null) {
    try {
      const sessionMarker = storage.get('conversion_session_marker');
      const gclid = storage.get('conversion_gclid') || getQueryParam('gclid');

      if (!sessionMarker && !gclid) {
        return Promise.resolve(false);
      }

      const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      const data = {
        session_marker: sessionMarker,
        gclid: gclid,
        conversion_value: conversionValue,
        conversion_currency: 'JPY',
        timezone: timezone
      };

      return postJSON('/app/maike/api/conversion/record', data, { timeout: 2000 })
        .then(result => {
          if (result && result.success) {
            return true;
          }
          return false;
        })
        .catch(e => {
          logError(e, { phase: 'record-conversion' });
          return false;
        });
    } catch (e) {
      logError(e, { phase: 'record-conversion-init' });
      return Promise.resolve(false);
    }
  }

  function withTimeout(promise, ms, label = 'timeout') {
    let timer;
    const to = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(`${label}: ${ms}ms`)), ms);
    });
    return Promise.race([promise.finally(() => clearTimeout(timer)), to]);
  }

  const storage = {
    set(k, v) { try { win.localStorage.setItem(k, v); } catch {} },
    get(k)    { try { return win.localStorage.getItem(k) || ''; } catch { return ''; } }
  };

  function beacon(url, payload) {
    try {
      const body = JSON.stringify(payload);
      const blob = new Blob([body], { type: 'application/json' });
      if (navigator.sendBeacon && navigator.sendBeacon(url, blob)) return Promise.resolve(true);
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        keepalive: true,
      }).then(() => true).catch(() => false);
    } catch {
      return Promise.resolve(false);
    }
  }

  function postJSON(url, data, { timeout = 3000, headers = {} } = {}) {
    try {
      const ctrl = ('AbortController' in win) ? new AbortController() : null;
      const signal = ctrl ? ctrl.signal : undefined;

      const req = fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', ...headers },
        body: JSON.stringify(data),
        keepalive: true,
        signal
      });

      if (ctrl) {
        setTimeout(() => { try { ctrl.abort(); } catch {} }, timeout + 50);
      }
      return withTimeout(req, timeout, 'fetch-timeout')
        .then(r => r && typeof r.json === 'function' ? r.json().catch(() => ({})) : ({}))
        .catch(() => beacon(url, data));
    } catch {
      return beacon(url, data);
    }
  }

  function logError(error, extra = {}) {
    const payload = {
      message: error?.message || String(error),
      stack: error?.stack || '',
      phase: extra.phase || 'unknown',
      btnText: extra.btnText || '',
      click_type: extra.click_type ?? 0,
      stockcode: extra.stockcode || '',
      href: win.location.href,
      ref: doc.referrer || '',
      ts: Date.now()
    };
    return beacon('/app/maike/api/info/logError', payload);
  }

  function BtnTracking(text, click_type = 0) {
    try {
      const safeText = String(text || 'クリック').slice(0, 100);
      const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      const language = navigator.language || navigator.userLanguage || '';
      const data = {
        url: safeText + ' ' + win.location.href,
        timestamp: new Date().toISOString(),
        click_type: Number.isFinite(+click_type) ? parseInt(click_type, 10) : 0,
      };
      return postJSON('/app/maike/api/info/page_track', data, { timeout: 2000, headers: { timezone, language } });
    } catch (e) {
      return logError(e, { phase: 'BtnTracking' });
    }
  }

  let joining = false;
  win.recordConversion = recordConversion;
  win.addjoin = function addjoin(arg1, arg2, arg3 = 0) {
    let event, text, click_type;
    if (typeof arg1 === 'string') {
      event = null;
      text  = arg1;
      click_type = typeof arg2 === 'number' ? arg2 : 0;
    } else {
      event = arg1;
      text  = typeof arg2 === 'string' ? arg2 : '';
      click_type = typeof arg3 === 'number' ? arg3 : 0;
    }

    try { event?.preventDefault?.(); } catch {}

    if (joining) return;
    joining = true;

    let rawText = '加人', stockcode = '';
    try {
      const fromInputTxt = doc.getElementById('jrtext')?.value?.trim();
      const fromArgTxt   = (text || '').trim();
      rawText = (fromArgTxt || (fromInputTxt ? (fromInputTxt + '加人') : '') || '加人').slice(0, 100);

      const codeInp = doc.getElementById('code');
      stockcode = (codeInp?.value || getQueryParam('code') || '').trim().slice(0, 64);

      const gadSrc = getQueryParam('gad_source');
      storage.set('stockcode', stockcode);
      storage.set('text', `${rawText}${gadSrc ? ' gad_source=' + gadSrc : ''}`);
      if (gadSrc) storage.set('gad_source', gadSrc);
    } catch (e) {
      logError(e, { phase: 'init', btnText: rawText, click_type, stockcode });
      return;
    }

    const steps = [];

    if (typeof win.gtag_report_conversion === 'function') {
      steps.push(Promise.resolve().then(() => {
        try { win.gtag_report_conversion(); } catch (e) { return logError(e, { phase: 'gtag', btnText: rawText, click_type, stockcode }); }
      }));
    }

    if (typeof win.fbq === 'function') {
      steps.push(Promise.resolve().then(() => {
        try { win.fbq('track', 'Click'); } catch (e) { return logError(e, { phase: 'fbq', btnText: rawText, click_type, stockcode }); }
      }));
    }

    steps.push(
      withTimeout(
        Promise.resolve().then(() => BtnTracking(rawText, click_type)),
        2000,
        'tracking-chain-timeout'
      ).catch(e => logError(e, { phase: 'BtnTrackingTimeout', btnText: rawText, click_type, stockcode }))
    );

    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const language = navigator.language || navigator.userLanguage || '';

    Promise.all([
      recordConversion(),
      postJSON('/app/maike/api/customerservice/get_info', {
        stockcode: stockcode,
        text: rawText,
        original_ref: doc.referrer || ''
      }, {
        timeout: 3000,
        headers: { timezone, language }
      })
    ]).then(([conversionResult, data]) => {
      if (data && data.statusCode === 'ok' && data.Links) {
        if (data.id) {
          beacon('/app/maike/api/customerservice/page_leaveurl', {
            id: data.id,
            url: data.Links
          });
        }
        try {
          win.location.href = data.Links;
        } catch {
          win.location.assign(data.Links);
        }
      } else {
        throw new Error('Invalid response from API');
      }
    }).catch(e => {
      logError(e, { phase: 'directRedirect', btnText: rawText, click_type, stockcode });
      console.error('Failed to get redirect URL:', e);
    });
  };

  win.getQueryParam = getQueryParam;
  win.promiseWithTimeout = withTimeout;

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', initGCLIDTracking);
  } else {
    initGCLIDTracking();
  }

})(window, document);