(function () {
  'use strict';

  const { createApp, reactive, ref, computed, watch, nextTick, onMounted, onBeforeUnmount } = Vue;
  const ElMessage = ElementPlus.ElMessage;
  const ElMessageBox = ElementPlus.ElMessageBox;

  const VIEWS = [
    { key: 'overview', name: '站点概览', icon: 'overview' },
    { key: 'test', name: '在线测试', icon: 'test' },
    { key: 'calls', name: '调用记录', icon: 'calls' },
    { key: 'api', name: '接口文档', icon: 'api' },
    { key: 'upstream', name: '上游设置', icon: 'upstream' },
    { key: 'site', name: '站点设置', icon: 'site' },
    { key: 'plugin', name: '对接插件', icon: 'plugin' },
    { key: 'system', name: '系统设置', icon: 'system' }
  ];

  const CODE_TEXT = {
    200: '成功', 400: '参数不对', 401: '密钥不对', 404: '没有记录', 405: '方法不对',
    409: '需要升级', 419: '页面过期', 429: '太频繁', 500: '服务异常', 502: '上游异常',
    503: '稍后再试', 504: '超时', 1001: '已发起', 1002: '处理中', 1008: '待采集',
    2001: '通过', 2002: '不通过'
  };

  function fmtTime(s) {
    if (!s) return '—';
    const t = String(s);
    return t.length >= 19 ? t.slice(5, 19) : t;
  }
  function fmtDay(s) {
    return String(s || '').slice(5);
  }
  function bytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }
  function codeText(c) {
    return CODE_TEXT[Number(c)] || ('错误 ' + c);
  }
  function codeCls(c) {
    c = Number(c);
    if (c === 2001 || c === 200 || c === 1001) return 'ok';
    if (c === 2002 || c === 2008 || c === 2009) return 'err';
    if (c >= 2000) return 'ok';
    if (c === 1002 || c === 1008) return 'wait';
    if (c >= 400) return 'err';
    return 'warn';
  }
  function verCmp(a, b) {
    const pa = String(a || '0').split('.').map((x) => parseInt(x, 10) || 0);
    const pb = String(b || '0').split('.').map((x) => parseInt(x, 10) || 0);
    const n = Math.max(pa.length, pb.length, 3);
    for (let i = 0; i < n; i++) {
      const x = pa[i] || 0;
      const y = pb[i] || 0;
      if (x !== y) return x > y ? 1 : -1;
    }
    return 0;
  }
  function maskKey(s) {
    s = String(s || '');
    if (!s) return '';
    if (s.length <= 8) return '********';
    return s.slice(0, 4) + '*'.repeat(Math.max(4, s.length - 8)) + s.slice(-4);
  }

  const App = {
    setup() {
      const boot = reactive({ ready: false, error: '' });
      const auth = ref(false);
      const upgrade = ref(false);
      const csrf = ref('');
      const version = ref('');
      const dbVer = ref('');
      const admin = reactive({ username: '' });
      const site = reactive({ name: '', url: '' });
      const view = ref('overview');
      const navOpen = ref(false);
      const upgrading = ref(false);
      const fileInput = ref(null);

      const login = reactive({ user: '', pwd: '', busy: false, err: '' });

      const ov = reactive({ status: 'idle', data: null, error: '' });
      const calls = reactive({
        status: 'idle', rows: [], total: 0, page: 1, pages: 1, ps: 20,
        error: '', api: '', kw: '', days: 0
      });
      const apiPage = reactive({ status: 'idle', data: null, error: '', doc: 'face_start', show: false });
      const up = reactive({ status: 'idle', type: 'liveness', data: null, error: '' });
      const sitePage = reactive({ status: 'idle', data: null, error: '', busy: false });
      const pluginPage = reactive({ status: 'idle', data: null, error: '' });
      const sys = reactive({ status: 'idle', data: null, error: '' });
      const tst = reactive({
        mode: 'compare', name: '', idcard: '', image: '', fileName: '', preview: '',
        busy: false, polling: false, error: '', result: null, token: '', verifyUrl: ''
      });
      const photoInput = ref(null);
      const qrBox = ref(null);

      const callDlg = reactive({ open: false, status: 'idle', data: null, error: '', id: 0 });
      const cfgDlg = reactive({ open: false, plugin: null, type: '', form: {}, busy: false, testing: false });
      const infoDlg = reactive({ open: false, plugin: null });
      const upDlg = reactive({
        open: false, step: 1, fileName: '', busy: false, preview: null, error: '', committed: false
      });
      const upFile = ref(null);

      const siteForm = reactive({ site_name: '', site_url: '', live_ttl: 300 });
      const pwdForm = reactive({ old: '', new: '', new2: '', busy: false });
      const pkgName = ref('');
      const pkgBusy = ref(false);
      const pkgErr = ref('');
      const pkgInput = ref(null);
      const pkgFile = ref(null);

      let kwTimer = null;

      const viewName = computed(() => {
        const v = VIEWS.find((x) => x.key === view.value);
        return v ? v.name : '数据概览';
      });
      const initial = computed(() => (admin.username || 'A').slice(0, 1).toUpperCase());
      const dbNewer = computed(() => {
        const db = sys.data ? sys.data.db_ver : dbVer.value;
        const app = sys.data ? sys.data.version : version.value;
        return verCmp(db, app) > 0;
      });
      const trendMax = computed(() => {
        const t = ov.data ? ov.data.trend : [];
        return Math.max(1, ...t.map((x) => Number(x.n) || 0));
      });
      const setupRows = computed(() => {
        const d = ov.data;
        if (!d) return [];
        return [
          { key: 'site', label: '站点信息', ok: !!d.setup.site, to: 'site' },
          { key: 'key', label: '接口密钥', ok: !!d.setup.key, to: 'api' },
          { key: 'live', label: '活体上游', ok: !!d.setup.live, to: 'upstream' },
          { key: 'compare', label: '人脸上游', ok: !!d.setup.compare, to: 'upstream' },
          { key: 'version', label: '系统版本', ok: !!d.setup.version, to: 'system' }
        ];
      });
      const allSetup = computed(() => setupRows.value.length > 0 && setupRows.value.every((x) => x.ok));
      const upTitle = computed(() => (up.type === 'liveness' ? '活体插件' : '人脸比对插件'));
      const activeName = computed(() => {
        const d = up.data;
        if (!d) return '';
        const p = (d.plugins || []).find((x) => x.active);
        return p ? p.meta.name : '未启用';
      });
      const testLabel = computed(() => {
        const r = tst.result;
        if (!r) return '';
        if (r.state === 'pass') return '通过';
        if (r.state === 'fail') return '不通过';
        if (r.state === 'wait') return '等待采集';
        return '没拿到结论';
      });
      const testScore = computed(() => {
        const r = tst.result;
        if (!r || r.score === null || r.score === undefined || r.score === '') return '—';
        return Math.round(Number(r.score) * 10) / 10;
      });
      const apiDocs = computed(() => {
        const d = apiPage.data;
        if (!d) return [];
        const base = d.base;
        const id = d.id;
        const startReq = "curl -X POST '" + base + "/api/v4/face_start' \\\n"
          + "  -H 'X-App-Id: " + id + "' \\\n"
          + "  -H 'X-App-Key: YOUR_APP_KEY' \\\n"
          + "  -H 'Content-Type: application/x-www-form-urlencoded' \\\n"
          + "  --data-urlencode 'name=张三' \\\n"
          + "  --data-urlencode 'idcard=110101199001011234' \\\n"
          + "  --data-urlencode 'living_type=26'";
        const queryReq = "curl -X POST '" + base + "/api/v4/face_query' \\\n"
          + "  -H 'X-App-Id: " + id + "' \\\n"
          + "  -H 'X-App-Key: YOUR_APP_KEY' \\\n"
          + "  -H 'Content-Type: application/x-www-form-urlencoded' \\\n"
          + "  -d 'order_no=ORDER_NO'";
        const hyReq = "curl -X POST '" + base + "/api/v4/facehy' \\\n"
          + "  -H 'X-App-Id: " + id + "' \\\n"
          + "  -H 'X-App-Key: YOUR_APP_KEY' \\\n"
          + "  -H 'Content-Type: application/x-www-form-urlencoded' \\\n"
          + "  --data-urlencode 'name=张三' \\\n"
          + "  --data-urlencode 'idcard=110101199001011234' \\\n"
          + "  --data-urlencode 'file_url=https://example.com/face.jpg'";
        return [
          {
            key: 'face_start',
            title: '发起活体认证',
            desc: '提交姓名和身份证号，拿到 verify_url 与 order_no。',
            method: 'POST',
            path: '/api/v4/face_start',
            params: [
              { n: 'name', t: 'string', r: true, d: '姓名，最多 30 个字' },
              { n: 'idcard', t: 'string', r: true, d: '18 位身份证号' },
              { n: 'living_type', t: 'string', r: false, d: '动作组合，1–6 里选，如 26' },
              { n: 'return_url', t: 'string', r: false, d: '认证结束后跳回的地址' }
            ],
            req: startReq,
            resp: '{\n  "code": 200,\n  "msg": "接口调用成功。",\n  "data": {\n'
              + '    "order_no": "SF202611210001",\n'
              + '    "verify_url": "https://example.com/live?t=…",\n'
              + '    "biz_id": "…",\n'
              + '    "living_type": "26"\n'
              + '  },\n  "trace": "…",\n  "cost": 0,\n  "balance": null\n}'
          },
          {
            key: 'face_query',
            title: '查询认证结果',
            desc: '拿 order_no 查询活体与比对的结论。',
            method: 'POST',
            path: '/api/v4/face_query',
            params: [
              { n: 'order_no', t: 'string', r: true, d: 'face_start 返回的单号' }
            ],
            req: queryReq,
            resp: '{\n  "code": 200,\n  "msg": "核验通过。",\n  "data": {\n'
              + '    "order_no": "SF202611210001",\n'
              + '    "passed": 1,\n'
              + '    "result_code": "OK",\n'
              + '    "image_url": "https://example.com/uploads/face/…",\n'
              + '    "score": 96,\n'
              + '    "message": "核验通过。"\n'
              + '  },\n  "trace": "…",\n  "cost": 0,\n  "balance": null\n}'
          },
          {
            key: 'facehy',
            title: '人脸身份比对',
            desc: '姓名、证件号加一张人脸图，直接判断是不是同一个人。',
            method: 'GET/POST',
            path: '/api/v4/facehy',
            params: [
              { n: 'name', t: 'string', r: true, d: '姓名，最多 30 个字' },
              { n: 'idcard', t: 'string', r: true, d: '18 位身份证号' },
              { n: 'file_url', t: 'string', r: false, d: '人脸图公网地址' },
              { n: 'file_base64', t: 'string', r: false, d: '人脸图 base64，与 file_url 二选一' }
            ],
            req: hyReq,
            resp: '{\n  "code": 200,\n  "msg": "一致。",\n  "data": {\n'
              + '    "score": 96,\n    "verify": 1,\n    "message": "一致。"\n'
              + '  },\n  "trace": "…",\n  "cost": 0,\n  "balance": null\n}'
          }
        ];
      });
      const apiDoc = computed(() => {
        const list = apiDocs.value;
        return list.find((x) => x.key === apiPage.doc) || list[0] || null;
      });
      const statusRows = [
        { c: 200, d: '成功' },
        { c: 400, d: '参数不完整或格式不对' },
        { c: 401, d: 'AppID 或 AppKey 不对' },
        { c: 404, d: '这个单号不存在' },
        { c: 502, d: '上游暂时不可用' },
        { c: 503, d: '结果还没出来，或者正在升级' },
        { c: 504, d: '认证超时' }
      ];

      async function api(act, opt) {
        opt = opt || {};
        let url = 'api.php?act=' + encodeURIComponent(act);
        if (opt.query) {
          Object.keys(opt.query).forEach((k) => {
            const v = opt.query[k];
            if (v === undefined || v === null || v === '') return;
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
          });
        }
        const headers = { Accept: 'application/json' };
        if (csrf.value) headers['X-CSRF-Token'] = csrf.value;
        let payload;
        if (opt.form) {
          payload = opt.form;
        } else if (opt.body) {
          headers['Content-Type'] = 'application/json';
          payload = JSON.stringify(opt.body);
        }
        let res;
        try {
          res = await fetch(url, {
            method: opt.method || 'GET',
            headers: headers,
            body: payload,
            credentials: 'same-origin',
            cache: 'no-store'
          });
        } catch (e) {
          const err = new Error('网络断了，再试一次。');
          err.status = 0;
          throw err;
        }
        const text = await res.text();
        let j = null;
        try {
          j = JSON.parse(text);
        } catch (e) {
          const err = new Error('服务端返回的内容看不懂（HTTP ' + res.status + '）。');
          err.status = res.status;
          throw err;
        }
        if (!res.ok || !j.ok) {
          const err = new Error((j && j.msg) || ('请求失败（HTTP ' + res.status + '）。'));
          err.status = res.status;
          err.upgrade = !!(j && j.upgrade);
          if (j && j.auth === false) {
            auth.value = false;
            csrf.value = '';
          }
          if (err.upgrade) upgrade.value = true;
          throw err;
        }
        return j.data;
      }

      function applyAuth(d) {
        admin.username = (d.admin && d.admin.username) || '';
        site.name = (d.site && d.site.name) || '';
        site.url = (d.site && d.site.url) || '';
        version.value = d.version || version.value;
        dbVer.value = d.db_ver || '';
      }

      function hashView() {
        const h = String(location.hash || '').replace(/^#\/?/, '');
        return VIEWS.some((v) => v.key === h) ? h : 'overview';
      }

      function resetPages() {
        [ov, calls, apiPage, up, sitePage, pluginPage, sys].forEach((p) => {
          if ('status' in p) p.status = 'idle';
          if ('error' in p) p.error = '';
        });
        resetTest();
      }

      async function bootApp() {
        boot.ready = false;
        boot.error = '';
        try {
          const d = await api('boot');
          csrf.value = d.csrf || '';
          version.value = d.version || '';
          upgrade.value = !!d.upgrade;
          auth.value = !!d.auth;
          if (d.auth) applyAuth(d);
          boot.ready = true;
          if (auth.value && !upgrade.value) {
            view.value = hashView();
            await load(view.value);
          }
        } catch (e) {
          boot.error = e.message;
          boot.ready = true;
        }
      }

      async function doLogin() {
        if (login.busy) return;
        login.err = '';
        if (!login.user.trim() || !login.pwd) {
          login.err = '账号和密码都要填。';
          return;
        }
        login.busy = true;
        try {
          if (!csrf.value) {
            const b = await api('boot');
            csrf.value = b.csrf || '';
          }
          const d = await api('login', {
            method: 'POST',
            body: { username: login.user.trim(), password: login.pwd }
          });
          csrf.value = d.csrf || csrf.value;
          auth.value = true;
          upgrade.value = !!d.upgrade;
          applyAuth(d);
          login.pwd = '';
          view.value = hashView();
          if (!upgrade.value) await load(view.value);
        } catch (e) {
          login.err = e.message;
          if (e.status === 419) {
            try {
              const b = await api('boot');
              csrf.value = b.csrf || csrf.value;
            } catch (x) {  }
          }
        } finally {
          login.busy = false;
        }
      }

      async function doLogout() {
        try {
          await api('logout', { method: 'POST', body: {} });
        } catch (e) {  }
        auth.value = false;
        admin.username = '';
        csrf.value = '';
        login.pwd = '';
        login.err = '';
        view.value = 'overview';
        navOpen.value = false;
        resetPages();
      }

      async function go(k, first) {
        if (!VIEWS.some((x) => x.key === k)) k = 'overview';
        navOpen.value = false;
        if (!first && location.hash !== '#/' + k) {
          location.hash = '#/' + k;
        }
        view.value = k;
        if (auth.value && !upgrade.value) await load(k);
      }

      async function load(k, force) {
        if (k === 'test') return;
        if (k === 'overview') return loadOv(force);
        if (k === 'calls') return loadCalls(force);
        if (k === 'api') return loadApi(force);
        if (k === 'upstream') return loadUp(force);
        if (k === 'site') return loadSite(force);
        if (k === 'plugin') return loadPlugin(force);
        if (k === 'system') return loadSys(force);
      }

      function refresh() {
        if (!auth.value || upgrade.value) return;
        if (view.value === 'calls') {
          loadCalls(true);
          return;
        }
        load(view.value, true);
      }

      async function loadOv(force) {
        if (ov.status === 'loading') return;
        if (ov.status === 'ok' && !force) return;
        ov.status = 'loading';
        ov.error = '';
        try {
          ov.data = await api('overview');
          ov.status = 'ok';
          if (ov.data) {
            version.value = ov.data.version || version.value;
            dbVer.value = ov.data.db_ver || '';
            upgrade.value = !!ov.data.upgrade;
          }
        } catch (e) {
          ov.error = e.message;
          ov.status = 'error';
        }
      }

      async function loadCalls(force) {
        if (calls.status === 'loading') return;
        if (calls.status === 'ok' && force === false) return;
        calls.status = 'loading';
        calls.error = '';
        try {
          const d = await api('calls', {
            query: { api: calls.api, kw: calls.kw, days: calls.days, page: calls.page, ps: calls.ps }
          });
          calls.rows = d.rows || [];
          calls.total = Number(d.total) || 0;
          calls.page = Number(d.page) || 1;
          calls.pages = Number(d.pages) || 1;
          calls.status = 'ok';
        } catch (e) {
          calls.error = e.message;
          calls.status = 'error';
        }
      }

      function onKw() {
        clearTimeout(kwTimer);
        kwTimer = setTimeout(() => {
          calls.page = 1;
          loadCalls(true);
        }, 320);
      }
      function searchNow() {
        clearTimeout(kwTimer);
        calls.page = 1;
        loadCalls(true);
      }
      function setCallApi(v) {
        if (calls.api === v) return;
        calls.api = v;
        calls.page = 1;
        loadCalls(true);
      }
      function setCallDays() {
        calls.page = 1;
        loadCalls(true);
      }
      function goCallsPage(p) {
        calls.page = p;
        loadCalls(true);
      }

      async function openCall(id) {
        callDlg.open = true;
        callDlg.id = id;
        callDlg.status = 'loading';
        callDlg.data = null;
        callDlg.error = '';
        try {
          callDlg.data = await api('call_detail', { query: { id: id } });
          callDlg.status = 'ok';
        } catch (e) {
          callDlg.error = e.message;
          callDlg.status = 'error';
        }
      }
      async function retryCall() {
        if (callDlg.id) await openCall(callDlg.id);
      }

      async function loadApi(force) {
        if (apiPage.status === 'loading') return;
        if (apiPage.status === 'ok' && !force) return;
        apiPage.status = 'loading';
        apiPage.error = '';
        try {
          apiPage.data = await api('api_info');
          apiPage.status = 'ok';
        } catch (e) {
          apiPage.error = e.message;
          apiPage.status = 'error';
        }
      }
      async function resetKey() {
        try {
          await ElMessageBox.confirm('生成新密钥后，旧密钥立刻失效。', '换一把密钥', {
            confirmButtonText: '生成',
            cancelButtonText: '算了',
            type: 'warning'
          });
        } catch (e) {
          return;
        }
        try {
          const d = await api('api_reset', { method: 'POST', body: {} });
          apiPage.data.id = d.id;
          apiPage.data.key = d.key;
          apiPage.show = true;
          ElMessage.success('新密钥好了。');
        } catch (e) {
          ElMessage.error(e.message);
        }
      }

      async function loadUp(force) {
        if (up.status === 'loading') return;
        if (up.status === 'ok' && !force) return;
        up.status = 'loading';
        up.error = '';
        try {
          up.data = await api('upstream', { query: { type: up.type } });
          up.status = 'ok';
        } catch (e) {
          up.error = e.message;
          up.status = 'error';
        }
      }
      function switchUp(t) {
        if (up.type === t) return;
        up.type = t;
        up.data = null;
        up.status = 'idle';
        loadUp(true);
      }
      function openCfg(p) {
        const form = {};
        (p.meta.config || []).forEach((f) => {
          form[f.key] = p.cfg && p.cfg[f.key] !== undefined ? String(p.cfg[f.key]) : String(f.default || '');
        });
        cfgDlg.plugin = p;
        cfgDlg.type = p.meta.type;
        cfgDlg.form = form;
        cfgDlg.open = true;
        cfgDlg.busy = false;
        cfgDlg.testing = false;
      }
      function cfgBody() {
        return {
          type: cfgDlg.type,
          plugin: cfgDlg.plugin ? cfgDlg.plugin.id : '',
          cfg: Object.assign({}, cfgDlg.form)
        };
      }
      async function saveCfg() {
        if (!cfgDlg.plugin || cfgDlg.busy) return;
        cfgDlg.busy = true;
        try {
          const d = await api('plugin_save', { method: 'POST', body: cfgBody() });
          cfgDlg.plugin.cfg = d.cfg || cfgDlg.form;
          cfgDlg.plugin.missing = d.missing || [];
          ElMessage.success('配置保存好了。');
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          cfgDlg.busy = false;
        }
      }
      async function testCfg() {
        if (!cfgDlg.plugin || cfgDlg.testing) return;
        cfgDlg.testing = true;
        try {
          const d = await api('plugin_test', { method: 'POST', body: cfgBody() });
          if (d.ok) ElMessage.success(d.msg || '连接正常。');
          else ElMessage.error(d.msg || '没连上。');
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          cfgDlg.testing = false;
        }
      }
      async function enableCfg() {
        if (!cfgDlg.plugin || cfgDlg.busy) return;
        cfgDlg.busy = true;
        try {
          await api('plugin_on', { method: 'POST', body: cfgBody() });
          ElMessage.success('已启用「' + cfgDlg.plugin.meta.name + '」。');
          cfgDlg.open = false;
          await loadUp(true);
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          cfgDlg.busy = false;
        }
      }
      function quickEnable(p) {
        if (p.missing && p.missing.length) {
          ElMessage.warning('先把必填项填完：' + p.missing.join('、') + '。');
          openCfg(p);
          return;
        }
        api('plugin_on', {
          method: 'POST',
          body: { type: up.type, plugin: p.id, cfg: Object.assign({}, p.cfg) }
        }).then(() => {
          ElMessage.success('已启用「' + p.meta.name + '」。');
          loadUp(true);
        }).catch((e) => {
          ElMessage.error(e.message);
        });
      }
      async function uninstallPlugin(p) {
        try {
          await ElMessageBox.confirm(
            '卸载「' + p.meta.name + '」？插件文件和它的配置都会删掉。',
            '卸载插件',
            { confirmButtonText: '卸载', cancelButtonText: '算了', type: 'warning' }
          );
        } catch (e) {
          return;
        }
        try {
          await api('plugin_uninstall', { method: 'POST', body: { plugin: p.id } });
          ElMessage.success('已卸载。');
          await loadUp(true);
        } catch (e) {
          ElMessage.error(e.message);
        }
      }
      async function uninstallById(id) {
        try {
          await ElMessageBox.confirm(
            '卸载「' + id + '」？插件文件和它的配置都会删掉。',
            '卸载插件',
            { confirmButtonText: '卸载', cancelButtonText: '算了', type: 'warning' }
          );
        } catch (e) {
          return;
        }
        try {
          await api('plugin_uninstall', { method: 'POST', body: { plugin: id } });
          ElMessage.success('已卸载。');
          await loadUp(true);
        } catch (e) {
          ElMessage.error(e.message);
        }
      }
      async function dropCfg(id) {
        try {
          await ElMessageBox.confirm('清掉「' + id + '」的残留配置？', '清理残留配置', {
            confirmButtonText: '清理',
            cancelButtonText: '算了',
            type: 'warning'
          });
        } catch (e) {
          return;
        }
        try {
          await api('cfg_drop', { method: 'POST', body: { plugin: id } });
          ElMessage.success('残留配置清掉了。');
          await loadUp(true);
        } catch (e) {
          ElMessage.error(e.message);
        }
      }
      function openInfo(p) {
        infoDlg.plugin = p;
        infoDlg.open = true;
      }
      function pluginPath(p) {
        return p ? 'public/plugins/' + p.id + '.php' : '';
      }

      function resetUpDlg() {
        upDlg.open = false;
        upDlg.step = 1;
        upDlg.fileName = '';
        upDlg.busy = false;
        upDlg.preview = null;
        upDlg.error = '';
        upDlg.committed = false;
        upFile.value = null;
        if (fileInput.value) fileInput.value.value = '';
      }
      function openUpload() {
        resetUpDlg();
        upDlg.open = true;
      }
      function pickFile() {
        if (fileInput.value) fileInput.value.click();
      }
      function onFileChange(e) {
        const f = e.target.files && e.target.files[0] ? e.target.files[0] : null;
        upFile.value = f;
        upDlg.fileName = f ? f.name : '';
        upDlg.error = '';
        upDlg.preview = null;
        upDlg.step = 1;
      }
      async function inspectFile() {
        if (!upFile.value || upDlg.busy) {
          upDlg.error = '先选一个 .php 文件。';
          return;
        }
        upDlg.busy = true;
        upDlg.error = '';
        try {
          const fd = new FormData();
          fd.append('plugin_file', upFile.value, upDlg.fileName);
          const d = await api('upload_inspect', { method: 'POST', form: fd });
          upDlg.preview = d;
          upDlg.step = 2;
        } catch (e) {
          upDlg.error = e.message;
        } finally {
          upDlg.busy = false;
        }
      }
      async function commitUpload() {
        if (!upDlg.preview || upDlg.busy) return;
        upDlg.busy = true;
        upDlg.error = '';
        try {
          await api('upload_commit', { method: 'POST', body: { token: upDlg.preview.token } });
          upDlg.committed = true;
          const t = upDlg.preview.type;
          const name = upDlg.preview.name;
          resetUpDlg();
          ElMessage.success('「' + name + '」已经上传。');
          up.type = t;
          up.status = 'idle';
          up.data = null;
          await loadUp(true);
        } catch (e) {
          upDlg.error = e.message;
        } finally {
          upDlg.busy = false;
        }
      }
      function onUpClosed() {
        if (upDlg.preview && !upDlg.committed) {
          api('upload_cancel', { method: 'POST', body: {} }).catch(() => {});
        }
        resetUpDlg();
      }
      function backToPick() {
        upDlg.step = 1;
        upDlg.error = '';
        upDlg.preview = null;
        if (upFile.value) {
          api('upload_cancel', { method: 'POST', body: {} }).catch(() => {});
        }
        if (fileInput.value) fileInput.value.value = '';
        upFile.value = null;
        upDlg.fileName = '';
      }

      async function loadSite(force) {
        if (sitePage.status === 'loading') return;
        if (sitePage.status === 'ok' && !force) return;
        sitePage.status = 'loading';
        sitePage.error = '';
        try {
          const d = await api('site_get');
          sitePage.data = d;
          siteForm.site_name = d.site_name;
          siteForm.site_url = d.site_url;
          siteForm.live_ttl = Number(d.live_ttl) || 300;
          sitePage.status = 'ok';
        } catch (e) {
          sitePage.error = e.message;
          sitePage.status = 'error';
        }
      }
      async function saveSite() {
        if (sitePage.busy) return;
        sitePage.busy = true;
        try {
          const d = await api('site_save', {
            method: 'POST',
            body: {
              site_name: siteForm.site_name,
              site_url: siteForm.site_url,
              live_ttl: siteForm.live_ttl
            }
          });
          sitePage.data.default_callback = d.default_callback;
          site.name = siteForm.site_name;
          site.url = siteForm.site_url;
          ElMessage.success('站点信息保存好了。');
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          sitePage.busy = false;
        }
      }

      async function loadPlugin(force) {
        if (pluginPage.status === 'loading') return;
        if (pluginPage.status === 'ok' && !force) return;
        pluginPage.status = 'loading';
        pluginPage.error = '';
        try {
          pluginPage.data = await api('downloads');
          pluginPage.status = 'ok';
        } catch (e) {
          pluginPage.error = e.message;
          pluginPage.status = 'error';
        }
      }
      function downloadUrl(f) {
        const base = (pluginPage.data && pluginPage.data.base) || site.url || '';
        return base.replace(/\/$/, '') + '/download.php?f=' + encodeURIComponent(f.key);
      }
      async function downloadFile(f) {
        try {
          const r = await fetch(downloadUrl(f), { credentials: 'same-origin', cache: 'no-store' });
          if (!r.ok) {
            let msg = '下载失败，稍后再试。';
            try {
              const t = await r.text();
              try { const j = JSON.parse(t); if (j && j.msg) msg = j.msg; }
              catch (_) { if (t && t.length < 80) msg = t; }
            } catch (_) {}
            ElMessage.error(msg);
            return;
          }
          const blob = await r.blob();
          let name = f.name || f.key + '.zip';
          const cd = r.headers.get('Content-Disposition') || '';
          const m = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(cd);
          if (m && m[1]) {
            try { name = decodeURIComponent(m[1].trim()); } catch (_) { name = m[1].trim(); }
          }
          const href = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = href;
          a.download = name;
          a.style.display = 'none';
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(href), 8000);
        } catch (e) {
          ElMessage.error('下载失败，稍后再试。');
        }
      }

      async function loadSys(force) {
        if (sys.status === 'loading') return;
        if (sys.status === 'ok' && !force) return;
        sys.status = 'loading';
        sys.error = '';
        try {
          sys.data = await api('system_info');
          sys.status = 'ok';
          version.value = sys.data.version || version.value;
          dbVer.value = sys.data.db_ver || '';
        } catch (e) {
          sys.error = e.message;
          sys.status = 'error';
        }
      }
      async function doUpgrade() {
        if (upgrading.value || dbNewer.value) return;
        upgrading.value = true;
        try {
          await api('upgrade', { method: 'POST', body: {} });
          upgrade.value = false;
          if (sys.data) {
            sys.data.need_upgrade = false;
            sys.data.db_ver = version.value;
          }
          resetPages();
          ElMessage.success('升级完成。');
          view.value = 'overview';
          location.hash = '#/overview';
          await loadOv(true);
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          upgrading.value = false;
        }
      }
      function pickPkg() {
        if (pkgInput.value) pkgInput.value.click();
      }
      function onPkgChange(e) {
        const f = e.target.files && e.target.files[0] ? e.target.files[0] : null;
        pkgFile.value = f;
        pkgName.value = f ? f.name : '';
        pkgErr.value = '';
      }
      async function uploadPkg() {
        if (!pkgFile.value || pkgBusy.value) return;
        pkgBusy.value = true;
        pkgErr.value = '';
        try {
          const fd = new FormData();
          fd.append('pkg', pkgFile.value, pkgName.value);
          const d = await api('up_pkg', { method: 'POST', form: fd });
          ElMessage.success(d ? (d.msg || '升级完成。') : '升级完成。');
          pkgFile.value = null;
          pkgName.value = '';
          if (pkgInput.value) pkgInput.value.value = '';
          version.value = (d && d.version) || version.value;
          upgrade.value = false;
          if (sys.data) {
            sys.data.need_upgrade = false;
            sys.data.db_ver = (d && d.version) || version.value;
          }
          resetPages();
          view.value = 'overview';
          location.hash = '#/overview';
          await loadOv(true);
        } catch (e) {
          pkgErr.value = e.message;
        } finally {
          pkgBusy.value = false;
        }
      }
      async function savePwd() {
        if (pwdForm.busy) return;
        if (!pwdForm.old || !pwdForm.new) {
          ElMessage.error('三个密码框都要填。');
          return;
        }
        if (pwdForm.new.length < 8) {
          ElMessage.error('新密码至少 8 位。');
          return;
        }
        if (pwdForm.new !== pwdForm.new2) {
          ElMessage.error('两次输入的新密码不一样。');
          return;
        }
        pwdForm.busy = true;
        try {
          await api('pwd_save', {
            method: 'POST',
            body: { old: pwdForm.old, new: pwdForm.new, new2: pwdForm.new2 }
          });
          pwdForm.old = '';
          pwdForm.new = '';
          pwdForm.new2 = '';
          ElMessage.success('密码改好了。');
        } catch (e) {
          ElMessage.error(e.message);
        } finally {
          pwdForm.busy = false;
        }
      }

      let testTimer = null;
      function stopTestPoll() {
        if (testTimer) {
          clearTimeout(testTimer);
          testTimer = null;
        }
        tst.polling = false;
      }
      function resetTest() {
        stopTestPoll();
        tst.name = '';
        tst.idcard = '';
        tst.image = '';
        tst.fileName = '';
        tst.preview = '';
        tst.busy = false;
        tst.error = '';
        tst.result = null;
        tst.token = '';
        tst.verifyUrl = '';
        if (photoInput.value) photoInput.value.value = '';
        if (qrBox.value) qrBox.value.innerHTML = '';
      }
      function setTestMode(m) {
        if (tst.busy || tst.mode === m) return;
        tst.mode = m;
        stopTestPoll();
        tst.error = '';
        tst.result = null;
        tst.token = '';
        tst.verifyUrl = '';
        if (qrBox.value) qrBox.value.innerHTML = '';
      }
      function renderQr() {
        const el = qrBox.value;
        if (!el) return;
        el.innerHTML = '';
        if (!tst.verifyUrl || !window.QRCode) return;
        try {
          new window.QRCode(el, {
            text: tst.verifyUrl,
            width: 120,
            height: 120,
            colorDark: '#111827',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M
          });
        } catch (e) {
          el.innerHTML = '';
        }
      }
      function pickPhoto() {
        if (photoInput.value) photoInput.value.click();
      }
      function onPhotoChange(e) {
        const f = e.target.files && e.target.files[0] ? e.target.files[0] : null;
        tst.image = '';
        tst.preview = '';
        tst.fileName = '';
        if (!f) return;
        if (!/^image\/(jpeg|png)$/.test(f.type) || f.size > 5 * 1024 * 1024) {
          ElMessage.error('只认 5MB 以内的 JPG / PNG。');
          if (photoInput.value) photoInput.value.value = '';
          return;
        }
        tst.fileName = f.name;
        const rd = new FileReader();
        rd.onload = () => {
          tst.image = String(rd.result || '');
          tst.preview = tst.image;
        };
        rd.readAsDataURL(f);
      }
      async function startTest() {
        if (tst.busy) return;
        stopTestPoll();
        tst.error = '';
        tst.result = null;
        tst.token = '';
        tst.verifyUrl = '';
        if (!tst.name.trim()) {
          tst.error = '先填姓名。';
          return;
        }
        if (!/^\d{17}[\dXx]$/.test(tst.idcard.trim())) {
          tst.error = '身份证号要是 18 位，末位可以是 X。';
          return;
        }
        if (tst.mode === 'compare' && !tst.image) {
          tst.error = '先选一张人脸照片。';
          return;
        }
        tst.busy = true;
        try {
          const d = await api('probe_start', {
            method: 'POST',
            body: {
              mode: tst.mode,
              name: tst.name.trim(),
              idcard: tst.idcard.trim(),
              image: tst.mode === 'compare' ? tst.image : ''
            }
          });
          tst.result = d;
          if (d && d.mode === 'live' && d.state === 'wait' && d.verify_url) {
            tst.token = d.token || '';
            tst.verifyUrl = d.verify_url;
            tst.polling = true;
            nextTick(renderQr);
            testTimer = setTimeout(pollTest, 2500);
          }
        } catch (e) {
          tst.error = e.message;
        } finally {
          tst.busy = false;
        }
      }
      async function pollTest() {
        if (!tst.token) {
          stopTestPoll();
          return;
        }
        try {
          const d = await api('probe_query', { method: 'POST', body: { token: tst.token } });
          tst.result = d;
          if (d.state !== 'wait') {
            stopTestPoll();
            return;
          }
        } catch (e) {
          if (e.status === 401 || e.status === 419) {
            stopTestPoll();
            tst.error = e.message;
            return;
          }
        }
        testTimer = setTimeout(pollTest, 3000);
      }

      async function cp(text) {
        const t = String(text === undefined || text === null ? '' : text);
        if (!t) return;
        try {
          if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(t);
          } else {
            const ta = document.createElement('textarea');
            ta.value = t;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
          }
          ElMessage.success('已复制');
        } catch (e) {
          ElMessage.error('复制失败，手动选一下。');
        }
      }

      function onHash() {
        if (!auth.value || upgrade.value) return;
        const k = hashView();
        if (k === view.value) return;
        view.value = k;
        navOpen.value = false;
        load(k);
      }

      watch(navOpen, (v) => {
        document.body.style.overflow = v ? 'hidden' : '';
      });

      onMounted(() => {
        window.addEventListener('hashchange', onHash);
        bootApp();
      });
      onBeforeUnmount(() => {
        window.removeEventListener('hashchange', onHash);
        clearTimeout(kwTimer);
        stopTestPoll();
        document.body.style.overflow = '';
      });

      return {
        VIEWS, boot, auth, upgrade, version, dbVer, admin, site, view, navOpen, upgrading,
        login, ov, calls, apiPage, up, sitePage, pluginPage, sys,
        tst, photoInput, qrBox, testLabel, testScore, setTestMode, pickPhoto, onPhotoChange, startTest, resetTest,
        callDlg, cfgDlg, infoDlg, upDlg, upFile, fileInput, siteForm, pwdForm,
        pkgName, pkgBusy, pkgErr, pkgInput, pkgFile,
        viewName, initial, dbNewer, trendMax, setupRows, allSetup, upTitle, activeName,
        apiDocs, apiDoc, statusRows,
        bootApp, doLogin, doLogout, go, refresh,
        loadOv, loadCalls, onKw, searchNow, setCallApi, setCallDays, goCallsPage,
        openCall, retryCall, loadApi, resetKey,
        loadUp, switchUp, openCfg, saveCfg, testCfg, enableCfg, quickEnable,
        uninstallPlugin, uninstallById, dropCfg, openInfo, pluginPath,
        openUpload, pickFile, onFileChange, inspectFile, commitUpload, onUpClosed, backToPick,
        loadSite, saveSite, loadPlugin, downloadUrl, downloadFile,
        loadSys, doUpgrade, pickPkg, onPkgChange, uploadPkg, savePwd, cp,
        fmtTime, fmtDay, bytes, codeText, codeCls, maskKey, verCmp
      };
    },

    template: `
<div v-if="!boot.ready" class="boot-screen">
  <img class="boot-mark" src="/assets/brand/logo-mark.webp" alt="">
  <div class="dots boot-dots"><i></i><i></i><i></i></div>
</div>

<div v-else-if="boot.error" class="boot-screen">
  <div class="panel" style="width:min(420px,calc(100% - 32px))">
    <div class="state">
      <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
      <b>后台没加载出来</b>
      <p>{{ boot.error }}</p>
      <div class="acts"><el-button type="primary" @click="bootApp">重试</el-button></div>
    </div>
  </div>
</div>

<div v-else-if="!auth" class="login-page">
  <div class="login-card">
    <div class="lbrand-row">
      <img class="login-logo" src="/assets/brand/logo.webp" alt="LOGO">
    </div>
    <div class="login-sub">管理登录</div>
    <div class="login-form">
      <el-input v-model="login.user" placeholder="管理员账号" autocomplete="username" @keyup.enter="doLogin"></el-input>
      <el-input v-model="login.pwd" type="password" placeholder="密码" autocomplete="current-password" show-password @keyup.enter="doLogin"></el-input>
      <div v-if="login.err" class="login-err">{{ login.err }}</div>
      <el-button type="primary" :loading="login.busy" @click="doLogin">登录</el-button>
    </div>
    <div class="login-foot">{{ site.name || 'PHP人脸实名分发系统' }}</div>
  </div>
</div>

<div v-else-if="upgrade" class="upg-page">
  <div class="upg-card">
    <img src="/assets/brand/logo.webp" alt="玄梦网络">
    <h1>{{ dbNewer ? '程序要更新' : '需要升级' }}</h1>
    <p>{{ dbNewer ? '数据库比程序新，先上传新程序。' : '覆盖文件之后在这里点一次升级。' }}</p>
    <div class="upg-vers"><span>程序 <b>{{ version }}</b></span><span>数据库 <b>{{ dbVer || '—' }}</b></span></div>
    <div class="upg-acts">
      <el-button v-if="!dbNewer" type="primary" :loading="upgrading" @click="doUpgrade">开始升级</el-button>
      <el-button @click="doLogout">退出登录</el-button>
    </div>
  </div>
</div>

<div v-else class="shell">
  <div v-if="navOpen" class="navmask on" @click="navOpen = false"></div>

  <aside class="sidebar" :class="{ on: navOpen }">
    <a class="sb-logo" href="#/overview" @click="navOpen = false" aria-label="站点概览">
      <img src="/assets/brand/logo.webp" alt="玄梦网络">
    </a>
    <nav class="nav">
      <a v-for="v in VIEWS" :key="v.key" class="nav-item" :class="{ on: view === v.key }" :href="'#/' + v.key" @click="navOpen = false">
        <svg class="ico"><use :href="'#i-' + v.icon"></use></svg>
        <span>{{ v.name }}</span>
      </a>
      <div class="nav-sep"></div>
      <a class="nav-item" href="https://qm.qq.com/q/omfCmcscTu" target="_blank" rel="noopener">
        <svg class="ico"><use href="#i-group"></use></svg>
        <span>加入群聊</span>
      </a>
    </nav>
    <div class="sb-foot">
      <div class="sb-user">
        <div class="avatar">{{ initial }}</div>
        <div class="u"><b>{{ admin.username || '管理员' }}</b><span>站点所有者</span></div>
      </div>
      <div class="row" style="margin-top:10px">
        <button class="sb-logout" style="flex:1;width:auto;height:34px" @click="doLogout">
          <svg class="ico sm"><use href="#i-logout"></use></svg>
          <span style="margin-left:6px;font-size:12px">退出登录</span>
        </button>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn tb-menu" @click="navOpen = !navOpen" aria-label="菜单">
        <svg class="ico"><use :href="navOpen ? '#i-close' : '#i-menu'"></use></svg>
      </button>
      <div class="tb-crumb">控制台<svg class="ico sm arrow" style="width:13px;height:13px"><use href="#i-arrow"></use></svg><span class="tb-title" style="display:inline">{{ viewName }}</span></div>
      <div class="tb-actions">
        <a class="icon-btn" style="width:auto;padding:0 12px;font-size:12px;text-decoration:none" href="#/api">接口文档</a>
        <el-tooltip content="刷新" placement="bottom" :show-after="300">
          <button class="icon-btn" @click="refresh" aria-label="刷新">
            <svg class="ico"><use href="#i-refresh"></use></svg>
          </button>
        </el-tooltip>
        <div class="tb-user">
          <span>{{ admin.username }}</span>
          <div class="avatar">{{ initial }}</div>
        </div>
      </div>
    </header>

    <main class="content">

      <section v-if="view === 'overview'">
        <div v-if="ov.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="ov.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b>
            <p>{{ ov.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadOv(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="ov.data">
          <div class="stats">
            <div class="stat">
              <div class="stat-i"><svg class="ico sm"><use href="#i-calls"></use></svg></div>
              <div class="stat-k">总调用量</div>
              <div class="stat-v">{{ ov.data.total }}</div>
              <div class="stat-s">历史累计</div>
            </div>
            <div class="stat">
              <div class="stat-i green"><svg class="ico sm"><use href="#i-check"></use></svg></div>
              <div class="stat-k">近 24 小时</div>
              <div class="stat-v">{{ ov.data.cnt24 }}</div>
              <div class="stat-s">成功 {{ ov.data.ok24 }}</div>
            </div>
            <div class="stat">
              <div class="stat-i amber"><svg class="ico sm"><use href="#i-shield"></use></svg></div>
              <div class="stat-k">成功率</div>
              <div class="stat-v">{{ ov.data.rate }}<small>%</small></div>
              <div class="stat-s">近 24 小时</div>
            </div>
            <div class="stat">
              <div class="stat-i gray"><svg class="ico sm"><use href="#i-system"></use></svg></div>
              <div class="stat-k">当前版本</div>
              <div class="stat-v" style="font-size:20px">{{ ov.data.version }}</div>
              <div class="stat-s">{{ ov.data.upgrade ? '待升级' : '已是最新' }}</div>
            </div>
          </div>

          <div class="ov-grid">
            <div class="panel">
              <div class="panel-h">
                <h3>近 7 天调用</h3>
                <a href="#/calls" class="sub">查看全部</a>
              </div>
              <div class="panel-b">
                <div class="trend">
                  <div class="tcol" v-for="d in ov.data.trend" :key="d.date">
                    <span class="tval">{{ d.n }}</span>
                    <div class="tbar" :class="{ zero: !d.n }" :style="{ height: Math.max(4, Math.round(d.n / trendMax * 100)) + '%' }"></div>
                    <span class="tday">{{ fmtDay(d.date) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="panel">
              <div class="panel-h">
                <h3>配置状态</h3>
                <span class="sub">{{ allSetup ? '已完成' : '待完成' }}</span>
              </div>
              <div class="panel-b">
                <div v-if="allSetup" class="all-done">
                  <svg class="ico sm"><use href="#i-check"></use></svg>
                  <span>都配好了</span>
                </div>
                <div v-else class="todo-list">
                  <div class="todo" v-for="r in setupRows" :key="r.key" v-show="!r.ok">
                    <span class="dot"><svg class="ico sm"><use href="#i-alert"></use></svg></span>
                    <span class="t">{{ r.label }}</span>
                    <el-button size="small" @click="go(r.to)">去设置</el-button>
                  </div>
                </div>
                <div v-if="allSetup" style="margin-top:14px">
                  <div class="plug-row">
                    <div class="plug-ico"><svg class="ico"><use href="#i-live"></use></svg></div>
                    <div class="grow">
                      <div class="plug-t">活体上游</div>
                      <div class="plug-v">{{ ov.data.live.id ? ov.data.live.name : '未启用' }}</div>
                    </div>
                    <span class="st" :class="ov.data.live.ready ? 'ok' : 'plain'">{{ ov.data.live.ready ? '就绪' : '未就绪' }}</span>
                  </div>
                  <div class="plug-row">
                    <div class="plug-ico"><svg class="ico ico-verify"><use href="#i-verify"></use></svg></div>
                    <div class="grow">
                      <div class="plug-t">人脸上游</div>
                      <div class="plug-v">{{ ov.data.compare.id ? ov.data.compare.name : '未启用' }}</div>
                    </div>
                    <span class="st" :class="ov.data.compare.ready ? 'ok' : 'plain'">{{ ov.data.compare.ready ? '就绪' : '未就绪' }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h">
              <h3>最近调用</h3>
              <a href="#/calls" class="sub">查看全部</a>
            </div>
            <div v-if="!ov.data.recent.length" class="state">
              <div class="state-ico"><svg class="ico"><use href="#i-calls"></use></svg></div>
              <b>还没有调用</b>
            </div>
            <template v-else>
              <div class="tbwrap hide-m">
                <table class="tb">
                  <thead><tr><th>时间</th><th>类型</th><th>单号</th><th>结果</th><th>耗时</th></tr></thead>
                  <tbody>
                    <tr v-for="r in ov.data.recent" :key="r.id" @click="openCall(r.id)" style="cursor:pointer">
                      <td class="nowrap num">{{ fmtTime(r.created_at) }}</td>
                      <td>{{ r.label }}</td>
                      <td class="mono clip">{{ r.order_no || '—' }}</td>
                      <td><span class="st" :class="codeCls(r.code)">{{ codeText(r.code) }}</span></td>
                      <td class="num">{{ r.cost_ms }} ms</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="mlist" style="padding:12px">
                <div class="mrow" v-for="r in ov.data.recent" :key="r.id" @click="openCall(r.id)">
                  <div class="mrow-top">
                    <span class="mrow-t">{{ r.label }}</span>
                    <span class="st" :class="codeCls(r.code)">{{ codeText(r.code) }}</span>
                  </div>
                  <div class="mrow-kv">
                    <span>时间</span><b>{{ fmtTime(r.created_at) }}</b>
                    <span>单号</span><b>{{ r.order_no || '—' }}</b>
                    <span>耗时</span><b>{{ r.cost_ms }} ms</b>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </template>
      </section>

      <section v-if="view === 'test'">
        <div class="panel">
          <div class="panel-h">
            <h3>在线测试</h3>
            <div class="chips">
              <button class="chip" :class="{ on: tst.mode === 'compare' }" @click="setTestMode('compare')">人脸比对</button>
              <button class="chip" :class="{ on: tst.mode === 'live' }" @click="setTestMode('live')">活体认证</button>
            </div>
          </div>
          <div class="panel-b">
            <div class="frows">
              <div class="frow">
                <label>姓名</label>
                <div class="ctl"><el-input v-model="tst.name" maxlength="30" autocomplete="off"></el-input></div>
              </div>
              <div class="frow">
                <label>身份证号</label>
                <div class="ctl"><el-input v-model="tst.idcard" maxlength="18" autocomplete="off"></el-input></div>
              </div>
              <div class="frow" v-if="tst.mode === 'compare'">
                <label>人脸照片</label>
                <div class="ctl">
                  <div class="tst-pick">
                    <el-button @click="pickPhoto">选择文件</el-button>
                    <span v-if="tst.fileName" class="fn">{{ tst.fileName }}</span>
                    <img v-if="tst.preview" :src="tst.preview" class="tst-photo" alt="">
                    <input ref="photoInput" type="file" accept="image/jpeg,image/png" style="display:none" @change="onPhotoChange">
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="panel-ft">
            <el-button type="primary" :loading="tst.busy" @click="startTest">{{ tst.mode === 'live' ? '发起认证' : '开始比对' }}</el-button>
          </div>
        </div>

        <div class="panel" v-if="tst.busy || tst.error || tst.result">
          <div class="panel-h">
            <h3>结果</h3>
            <span class="sub" v-if="tst.result && tst.result.plugin_name">{{ tst.result.plugin_name }}</span>
          </div>
          <div v-if="tst.busy" class="dots"><i></i><i></i><i></i></div>
          <div v-else-if="tst.error" class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没测成</b>
            <p>{{ tst.error }}</p>
            <div class="acts"><el-button type="primary" @click="startTest">重试</el-button></div>
          </div>
          <template v-else-if="tst.result">
            <div class="tst-res">
              <div class="tst-verdict" :class="tst.result.state">
                <span class="tst-vico"><svg class="ico lg"><use :href="tst.result.state === 'pass' ? '#i-check' : (tst.result.state === 'wait' ? '#i-live' : '#i-alert')"></use></svg></span>
                <div class="grow">
                  <b>{{ testLabel }}</b>
                  <span v-if="tst.result.msg">{{ tst.result.msg }}</span>
                </div>
                <span v-if="tst.polling" class="dots tst-dots"><i></i><i></i><i></i></span>
              </div>

              <div class="tst-meta">
                <div class="tst-mk" v-if="tst.mode === 'compare'"><span>分值</span><b class="num">{{ testScore }}</b></div>
                <div class="tst-mk" v-if="tst.result.cost_ms !== undefined"><span>耗时</span><b class="num">{{ tst.result.cost_ms }} ms</b></div>
                <div class="tst-mk" v-if="tst.result.order_no"><span>单号</span><b class="mono">{{ tst.result.order_no }}</b></div>
                <div class="tst-mk" v-if="tst.result.plugin"><span>上游</span><b class="mono">{{ tst.result.plugin }}</b></div>
                <div class="tst-mk" v-if="tst.result.live_plugin"><span>活体</span><b>{{ tst.result.live_plugin }}</b></div>
                <div class="tst-mk" v-if="tst.result.cmp_plugin"><span>比对</span><b>{{ tst.result.cmp_plugin }}</b></div>
              </div>

              <div v-if="tst.verifyUrl" class="tst-live">
                <div class="tst-qr" ref="qrBox"></div>
                <div class="tst-live-main">
                  <div class="readbar">
                    <code>{{ tst.verifyUrl }}</code>
                    <button class="icon-btn" style="width:28px;height:28px" @click="cp(tst.verifyUrl)" aria-label="复制采集地址"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
                  </div>
                  <a class="el-button el-button--primary el-button--small" :href="tst.verifyUrl" target="_blank" rel="noopener noreferrer">打开采集页</a>
                </div>
              </div>

              <el-collapse class="tst-raw">
                <el-collapse-item title="上游报文" name="raw">
                  <div class="doc-sub">发给上游</div>
                  <pre class="code drawer-pre">{{ tst.result.up_req || '（没有请求上游）' }}</pre>
                  <div class="doc-sub">上游返回</div>
                  <pre class="code drawer-pre">{{ tst.result.up_resp || '（没有上游返回）' }}</pre>
                </el-collapse-item>
              </el-collapse>
            </div>
          </template>
        </div>
      </section>

      <section v-if="view === 'calls'">
        <div class="filters">
          <div class="chips">
            <button class="chip" :class="{ on: calls.api === '' }" @click="setCallApi('')">全部</button>
            <button class="chip" :class="{ on: calls.api === 'chain' }" @click="setCallApi('chain')">活体认证</button>
            <button class="chip" :class="{ on: calls.api === 'facehy' }" @click="setCallApi('facehy')">人脸比对</button>
          </div>
          <el-input class="search-box" v-model="calls.kw" placeholder="单号 / Trace / IP" clearable @input="onKw" @keyup.enter="searchNow">
            <template #prefix><svg class="ico sm" style="color:#8b95aa"><use href="#i-search"></use></svg></template>
          </el-input>
          <el-select class="days-select" v-model="calls.days" @change="setCallDays">
            <el-option :value="0" label="全部时间"></el-option>
            <el-option :value="1" label="近 24 小时"></el-option>
            <el-option :value="7" label="近 7 天"></el-option>
            <el-option :value="30" label="近 30 天"></el-option>
          </el-select>
          <el-button :loading="calls.status === 'loading'" @click="searchNow">查询</el-button>
        </div>

        <div class="panel">
          <div v-if="calls.status === 'loading'" class="dots"><i></i><i></i><i></i></div>
          <div v-else-if="calls.status === 'error'" class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b>
            <p>{{ calls.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadCalls(true)">重试</el-button></div>
          </div>
          <template v-else>
            <template v-if="calls.rows.length">
              <div class="tbwrap hide-m">
                <table class="tb">
                  <thead>
                    <tr><th>时间</th><th>类型</th><th>单号</th><th>结果</th><th>耗时</th><th>IP</th><th class="r">操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in calls.rows" :key="r.id">
                      <td class="nowrap num">{{ fmtTime(r.created_at) }}</td>
                      <td class="nowrap">{{ r.label }}</td>
                      <td class="mono clip">{{ r.order_no || r.trace }}</td>
                      <td><span class="st" :class="codeCls(r.code)">{{ codeText(r.code) }}</span></td>
                      <td class="num nowrap">{{ r.cost_ms }} ms</td>
                      <td class="mono nowrap">{{ r.ip || '—' }}</td>
                      <td class="r"><el-button size="small" @click="openCall(r.id)">查看</el-button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="mlist" style="padding:12px">
                <div class="mrow" v-for="r in calls.rows" :key="r.id">
                  <div class="mrow-top">
                    <span class="mrow-t">{{ r.label }} · {{ fmtTime(r.created_at) }}</span>
                    <span class="st" :class="codeCls(r.code)">{{ codeText(r.code) }}</span>
                  </div>
                  <div class="mrow-kv">
                    <span>单号</span><b>{{ r.order_no || '—' }}</b>
                    <span>耗时</span><b>{{ r.cost_ms }} ms</b>
                    <span>IP</span><b>{{ r.ip || '—' }}</b>
                  </div>
                  <div class="mrow-acts"><el-button size="small" @click="openCall(r.id)">查看</el-button></div>
                </div>
              </div>
              <div class="pager">
                <span class="pager-total">共 {{ calls.total }} 条</span>
                <el-pagination background layout="prev, pager, next" :current-page="calls.page" :page-size="calls.ps" :total="calls.total" @current-change="goCallsPage"></el-pagination>
              </div>
            </template>
            <div v-else class="state">
              <div class="state-ico"><svg class="ico"><use href="#i-calls"></use></svg></div>
              <b>没有调用记录</b>
              <div class="acts"><el-button @click="searchNow">刷新</el-button></div>
            </div>
          </template>
        </div>
      </section>

      <section v-if="view === 'api'">
        <div v-if="apiPage.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="apiPage.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b><p>{{ apiPage.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadApi(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="apiPage.data">
          <div class="panel">
            <div class="panel-h">
              <h3>接口密钥</h3>
              <el-button size="small" @click="resetKey">换一把</el-button>
            </div>
            <div class="panel-b">
              <div class="frows">
                <div class="frow">
                  <label>AppID</label>
                  <div class="ctl">
                    <div class="readbar"><code>{{ apiPage.data.id }}</code>
                      <button class="icon-btn" style="width:28px;height:28px" @click="cp(apiPage.data.id)" aria-label="复制 AppID"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
                    </div>
                  </div>
                </div>
                <div class="frow">
                  <label>AppKey</label>
                  <div class="ctl">
                    <div class="readbar"><code>{{ apiPage.show ? apiPage.data.key : maskKey(apiPage.data.key) }}</code>
                      <button class="icon-btn" style="width:28px;height:28px" @click="apiPage.show = !apiPage.show" aria-label="显示密钥"><svg class="ico sm"><use :href="apiPage.show ? '#i-eyeoff' : '#i-eye'"></use></svg></button>
                      <button class="icon-btn" style="width:28px;height:28px" @click="cp(apiPage.data.key)" aria-label="复制 AppKey"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="docs">
              <div class="docs-side">
                <div class="docs-sec">接口</div>
                <a v-for="d in apiDocs" :key="d.key" class="docs-link" :class="{ on: apiDoc && apiDoc.key === d.key }" href="#/api" @click.prevent="apiPage.doc = d.key">{{ d.title }}</a>
              </div>
              <div class="docs-main">
                <el-select class="docs-select" v-model="apiPage.doc">
                  <el-option v-for="d in apiDocs" :key="d.key" :label="d.title" :value="d.key"></el-option>
                </el-select>
                <template v-if="apiDoc">
                  <h2 class="doc-h">{{ apiDoc.title }}</h2>
                  <p class="doc-d">{{ apiDoc.desc }}</p>
                  <div class="endpoint">
                    <span class="method" :class="{ get: apiDoc.method.indexOf('GET') === 0 }">{{ apiDoc.method }}</span>
                    <code>{{ apiPage.data.base + apiDoc.path }}</code>
                    <button class="icon-btn" style="width:28px;height:28px;margin-left:auto" @click="cp(apiPage.data.base + apiDoc.path)" aria-label="复制地址"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
                  </div>

                  <div class="doc-sub">请求参数</div>
                  <div class="params">
                    <div class="prow hd"><span>参数</span><span>类型</span><span>必填</span><span>说明</span></div>
                    <div class="prow" v-for="p in apiDoc.params" :key="p.n">
                      <code>{{ p.n }}</code>
                      <span class="pt">{{ p.t }}</span>
                      <span class="pr" :class="{ y: p.r }">{{ p.r ? '是' : '否' }}</span>
                      <span class="pd">{{ p.d }}</span>
                    </div>
                  </div>

                  <div class="doc-sub">请求示例</div>
                  <div class="code-head">
                    <span>curl</span>
                    <div class="code-actions"><el-button size="small" @click="cp(apiDoc.req)">复制</el-button></div>
                  </div>
                  <pre class="code">{{ apiDoc.req }}</pre>

                  <div class="doc-sub">返回示例</div>
                  <pre class="code">{{ apiDoc.resp }}</pre>

                  <div class="doc-sub">返回码</div>
                  <div class="status-list">
                    <div class="status-row" v-for="s in statusRows" :key="s.c"><b>{{ s.c }}</b><span>{{ s.d }}</span></div>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </template>
      </section>

      <section v-if="view === 'upstream'">
        <div class="tabbar">
          <div class="chips">
            <button class="chip" :class="{ on: up.type === 'liveness' }" @click="switchUp('liveness')">活体上游</button>
            <button class="chip" :class="{ on: up.type === 'compare' }" @click="switchUp('compare')">人脸上游</button>
          </div>
          <el-button type="primary" @click="openUpload">
            <svg class="ico sm"><use href="#i-upload"></use></svg>
            <span style="margin-left:5px">上传插件</span>
          </el-button>
        </div>

        <div v-if="up.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="up.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b><p>{{ up.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadUp(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="up.data">
          <div v-for="e in up.data.errors" :key="e.id" class="errbar">
            <svg class="ico sm"><use href="#i-alert"></use></svg>
            <div class="grow"><code>{{ e.id }}</code>：{{ e.error }}</div>
            <el-button size="small" type="danger" plain @click="uninstallById(e.id)">卸载</el-button>
          </div>
          <div v-for="o in up.data.orphans" :key="o" class="orphanbar">
            <div class="grow">残留配置 <code>{{ o }}</code></div>
            <el-button size="small" @click="dropCfg(o)">清理</el-button>
          </div>

          <div class="panel">
            <div class="panel-h">
              <h3>{{ upTitle }} · {{ up.data.count }}</h3>
              <span class="sub">当前启用：{{ activeName }}</span>
            </div>
            <div v-if="!up.data.plugins.length" class="state">
              <div class="state-ico"><svg class="ico" :class="{ 'ico-verify': up.type === 'compare' }"><use :href="up.type === 'compare' ? '#i-verify' : '#i-live'"></use></svg></div>
              <b>还没有插件</b>
              <div class="acts"><el-button type="primary" @click="openUpload">上传插件</el-button></div>
            </div>
            <template v-else>
              <div class="tbwrap hide-m">
                <table class="tb">
                  <thead><tr><th>插件</th><th>版本</th><th>配置</th><th>状态</th><th class="r">操作</th></tr></thead>
                  <tbody>
                    <tr v-for="p in up.data.plugins" :key="p.id">
                      <td>
                        <div class="plug-name">
                          <div class="plug-ico"><svg class="ico" :class="{ 'ico-verify': p.meta.type === 'compare' }"><use :href="p.meta.type === 'compare' ? '#i-verify' : '#i-live'"></use></svg></div>
                          <div class="t"><b>{{ p.meta.name }}</b><span>{{ p.id }}</span></div>
                        </div>
                      </td>
                      <td class="mono nowrap">{{ p.meta.version }}</td>
                      <td>
                        <span v-if="p.missing.length" class="st warn">缺 {{ p.missing.length }} 项</span>
                        <span v-else class="st ok">完整</span>
                      </td>
                      <td>
                        <span v-if="p.active" class="st ok">已启用</span>
                        <span v-else class="st plain">未启用</span>
                      </td>
                      <td>
                        <div class="row-actions">
                          <el-button size="small" @click="openCfg(p)">配置</el-button>
                          <el-button v-if="p.meta.intro || p.meta.usage" size="small" @click="openInfo(p)">说明</el-button>
                          <el-button v-if="!p.active" size="small" type="primary" plain @click="quickEnable(p)">启用</el-button>
                          <el-button size="small" type="danger" plain @click="uninstallPlugin(p)">卸载</el-button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="mlist" style="padding:12px">
                <div class="mrow" v-for="p in up.data.plugins" :key="p.id">
                  <div class="mrow-top">
                    <div class="plug-name">
                      <div class="plug-ico"><svg class="ico" :class="{ 'ico-verify': p.meta.type === 'compare' }"><use :href="p.meta.type === 'compare' ? '#i-verify' : '#i-live'"></use></svg></div>
                      <div class="t"><b>{{ p.meta.name }}</b><span>{{ p.id }}</span></div>
                    </div>
                    <span v-if="p.active" class="st ok">已启用</span>
                    <span v-else class="st plain">未启用</span>
                  </div>
                  <div class="mrow-kv">
                    <span>版本</span><b>{{ p.meta.version }}</b>
                    <span>配置</span><b>{{ p.missing.length ? '缺 ' + p.missing.length + ' 项' : '完整' }}</b>
                  </div>
                  <div class="mrow-acts">
                    <el-button size="small" @click="openCfg(p)">配置</el-button>
                    <el-button v-if="p.meta.intro || p.meta.usage" size="small" @click="openInfo(p)">说明</el-button>
                    <el-button v-if="!p.active" size="small" type="primary" plain @click="quickEnable(p)">启用</el-button>
                    <el-button size="small" type="danger" plain @click="uninstallPlugin(p)">卸载</el-button>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </template>
      </section>

      <section v-if="view === 'site'">
        <div v-if="sitePage.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="sitePage.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b><p>{{ sitePage.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadSite(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="sitePage.data">
          <div class="panel">
            <div class="panel-h"><h3>站点信息</h3></div>
            <div class="panel-b">
              <div class="frows">
                <div class="frow">
                  <label>站点名称</label>
                  <div class="ctl"><el-input v-model="siteForm.site_name" maxlength="40"></el-input></div>
                </div>
                <div class="frow">
                  <label>对外地址</label>
                  <div class="ctl"><el-input v-model="siteForm.site_url"></el-input></div>
                </div>
                <div class="frow">
                  <label>认证有效期</label>
                  <div class="ctl">
                    <el-input-number v-model="siteForm.live_ttl" :min="sitePage.data.ttl_min" :max="sitePage.data.ttl_max"></el-input-number>
                    <span class="sub">秒</span>
                  </div>
                </div>
                <div class="frow">
                  <label>默认回跳</label>
                  <div class="ctl">
                    <div class="readbar"><code>{{ sitePage.data.default_callback }}</code>
                      <button class="icon-btn" style="width:28px;height:28px" @click="cp(sitePage.data.default_callback)" aria-label="复制默认回跳"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="panel-ft">
              <el-button type="primary" :loading="sitePage.busy" @click="saveSite">保存</el-button>
            </div>
          </div>
        </template>
      </section>

      <section v-if="view === 'plugin'">
        <div v-if="pluginPage.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="pluginPage.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b><p>{{ pluginPage.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadPlugin(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="pluginPage.data">
          <div class="dlgrid">
            <div class="dlcard" v-for="f in pluginPage.data.files" :key="f.key">
              <div class="dl-top">
                <div class="dl-ico"><svg class="ico"><use href="#i-plugin"></use></svg></div>
                <div class="dl-t"><b>{{ f.title }}</b><span>{{ f.name }}</span></div>
              </div>
              <div class="dl-meta">
                <span class="st plain">v{{ f.version }}</span>
                <span>{{ f.exists ? bytes(f.size) : '文件不在' }}</span>
              </div>
              <div class="dl-foot">
                <a v-if="f.exists" class="el-button el-button--primary el-button--small" :href="downloadUrl(f)" @click.prevent="downloadFile(f)">
                  <svg class="ico sm"><use href="#i-download"></use></svg>
                  <span style="margin-left:4px">下载</span>
                </a>
                <span v-else class="sub">未上传到 storage/downloads</span>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h"><h3>接入地址</h3></div>
            <div class="panel-b">
              <div class="base-card">
                <code>{{ pluginPage.data.base }}</code>
                <button class="icon-btn" style="width:28px;height:28px" @click="cp(pluginPage.data.base)" aria-label="复制接入地址"><svg class="ico sm"><use href="#i-copy"></use></svg></button>
              </div>
            </div>
          </div>
        </template>
      </section>

      <section v-if="view === 'system'">
        <div v-if="sys.status === 'loading'" class="panel">
          <div class="dots"><i></i><i></i><i></i></div>
        </div>
        <div v-else-if="sys.status === 'error'" class="panel">
          <div class="state">
            <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
            <b>没加载出来</b><p>{{ sys.error }}</p>
            <div class="acts"><el-button type="primary" @click="loadSys(true)">重试</el-button></div>
          </div>
        </div>
        <template v-else-if="sys.data">
          <div class="panel">
            <div class="panel-h"><h3>系统信息</h3></div>
            <div class="panel-b">
              <div class="kv">
                <div class="kvr"><span>程序版本</span><b class="mono">{{ sys.data.version }}</b></div>
                <div class="kvr"><span>数据库版本</span><b class="mono">{{ sys.data.db_ver || '—' }}</b></div>
                <div class="kvr"><span>PHP</span><b class="mono">{{ sys.data.php }}</b></div>
                <div class="kvr"><span>管理员</span><b>{{ sys.data.admin }}</b></div>
                <div class="kvr"><span>调用记录</span><b class="num">{{ sys.data.calls }}</b></div>
                <div class="kvr"><span>认证单</span><b class="num">{{ sys.data.tasks }}</b></div>
                <div class="kvr"><span>认证有效期</span><b class="num">{{ sys.data.ttl }} 秒</b></div>
                <div class="kvr"><span>人脸目录</span><b class="mono">{{ sys.data.face_dir }}</b></div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h"><h3>版本</h3></div>
            <div class="panel-b">
              <div class="upgbox" :class="dbNewer ? 'newer' : (sys.data.need_upgrade ? '' : 'ok')">
                <div>
                  <b>{{ dbNewer ? '数据库比程序新' : (sys.data.need_upgrade ? '需要升级' : '已是最新') }}</b>
                  <span>程序 {{ sys.data.version }} · 数据库 {{ sys.data.db_ver || '—' }}</span>
                </div>
                <el-button v-if="sys.data.need_upgrade && !dbNewer" type="primary" :loading="upgrading" @click="doUpgrade">开始升级</el-button>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h"><h3>上传更新包</h3></div>
            <div class="panel-b">
              <div v-if="!sys.data.zip" class="orphanbar">
                <div class="grow">服务器没开 zip 扩展，更新包用不了。手动解压覆盖程序文件，再点上面的「开始升级」。</div>
              </div>
              <template v-else>
                <div class="filepick">
                  <el-button @click="pickPkg">选择文件</el-button>
                  <span class="fn" :class="{ has: pkgName }">{{ pkgName || '没有选择文件' }}</span>
                  <input ref="pkgInput" type="file" accept=".zip,application/zip" style="display:none" @change="onPkgChange">
                </div>
                <div v-if="pkgErr" class="login-err" style="margin-top:12px">{{ pkgErr }}</div>
                <div style="margin-top:14px;display:flex;justify-content:flex-end">
                  <el-button type="primary" :loading="pkgBusy" :disabled="!pkgName" @click="uploadPkg">上传并升级</el-button>
                </div>
              </template>
            </div>
          </div>

          <div class="panel">
            <div class="panel-h"><h3>管理员密码</h3></div>
            <div class="panel-b">
              <div class="frows" style="max-width:520px">
                <div class="frow">
                  <label>当前密码</label>
                  <div class="ctl"><el-input v-model="pwdForm.old" type="password" show-password autocomplete="current-password"></el-input></div>
                </div>
                <div class="frow">
                  <label>新密码</label>
                  <div class="ctl"><el-input v-model="pwdForm.new" type="password" show-password autocomplete="new-password"></el-input></div>
                </div>
                <div class="frow">
                  <label>再输一遍</label>
                  <div class="ctl"><el-input v-model="pwdForm.new2" type="password" show-password autocomplete="new-password" @keyup.enter="savePwd"></el-input></div>
                </div>
              </div>
            </div>
            <div class="panel-ft">
              <el-button type="primary" :loading="pwdForm.busy" @click="savePwd">保存密码</el-button>
            </div>
          </div>
        </template>
      </section>

    </main>
  </div>

  <el-drawer v-model="callDlg.open" title="调用详情" size="720px">
    <div v-if="callDlg.status === 'loading'" class="dots"><i></i><i></i><i></i></div>
    <div v-else-if="callDlg.status === 'error'" class="state">
      <div class="state-ico bad"><svg class="ico lg"><use href="#i-alert"></use></svg></div>
      <b>没加载出来</b><p>{{ callDlg.error }}</p>
      <div class="acts"><el-button type="primary" @click="retryCall">重试</el-button></div>
    </div>
    <template v-else-if="callDlg.data">
      <div class="meta-grid">
        <div class="k">时间</div><div class="v mono">{{ callDlg.data.created_at }}</div>
        <div class="k">类型</div><div class="v">{{ callDlg.data.label }}</div>
        <div class="k">Trace</div><div class="v mono">{{ callDlg.data.trace }}</div>
        <div class="k">单号</div><div class="v mono">{{ callDlg.data.order_no || '—' }}</div>
        <div class="k">结果</div><div class="v"><span class="st" :class="codeCls(callDlg.data.code)">{{ codeText(callDlg.data.code) }} · {{ callDlg.data.code }}</span></div>
        <div class="k">HTTP</div><div class="v mono">{{ callDlg.data.http }}</div>
        <div class="k">耗时</div><div class="v mono">{{ callDlg.data.cost_ms }} ms</div>
        <div class="k">IP</div><div class="v mono">{{ callDlg.data.ip || '—' }}</div>
        <div class="k">UA</div><div class="v mono">{{ callDlg.data.ua || '—' }}</div>
        <div class="k">返回摘要</div><div class="v">{{ callDlg.data.msg || '—' }}</div>
      </div>
      <div class="doc-sub">调用方请求</div>
      <pre class="code drawer-pre">{{ callDlg.data.req || '（没有记录）' }}</pre>
      <div class="doc-sub">发给上游</div>
      <pre class="code drawer-pre">{{ callDlg.data.up_req || '（没有请求上游）' }}</pre>
      <div class="doc-sub">上游返回</div>
      <pre class="code drawer-pre">{{ callDlg.data.up_resp || '（没有上游返回）' }}</pre>
      <div class="doc-sub">返回调用方</div>
      <pre class="code drawer-pre">{{ callDlg.data.resp || '（没有记录）' }}</pre>
    </template>
  </el-drawer>

  <el-drawer v-model="cfgDlg.open" :title="cfgDlg.plugin ? '配置 · ' + cfgDlg.plugin.meta.name : '配置'" size="480px" :close-on-click-modal="false">
    <template v-if="cfgDlg.plugin">
      <div class="meta-grid" style="margin-bottom:16px">
        <div class="k">插件</div><div class="v mono">{{ cfgDlg.plugin.id }}</div>
        <div class="k">版本</div><div class="v">{{ cfgDlg.plugin.meta.version }}</div>
        <div class="k">文件</div><div class="v mono">{{ pluginPath(cfgDlg.plugin) }}</div>
      </div>
      <div class="cfg-form">
        <div class="cfg-field" v-for="f in cfgDlg.plugin.meta.config" :key="f.key">
          <label>{{ f.label }}<i v-if="f.required">*</i></label>
          <el-switch v-if="f.type === 'switch'" v-model="cfgDlg.form[f.key]" active-value="1" inactive-value=""></el-switch>
          <el-select v-else-if="f.type === 'select'" v-model="cfgDlg.form[f.key]">
            <el-option v-for="(label, val) in f.options" :key="val" :label="label" :value="val"></el-option>
          </el-select>
          <el-input v-else-if="f.type === 'textarea'" v-model="cfgDlg.form[f.key]" type="textarea" :rows="3" :placeholder="f.placeholder || ''"></el-input>
          <el-input v-else v-model="cfgDlg.form[f.key]" :type="f.type === 'password' ? 'password' : 'text'" :show-password="f.type === 'password'" :placeholder="f.placeholder || ''"></el-input>
        </div>
      </div>
    </template>
    <template #footer>
      <div class="row" style="justify-content:space-between">
        <el-button :loading="cfgDlg.testing" @click="testCfg">测试连接</el-button>
        <div class="row">
          <el-button :loading="cfgDlg.busy" @click="saveCfg">保存</el-button>
          <el-button type="primary" :loading="cfgDlg.busy" @click="enableCfg">启用</el-button>
        </div>
      </div>
    </template>
  </el-drawer>

  <el-drawer v-model="infoDlg.open" :title="infoDlg.plugin ? infoDlg.plugin.meta.name : '插件说明'" size="460px">
    <template v-if="infoDlg.plugin">
      <div class="meta-grid" style="margin-bottom:16px">
        <div class="k">提供方</div><div class="v">{{ infoDlg.plugin.meta.vendor || '—' }}</div>
        <div class="k">版本</div><div class="v">{{ infoDlg.plugin.meta.version }}</div>
        <div class="k">文件</div><div class="v mono">{{ pluginPath(infoDlg.plugin) }}</div>
        <div class="k">类型</div><div class="v">{{ infoDlg.plugin.meta.type === 'liveness' ? '活体上游' : '人脸比对' }}</div>
      </div>
      <div v-if="infoDlg.plugin.meta.intro" class="plug-intro">{{ infoDlg.plugin.meta.intro }}</div>
      <div v-if="infoDlg.plugin.meta.usage" class="doc-sub">用法</div>
      <div v-if="infoDlg.plugin.meta.usage" class="plug-intro">{{ infoDlg.plugin.meta.usage }}</div>
      <div v-if="infoDlg.plugin.meta.buy_url" style="margin-top:18px">
        <a class="el-button el-button--primary" :href="infoDlg.plugin.meta.buy_url" target="_blank" rel="noopener noreferrer">{{ infoDlg.plugin.meta.buy_text || '打开插件主页' }}</a>
      </div>
    </template>
  </el-drawer>

  <el-dialog v-model="upDlg.open" title="上传插件" width="480px" :close-on-click-modal="false" @closed="onUpClosed">
    <template v-if="upDlg.step === 1">
      <div class="filepick">
        <el-button @click="pickFile">选择文件</el-button>
        <span class="fn" :class="{ has: upDlg.fileName }">{{ upDlg.fileName || '没有选择文件' }}</span>
        <input ref="fileInput" type="file" accept=".php" style="display:none" @change="onFileChange">
      </div>
      <div v-if="upDlg.error" class="login-err" style="margin-top:12px">{{ upDlg.error }}</div>
    </template>
    <template v-else-if="upDlg.preview">
      <div class="up-meta">
        <div class="mr"><span>插件</span><b>{{ upDlg.preview.name }}</b></div>
        <div class="mr"><span>提供方</span><b>{{ upDlg.preview.vendor || '—' }}</b></div>
        <div class="mr"><span>版本</span><b>{{ upDlg.preview.version }}</b></div>
        <div class="mr"><span>类型</span><b>{{ upDlg.preview.type === 'liveness' ? '活体上游' : '人脸比对' }}</b></div>
        <div class="mr"><span>文件</span><b class="mono">{{ upDlg.preview.short }}.php · {{ bytes(upDlg.preview.size) }}</b></div>
        <div class="mr"><span>配置项</span><b>{{ (upDlg.preview.config || []).length }} 项</b></div>
        <div class="mr" v-if="upDlg.preview.overwrite"><span>同名</span><b><span class="overwrite-tag">覆盖原插件</span></b></div>
      </div>
      <div v-if="upDlg.error" class="login-err" style="margin-top:12px">{{ upDlg.error }}</div>
    </template>
    <template #footer>
      <div v-if="upDlg.step === 1" class="row" style="justify-content:flex-end">
        <el-button @click="upDlg.open = false">取消</el-button>
        <el-button type="primary" :loading="upDlg.busy" :disabled="!upDlg.fileName" @click="inspectFile">检查文件</el-button>
      </div>
      <div v-else class="row" style="justify-content:flex-end">
        <el-button @click="backToPick">重新选择</el-button>
        <el-button type="primary" :loading="upDlg.busy" @click="commitUpload">确认上传</el-button>
      </div>
    </template>
  </el-dialog>

</div>
`
  };

  const app = createApp(App);
  app.use(ElementPlus, { locale: ElementPlusLocaleZhCn });
  app.config.errorHandler = function (err) {
    console.error(err);
    try {
      ElMessage.error('页面出了点问题，刷新一下。');
    } catch (e) {  }
  };
  app.mount('#app');
})();
