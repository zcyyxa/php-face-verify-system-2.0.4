<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

final class Calls
{
    private static ?string $rawBody = null;

    public static function ctx(string $api): array
    {
        return ['api' => $api, 'trace' => _f16(), 't0' => microtime(true), 'up_req' => '', 'up_resp' => '', 'order_no' => ''];
    }

    private static function rawBody(): string
    {
        if (self::$rawBody !== null) {
            return self::$rawBody;
        }
        $b = (string)file_get_contents('php://input');
        if ($b === '' && !empty($_POST)) {
            $b = http_build_query($_POST);
        }
        self::$rawBody = $b;
        return $b;
    }

    private static function reqText(): string
    {
        $m   = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri = (string)preg_replace('~([?&](?:appkey|app_key|api_key|key)=)[^&]*~i', '$1****', $uri);
        $lines = [$m . ' ' . $uri];

        $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if ($ct !== '') {
            $lines[] = 'Content-Type: ' . $ct;
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua !== '') {
            $lines[] = 'User-Agent: ' . mb_substr($ua, 0, 200, 'UTF-8');
        }
        $id = (string)($_SERVER['HTTP_X_APP_ID'] ?? '');
        if ($id !== '') {
            $lines[] = 'X-App-Id: ' . $id;
        }
        $key = (string)($_SERVER['HTTP_X_APP_KEY'] ?? '');
        if ($key !== '') {
            $lines[] = 'X-App-Key: ' . _f26($key);
        }
        if ($key === '') {
            foreach (['appkey', 'api_key', 'key'] as $pk) {
                if (isset($_POST[$pk])) {
                    $lines[] = $pk . '（参数）: ' . _f26((string)$_POST[$pk]);
                    break;
                }
            }
        }

        $body = self::rawBody();
        $body = (string)preg_replace('~((?:^|&)(?:appkey|app_key|api_key|key)=)[^&]*~i', '$1****', $body);
        $body = (string)preg_replace('~("(?:appkey|app_key|api_key|key)"\s*:\s*")[^"]*~i', '$1****', $body);

        return implode("\n", $lines) . "\n\n" . _f10($body, 65536);
    }

    public static function chainApi(string $api): bool
    {
        return in_array($api, ['face_start', 'face_query', 'chain'], true);
    }

    private static function stepLabel(string $api): string
    {
        return ['face_start' => '活体发起', 'face_query' => '活体结果'][$api] ?? $api;
    }

    private static function chainCode(string $api, int $code, string $resp): int
    {
        if ($api === 'face_start' && $code === 200) {
            return 1001;
        }
        $j = json_decode($resp, true);
        if (is_array($j)) {
            $d = is_array($j['data'] ?? null) ? $j['data'] : [];
            if (array_key_exists('passed', $d)) {
                return (int)$d['passed'] === 1 ? 2001 : 2002;
            }
        }
        return $code;
    }

    private static function chainDone(array $row): bool
    {
        return (int)$row['code'] >= 2000 || strpos((string)$row['resp'], '"passed"') !== false;
    }

