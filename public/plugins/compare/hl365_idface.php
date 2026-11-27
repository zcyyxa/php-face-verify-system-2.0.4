<?php
if (!defined('UP_PLUGIN_LOAD')) {
    http_response_code(404);
    exit;
}

$baseUrl = function (array $cfg): string {
    $base = rtrim(trim((string)($cfg['host'] ?? '')), '/');
    if ($base === '') {
        $base = 'https://zfa.market.alicloudapi.com';
    }
    $tail = '/efficient/idfaceIdentity';
    if (substr($base, -strlen($tail)) === $tail) {
        return substr($base, 0, -strlen($tail));
    }
    return $base;
};

$heads = function (array $cfg): array {
    return ['Authorization: APPCODE ' . trim((string)($cfg['appcode'] ?? ''))];
};

$secrets = function (array $cfg): array {
    return [trim((string)($cfg['appcode'] ?? ''))];
};

$isHttpUrl = function (string $u): bool {
    if ($u === '' || strlen($u) > 1024 || preg_match('~\s~', $u)) {
        return false;
    }
    $p = parse_url($u);
    return is_array($p) && in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)
        && (string)($p['host'] ?? '') !== '';
};

$fetchB64 = function (string $url): string {
    if ($url === '') {
        return '';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'FaceVerify/plugin',
        CURLOPT_MAXFILESIZE    => 1048576,
    ]);
    $bin  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($bin) || $http !== 200 || $bin === '' || strlen($bin) > 1048576) {
        return '';
    }
    return base64_encode($bin);
};

