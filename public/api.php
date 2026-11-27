<?php

$root = dirname(__DIR__);

if (!is_file(__DIR__ . '/storage/install.lock')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => '还没安装。', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_file($root . '/.env')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => '缺少数据库配置。', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

require $root . '/vendor/autoload.php';
(new \think\App())->initialize();
try {
    \app\service\Upgrade::settle();
} catch (\Throwable $ex) {
}

_f5();

function _f18(): array
{
    static $data = null;
    if (is_array($data)) {
        return $data;
    }
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $data = [];
    if (strpos($ct, 'application/json') !== false) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($j)) {
            $data = $j;
        }
    }
    if ($data === []) {
        $data = array_merge($_GET, $_POST);
    }
    return $data;
}

function fail(string $msg, int $http = 400, array $extra = []): void
{
    _f21($http, array_merge(['ok' => false, 'msg' => $msg, 'data' => null], $extra));
}

function done(array $data = [], string $msg = ''): void
{
    _f21(200, ['ok' => true, 'msg' => $msg, 'data' => $data]);
}

function _f8(array $meta, array $cfg): string
{
    foreach ($meta['config'] as $f) {
        $v = (string)($cfg[$f['key']] ?? '');
        if (!empty($f['required']) && $v === '') {
            return $f['label'] . '不能为空。';
        }
        if ($v === '') {
            continue;
        }
        if ($f['type'] === 'url' && !_f35($v)) {
            return $f['label'] . '要以 http:// 或 https:// 开头。';
        }
        if ($f['type'] === 'number' && !preg_match('~^-?\d+(?:\.\d+)?$~', $v)) {
            return $f['label'] . '要是数字。';
        }
        if ($f['type'] === 'select' && !isset($f['options'][$v])) {
            return $f['label'] . '的取值不对。';
        }
    }
    return '';
}

function _f27(string $type): array
{
    $id = \app\service\Plugin::active($type);
    if ($id === '') {
        return ['id' => '', 'name' => '', 'version' => '', 'ready' => false, 'missing' => []];
    }
    $meta = \app\service\Plugin::meta($id);
    return [
        'id'      => $id,
        'name'    => $meta !== null ? (string)$meta['name'] : '',
        'version' => $meta !== null ? (string)$meta['version'] : '',
        'ready'   => $type === 'liveness' ? \app\service\Plugin::livenessReady($id) : \app\service\Plugin::compareReady($id),
        'missing' => \app\service\Plugin::missing($id),
    ];
}

$act = trim((string)($_GET['act'] ?? (_f18()['act'] ?? '')));

