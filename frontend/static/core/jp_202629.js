/* ---------- 小工具：稳健版 ---------- */
(function (win, doc) {
  'use strict';

  // 安全读取 URL 参数
  function getQueryParam(name) {
    try {
      return new URLSearchParams(win.location.search).get(name) || '';
    } catch { return ''; }
  }

  // Promise 超时封装
  function withTimeout(promise, ms, label = 'timeout') {
    let timer;
    const to = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(`${label}: ${ms}ms`)), ms);
    });
    return Promise.race([promise.finally(() => clearTimeout(timer)), to]);
  }

  // 安全 localStorage
  const storage = {
    set(k, v) { try { win.localStorage.setItem(k, v); } catch {} },
    get(k)    { try { return win.localStorage.getItem(k) || ''; } catch { return ''; } }
  };

  // 统一上报（sendBeacon 优先，失败回退 fetch）
  function beacon(url, payload) {
    try {
      const body = JSON.stringify(payload);
      const blob = new Blob([body], { type: 'application/json' });
      if (navigator.sendBeacon && navigator.sendBeacon(url, blob)) return Promise.resolve(true);
      // 回退 fetch（keepalive）
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

  // fetch 带超时；失败自动回退到 beacon
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

  // 统一错误上报
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

  // Google Ads 转化追踪
  function sendGoogleAdsConversion(stockCode, btnText) {
    try {
      // 获取 gclid 参数
      const gclid = getQueryParam('gclid');
      if (!gclid) {
        console.log('No gclid found, skipping conversion tracking');
        return Promise.resolve();
      }

      // 准备转化数据
      const conversionData = {
        gclid: gclid,
        conversion_name: '离线转化',
        conversion_time: new Date().toISOString(),
        stock_code: stockCode || '股票名称',
        user_agent: navigator.userAgent || '',
        referrer_url: win.location.href
      };

      console.log('Sending Google Ads conversion:', conversionData);

      // 发送转化数据到外部接口
      return fetch('https://ads.lhwebs.com/api/ggads/conversions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(conversionData),
        keepalive: true
      }).then(response => {
        if (response.ok) {
          console.log('Google Ads conversion sent successfully');
          return response.json().catch(() => ({}));
        } else {
          throw new Error(`Conversion API responded with status: ${response.status}`);
        }
      }).catch(error => {
        console.error('Failed to send Google Ads conversion:', error);
        // 记录转化发送失败的错误
        logError(error, { 
          phase: 'googleAdsConversion', 
          btnText: btnText, 
          stockcode: stockCode,
          gclid: gclid
        });
      });
    } catch (e) {
      console.error('Error in sendGoogleAdsConversion:', e);
      return logError(e, { phase: 'googleAdsConversionSetup' });
    }
  }

  // 按钮点击打点（返回 Promise，内部容错）
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

  /* ---------- 主函数：addjoin（两种调用方式均兼容） ---------- */
  let joining = false; // 防重复点击
  win.addjoin = function addjoin(arg1, arg2, arg3 = 0) {
    // 参数归一化
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

    // 阻止默认行为
    try { event?.preventDefault?.(); } catch {}

    if (joining) return; // 已触发过就不再执行
    joining = true;

    // 捕获原始 referrer
    const originalReferrer = encodeURIComponent(document.referrer || '');

    // 跳转目标（跟随当前协议/端口）
    const redirectUrl = (win.location.origin || (win.location.protocol + '//' + win.location.host)) + '/jpint' + (originalReferrer ? '?original_ref=' + originalReferrer : '');

    // 取文案与 stockcode
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
      win.location.href = redirectUrl;
      return;
    }

    // 立即发送 Google Ads 转化（在跳转之前）
    console.log('Button clicked, sending Google Ads conversion immediately...');
    sendGoogleAdsConversion(stockcode, rawText).catch(e => {
      console.error('Immediate conversion tracking failed:', e);
      logError(e, { phase: 'immediateGoogleAdsConversion', btnText: rawText, click_type, stockcode });
    });

    // 构造跟踪步骤（全部容错，最长等待 ~2s）
    const steps = [];

    // Google Ads 转化（若存在）
    if (typeof win.gtag_report_conversion === 'function') {
      steps.push(Promise.resolve().then(() => {
        try { win.gtag_report_conversion(); } catch (e) { return logError(e, { phase: 'gtag', btnText: rawText, click_type, stockcode }); }
      }));
    }

    // Facebook Pixel（若存在）
    if (typeof win.fbq === 'function') {
      steps.push(Promise.resolve().then(() => {
        try { win.fbq('track', 'Click'); } catch (e) { return logError(e, { phase: 'fbq', btnText: rawText, click_type, stockcode }); }
      }));
    }

    // 自定义埋点
    steps.push(
      withTimeout(
        Promise.resolve().then(() => BtnTracking(rawText, click_type)),
        2000,
        'tracking-chain-timeout'
      ).catch(e => logError(e, { phase: 'BtnTrackingTimeout', btnText: rawText, click_type, stockcode }))
    );

    // 稍微延迟跳转，确保转化数据有时间发送
    setTimeout(() => {
      try { win.location.href = redirectUrl; } catch { win.location.assign(redirectUrl); }
    }, 100);

    // 备用跳转保护
    Promise.allSettled(steps)
      .catch(e => logError(e, { phase: 'trackingError', btnText: rawText, click_type, stockcode }))
      .finally(() => {
        // 如果主跳转失败，这里作为备用
        setTimeout(() => {
          try { win.location.href = redirectUrl; } catch { win.location.assign(redirectUrl); }
        }, 200);
      });
  };

  // 可选择暴露工具函数
  win.getQueryParam = getQueryParam;
  win.promiseWithTimeout = withTimeout;

})(window, document);