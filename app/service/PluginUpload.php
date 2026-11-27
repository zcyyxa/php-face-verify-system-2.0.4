<?php
declare(strict_types=1);

namespace app\service;

use Throwable;

final class PluginUpload
{
    public static function tempDir(): string
    {
        return _f34() . '/plugin_tmp';
    }

    public static function prepare(array $f, string &$err): bool
    {
        $err  = '';
        $tmp  = (string)($f['tmp_name'] ?? '');
        $name = basename(str_replace('\\', '/', (string)($f['name'] ?? '')));
        $size = (int)($f['size'] ?? 0);
        $ec   = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($ec === UPLOAD_ERR_NO_FILE) {
            $err = '先选一个插件文件。';
            return false;
        }
        if ($ec === UPLOAD_ERR_INI_SIZE || $ec === UPLOAD_ERR_FORM_SIZE) {
            $err = '插件文件不能超过 2 MB。';
            return false;
        }
        if ($ec !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            $err = '文件没传上来，再试一次。';
            return false;
        }
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            $err = '插件文件不能超过 2 MB。';
            return false;
        }
        if (!preg_match('~^[a-z0-9][a-z0-9_-]*\.php$~', $name)) {
            $err = '文件名用小写字母、数字、下划线或短横线，并以 .php 结尾。';
            return false;
        }
        $short = substr($name, 0, -4);
        if (strlen($short) > 48 || strpos($short, '_') === false) {
            $err = '文件名要带品牌和产品，用「品牌_产品.php」，例如 aliyun_faceid.php。';
            return false;
        }

        $src = (string)@file_get_contents($tmp);
        if (strncmp($src, '<?php', 5) !== 0) {
            $err = '这不是 PHP 插件文件。';
            return false;
        }
        if (!preg_match('~defined\s*\(\s*[\'"]UP_PLUGIN_LOAD[\'"]\s*\)~', $src)) {
            $err = '插件文件缺少直接访问保护，文件里要有 UP_PLUGIN_LOAD 判断。';
            return false;
        }

        $qdir = self::tempDir();
        if (!is_dir($qdir) && !@mkdir($qdir, 0775, true) && !is_dir($qdir)) {
            $err = '建不了临时目录：storage/plugin_tmp。';
            return false;
        }
        if (!is_writable($qdir)) {
            $err = '临时目录不可写：storage/plugin_tmp。';
            return false;
        }
        foreach (glob($qdir . '/*.phptmp') ?: [] as $old) {
            if ((int)@filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }

        $qfile = $qdir . '/' . bin2hex(random_bytes(12)) . '.phptmp';
        if (!move_uploaded_file($tmp, $qfile)) {
            $err = '文件没存下来，检查 storage/plugin_tmp 的权限。';
            return false;
        }

        if (!defined('UP_PLUGIN_LOAD')) {
            define('UP_PLUGIN_LOAD', 1);
        }
        ob_start();
        try {
            $def = require $qfile;
        } catch (Throwable $ex) {
            ob_end_clean();
            @unlink($qfile);
            $err = '插件加载失败：' . $ex->getMessage();
            return false;
        }
        $echo = (string)ob_get_clean();
        if (trim($echo) !== '') {
            @unlink($qfile);
            $err = '插件加载时输出了内容，插件文件只能返回配置数组。';
            return false;
        }
        if (!is_array($def) || !isset($def['meta']) || !is_array($def['meta'])) {
            @unlink($qfile);
            $err = '不是插件文件：没有返回 meta。';
            return false;
        }

        $liveOk = isset($def['liveness']['start'], $def['liveness']['query'])
            && is_callable($def['liveness']['start']) && is_callable($def['liveness']['query']);
        $cmpOk  = isset($def['compare']['compare']) && is_callable($def['compare']['compare']);
        if ($liveOk && $cmpOk) {
            @unlink($qfile);
            $err = '一个文件只能做活体或比对其中一种。';
            return false;
        }
        if (!$liveOk && !$cmpOk) {
            @unlink($qfile);
            $err = '不是插件文件：没有 liveness.start + liveness.query，也没有 compare.compare。';
            return false;
        }
        $type     = $liveOk ? 'liveness' : 'compare';
        $metaName = trim((string)($def['meta']['name'] ?? ''));
        if ($metaName === '') {
            @unlink($qfile);
            $err = '插件 meta 里要有名称（name）。';
            return false;
        }

        $meta = Plugin::metaNorm($type, $short, $def['meta']);
        $dest = Plugin::dir() . '/' . $type . '/' . $short . '.php';
        $_SESSION['up_upload'] = [
            'token'     => bin2hex(random_bytes(16)),
            'file'      => $qfile,
            'hash'      => (string)hash_file('sha256', $qfile),
            'short'     => $short,
            'type'      => $type,
            'name'      => (string)$meta['name'],
            'vendor'    => (string)$meta['vendor'],
            'version'   => (string)$meta['version'],
            'size'      => $size,
            'overwrite' => is_file($dest),
            'config'    => array_map(static fn(array $c): string => (string)$c['label'], $meta['config']),
        ];
        return true;
    }

