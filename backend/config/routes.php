<?php
declare(strict_types=1);

use App\Controllers\StockController;
use App\Controllers\CustomerServiceController;
use App\Controllers\AdminController;
use App\Controllers\TrackingController;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    // 健康检查端点
    $app->get('/health', function ($request, $response, $args) {
        $response->getBody()->write(json_encode(['status' => 'healthy', 'timestamp' => date('c')]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // 股票相关API
    $app->group('/app/maike/api/stock', function (Group $group) {
        $group->get('/getinfo', StockController::class . ':getStockInfo');
    });

    // 客服相关API
    $app->group('/app/maike/api/customerservice', function (Group $group) {
        $group->post('/get_info', CustomerServiceController::class . ':getInfo');
    });

    // 管理后台页面
    $app->group('/admin', function (Group $group) {
        // 登录页面和登录处理（不需要认证）
        $group->get('', AdminController::class . ':login');
        $group->get('/', AdminController::class . ':login');
        $group->post('/login', AdminController::class . ':handleLogin');
        $group->get('/logout', AdminController::class . ':logout');
        
        // 需要认证的页面
        $group->get('/dashboard', AdminController::class . ':dashboard');
        $group->get('/customer-services', AdminController::class . ':customerServices');
        $group->post('/customer-services', AdminController::class . ':customerServices');
        $group->get('/tracking', TrackingController::class . ':tracking');

        // 管理后台API
        $group->map(['GET', 'POST', 'PUT', 'DELETE'], '/api/customer-services', AdminController::class . ':apiCustomerServices');
        $group->map(['GET', 'POST'], '/api/settings', AdminController::class . ':apiSettings');
        $group->get('/api/tracking/clicks', TrackingController::class . ':apiGetClicks');
        $group->post('/api/tracking/sync', TrackingController::class . ':apiSyncClicks');
        $group->get('/api/tracking/filters', TrackingController::class . ':apiGetFilters');
    });

    // 跳转页面
    $app->get('/jpint', function ($request, $response, $args) {
        $html = '<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Loading…</title>
  <meta name="robots" content="noindex, nofollow"/>
  <meta name="color-scheme" content="light dark"/>

  <style>
    :root{
      --bg1:#10a35a; --bg2:#25D366;
      --surface:#ffffff; --surface-2:#f6faf8;
      --text-strong:#0b1f18; --text:#213a32; --muted:#5b6f66;
      --border:#e6f0ec; --border-strong:#d8e7e0;
      --accent:#1fbe61; --accent-2:#13a455; --accent-weak:#e8f7ee;
      --shadow: 0 14px 40px rgba(0,0,0,.12);
    }
    @media (prefers-color-scheme: dark){
      :root{
        --bg1:#0b2a21; --bg2:#134332;
        --surface:#101a17; --surface-2:#0c1512;
        --text-strong:#eaf5ef; --text:#dff0e9; --muted:#b8d0c7;
        --border:#1c2f29; --border-strong:#27453b;
        --accent:#38d27a; --accent-2:#22c169; --accent-weak:#13261f;
        --shadow: 0 18px 50px rgba(0,0,0,.35);
      }
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0; padding:20px; display:flex; align-items:center; justify-content:center;
      background:
        radial-gradient(900px 650px at 10% -10%, var(--bg2), transparent 60%),
        radial-gradient(900px 700px at 110% 120%, var(--bg1), transparent 55%),
        linear-gradient(120deg, var(--bg1), var(--bg2));
      color:var(--text-strong);
      font-family: ui-sans-serif, system-ui, -apple-system, "Hiragino Kaku Gothic ProN", Meiryo, Segoe UI, Roboto, sans-serif;
      -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
    }

    .card{
      width:min(680px, 92vw);
      background:var(--surface);
      border:1px solid var(--border-strong);
      border-radius:20px; box-shadow:var(--shadow);
      padding:26px 22px; display:flex; flex-direction:column; gap:16px; align-items:center; text-align:center;
    }

    .badge-row{display:flex; flex-wrap:wrap; gap:8px; justify-content:center}
    .badge{
      display:inline-flex; align-items:center; gap:6px;
      padding:6px 10px; border-radius:999px; font-size:12px;
      background:var(--accent-weak); border:1px solid var(--border-strong); color:var(--text-strong)
    }
    .dot{width:8px; height:8px; border-radius:50%; background:#6ee7b7; box-shadow:0 0 10px #6ee7b7}

    .spinner{
      --s:64px; width:var(--s); height:var(--s); border-radius:50%;
      border:6px solid #e4efe9; border-top-color:var(--accent);
      animation:spin 1s linear infinite; position:relative; margin-top:4px;
    }
    .spinner:after{
      content:""; position:absolute; inset:-10px; border-radius:50%;
      border:1px dashed var(--border); animation:spin 6s linear infinite reverse;
    }
    @keyframes spin{to{transform:rotate(360deg)}}
    @media (prefers-reduced-motion:reduce){.spinner,.spinner:after{animation:none}}

    .headline{ font-size:22px; line-height:1.35; letter-spacing:.2px; margin-top:6px; color:var(--text-strong); }
    .sub{ font-size:14.5px; line-height:1.8; color:var(--text); max-width:54ch }

    .progress{ width:100%; height:10px; border-radius:999px; background:var(--surface-2); border:1px solid var(--border); overflow:hidden }
    .bar{ width:0; height:100%; background:linear-gradient(90deg,var(--accent),var(--accent-2)) }

    .safe{ display:flex; align-items:center; gap:10px; color:var(--muted); font-size:12px; }
    .shield{ width:18px; height:18px; border-radius:6px; display:grid; place-items:center; background:var(--surface-2); border:1px solid var(--border) }

    .actions{ display:flex; gap:12px; flex-wrap:wrap; justify-content:center; margin-top:4px; }
    .btn{
      appearance:none; border:none; cursor:pointer;
      padding:11px 16px; border-radius:12px; font-weight:700; font-size:14px;
      color:#062317; background:linear-gradient(180deg,var(--accent),var(--accent-2));
      box-shadow:0 8px 18px rgba(31,190,97,.25); border:1px solid rgba(0,0,0,.04);
      transition: transform .05s ease;
    }
    .btn:active{ transform: translateY(1px) }
    .btn[disabled]{ opacity:.55; cursor:not-allowed; filter:saturate(.6) }

    .btn.secondary{
      background:var(--surface); color:var(--text-strong);
      border:1px solid var(--border-strong); box-shadow:none;
    }

    .foot{ color:var(--muted); font-size:12px; margin-top:2px; }
    .section{display:none}
    .section.active{display:block}
  </style>
</head>
<body>
  <main class="card" role="status" aria-live="polite" aria-busy="true">
    <div class="badge-row">
      <span class="badge"><span class="dot" aria-hidden="true"></span> <span id="badge-secure">Encrypted</span></span>
      <span class="badge" id="badge-no-spam">No spam</span>
      <span class="badge" id="badge-safe-bridge">Safe redirect</span>
    </div>

    <div class="spinner" aria-hidden="true"></div>
    <div id="headline" class="headline">Opening…</div>
    <div class="sub" id="cs-name" style="display:none;"></div>

    <div class="progress" aria-hidden="true"><div class="bar" id="bar"></div></div>

    <div class="safe">
      <div class="shield" aria-hidden="true">🛡️</div>
      <span id="safe-text">This is a secure bridge page. No personal data required.</span>
    </div>

    <!-- 主操作按钮：根据接口返回填充并启用 -->
    <div class="actions">
      <button id="btn-open" class="btn" disabled aria-disabled="true">Open</button>
      <button id="btn-join" class="btn secondary" disabled aria-disabled="true">Join</button>
    </div>

    <!-- 错误提示区（仅在接口失败时出现） -->
    <section id="sec-error" class="section" aria-live="assertive">
      <div class="headline" id="err-title" style="margin-top:8px;">Unable to connect</div>
      <div id="alertMessage" class="sub" style="margin-bottom:8px;">Please check your network or return to Home.</div>
      <div class="actions" style="margin-top:6px;">
        <button id="btn-home" class="btn">Back to Home</button>
        <button id="btn-retry" class="btn secondary">Retry</button>
      </div>
    </section>

    <div class="foot" id="foot-left">© Secure Bridge | Only launches the official app</div>
  </main>
<script>
(function () {
  // ===== 基本配置（可被 URL 覆盖）=====
  const qp = new URLSearchParams(location.search);
  const serviceName = (window.SERVICE_NAME || qp.get(\'service\') || \'LINE\').trim();
  const lang = (qp.get(\'lang\') || \'ja\').toLowerCase();
  const originalRef = qp.get(\'original_ref\') || \'\'; // 新增：获取原始 referrer

  const I18N = {
    \'en\': {
      badgeSecure: \'Encrypted connection\',
      badgeNoSpam: \'No spam or abuse\',
      badgeSafeBridge: \'Safe redirect\',
      opening: `Opening ${serviceName}… Please wait`,
      csName: (name)=> name ? `Official account: ${name}` : \'\',
      safe: `This page is a secure bridge. No personal data is required.`,
      btnOpen: `Open ${serviceName} now`,
      btnJoin: \'Join now\',
      errTitle: \'Unable to connect\',
      errMsg: `Can\'t connect to ${serviceName}. Check your network or go back to the home page.`,
      home: \'Back to Home\', retry: \'Retry\',
      footLeft: \'© Secure Bridge | Only launches the official app\'
    },
    \'zh-tw\': {
      badgeSecure: \'連線已加密\',
      badgeNoSpam: \'不會發送垃圾訊息\',
      badgeSafeBridge: \'安全轉址\',
      opening: `正在開啟 ${serviceName}，請稍候…`,
      csName: (name)=> name ? `官方帳號：${name}` : \'\',
      safe: `本頁僅作為中繼橋接，無需填寫個人資料。`,
      btnOpen: `立即開啟 ${serviceName}`,
      btnJoin: \'立即加入\',
      errTitle: \'無法連線\',
      errMsg: `無法連線至 ${serviceName}。請檢查網路或返回首頁。`,
      home: \'返回首頁\', retry: \'重試\',
      footLeft: \'© Secure Bridge｜僅啟動官方應用\'
    },
    \'zh-cn\': {
      badgeSecure: \'连接已加密\',
      badgeNoSpam: \'不会发送垃圾信息\',
      badgeSafeBridge: \'安全跳转\',
      opening: `正在打开 ${serviceName}，请稍候…`,
      csName: (name)=> name ? `官方账号：${name}` : \'\',
      safe: `此页面仅作中继桥接，无需填写个人信息。`,
      btnOpen: `立即打开 ${serviceName}`,
      btnJoin: \'立即加入\',
      errTitle: \'无法连接\',
      errMsg: `无法连接到 ${serviceName}。请检查网络或返回首页。`,
      home: \'返回首页\', retry: \'重试\',
      footLeft: \'© Secure Bridge｜仅启动官方应用\'
    },
    \'ja\': {
      badgeSecure: \'通信は暗号化\',
      badgeNoSpam: \'迷惑行為はありません\',
      badgeSafeBridge: \'安全な転送\',
      opening: `${serviceName} を開いています… しばらくお待ちください`,
      csName: (name)=> name ? `公式アカウント：${name}` : \'\',
      safe: \'このページは中継専用です。個人情報の入力は不要です。\',
      btnOpen: `今すぐ ${serviceName} を開く`,
      btnJoin: \'今すぐ参加\',
      errTitle: \'接続できませんでした\',
      errMsg: `${serviceName} に接続できません。ネットワークをご確認いただくか、ホームに戻ってください。`,
      home: \'ホームに戻る\', retry: \'再試行\',
      footLeft: \'© Secure Bridge | 正規アプリのみを起動します\'
    }
  };
  const t = I18N[lang] || I18N.en;

  // ===== 绑定 DOM =====
  const $ = (id)=> document.getElementById(id);
  const secError = $(\'sec-error\');
  const bar = $(\'bar\');
  const btnOpen = $(\'btn-open\');
  const btnJoin = $(\'btn-join\');

  // 初始文案
  document.title = t.opening;
  $(\'badge-secure\').textContent = t.badgeSecure;
  $(\'badge-no-spam\').textContent = t.badgeNoSpam;
  $(\'badge-safe-bridge\').textContent = t.badgeSafeBridge;
  $(\'headline\').textContent = t.opening;
  $(\'safe-text\').textContent = t.safe;
  $(\'err-title\').textContent = t.errTitle;
  $(\'alertMessage\').textContent = t.errMsg;
  $(\'btn-home\').textContent = t.home;
  $(\'btn-retry\').textContent = t.retry;
  $(\'foot-left\').textContent = t.footLeft;
  btnOpen.textContent = t.btnOpen;
  btnJoin.textContent = t.btnJoin;

  // ===== 配置：单次拉起 + 仅失败时跳转 =====
  const cfg = {
    openDelay: 150,       // 首次拉起延时
    fallbackDelay: 5000,  // 成功检测窗口（未离开则视为失败）
  };

  // ===== 轻量日志 & 进度条 =====
  const isDebug = location.search.includes(\'debug=1\');
  function startProgress(){
    if (!bar) return;
    let p = 0; bar.style.width = \'0%\';
    const step = ()=> {
      if (!bar || p >= 95) return;
      p += Math.random()*18 + 8; if (p>95) p = 95;
      bar.style.width = p + \'%\';
      requestAnimationFrame(()=> setTimeout(step, 260));
    };
    step();
  }

  // ===== 状态 =====
  let serviceUrl = \'\', fallbackLink = \'/\', csDisplayName = \'\';
  const guards = { fallbackTimer: null, launchSuccess: false, openedAt: 0, listenersOn: false };

  // 多策略打开（不区分 iOS/Android）
  function openWithStrategies(url) {
    try { window.location.href = url; } catch(_){}
    try { window.location.assign(url); } catch(_){}
    try { window.location.replace(url); } catch(_){}
    try {
      const ifr = document.createElement(\'iframe\');
      ifr.style.display = \'none\';
      ifr.src = url;
      document.body.appendChild(ifr);
      setTimeout(() => { try { document.body.removeChild(ifr); } catch(_){} }, 1800);
    } catch(_){}
    try {
      const a = document.createElement(\'a\');
      a.href = url;
      a.style.display = \'none\';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    } catch(_){}
  }

  // ===== 成功监测 & 兜底跳转控制 =====
  function removeSuccessListeners(){
    if (!guards.listenersOn) return;
    document.removeEventListener(\'visibilitychange\', onVis);
    window.removeEventListener(\'pagehide\', onPageHide);
    window.removeEventListener(\'blur\', onBlurEarly);
    guards.listenersOn = false;
  }
  function markLaunchedSuccess(){
    if (guards.launchSuccess) return;
    guards.launchSuccess = true;
    clearTimeout(guards.fallbackTimer);
    removeSuccessListeners();
    if (isDebug) console.log(\'[launch] success detected, no fallback redirect\');
  }
  function onVis(){
    if (document.visibilityState === \'hidden\') markLaunchedSuccess();
  }
  function onPageHide(){
    markLaunchedSuccess();
  }
  function onBlurEarly(){
    // 某些 iOS/Android 场景先触发 blur，再隐藏；限定早期窗口内有效
    if (performance.now() - guards.openedAt < 1200) markLaunchedSuccess();
  }

  function launchOnce(){
    guards.launchSuccess = false;
    guards.openedAt = performance.now();

    // 绑定一次性的成功监测监听
    if (!guards.listenersOn){
      document.addEventListener(\'visibilitychange\', onVis);
      window.addEventListener(\'pagehide\', onPageHide);
      window.addEventListener(\'blur\', onBlurEarly);
      guards.listenersOn = true;
    }

    // 安排兜底：仅当未成功时才跳 Links
    clearTimeout(guards.fallbackTimer);
    guards.fallbackTimer = setTimeout(()=>{
      if (!guards.launchSuccess) {
        if (isDebug) console.warn(\'fallback: open failed → redirect to Links\');
        location.href = fallbackLink;
      }
    }, cfg.fallbackDelay);

    // 稍等再拉起，给浏览器时间渲染
    setTimeout(()=> openWithStrategies(serviceUrl), cfg.openDelay);
  }

  // ===== 拉取目标并启动流程（只尝试一次自动拉起）=====
  async function fetchServiceData(){
    secError.classList.remove(\'active\');
    startProgress();
    try{
      const res = await fetch(location.origin + \'/app/maike/api/customerservice/get_info\', {
        method:\'POST\',
        headers:{
          \'Content-Type\':\'application/json\',
          \'timezone\': Intl.DateTimeFormat().resolvedOptions().timeZone,
          \'language\': lang
        },
        body: JSON.stringify({
          stockcode: localStorage.getItem(\'stockcode\') || \'\',
          text: localStorage.getItem(\'text\') || \'\',
          original_ref: decodeURIComponent(originalRef) // 新增：发送原始 referrer
        })
      });
      const data = await res.json();
      if (isDebug) console.log(\'[get_info]\', data);

      if (data.statusCode === \'ok\' && data.CustomerServiceUrl){
        serviceUrl = data.CustomerServiceUrl;
        fallbackLink = data.Links || \'/\';
        csDisplayName = data.CustomerServiceName || \'\';

        // 展示客服名（如有）
        const nameEl = $(\'cs-name\');
        const nameTxt = (t.csName && typeof t.csName === \'function\') ? t.csName(csDisplayName) : \'\';
        if (nameTxt) { nameEl.textContent = nameTxt; nameEl.style.display = \'\'; }

        // 启用按钮（多语言文案）
        btnOpen.disabled = false; btnOpen.setAttribute(\'aria-disabled\',\'false\'); btnOpen.textContent = t.btnOpen;
        btnJoin.disabled = false; btnJoin.setAttribute(\'aria-disabled\',\'false\'); btnJoin.textContent = t.btnJoin;

        // 绑定按钮操作
        btnOpen.onclick = ()=> {
          launchOnce();
        };
        btnJoin.onclick = ()=> {
          location.href = fallbackLink;
        };

        // 进度条拉满
        if (bar) bar.style.width=\'100%\';

        // 自动拉起（仅一次）
        launchOnce();

      } else {
        throw new Error(\'URL not provided\');
      }
    }catch(err){
      if (isDebug) console.error(\'fetchServiceData error:\', err);
      secError.classList.add(\'active\');
    }
  }

  // 错误区交互
  $(\'btn-retry\')?.addEventListener(\'click\', fetchServiceData);
  $(\'btn-home\')?.addEventListener(\'click\', ()=>{ location.href=\'/\' });

  // ===== 启动 =====
  fetchServiceData();
})();
</script>

</body>
</html>';
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};