try {
    if ($act === 'boot') {
        $me = _f4();
        $out = [
            'auth'    => $me !== null,
            'csrf'    => _f12(),
            'version' => APP_VERSION,
            'upgrade' => \app\service\Settings::needUpgrade(),
        ];
        if ($me !== null) {
            $out += [
                'admin'   => ['username' => (string)$me['username']],
                'site'    => [
                    'name' => \app\service\Settings::siteName(),
                    'url'  => _f32(),
                ],
                'db_ver'  => (string)\app\service\Settings::get('db_ver', ''),
                'base'    => _f2(),
            ];
        }
        done($out);
    }

    if ($act === 'login') {
        if (!_f20()) {
            fail('只收 POST。', 405);
        }
        if (!_f11()) {
            fail('页面放久了，刷新一下再登录。', 419);
        }
        $now = time();
        $fails = is_array($_SESSION['login_fails'] ?? null) ? $_SESSION['login_fails'] : [];
        $fails = array_values(array_filter($fails, static fn($t) => (int)$t > $now - 600));
        if (count($fails) >= 8) {
            fail('试太多次了，过十分钟再试。', 429);
        }
        $user = trim((string)_f18()['username'] ?? '');
        $pwd  = (string)(_f18()['password'] ?? '');
        $row  = $user !== '' ? \think\facade\Db::name('admins')->where('username', $user)->find() : null;
        if (!is_array($row) || !_f30($pwd, (string)$row['pwd'])) {
            $fails[] = $now;
            $_SESSION['login_fails'] = $fails;
            usleep(250000);
            fail('账号或密码不对。', 401);
        }
        unset($_SESSION['login_fails']);
        _f24($row);
        done([
            'auth'    => true,
            'csrf'    => _f12(),
            'upgrade' => \app\service\Settings::needUpgrade(),
            'admin'   => ['username' => (string)$row['username']],
            'site'    => [
                'name' => \app\service\Settings::siteName(),
                'url'  => _f32(),
            ],
            'base'    => _f2(),
            'version' => APP_VERSION,
            'db_ver'  => (string)\app\service\Settings::get('db_ver', ''),
        ]);
    }

    if ($act === 'logout') {
        if (!_f20()) {
            fail('只收 POST。', 405);
        }
        if (!_f11()) {
            fail('页面放久了，刷新一下。', 419);
        }
        _f25();
        done(['auth' => false]);
    }

    $me = _f4();
    if ($me === null) {
        fail('登录过期了，重新登录。', 401, ['auth' => false]);
    }
    if (_f20() && !_f11()) {
        fail('页面放久了，刷新一下再试。', 419);
    }

    $upgrade = \app\service\Settings::needUpgrade();
    if ($upgrade && !in_array($act, ['upgrade', 'up_pkg', 'boot', 'logout'], true)) {
        fail('先完成系统升级。', 409, ['upgrade' => true]);
    }

    switch ($act) {
        case 'overview':
            $since = date('Y-m-d H:i:s', time() - 86400);
            $total = (int)\think\facade\Db::name('call_logs')->count();
            $cnt24 = (int)\think\facade\Db::name('call_logs')->where('created_at', '>=', $since)->count();
            $ok24  = (int)\think\facade\Db::name('call_logs')->where('created_at', '>=', $since)
                ->whereRaw('(code = 200 OR code >= 2000 OR code = 1001)')->count();
            $rate  = $cnt24 > 0 ? (int)round($ok24 * 100 / $cnt24) : 0;

            $start = date('Y-m-d 00:00:00', strtotime('-6 days'));
            $raw = \think\facade\Db::query(
                'SELECT DATE(created_at) AS d, COUNT(*) AS n,'
                . ' SUM(CASE WHEN code = 200 OR code >= 2000 OR code = 1001 THEN 1 ELSE 0 END) AS ok'
                . ' FROM call_logs WHERE created_at >= ? GROUP BY DATE(created_at)',
                [$start]
            );
            $by = [];
            foreach ($raw as $r) {
                $by[(string)$r['d']] = [(int)$r['n'], (int)$r['ok']];
            }
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime('-' . $i . ' days'));
                $trend[] = ['date' => $d, 'n' => $by[$d][0] ?? 0, 'ok' => $by[$d][1] ?? 0];
            }

            $recent = \think\facade\Db::name('call_logs')
                ->field('id,api,order_no,code,msg,cost_ms,created_at')
                ->order('id', 'desc')->limit(6)->select()->toArray();
            foreach ($recent as &$r) {
                $r['id']    = (int)$r['id'];
                $r['code']  = (int)$r['code'];
                $r['label'] = \app\service\Calls::label((string)$r['api']);
            }
            unset($r);

            $live = _f27('liveness');
            $cmp  = _f27('compare');
            $siteOk = trim((string)\app\service\Settings::get('site_name', '')) !== ''
                && trim((string)\app\service\Settings::get('site_url', '')) !== '';
            $keyOk = trim((string)\app\service\Settings::get('api_id', '')) !== ''
                && trim((string)\app\service\Settings::get('api_key', '')) !== '';
            done([
                'total'   => $total,
                'cnt24'   => $cnt24,
                'ok24'    => $ok24,
                'rate'    => $rate,
                'trend'   => $trend,
                'recent'  => $recent,
                'live'    => $live,
                'compare' => $cmp,
                'setup'   => [
                    'site'    => $siteOk,
                    'key'     => $keyOk,
                    'live'    => $live['ready'],
                    'compare' => $cmp['ready'],
                    'version' => !$upgrade,
                ],
                'version' => APP_VERSION,
                'db_ver'  => (string)\app\service\Settings::get('db_ver', ''),
                'upgrade' => $upgrade,
            ]);
            break;

        case 'calls':
            $p = _f18();
            $page = max(1, (int)($p['page'] ?? 1));
            $ps   = (int)($p['ps'] ?? 20);
            $ps   = $ps < 1 ? 20 : min(100, $ps);
            $data = \app\service\Calls::query($p, $page, $ps);
            done($data);
            break;

        case 'call_detail':
            $id = (int)(_f18()['id'] ?? 0);
            $row = \app\service\Calls::detail($id);
            if ($row === null) {
                fail('没有这一条记录，可能已经被清掉了。', 404);
            }
            done($row);
            break;

        case 'upstream':
            $type = (string)(_f18()['type'] ?? 'liveness');
            if (!in_array($type, \app\service\Plugin::TYPES, true)) {
                fail('上游类型不对。');
            }
            $active = \app\service\Plugin::active($type);
            $list   = [];
            foreach (\app\service\Plugin::of($type) as $id => $p) {
                $list[] = [
                    'id'      => $id,
                    'meta'    => $p['meta'],
                    'cfg'     => \app\service\Plugin::cfg($id),
                    'missing' => \app\service\Plugin::missing($id),
                    'active'  => $id === $active,
                    'has_test'=> \app\service\Plugin::hasTest($id),
                ];
            }
            $errors = [];
            foreach (\app\service\Plugin::all() as $id => $p) {
                if (!empty($p['error']) && (string)$p['type'] === $type) {
                    $errors[] = ['id' => $id, 'error' => (string)$p['error']];
                }
            }
            $orphans = [];
            foreach (\app\service\Plugin::cfgOrphans() as $oid => $_) {
                if (strpos($oid, $type . '/') === 0) {
                    $orphans[] = $oid;
                }
            }
            done([
                'type'    => $type,
                'active'  => $active,
                'count'   => count($list),
                'plugins' => $list,
                'errors'  => $errors,
                'orphans' => $orphans,
            ]);
            break;

        case 'plugin_save':
        case 'plugin_test':
        case 'plugin_on':
            $p    = _f18();
            $id   = trim((string)($p['plugin'] ?? ''));
            $type = trim((string)($p['type'] ?? ''));
            $plug = \app\service\Plugin::find($id);
            if (!in_array($type, \app\service\Plugin::TYPES, true)) {
                fail('上游类型不对。');
            }
            if ($plug === null || empty($plug['meta'])) {
                fail('这个插件不存在，或者没有加载起来。');
            }
            if ((string)$plug['meta']['type'] !== $type) {
                fail('这个插件不支持这一种上游。');
            }
            $id   = (string)$plug['id'];
            $meta = $plug['meta'];
            $cfg  = [];
            foreach ($meta['config'] as $f) {
                $cfg[$f['key']] = trim((string)(($p['cfg'][$f['key']] ?? '')));
            }
            $err = _f8($meta, $cfg);
            if ($err !== '') {
                fail($err);
            }

            if ($act === 'plugin_test') {
                $t = \app\service\Plugin::test($id, $cfg);
                done(['ok' => !empty($t['ok']), 'level' => (string)$t['level'], 'msg' => (string)$t['msg']]);
            }
            \app\service\Plugin::saveCfg($id, $cfg);
            if ($act === 'plugin_save') {
                done(['cfg' => \app\service\Plugin::cfg($id), 'missing' => \app\service\Plugin::missing($id)], '「' . $meta['name'] . '」的配置保存好了。');
            }
            $miss = \app\service\Plugin::missing($id);
            if ($miss !== []) {
                fail('先把必填项填完：' . implode('、', $miss) . '。');
            }
            \app\service\Plugin::setActive($type, $id);
            done(['active' => $id], '已启用「' . $meta['name'] . '」。');
            break;

        case 'plugin_uninstall':
            $id = trim((string)(_f18()['plugin'] ?? ''));
            $err = '';
            if (!\app\service\Plugin::uninstall($id, $err)) {
                fail($err);
            }
            done([], '插件已经卸载。');
            break;

        case 'cfg_drop':
            $id = trim((string)(_f18()['plugin'] ?? ''));
            if (!\app\service\Plugin::dropCfg($id)) {
                fail(\app\service\Plugin::find($id) !== null ? '插件还在，不能清理它的配置。' : '这条配置已经不存在了。');
            }
            done([], '残留配置已经清掉了。');
            break;

        case 'upload_inspect':
            $err = '';
            if (!\app\service\PluginUpload::prepare($_FILES['plugin_file'] ?? [], $err)) {
                fail($err);
            }
            $v = \app\service\PluginUpload::preview();
            if ($v === null) {
                fail('没有拿到上传信息，重新选一次文件。');
            }
            done([
                'token'     => (string)$v['token'],
                'short'     => (string)$v['short'],
                'type'      => (string)$v['type'],
                'name'      => (string)$v['name'],
                'vendor'    => (string)$v['vendor'],
                'version'   => (string)$v['version'],
                'size'      => (int)$v['size'],
                'overwrite' => (bool)$v['overwrite'],
                'config'    => (array)$v['config'],
            ]);
            break;

        case 'upload_commit':
            $err = '';
            $msg = \app\service\PluginUpload::commit((string)(_f18()['token'] ?? ''), $err);
            if ($err !== '') {
                fail($err);
            }
            done([], $msg);
            break;

        case 'upload_cancel':
            $v = \app\service\PluginUpload::preview();
            \app\service\PluginUpload::cancel();
            done(['type' => (string)($v['type'] ?? '')]);
            break;

        case 'site_get':
            done([
                'site_name'        => (string)\app\service\Settings::get('site_name', ''),
                'site_url'         => (string)\app\service\Settings::get('site_url', ''),
                'live_ttl'         => \app\service\Settings::liveTtl(),
                'default_callback' => _f23(),
                'ttl_min'          => LIVE_TTL_MIN,
                'ttl_max'          => LIVE_TTL_MAX,
            ]);
            break;

        case 'site_save':
            $p = _f18();
            $name = trim((string)($p['site_name'] ?? ''));
            $url  = trim((string)($p['site_url'] ?? ''));
            $ttl  = (int)($p['live_ttl'] ?? 0);
            if ($name === '') {
                fail('站点名称不能为空。');
            }
            if (mb_strlen($name, 'UTF-8') > 40) {
                fail('站点名称最多 40 个字。');
            }
            if ($url === '' || !_f35($url)) {
                fail('站点对外地址要以 http:// 或 https:// 开头。');
            }
            if (strlen($url) > 255) {
                fail('站点对外地址太长了，最多 255 个字符。');
            }
            if ($ttl < LIVE_TTL_MIN || $ttl > LIVE_TTL_MAX) {
                fail('认证有效期要在 ' . LIVE_TTL_MIN . '–' . LIVE_TTL_MAX . ' 秒之间。');
            }
            \app\service\Settings::set('site_name', $name);
            \app\service\Settings::set('site_url', rtrim($url, '/'));
            \app\service\Settings::set('live_ttl', (string)$ttl);
            done([
                'default_callback' => _f23(),
            ], '站点信息保存好了。');
            break;

        case 'api_info':
            done([
                'id'   => (string)\app\service\Settings::get('api_id', ''),
                'key'  => (string)\app\service\Settings::get('api_key', ''),
                'base' => _f32(),
                'live' => _f23(),
                'endpoints' => [
                    ['name' => '活体发起', 'method' => 'POST', 'path' => '/api/v4/face_start'],
                    ['name' => '活体结果', 'method' => 'POST', 'path' => '/api/v4/face_query'],
                    ['name' => '人脸比对', 'method' => 'GET/POST', 'path' => '/api/v4/facehy'],
                ],
            ]);
            break;

        case 'api_reset':
            $nid  = 'AK' . strtoupper(bin2hex(random_bytes(8)));
            $nkey = bin2hex(random_bytes(24));
            \app\service\Settings::set('api_id', $nid);
            \app\service\Settings::set('api_key', $nkey);
            done(['id' => $nid, 'key' => $nkey], '新密钥生成好了，旧密钥已经失效。');
            break;

        case 'probe_start':
            $p    = _f18();
            $name = trim((string)($p['name'] ?? ''));
            $idc  = strtoupper(trim((string)($p['idcard'] ?? '')));
            $mode = (string)($p['mode'] ?? 'compare');
            if (!_f37($name)) {
                fail('姓名不能为空，最多 30 个字。');
            }
            if (!_f36($idc)) {
                fail('身份证号要是 18 位，末位可以是 X。');
            }
            if (!in_array($mode, ['compare', 'live'], true)) {
                fail('认证方式不对。');
            }

            if ($mode === 'compare') {
                if (!\app\service\Plugin::compareReady()) {
                    fail('还没启用可以用的比对插件，先去上游设置里配好。');
                }
                $b64 = trim((string)($p['image'] ?? ''));
                if ($b64 === '') {
                    fail('先选一张人脸照片。');
                }
                if (strlen($b64) > 7340032) {
                    fail('图片太大了，换一张 5MB 以内的。');
                }
                $st = \app\service\Image::store(['base64' => $b64], 'test:' . sha1($b64));
                if (empty($st['ok'])) {
                    fail((string)$st['err'] === 'save' ? '人脸图没存下来，检查 uploads 目录权限。' : '这张图不是有效的 JPG / PNG。');
                }
                $imgUrl = _f1((string)$st['rel']);
                $t0 = microtime(true);
                $r  = \app\service\Plugin::compare([
                    'name'             => $name,
                    'idcard'           => $idc,
                    'image_url'        => $imgUrl,
                    'image_base64'     => (string)$st['b64'],
                    'image_mime'       => (string)$st['mime'],
                    'image_source_url' => $imgUrl,
                ]);
                $desc  = \app\service\Plugin::meta((string)$r['plugin']);
                $state = 'error';
                if (empty($r['error'])) {
                    if ($r['passed'] === 1) {
                        $state = 'pass';
                    } elseif ($r['passed'] === 0) {
                        $state = 'fail';
                    }
                }
                done([
                    'mode'        => 'compare',
                    'state'       => $state,
                    'passed'      => $r['passed'],
                    'score'       => $r['score'],
                    'msg'         => (string)$r['msg'],
                    'plugin'      => (string)$r['plugin'],
                    'plugin_name' => $desc !== null ? (string)$desc['name'] : '',
                    'image'       => $imgUrl,
                    'cost_ms'     => (int)round((microtime(true) - $t0) * 1000),
                    'http'        => (int)$r['raw']['http'],
                    'up_req'      => _f10((string)$r['raw']['req']),
                    'up_resp'     => _f10((string)$r['raw']['resp']),
                ]);
            }

            if (!\app\service\Plugin::livenessReady()) {
                fail('还没启用可以用的活体插件，先去上游设置里配好。');
            }
            $task   = \app\service\Task::new('test', $name, $idc);
            $taskId = (int)$task['id'];
            $back   = _f23() . '?t=' . rawurlencode((string)$task['token']);
            $t0     = microtime(true);
            $cmd    = \app\service\Plugin::start([
                'name'        => $name,
                'idcard'      => $idc,
                'return_url'  => $back,
                'living_type' => '',
                'ip'          => _f9(),
            ]);
            $desc = \app\service\Plugin::meta((string)$cmd['plugin']);
            $cost = (int)round((microtime(true) - $t0) * 1000);
            if (!$cmd['ok']) {
                \app\service\Task::delete($taskId);
                done([
                    'mode'        => 'live',
                    'state'       => 'error',
                    'msg'         => (string)$cmd['msg'] !== '' ? (string)$cmd['msg'] : '上游没受理这次发起。',
                    'plugin'      => (string)$cmd['plugin'],
                    'plugin_name' => $desc !== null ? (string)$desc['name'] : '',
                    'cost_ms'     => $cost,
                    'http'        => (int)$cmd['raw']['http'],
                    'up_req'      => _f10((string)$cmd['raw']['req']),
                    'up_resp'     => _f10((string)$cmd['raw']['resp']),
                ]);
            }
            \app\service\Task::update($taskId, [
                'order_no' => (string)$cmd['order_no'],
                'state'    => 'wait',
                'code'     => 1001,
                'msg'      => '等待活体结果。',
            ]);
            done([
                'mode'        => 'live',
                'state'       => 'wait',
                'token'       => (string)$task['token'],
                'order_no'    => (string)$cmd['order_no'],
                'verify_url'  => (string)$cmd['verify_url'],
                'living_type' => (string)$cmd['living_type'],
                'plugin'      => (string)$cmd['plugin'],
                'plugin_name' => $desc !== null ? (string)$desc['name'] : '',
                'cost_ms'     => $cost,
                'http'        => (int)$cmd['raw']['http'],
                'up_req'      => _f10((string)$cmd['raw']['req']),
                'up_resp'     => _f10((string)$cmd['raw']['resp']),
            ]);
            break;

        case 'probe_query':
            $token = trim((string)(_f18()['token'] ?? ''));
            $row   = \app\service\Task::byToken($token);
            if ($row === null || (string)$row['kind'] !== 'test') {
                fail('这一单找不到了。', 404);
            }
            $res = \app\service\Task::advance($row, 12);
            $row = $res['row'];
            $lv  = \app\service\Plugin::meta((string)$row['live_plugin']);
            $cmp = \app\service\Plugin::meta((string)$row['cmp_plugin']);
            done([
                'mode'        => 'live',
                'state'       => (string)$row['state'],
                'order_no'    => (string)$row['order_no'],
                'passed'      => $row['passed'] === null ? null : (int)$row['passed'],
                'score'       => $row['score'] === null || $row['score'] === '' ? null : (float)$row['score'],
                'msg'         => (string)$row['msg'],
                'image'       => (string)$row['image_rel'] !== '' ? _f1((string)$row['image_rel']) : '',
                'live_plugin' => $lv !== null ? (string)$lv['name'] : (string)$row['live_plugin'],
                'cmp_plugin'  => $cmp !== null ? (string)$cmp['name'] : (string)$row['cmp_plugin'],
                'up_req'      => _f10((string)$res['up_req']),
                'up_resp'     => _f10((string)$res['up_resp']),
            ]);
            break;

        case 'download':
            \app\controller\Download::serve((string)(_f18()['f'] ?? ''));
            break;

        case 'downloads':
            $out = [];
            foreach ([
                'ysface'     => ['name' => 'ysface-plugin.zip', 'title' => '财务系统版', 'version' => '1.4'],
                'ysface_v10' => ['name' => 'ysface-v10-plugin.zip', 'title' => 'V10 业务系统版', 'version' => '1.1.0'],
            ] as $k => $m) {
                $f = _f34() . '/downloads/' . $m['name'];
                $out[] = [
                    'key'     => $k,
                    'title'   => $m['title'],
                    'version' => $m['version'],
                    'name'    => $m['name'],
                    'size'    => is_file($f) ? (int)filesize($f) : 0,
                    'exists'  => is_file($f),
                ];
            }
            done(['files' => $out, 'base' => _f32()]);
            break;

        case 'system_info':
            done([
                'version'      => APP_VERSION,
                'db_ver'       => (string)\app\service\Settings::get('db_ver', ''),
                'need_upgrade' => $upgrade,
                'php'          => PHP_VERSION,
                'admin'        => (string)$me['username'],
                'calls'        => (int)\think\facade\Db::name('call_logs')->count(),
                'tasks'        => (int)\think\facade\Db::name('live_tasks')->count(),
                'face_dir'     => 'public/uploads/face',
                'ttl'          => \app\service\Settings::liveTtl(),
                'zip'          => class_exists('ZipArchive'),
            ]);
            break;

        case 'upgrade':
            $curVer = (string)\app\service\Settings::get('db_ver', '');
            if ($curVer !== '' && version_compare($curVer, APP_VERSION, '>')) {
                fail('数据库比程序新，先上传新程序，别在这里降级。');
            }
            $notes = \app\service\Upgrade::finishUpgrade();
            done(['notes' => $notes, 'db_ver' => APP_VERSION], '升级完成。');
            break;

        case 'up_pkg':
            if (!class_exists('ZipArchive')) {
                fail('服务器没开 zip 扩展，更新包用不了。手动解压覆盖程序文件，再点「开始升级」。');
            }
            $f = $_FILES['pkg'] ?? [];
            $e = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($e !== UPLOAD_ERR_OK) {
                fail(\app\service\Upgrade::uploadError($e));
            }
            $tmp = (string)($f['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                fail('上传的临时文件不见了，重试一次。');
            }
            $r = \app\service\Upgrade::apply($tmp);
            if (!$r['ok']) {
                fail((string)$r['msg']);
            }
            $notes = \app\service\Upgrade::finishUpgrade((string)$r['version']);
            done([
                'version' => $r['version'],
                'from'    => $r['from'],
                'files'   => (int)$r['files'],
                'pending' => $r['pending'] ?? [],
                'notes'   => $notes,
            ], '升级到 ' . $r['version'] . ' 完成，覆盖 ' . $r['files'] . ' 个文件。');
            break;

        case 'pwd_save':
            $p    = _f18();
            $old  = (string)($p['old'] ?? '');
            $new  = (string)($p['new'] ?? '');
            $new2 = (string)($p['new2'] ?? '');
            if (!_f30($old, (string)$me['pwd'])) {
                fail('当前密码不对。');
            }
            if (strlen($new) < 8) {
                fail('新密码至少 8 位。');
            }
            if ($new !== $new2) {
                fail('两次输入的新密码不一样。');
            }
            \think\facade\Db::name('admins')->where('id', (int)$me['id'])->update(['pwd' => _f29($new)]);
            done([], '密码改好了。');
            break;

        default:
            fail('没有这个操作。', 404);
    }
} catch (\Throwable $ex) {
    error_log('[admin_api] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    fail('服务出了点问题，稍后再试。', 500);
}