    public static function preview(): ?array
    {
        $v = $_SESSION['up_upload'] ?? null;
        return is_array($v) && (string)($v['token'] ?? '') !== '' ? $v : null;
    }

    public static function cancel(): void
    {
        $v = $_SESSION['up_upload'] ?? null;
        if (is_array($v) && is_string($v['file'] ?? null) && $v['file'] !== '') {
            @unlink((string)$v['file']);
        }
        unset($_SESSION['up_upload']);
    }

    public static function commit(string $token, string &$err): string
    {
        $err = '';
        $v   = $_SESSION['up_upload'] ?? null;
        if (!is_array($v) || $token === '' || !hash_equals((string)($v['token'] ?? ''), $token)) {
            $err = '这次上传已经失效，重新选一次文件。';
            return '';
        }

        $qfile = (string)($v['file'] ?? '');
        $short = (string)($v['short'] ?? '');
        $type  = (string)($v['type'] ?? '');
        if (!in_array($type, Plugin::TYPES, true) || !preg_match('~^[a-z0-9][a-z0-9_-]{0,47}$~', $short)) {
            self::cancel();
            $err = '这一次上传的信息不对，重新选一次文件。';
            return '';
        }
        if (!is_file($qfile) || !hash_equals((string)($v['hash'] ?? ''), (string)hash_file('sha256', $qfile))) {
            self::cancel();
            $err = '临时文件不见了，重新选一次文件。';
            return '';
        }
        $src = @file_get_contents($qfile);
        if (!is_string($src) || $src === '') {
            self::cancel();
            $err = '临时文件读不出来，重新选一次文件。';
            return '';
        }

        $dir = Plugin::dir() . '/' . $type;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $err = '插件目录不可写：public/plugins/' . $type;
            return '';
        }
        $dest = $dir . '/' . $short . '.php';
        $tmp  = $dir . '/.' . $short . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $src, LOCK_EX) !== strlen($src)) {
            @unlink($tmp);
            $err = '插件写不进去，检查 public/plugins/' . $type . ' 的权限。';
            return '';
        }
        @chmod($tmp, 0644);
        if (is_file($dest) && !@unlink($dest)) {
            @unlink($tmp);
            $err = '同名插件替换失败，检查文件权限。';
            return '';
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            $err = '插件写不进去，检查 public/plugins/' . $type . ' 的权限。';
            return '';
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dest, true);
        }
        @unlink($qfile);
        $name = (string)($v['name'] ?? $short);
        $was  = !empty($v['overwrite']);
        unset($_SESSION['up_upload']);
        Plugin::flush();
        return '「' . $name . '」已' . ($was ? '更新' : '上传') . '。';
    }
}
