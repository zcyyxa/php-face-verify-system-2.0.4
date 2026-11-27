<?php
declare(strict_types=1);

namespace app\service;

final class Image
{
    public const MAX = 5242880;

    public static function kind(string $bin): string
    {
        if (substr($bin, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }
        if (substr($bin, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'png';
        }
        return '';
    }

    public static function mime(string $kind): string
    {
        return $kind === 'png' ? 'image/png' : 'image/jpeg';
    }

    public static function b64bin(string $s): string
    {
        $s = trim($s);
        if (stripos($s, 'base64,') !== false) {
            $s = substr($s, stripos($s, 'base64,') + 7);
        }
        $s = (string)preg_replace('~\s+~', '', $s);
        if ($s === '' || strlen($s) > (int)(self::MAX * 1.4) + 1024) {
            return '';
        }
        $bin = base64_decode($s, true);
        return is_string($bin) ? $bin : '';
    }

    private static function baseDir(string $key): array
    {
        $hash   = sha1($key);
        $relDir = FACE_DIR_REL . '/' . substr($hash, 0, 2);
        $absDir = _f28() . '/' . $relDir;
        return [$relDir, $absDir, $hash];
    }

    public static function reuse(string $rel): array
    {
        $abs = _f28() . '/' . ltrim($rel, '/');
        if (!is_file($abs) || (int)@filesize($abs) <= 0) {
            return ['ok' => false];
        }
        $bin = (string)@file_get_contents($abs);
        if ($bin === '' || strlen($bin) > self::MAX) {
            return ['ok' => false];
        }
        $kind = self::kind($bin);
        if ($kind === '' || substr($rel, -3) !== $kind) {
            return ['ok' => false];
        }
        return [
            'ok'    => true,
            'rel'   => $rel,
            'mime'  => self::mime($kind),
            'b64'   => base64_encode($bin),
            'bytes' => strlen($bin),
            'err'   => '',
        ];
    }

    private static function write(string $relDir, string $absDir, string $hash, string $bin, string $kind): string
    {
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            return '';
        }
        $to  = $absDir . '/' . $hash . '.' . $kind;
        $tmp = $absDir . '/' . $hash . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bin) !== strlen($bin)) {
            @unlink($tmp);
            return '';
        }
        if (!@rename($tmp, $to)) {
            @unlink($tmp);
            return '';
        }
        @chmod($to, 0644);
        return $relDir . '/' . $hash . '.' . $kind;
    }

    private static function fetch(string $url): string
    {
        if (!_f35($url) || strlen($url) > 1024) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'FaceVerify/' . APP_VERSION,
        ]);
        $bin  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($bin) || $http !== 200 || $bin === '' || strlen($bin) > self::MAX) {
            return '';
        }
        return $bin;
    }

    public static function store($img, string $key): array
    {
        $bad = ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'missing'];

        $b64Raw = '';
        $url    = '';
        if (is_string($img)) {
            $img = stripos(trim($img), 'data:') === 0 ? ['base64' => $img] : ['url' => $img];
        }
        if (is_array($img)) {
            $b64Raw = trim((string)($img['base64'] ?? ($img['b64'] ?? '')));
            $url    = trim((string)($img['url'] ?? ''));
        }
        if ($b64Raw === '' && $url === '') {
            return $bad;
        }

        [$relDir, $absDir, $hash] = self::baseDir($key);

        foreach (['jpg', 'png'] as $ext) {
            $rel = $relDir . '/' . $hash . '.' . $ext;
            $hit = self::reuse($rel);
            if (!empty($hit['ok'])) {
                return $hit;
            }
            $abs = _f28() . '/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }

        if ($b64Raw !== '') {
            $bin = self::b64bin($b64Raw);
            if ($bin === '') {
                return ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'type'];
            }
        } else {
            $bin = self::fetch($url);
            if ($bin === '') {
                return ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'fetch'];
            }
        }

        if ($bin === '' || strlen($bin) > self::MAX) {
            return ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'type'];
        }
        $kind = self::kind($bin);
        if ($kind === '') {
            return ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'type'];
        }
        $mime = self::mime($kind);
        $rel  = self::write($relDir, $absDir, $hash, $bin, $kind);
        if ($rel === '') {
            return ['ok' => false, 'rel' => '', 'mime' => '', 'b64' => '', 'bytes' => 0, 'err' => 'save'];
        }
        return [
            'ok'    => true,
            'rel'   => $rel,
            'mime'  => $mime,
            'b64'   => base64_encode($bin),
            'bytes' => strlen($bin),
            'err'   => '',
        ];
    }

    public static function b64OfRel(string $rel): array
    {
        if ($rel === '') {
            return ['ok' => false, 'b64' => '', 'mime' => ''];
        }
        $hit = self::reuse($rel);
        if (empty($hit['ok'])) {
            return ['ok' => false, 'b64' => '', 'mime' => ''];
        }
        return ['ok' => true, 'b64' => (string)$hit['b64'], 'mime' => (string)$hit['mime']];
    }
}