    private static function storeChain(array $ctx, string $order, int $http, int $code, string $msg, string $resp): void
    {
        $api    = (string)$ctx['api'];
        $disp   = self::chainCode($api, $code, $resp);
        $msg250 = mb_substr($msg, 0, 250, 'UTF-8');
        $row    = Db::name('call_logs')->where('api', 'chain')->where('order_no', $order)->order('id', 'desc')->find();
        $row    = is_array($row) ? $row : null;
        $stepNo = $row === null ? 1 : (substr_count((string)$row['req'], '===== 第 ') + 1);

        if ($row !== null && (int)$row['code'] === $disp && (string)$row['msg'] === $msg250) {
            return;
        }
        if ($row !== null && self::chainDone($row) && $disp < 2000) {
            return;
        }

        $label = self::stepLabel($api);
        if ($api === 'face_query' && strpos((string)$ctx['up_req'], '===== 实名比对 =====') !== false) {
            $label .= '（含人脸核验）';
        }
        $head = '===== 第 ' . $stepNo . ' 步 · ' . $label . ' · ' . date('Y-m-d H:i:s')
            . ' · ' . (string)$ctx['trace'] . ' =====';

        $reqAdd    = "\n\n" . $head . "\n" . self::reqText();
        $upReqAdd  = "\n\n" . $head . "（发给上游）\n"
            . ((string)$ctx['up_req'] !== '' ? (string)$ctx['up_req'] : '（没有请求上游）');
        $upRespAdd = "\n\n" . $head . "（上游返回）\n"
            . ((string)$ctx['up_resp'] !== '' ? (string)$ctx['up_resp'] : '（没有上游返回）');
        $respAdd   = "\n\n" . $head . "（返回调用方）\n" . _f10($resp, 131072);
        $cost      = (int)round((microtime(true) - (float)$ctx['t0']) * 1000);

        if ($row !== null) {
            Db::name('call_logs')->where('id', (int)$row['id'])->update([
                'code'    => $disp,
                'http'    => $http,
                'msg'     => $msg250,
                'req'     => (string)$row['req'] . $reqAdd,
                'up_req'  => (string)$row['up_req'] . $upReqAdd,
                'up_resp' => (string)$row['up_resp'] . $upRespAdd,
                'resp'    => (string)$row['resp'] . $respAdd,
                'cost_ms' => (int)$row['cost_ms'] + $cost,
            ]);
            return;
        }

        Db::name('call_logs')->insert([
            'trace'      => (string)$ctx['trace'],
            'api'        => 'chain',
            'order_no'   => $order,
            'code'       => $disp,
            'http'       => $http,
            'msg'        => $msg250,
            'ip'         => _f9(),
            'ua'         => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250, 'UTF-8'),
            'req'        => $reqAdd,
            'up_req'     => $upReqAdd,
            'up_resp'    => $upRespAdd,
            'resp'       => $respAdd,
            'cost_ms'    => $cost,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function store(array $ctx, int $http, int $code, string $msg, string $resp): void
    {
        try {
            $order = trim((string)($ctx['order_no'] ?? ''));
            if ($order !== '' && self::chainApi((string)$ctx['api'])) {
                self::storeChain($ctx, $order, $http, $code, $msg, $resp);
                return;
            }
            Db::name('call_logs')->insert([
                'trace'      => (string)$ctx['trace'],
                'api'        => (string)$ctx['api'],
                'order_no'   => (string)$ctx['order_no'],
                'code'       => $code,
                'http'       => $http,
                'msg'        => mb_substr($msg, 0, 250, 'UTF-8'),
                'ip'         => _f9(),
                'ua'         => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250, 'UTF-8'),
                'req'        => self::reqText(),
                'up_req'     => (string)$ctx['up_req'],
                'up_resp'    => (string)$ctx['up_resp'],
                'resp'       => $resp,
                'cost_ms'    => (int)round((microtime(true) - (float)$ctx['t0']) * 1000),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $ex) {
            error_log('[call_log] ' . $ex->getMessage());
        }
    }

    public static function label(string $api): string
    {
        return [
            'chain'      => '活体认证',
            'facehy'     => '人脸比对',
            'face_start' => '活体发起',
            'face_query' => '活体结果',
        ][$api] ?? $api;
    }

    public static function codeClass(int $code): string
    {
        if ($code === 200 || $code >= 2000 || $code === 1001) {
            return 'ok';
        }
        if (in_array($code, [1002, 1008], true)) {
            return 'wait';
        }
        if ($code >= 400) {
            return 'err';
        }
        return 'warn';
    }

    public static function query(array $f, int $page, int $ps): array
    {
        $where = [];
        $par   = [];
        $api = (string)($f['api'] ?? '');
        if (in_array($api, ['chain', 'facehy', 'face_start', 'face_query'], true)) {
            $where[] = 'api = ?';
            $par[]   = $api;
        }
        $kw = trim((string)($f['kw'] ?? ''));
        if ($kw !== '') {
            $where[] = '(order_no LIKE ? OR trace LIKE ? OR ip LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($par, $like, $like, $like);
        }
        $days = (int)($f['days'] ?? 0);
        if ($days > 0 && $days <= 365) {
            $where[] = 'created_at >= ?';
            $par[]   = date('Y-m-d H:i:s', time() - $days * 86400);
        }
        $ws = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int)Db::query('SELECT COUNT(*) AS n FROM call_logs' . $ws, $par)[0]['n'];
        $pages = max(1, (int)ceil($total / $ps));
        $page  = min(max(1, $page), $pages);
        $off   = ($page - 1) * $ps;
        $rows  = Db::query(
            'SELECT id,trace,api,order_no,code,http,msg,ip,cost_ms,created_at FROM call_logs'
            . $ws . ' ORDER BY id DESC LIMIT ' . $ps . ' OFFSET ' . $off,
            $par
        );
        foreach ($rows as &$r) {
            $r['id']      = (int)$r['id'];
            $r['code']    = (int)$r['code'];
            $r['http']    = (int)$r['http'];
            $r['cost_ms'] = (int)$r['cost_ms'];
            $r['label']   = self::label((string)$r['api']);
        }
        unset($r);
        return ['total' => $total, 'page' => $page, 'pages' => $pages, 'rows' => $rows];
    }

    public static function detail(int $id): ?array
    {
        $r = $id > 0 ? Db::name('call_logs')->where('id', $id)->find() : null;
        if (!is_array($r)) {
            return null;
        }
        foreach (['id', 'code', 'http', 'cost_ms'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        $r['label'] = self::label((string)$r['api']);
        return $r;
    }
}
