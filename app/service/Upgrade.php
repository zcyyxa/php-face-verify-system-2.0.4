<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class Upgrade
{
    const MAX_ZIP   = 67108864;
    const MAX_TOTAL = 268435456;
    const MAX_FILES = 5000;
    const MAX_FILE  = 33554432;

    private static function norm(string $name): string
    {
        $raw = str_replace('\\', '/', $name);
        if ($raw === '' || $raw[0] === '/' || preg_match('~^[A-Za-z]:~', $raw)) {
            throw new \RuntimeException('包里有不安全的路径：' . $name);
        }
        $parts = [];
        foreach (explode('/', $raw) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..' || strpos($seg, ':') !== false || preg_match('~[\x00-\x1F]~', $seg)) {
                throw new \RuntimeException('包里有不安全的路径：' . $name);
            }
            $parts[] = $seg;
        }
        $rel = implode('/', $parts);
        if ($rel === '') {
            throw new \RuntimeException('包里有空路径。');
        }
        return $rel;
    }

    private static function protected_(string $rel): bool
    {
        $low = strtolower($rel);
        if ($low === '.env' || $low === 'public/storage/install.lock' || $low === 'update.zip' || $low === 'composer.json') {
            return true;
        }
        foreach (['public/storage/', 'public/uploads/', 'public/plugins/', 'runtime/', 'storage/'] as $p) {
            if (strpos($low, $p) === 0) {
                if ($p === 'public/plugins/') {
                    return is_file(_f6() . '/' . $rel);
                }
                return true;
            }
        }
        if (strpos($low, '.upgrade') === 0 || strpos($low, '__macosx/') === 0 || strpos($low, '.git/') === 0 || strpos($low, 'node_modules/') === 0) {
            return true;
        }
        $base = basename($low);
        return $base === '.ds_store' || $base === 'thumbs.db' || $base === 'desktop.ini';
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        } catch (\Throwable $ex) {
        }
        @rmdir($dir);
    }

    private static function versionFrom(string $file): string
    {
        $s = (string)@file_get_contents($file);
        if (preg_match("~define\\s*\\(\\s*'APP_VERSION'\\s*,\\s*'([0-9A-Za-z._-]{1,24})'\\s*\\)~", $s, $m)) {
            return (string)$m[1];
        }
        return '';
    }

    private static function extract(string $zipFile): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('服务器没开 zip 扩展，升级包解不开。');
        }
        $size = (int)@filesize($zipFile);
        if ($size <= 0) {
            throw new \RuntimeException('升级包是空的。');
        }
        if ($size > self::MAX_ZIP) {
            throw new \RuntimeException('升级包超过 64MB，先精简一下再传。');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('这个文件不是能打开的 zip。');
        }

        $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/sfr_upg_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            $zip->close();
            throw new \RuntimeException('PHP 的临时目录建不出子目录，检查权限。');
        }

        $closed = false;
        try {
            $num = (int)$zip->numFiles;
            if ($num <= 0) {
                throw new \RuntimeException('升级包里没有文件。');
            }
            if ($num > self::MAX_FILES) {
                throw new \RuntimeException('升级包里的文件太多（超过 ' . self::MAX_FILES . ' 个）。');
            }

            $files = [];
            $total = 0;
            for ($i = 0; $i < $num; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new \RuntimeException('升级包里有读不出来的条目。');
                }
                $name = (string)($stat['name'] ?? '');
                if ($name === '' || substr($name, -1) === '/') {
                    continue;
                }
                $rel = self::norm($name);
                if (self::protected_($rel)) {
                    continue;
                }
                $n = (int)($stat['size'] ?? 0);
                if ($n < 0 || $n > self::MAX_FILE) {
                    throw new \RuntimeException('包里的文件太大：' . $rel);
                }
                $total += $n;
                if ($total > self::MAX_TOTAL) {
                    throw new \RuntimeException('解压后超过 256MB，升级包太大了。');
                }
                $data = $zip->getFromIndex($i);
                if ($data === false) {
                    throw new \RuntimeException('包里的文件读不出来：' . $rel);
                }
                $to  = $tmp . '/' . $rel;
                $dir = dirname($to);
                if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException('临时目录里建不出子目录。');
                }
                if (@file_put_contents($to, $data, LOCK_EX) !== strlen($data)) {
                    throw new \RuntimeException('临时目录写不进去，检查磁盘空间。');
                }
                $files[$rel] = true;
            }
            $closed = true;
            try {
                $zip->close();
            } catch (\Throwable $ex) {
            }

            if (!$files) {
                throw new \RuntimeException('升级包里没有可写的程序文件。');
            }

            $anchor = 'version.php';
            $prefix = '';
            if (!isset($files[$anchor])) {
                $cands = [];
                foreach (array_keys($files) as $rel) {
                    if (preg_match('~^([^/]+)/' . preg_quote($anchor, '~') . '$~', $rel, $m)) {
                        $cands[$m[1]] = true;
                    }
                }
                if (count($cands) !== 1) {
                    throw new \RuntimeException('包里找不到站根：要么顶层有 ' . $anchor . '，要么只有一个顶层文件夹（里面有它）。');
                }
                $prefix = (string)array_key_first($cands) . '/';
            }

            foreach (['version.php', 'install/schema.sql', 'public/index.php', 'app/common.php'] as $one) {
                if (!isset($files[$prefix . $one])) {
                    throw new \RuntimeException('包里少了 ' . $one . '，不是完整的更新包。');
                }
            }

            $ver = self::versionFrom($tmp . '/' . $prefix . 'version.php');
            if ($ver === '' || !preg_match('~^\d+(?:\.\d+){0,3}$~', $ver)) {
                throw new \RuntimeException('包里的 version.php 没写出版本号。');
            }

            return ['tmp' => $tmp, 'prefix' => $prefix, 'version' => $ver, 'files' => count($files)];
        } catch (\Throwable $ex) {
            if (!$closed) {
                try {
                    $zip->close();
                } catch (\Throwable $ex2) {
                }
            }
            self::rmdir($tmp);
            throw $ex;
        }
    }

    public static function apply(string $zipFile): array
    {
        try {
            $pkg = self::extract($zipFile);
        } catch (\Throwable $ex) {
            return ['ok' => false, 'msg' => $ex->getMessage()];
        }

        $new  = (string)$pkg['version'];
        $from = APP_VERSION;
        if (version_compare($new, $from, '<')) {
            self::rmdir((string)$pkg['tmp']);
            return ['ok' => false, 'msg' => '这个包比当前版本旧（' . $new . ' < ' . $from . '），不支持降级。'];
        }
        $dbVer = trim((string)Settings::get('db_ver', ''));
        if ($dbVer !== '' && preg_match('~^\d+(?:\.\d+){0,3}$~', $dbVer) && version_compare($new, $dbVer, '<')) {
            self::rmdir((string)$pkg['tmp']);
            return ['ok' => false, 'msg' => '这个包比当前库结构版本旧（' . $new . ' < ' . $dbVer . '），不支持降级。'];
        }

        $tmp    = (string)$pkg['tmp'];
        $prefix = (string)$pkg['prefix'];
        $root   = _f6();

        try {
            $targets = [];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                $full = str_replace('\\', '/', $f->getPathname());
                $rel  = substr($full, strlen($tmp) + 1);
                if ($prefix !== '') {
                    if (strpos($rel, $prefix) !== 0) {
                        continue;
                    }
                    $rel = substr($rel, strlen($prefix));
                }
                if ($rel === '' || self::protected_($rel)) {
                    continue;
                }
                $targets[$rel] = $f->getPathname();
            }
            if (!$targets) {
                throw new \RuntimeException('包里没有可写的程序文件。');
            }

            foreach ($targets as $rel => $src) {
                $dst = $root . '/' . $rel;
                $dir = dirname($dst);
                if (!is_dir($dir) || !is_writable($dir)) {
                    throw new \RuntimeException('目录不可写：' . $rel);
                }
                if (is_file($dst) && !is_writable($dst)) {
                    throw new \RuntimeException('文件不可写：' . $rel);
                }
            }

            $n = 0;
            $pending = [];
            foreach ($targets as $rel => $src) {
                $dst = $root . '/' . $rel;
                $dir = dirname($dst);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('建不出目录：' . $rel);
                }
                $data = (string)file_get_contents($src);
                $tmpFile = $dir . '/.' . basename($dst) . '.' . bin2hex(random_bytes(4)) . '.upg';
                if (@file_put_contents($tmpFile, $data, LOCK_EX) !== strlen($data)) {
                    @unlink($tmpFile);
                    throw new \RuntimeException('写不进去：' . $rel);
                }
                if (self::swap($tmpFile, $dst)) {
                    @chmod($dst, 0644);
                    $n++;
                } else {
                    $pending[] = $rel;
                }
            }

            self::rmdir($tmp);
            if ($pending) {
                self::pendingSet($pending);
            }
            return [
                'ok'      => true,
                'version' => $new,
                'from'    => $from,
                'files'   => $n,
                'pending' => $pending,
            ];
        } catch (\Throwable $ex) {
            self::rmdir($tmp);
            return ['ok' => false, 'msg' => $ex->getMessage()];
        }
    }

    private static function swap(string $tmpFile, string $dst): bool
    {
        if (@rename($tmpFile, $dst)) {
            return true;
        }
        if (!is_file($dst)) {
            return false;
        }
        $bak = $dst . '.bak.' . bin2hex(random_bytes(4));
        if (!@rename($dst, $bak)) {
            return false;
        }
        if (@rename($tmpFile, $dst)) {
            @unlink($bak);
            return true;
        }
        @rename($bak, $dst);
        return false;
    }

    private static function pendingFile(): string
    {
        return _f28() . '/storage/upgrade_pending.json';
    }

    private static function pendingSet(array $rels): void
    {
        $m = [];
        foreach ($rels as $rel) {
            $dir = dirname(_f6() . '/' . $rel);
            foreach ((array)@scandir($dir) as $one) {
                if (is_string($one) && str_starts_with($one, '.' . basename($rel) . '.') && str_ends_with($one, '.upg')) {
                    $m[$rel] = $dir . '/' . $one;
                    break;
                }
            }
        }
        $pf = self::pendingFile();
        if (!is_dir(dirname($pf))) {
            @mkdir(dirname($pf), 0775, true);
        }
        @file_put_contents($pf, (string)json_encode($m, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function settle(): void
    {
        $pf = self::pendingFile();
        if (!is_file($pf)) {
            return;
        }
        $m = json_decode((string)@file_get_contents($pf), true);
        if (!is_array($m) || !$m) {
            @unlink($pf);
            return;
        }
        $left = [];
        foreach ($m as $rel => $tmp) {
            $rel = (string)$rel;
            $tmp = (string)$tmp;
            if ($rel === '' || $tmp === '' || !is_file($tmp) || self::protected_($rel)) {
                continue;
            }
            $dst = _f6() . '/' . $rel;
            $dir = dirname($dst);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $left[$rel] = $tmp;
                continue;
            }
            if (self::swap($tmp, $dst)) {
                @chmod($dst, 0644);
            } else {
                $left[$rel] = $tmp;
            }
        }
        if ($left) {
            @file_put_contents($pf, (string)json_encode($left, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } else {
            @unlink($pf);
        }
    }

    public static function uploadError(int $e): string
    {
        switch ($e) {
            case UPLOAD_ERR_INI_SIZE:
                return '上传的文件超过了 PHP 的 upload_max_filesize（当前 ' . (string)ini_get('upload_max_filesize') . '）。';
            case UPLOAD_ERR_FORM_SIZE:
                return '上传的文件超过了表单限制。';
            case UPLOAD_ERR_PARTIAL:
                return '文件只传了一半，重试一次。';
            case UPLOAD_ERR_NO_FILE:
                return '没选更新包。';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'PHP 没有可写的临时目录。';
            case UPLOAD_ERR_CANT_WRITE:
                return 'PHP 写不了临时文件，检查磁盘。';
            case UPLOAD_ERR_EXTENSION:
                return '上传被 PHP 扩展拦住了。';
            default:
                return '上传没成（错误码 ' . $e . '）。';
        }
    }

    public static function finishUpgrade(?string $ver = null): array
    {
        $notes = Schema::upgrade();
        $v = ($ver !== null && preg_match('~^\d+(?:\.\d+){0,3}$~', $ver)) ? $ver : APP_VERSION;
        Settings::set('db_ver', $v);
        return $notes;
    }
}
