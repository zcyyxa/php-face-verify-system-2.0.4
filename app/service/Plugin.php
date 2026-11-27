<?php
declare(strict_types=1);

namespace app\service;

use Throwable;

final class Plugin
{
    public const TYPES = ['liveness', 'compare'];

    private static ?array $scanned = null;
    private static array $loaded = [];

    public static function dir(): string
    {
        return _f28() . '/plugins';
    }

    public static function flush(): void
    {
        self::$scanned = null;
        self::$loaded  = [];
    }

    public static function all(): array
    {
        if (is_array(self::$scanned)) {
            return self::$scanned;
        }
        self::$scanned = [];
        if (!defined('UP_PLUGIN_LOAD')) {
            define('UP_PLUGIN_LOAD', 1);
        }
        foreach (self::TYPES as $type) {
            $files = glob(self::dir() . '/' . $type . '/*.php') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $file  = str_replace('\\', '/', $file);
                $short = basename($file, '.php');
                if (!preg_match('~^[a-z0-9_\-]{1,48}$~', $short)) {
                    continue;
                }
                if ($short === 'platform') {
                    @unlink($file);
                    continue;
                }
                self::$scanned[$type . '/' . $short] = self::loadFile($type, $short, $file);
            }
        }
        return self::$scanned;
    }

    private static function loadFile(string $type, string $short, string $file): array
    {
        $key = $type . '/' . $short;
        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }
        $base = ['id' => $key, 'short' => $short, 'type' => $type, 'error' => '', 'meta' => null, 'def' => null, 'file' => $file];
        if (!defined('UP_PLUGIN_LOAD')) {
            define('UP_PLUGIN_LOAD', 1);
        }
        try {
            $def = require $file;
        } catch (Throwable $ex) {
            error_log('[plugin] ' . $key . ': ' . $ex->getMessage());
            $base['error'] = '插件加载失败：' . $ex->getMessage();
            return self::$loaded[$key] = $base;
        }
        if (!is_array($def) || !isset($def['meta']) || !is_array($def['meta'])) {
            $base['error'] = '插件没有返回 meta。';
            return self::$loaded[$key] = $base;
        }
        $base['meta'] = self::metaNorm($type, $short, $def['meta']);
        $base['def']  = $def;
        return self::$loaded[$key] = $base;
    }

    public static function metaNorm(string $type, string $id, array $m): array
    {
        $buy = trim((string)($m['buy_url'] ?? ''));
        if ($buy !== '' && !_f35($buy)) {
            $buy = '';
        }
        $cfg = [];
        foreach ((array)($m['config'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $k = trim((string)($f['key'] ?? ''));
            if (!preg_match('~^[a-zA-Z0-9_\-]{1,32}$~', $k)) {
                continue;
            }
            $ft = (string)($f['type'] ?? 'text');
            if (!in_array($ft, ['text', 'url', 'password', 'number', 'select', 'switch', 'textarea'], true)) {
                $ft = 'text';
            }
            $opts = [];
            foreach ((array)($f['options'] ?? []) as $ov => $ol) {
                if (is_int($ov)) {
                    $opts[(string)$ol] = (string)$ol;
                } else {
                    $opts[(string)$ov] = (string)$ol;
                }
            }
            $cfg[] = [
                'key'         => $k,
                'label'       => trim((string)($f['label'] ?? $k)) ?: $k,
                'type'        => $ft,
                'required'    => !empty($f['required']),
                'default'     => (string)($f['default'] ?? ''),
                'hint'        => trim((string)($f['hint'] ?? '')),
                'placeholder' => trim((string)($f['placeholder'] ?? '')),
                'options'     => $opts,
            ];
        }
        return [
            'id'       => $type . '/' . $id,
            'short'    => $id,
            'type'     => $type,
            'name'     => trim((string)($m['name'] ?? $id)) ?: $id,
            'vendor'   => trim((string)($m['vendor'] ?? '')),
            'version'  => trim((string)($m['version'] ?? '')),
            'types'    => [$type],
            'intro'    => trim((string)($m['intro'] ?? '')),
            'usage'    => trim((string)($m['usage'] ?? '')),
            'buy_url'  => $buy,
            'buy_text' => trim((string)($m['buy_text'] ?? '')) ?: '去购买',
            'config'   => $cfg,
            'timeout'  => max(3, min(120, (int)($m['timeout'] ?? 20))),
        ];
    }

    public static function find(string $id): ?array
    {
        $id = trim(str_replace('\\', '/', $id), '/');
        if ($id === '') {
            return null;
        }
        if (strpos($id, '/') !== false) {
            [$type, $short] = explode('/', $id, 2);
            return in_array($type, self::TYPES, true) ? self::loadByShort($type, $short) : null;
        }
        $hits = [];
        foreach (self::TYPES as $type) {
            $p = self::loadByShort($type, $id);
            if ($p !== null && empty($p['error'])) {
                $hits[] = $p;
            }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    private static function loadByShort(string $type, string $short): ?array
    {
        if (!in_array($type, self::TYPES, true) || !preg_match('~^[a-z0-9_\-]{1,48}$~', $short)) {
            return null;
        }
        $key = $type . '/' . $short;
        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }
        $file = self::dir() . '/' . $type . '/' . $short . '.php';
        if (!is_file($file)) {
            return null;
        }
        return self::loadFile($type, $short, $file);
    }

    public static function meta(string $id): ?array
    {
        $p = self::find($id);
        return $p !== null && empty($p['error']) ? $p['meta'] : null;
    }

    public static function of(string $type): array
    {
        if (!in_array($type, self::TYPES, true)) {
            return [];
        }
        $out = [];
        foreach (self::all() as $key => $p) {
            if (empty($p['error']) && (string)$p['type'] === $type) {
                $out[$key] = $p;
            }
        }
        return $out;
    }

    public static function handler(string $id, string $type, string $action): ?callable
    {
        $p = self::find($id);
        if ($p === null || empty($p['def']) || (string)$p['type'] !== $type) {
            return null;
        }
        $h = $p['def'][$type][$action] ?? null;
        return is_callable($h) ? $h : null;
    }

    public static function hasTest(string $id): bool
    {
        $p = self::find($id);
        return $p !== null && is_callable($p['def']['test'] ?? null);
    }

    public static function cfg(string $id): array
    {
        $p = self::find($id);
        if ($p === null || empty($p['meta'])) {
            return [];
        }
        $cfg = [];
        foreach ($p['meta']['config'] as $f) {
            $cfg[$f['key']] = (string)$f['default'];
        }
        $saved = (string)Settings::get('up_cfg.' . $p['id'], '');
        if ($saved === '') {
            $saved = (string)Settings::get('up_cfg.' . $p['short'], '');
        }
        if ($saved !== '') {
            $j = json_decode($saved, true);
            if (is_array($j)) {
                foreach ($cfg as $k => $_) {
                    if (array_key_exists($k, $j) && is_scalar($j[$k])) {
                        $cfg[$k] = (string)$j[$k];
                    }
                }
            }
        }
        return $cfg;
    }

    public static function saveCfg(string $id, array $cfg): void
    {
        $p = self::find($id);
        if ($p === null || empty($p['meta'])) {
            return;
        }
        $out = [];
        foreach ($p['meta']['config'] as $f) {
            $k = $f['key'];
            if (array_key_exists($k, $cfg) && is_scalar($cfg[$k])) {
                $out[$k] = trim((string)$cfg[$k]);
            }
        }
        Settings::set('up_cfg.' . $p['id'], (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function cfgOrphans(): array
    {
        $out = [];
        foreach (Settings::all() as $k => $v) {
            if (strpos($k, 'up_cfg.') !== 0) {
                continue;
            }
            $id = substr($k, 7);
            if (strpos($id, '/') === false || self::find($id) !== null) {
                continue;
            }
            $out[$id] = (string)$v;
        }
        ksort($out);
        return $out;
    }

    public static function dropCfg(string $id): bool
    {
        $id  = trim(str_replace('\\', '/', $id), '/');
        $pos = strpos($id, '/');
        if ($pos === false) {
            return false;
        }
        $type = substr($id, 0, $pos);
        if (!in_array($type, self::TYPES, true) || self::find($id) !== null) {
            return false;
        }
        Settings::del('up_cfg.' . $id);
        $cur = trim((string)Settings::get('up_' . $type, ''));
        if ($cur === $id || ($cur !== '' && strpos($cur, '/') === false && $cur === substr($id, $pos + 1))) {
            Settings::set('up_' . $type, '');
        }
        return true;
    }

    public static function uninstall(string $id, string &$err): bool
    {
        $err = '';
        $id  = trim(str_replace('\\', '/', $id), '/');
        $all = self::all();
        if ($id === '' || !isset($all[$id])) {
            $err = '这个插件不存在。';
            return false;
        }
        $p     = $all[$id];
        $type  = (string)$p['type'];
        $short = (string)$p['short'];
        if (!in_array($type, self::TYPES, true) || !preg_match('~^[a-z0-9_\-]{1,48}$~', $short)) {
            $err = '插件 ID 不对，没有删。';
            return false;
        }
        $dir  = realpath(self::dir() . '/' . $type);
        $real = realpath((string)$p['file']);
        if ($dir === false || $real === false || dirname($real) !== $dir || basename($real) !== $short . '.php') {
            $err = '插件路径不对，没有删。';
            return false;
        }
        if (!@unlink($real)) {
            $err = '插件文件删不掉，检查 public/plugins/' . $type . ' 的权限。';
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($real, true);
        }
        Settings::del('up_cfg.' . $type . '/' . $short);
        $shared = false;
        foreach ($all as $k => $q) {
            if ($k !== $id && (string)($q['short'] ?? '') === $short) {
                $shared = true;
                break;
            }
        }
        if (!$shared) {
            Settings::del('up_cfg.' . $short);
        }
        $cur = trim((string)Settings::get('up_' . $type, ''));
        if ($cur === $type . '/' . $short || $cur === $short) {
            Settings::set('up_' . $type, '');
        }
        self::flush();
        return true;
    }

    public static function missing(string $id): array
    {
        $meta = self::meta($id);
        if ($meta === null) {
            return ['插件不存在'];
        }
        $cfg  = self::cfg($id);
        $miss = [];
        foreach ($meta['config'] as $f) {
            if (!empty($f['required']) && trim((string)($cfg[$f['key']] ?? '')) === '') {
                $miss[] = $f['label'];
            }
        }
        return $miss;
    }

    public static function active(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            return '';
        }
        $id = trim((string)Settings::get('up_' . $type, ''));
        if ($id === '') {
            return '';
        }
        $p = self::find($id);
        if (($p === null || (string)$p['type'] !== $type) && strpos($id, '/') === false) {
            $p = self::find($type . '/' . $id);
        }
        if ($p === null || (string)$p['type'] !== $type) {
            return '';
        }
        $key = (string)$p['id'];
        if ($key !== $id) {
            Settings::set('up_' . $type, $key);
        }
        return $key;
    }

    public static function setActive(string $type, string $id): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }
        $p = self::find($id);
        if ($p === null || (string)$p['type'] !== $type) {
            return false;
        }
        Settings::set('up_' . $type, (string)$p['id']);
        return true;
    }

    public static function livenessReady(?string $id = null): bool
    {
        $id = $id !== null && $id !== '' ? $id : self::active('liveness');
        if ($id === '' || self::handler($id, 'liveness', 'start') === null || self::handler($id, 'liveness', 'query') === null) {
            return false;
        }
        return self::missing($id) === [];
    }

    public static function compareReady(?string $id = null): bool
    {
        $id = $id !== null && $id !== '' ? $id : self::active('compare');
        if ($id === '' || self::handler($id, 'compare', 'compare') === null) {
            return false;
        }
        return self::missing($id) === [];
    }

    public static function ready(): bool
    {
        return self::livenessReady() && self::compareReady();
    }

    private static function raw(array $res): array
    {
        $raw = is_array($res['raw'] ?? null) ? $res['raw'] : [];
        return [
            'req'  => (string)($raw['req'] ?? ''),
            'resp' => (string)($raw['resp'] ?? ''),
            'http' => (int)($raw['http'] ?? 0),
            'json' => $raw['json'] ?? null,
        ];
    }

    public static function verdict($v): ?int
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_int($v) || is_float($v)) {
            return (int)$v === 1 ? 1 : ((int)$v === 0 ? 0 : null);
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['1', 'true', 'yes', 'y', 'pass', 'passed', 'ok', '一致', '通过', '是'], true)) {
                return 1;
            }
            if (in_array($s, ['0', 'false', 'no', 'n', 'fail', 'failed', '不一致', '不通过', '否'], true)) {
                return 0;
            }
            if (is_numeric($s)) {
                return (int)$s === 1 ? 1 : ((int)$s === 0 ? 0 : null);
            }
        }
        return null;
    }

    public static function imageNorm($img): ?array
    {
        if (is_string($img) && trim($img) !== '') {
            $img = stripos(trim($img), 'data:') === 0 ? ['base64' => $img] : ['url' => $img];
        }
        if (!is_array($img)) {
            return null;
        }
        $url  = trim((string)($img['url'] ?? ($img['image_url'] ?? '')));
        $b64  = trim((string)($img['base64'] ?? ($img['b64'] ?? '')));
        $mime = trim((string)($img['mime'] ?? ''));
        if ($url !== '' && (!_f35($url) || strlen($url) > 1024)) {
            $url = '';
        }
        if ($url === '' && $b64 === '') {
            return null;
        }
        return ['url' => $url, 'base64' => $b64, 'mime' => $mime];
    }

    public static function start(array $in): array
    {
        $out = [
            'ok' => false, 'order_no' => '', 'verify_url' => '', 'biz_id' => '', 'living_type' => '',
            'msg' => '', 'retry' => false, 'plugin' => '', 'raw' => self::raw([]),
        ];
        $id = self::active('liveness');
        $out['plugin'] = $id;
        if ($id === '') {
            $out['msg'] = '还没启用活体检测上游。';
            return $out;
        }
        $fn = self::handler($id, 'liveness', 'start');
        if ($fn === null) {
            $out['msg'] = '这个插件没有提供活体发起。';
            return $out;
        }
        $meta = self::meta($id);
        try {
            $res = $fn(self::cfg($id), $in + ['timeout' => (int)($meta['timeout'] ?? 20)]);
        } catch (Throwable $ex) {
            error_log('[plugin_start] ' . $id . ': ' . $ex->getMessage());
            $out['msg'] = '插件执行出错。';
            return $out;
        }
        if (!is_array($res)) {
            $out['msg'] = '插件没有返回结果。';
            return $out;
        }
        $out['ok']          = !empty($res['ok']);
        $out['order_no']    = trim((string)($res['order_no'] ?? ''));
        $out['verify_url']  = trim((string)($res['verify_url'] ?? ''));
        $out['biz_id']      = trim((string)($res['biz_id'] ?? ''));
        $out['living_type'] = trim((string)($res['living_type'] ?? ''));
        $out['msg']         = trim((string)($res['msg'] ?? ''));
        $out['retry']       = !empty($res['retry']);
        $out['raw']         = self::raw($res);

        if ($out['ok'] && (
            $out['order_no'] === '' || strlen($out['order_no']) > 64
            || $out['verify_url'] === '' || !_f35($out['verify_url']) || strlen($out['verify_url']) > 1024
        )) {
            $out['ok']    = false;
            $out['retry'] = false;
            $out['msg']   = '上游没给出单号或采集页地址。';
        }
        return $out;
    }

    public static function query(string $orderNo, ?string $plugin = null, array $ctx = []): array
    {
        $out = [
            'state' => 'error', 'passed' => null, 'score' => null, 'image' => null,
            'msg' => '', 'retry' => false, 'plugin' => '', 'raw' => self::raw([]),
        ];
        $id = $plugin !== null && $plugin !== '' ? $plugin : self::active('liveness');
        $p  = $id !== '' ? self::find($id) : null;
        if ($p === null) {
            $id = self::active('liveness');
            $p  = $id !== '' ? self::find($id) : null;
        }
        if ($p !== null) {
            $id = (string)$p['id'];
        }
        $out['plugin'] = $id;
        if ($id === '') {
            $out['msg'] = '还没启用活体检测上游。';
            return $out;
        }
        $fn = self::handler($id, 'liveness', 'query');
        if ($fn === null) {
            $out['msg'] = '这个插件没有提供活体结果查询。';
            return $out;
        }
        $meta = self::meta($id);
        try {
            $res = $fn(self::cfg($id), $orderNo, $ctx + ['timeout' => (int)($meta['timeout'] ?? 20)]);
        } catch (Throwable $ex) {
            error_log('[plugin_query] ' . $id . ': ' . $ex->getMessage());
            $out['retry'] = true;
            return $out;
        }
        if (!is_array($res)) {
            $out['retry'] = true;
            return $out;
        }
        $state = (string)($res['state'] ?? 'error');
        if ($state === 'live_pass') {
            $state = 'pass';
        }
        if (!in_array($state, ['wait', 'pass', 'fail', 'error'], true)) {
            $state = 'error';
        }
        $out['state'] = $state;
        $out['msg']   = trim((string)($res['msg'] ?? ''));
        $out['retry'] = !empty($res['retry']);
        $out['raw']   = self::raw($res);
        if (isset($res['score']) && is_numeric($res['score'])) {
            $out['score'] = (float)$res['score'];
        }
        if ($state === 'pass') {
            $out['passed'] = 1;
            $out['image']  = self::imageNorm($res['image'] ?? null);
        } elseif ($state === 'fail') {
            $out['passed'] = 0;
        }
        return $out;
    }

    public static function compare(array $in, ?string $plugin = null): array
    {
        $out = ['ok' => false, 'passed' => null, 'score' => null, 'msg' => '', 'plugin' => '', 'raw' => self::raw([]), 'error' => ''];
        $id = $plugin !== null && $plugin !== '' ? $plugin : self::active('compare');
        $p  = $id !== '' ? self::find($id) : null;
        if ($p === null) {
            $id = self::active('compare');
            $p  = $id !== '' ? self::find($id) : null;
        }
        if ($p !== null) {
            $id = (string)$p['id'];
        }
        $out['plugin'] = $id;
        if ($id === '') {
            $out['error'] = 'no_plugin';
            $out['msg']   = '还没启用实名核验上游。';
            return $out;
        }
        $fn = self::handler($id, 'compare', 'compare');
        if ($fn === null) {
            $out['error'] = 'no_handler';
            $out['msg']   = '这个插件没有提供实名比对。';
            return $out;
        }
        $meta = self::meta($id);
        try {
            $res = $fn(self::cfg($id), $in + ['timeout' => (int)($meta['timeout'] ?? 20)]);
        } catch (Throwable $ex) {
            error_log('[plugin_compare] ' . $id . ': ' . $ex->getMessage());
            $out['error'] = 'exception';
            $out['msg']   = '插件执行出错。';
            return $out;
        }
        if (!is_array($res)) {
            $out['error'] = 'bad_result';
            return $out;
        }
        $out['ok']     = array_key_exists('ok', $res) ? !empty($res['ok']) : (isset($res['passed']) || isset($res['verify']));
        $out['passed'] = self::verdict($res['passed'] ?? ($res['verify'] ?? null));
        $out['msg']    = trim((string)($res['msg'] ?? ''));
        $out['raw']    = self::raw($res);
        if (isset($res['score']) && is_numeric($res['score'])) {
            $out['score'] = (float)$res['score'];
        }
        return $out;
    }

    public static function test(string $id, ?array $cfgOverride = null): array
    {
        $p = self::find($id);
        if ($p === null) {
            return ['ok' => false, 'level' => 'err', 'msg' => '插件不存在。'];
        }
        $fn = $p['def']['test'] ?? null;
        if (!is_callable($fn)) {
            return ['ok' => false, 'level' => 'err', 'msg' => '这个插件没有提供测试。'];
        }
        try {
            $res = $fn($cfgOverride ?? self::cfg($id));
        } catch (Throwable $ex) {
            error_log('[plugin_test] ' . $id . ': ' . $ex->getMessage());
            return ['ok' => false, 'level' => 'err', 'msg' => '测试时插件出错：' . $ex->getMessage()];
        }
        if (!is_array($res)) {
            return ['ok' => false, 'level' => 'err', 'msg' => '插件没有返回测试结果。'];
        }
        return [
            'ok'    => !empty($res['ok']),
            'level' => (string)($res['level'] ?? (!empty($res['ok']) ? 'ok' : 'err')),
            'msg'   => trim((string)($res['msg'] ?? '')),
        ];
    }

    public static function cleanSay(string $s, string $def): string
    {
        $s = trim((string)preg_replace('~\s+~u', ' ', $s));
        if ($s === '' || mb_strlen($s, 'UTF-8') > 120) {
            return $def;
        }
        if (preg_match('~https?://|api/v4|facehy|face_start|face_query|余额|AppKey|AppID|阿里云|支付宝~i', $s)) {
            return $def;
        }
        return $s;
    }
}