$post = function (string $url, array $params, array $headers, int $timeout, array $secrets): array {
    $body  = http_build_query($params);
    $lines = ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Accept: application/json'];
    foreach ($headers as $h) {
        $lines[] = (string)$h;
    }
    $mask = function (string $s) use ($secrets): string {
        foreach ($secrets as $sec) {
            $sec = trim((string)$sec);
            if ($sec === '') {
                continue;
            }
            $m = strlen($sec) <= 4 ? str_repeat('*', strlen($sec)) : '****' . substr($sec, -4);
            $s = str_replace($sec, $m, $s);
            $enc = urlencode($sec);
            if ($enc !== $sec) {
                $s = str_replace($enc, $m, $s);
            }
        }
        return $s;
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $lines,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $raw  = curl_exec($ch);
    $err  = (string)curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = '';
    $resp = '';
    if ($raw !== false) {
        $head = substr((string)$raw, 0, $hlen);
        $resp = substr((string)$raw, $hlen);
    }

    $json = null;
    $b = trim($resp);
    if ($b !== '') {
        if (substr($b, 0, 3) === "\xEF\xBB\xBF") {
            $b = trim(substr($b, 3));
        }
        $json = json_decode($b, true);
        if (!is_array($json)) {
            $i = strpos($b, '{');
            $k = strrpos($b, '}');
            if ($i !== false && $k !== false && $k > $i) {
                $json = json_decode(substr($b, $i, $k - $i + 1), true);
            }
        }
        if (!is_array($json)) {
            $json = null;
        }
    }

    return [
        'http' => $http,
        'body' => $resp,
        'err'  => $raw === false ? ($err !== '' ? $err : '请求没发出去') : '',
        'json' => $json,
        'req'  => 'POST ' . $url . "\n" . $mask(implode("\n", $lines)) . "\n\n" . $mask(substr($body, 0, 16384)),
        'resp' => 'HTTP ' . $http . "\n" . trim($head) . "\n\n" . substr($resp, 0, 262144),
    ];
};

return [
    'meta' => [
        'name'     => '阿里云市场 · 互联三六五实人认证',
        'vendor'   => '北京互联三六五科技有限公司',
        'version'  => '1.0.0',
        'intro'    => '姓名 + 证件号 + 人脸图，和公安库身份证头像比对，返回千分制相似度。',
        'usage'    => "在阿里云控制台给这个接口开通 AppCode，复制到「AppCode」里。\n"
            . "这家只收 base64：手里只有活体上游的原图地址时，插件会自己拉一次再转。\n"
            . "Validate_Result=1 判同一人，2 及以上和负数里的 -1/-2/-3 都按不通过收口。",
        'buy_url'  => 'https://market.aliyun.com/detail/cmapi00037888',
        'buy_text' => '去阿里云购买',
        'timeout'  => 30,
        'config'   => [
            [
                'key'         => 'appcode',
                'label'       => 'AppCode',
                'type'        => 'password',
                'required'    => true,
                'placeholder' => '阿里云控制台里的 AppCode',
            ],
            [
                'key'         => 'host',
                'label'       => '接口地址',
                'type'        => 'url',
                'required'    => false,
                'default'     => 'https://zfa.market.alicloudapi.com',
                'placeholder' => 'https://zfa.market.alicloudapi.com',
            ],
        ],
    ],

    'compare' => [
        'compare' => function (array $cfg, array $in) use ($baseUrl, $heads, $secrets, $post, $isHttpUrl, $fetchB64): array {
            $name   = trim((string)($in['name'] ?? ''));
            $idcard = trim((string)($in['idcard'] ?? ''));
            if ($name === '' || $idcard === '') {
                return ['ok' => false, 'passed' => null, 'msg' => '这一单缺姓名或证件号。'];
            }

            $b64 = trim((string)($in['image_base64'] ?? ''));
            if (preg_match('~^data:[^;]+;base64,~i', $b64) === 1) {
                $b64 = (string)substr($b64, (int)strpos($b64, ',') + 1);
            }
            $b64 = (string)preg_replace('~\s+~', '', $b64);
            if ($b64 === '') {
                $src = trim((string)($in['image_source_url'] ?? ''));
                if ($isHttpUrl($src)) {
                    $b64 = $fetchB64($src);
                }
            }
            if ($b64 === '') {
                return ['ok' => false, 'passed' => null, 'msg' => '没有可比对的人脸图。'];
            }

            $r = $post($baseUrl($cfg) . '/efficient/idfaceIdentity', [
                'name'      => $name,
                'number'    => $idcard,
                'base64Str' => $b64,
            ], $heads($cfg), (int)($in['timeout'] ?? 30), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            $j = $r['json'];
            if ((int)$r['http'] !== 200 || !is_array($j)) {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }
            if (isset($j['error_code']) && (int)$j['error_code'] !== 0) {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            $res = is_array($j['result'] ?? null) ? $j['result'] : [];
            $vr  = isset($res['Validate_Result']) ? (int)$res['Validate_Result'] : null;
            if ($vr === null) {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            if ($vr === 1) {
                $out = ['ok' => true, 'passed' => 1, 'raw' => $r];
                if (isset($res['Similarity']) && is_numeric($res['Similarity'])) {
                    $out['score'] = (int)round((float)$res['Similarity'] / 10);
                }
                return $out;
            }

            $fail = [2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, -1 => 1, -2 => 1, -3 => 1];
            if (isset($fail[$vr])) {
                return ['ok' => true, 'passed' => 0, 'raw' => $r];
            }

            return ['ok' => false, 'passed' => null, 'raw' => $r];
        },
    ],

    'test' => function (array $cfg) use ($baseUrl, $heads, $secrets, $post): array {
        $r = $post($baseUrl($cfg) . '/efficient/idfaceIdentity', [], $heads($cfg), 15, $secrets($cfg));
        if ($r['err'] !== '') {
            return ['ok' => false, 'level' => 'err', 'msg' => '连不上上游：' . mb_substr($r['err'], 0, 140, 'UTF-8')];
        }
        $http = (int)$r['http'];
        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游不认这个 AppCode，核对一下有没有复制全、接口有没有开通。'];
        }
        if ($http === 400) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回 400，这是对的。'];
        }
        $j = $r['json'];
        if (is_array($j) && isset($j['error_code'])) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回了业务错误，这是对的。'];
        }
        return ['ok' => false, 'level' => 'err', 'msg' => '上游回了 HTTP ' . $http . '，这一次没验出 AppCode 对不对。'];
    },
];