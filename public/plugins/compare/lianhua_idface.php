<?php
if (!defined('UP_PLUGIN_LOAD')) {
    http_response_code(404);
    exit;
}

$baseUrl = function (array $cfg): string {
    $base = rtrim(trim((string)($cfg['host'] ?? '')), '/');
    if ($base === '') {
        $base = 'https://lhrlsfzbd.market.alicloudapi.com';
    }
    if (substr($base, -20) === '/face-idcard/compare') {
        $base = substr($base, 0, -20);
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
        'name'     => '阿里云市场 · 怜花人脸三要素',
        'vendor'   => '杭州怜花科技有限公司',
        'version'  => '1.0.0',
        'intro'    => '姓名 + 证件号 + 人脸图，和公安库身份证头像比对，返回 0–1 的分值。',
        'usage'    => "在阿里云控制台给这个接口开通 AppCode，复制到「AppCode」里。\n"
            . "默认发 base64，不依赖外网；可切成活体上游原图地址，那个地址要公网能打开才行。\n"
            . "分值 >=0.60 判同一人，0.40–0.60 判不确定（按不通过收口），<0.40 判不同人。",
        'buy_url'  => 'https://market.aliyun.com/detail/cmapi00061263',
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
                'default'     => 'https://lhrlsfzbd.market.alicloudapi.com',
                'placeholder' => 'https://lhrlsfzbd.market.alicloudapi.com',
            ],
            [
                'key'     => 'image_mode',
                'label'   => '图片提交方式',
                'type'    => 'select',
                'default' => 'auto',
                'options' => [
                    'auto'   => 'base64 优先，没有再用原图地址',
                    'base64' => '只发 base64',
                    'source' => '只发活体上游原图地址',
                ],
            ],
        ],
    ],

    'compare' => [
        'compare' => function (array $cfg, array $in) use ($baseUrl, $heads, $secrets, $post, $isHttpUrl): array {
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
            $src = trim((string)($in['image_source_url'] ?? ''));

            $mode = trim((string)($cfg['image_mode'] ?? 'auto'));
            $use  = '';
            if ($mode === 'base64') {
                $use = $b64 !== '' ? 'base64' : '';
            } elseif ($mode === 'source') {
                $use = $isHttpUrl($src) ? 'source' : '';
            } else {
                $use = $b64 !== '' ? 'base64' : ($isHttpUrl($src) ? 'source' : '');
            }

            $params = ['name' => $name, 'idcard' => $idcard];
            if ($use === 'base64') {
                $params['image'] = $b64;
            } elseif ($use === 'source') {
                $params['url'] = $src;
            } else {
                return ['ok' => false, 'passed' => null, 'msg' => '没有可比对的人脸图。'];
            }

            $r = $post($baseUrl($cfg) . '/face-idcard/compare', $params, $heads($cfg), (int)($in['timeout'] ?? 30), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            $j = $r['json'];
            $code = is_array($j) && isset($j['code']) ? (int)$j['code'] : (int)$r['http'];
            if ((int)$r['http'] !== 200 || $code !== 200 || !is_array($j)) {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            $d   = is_array($j['data'] ?? null) ? $j['data'] : [];
            $inc = $j['incorrect'] ?? ($d['incorrect'] ?? null);
            $inc = $inc === null ? null : (int)$inc;
            $raw = $j['score'] ?? ($d['score'] ?? null);

            $fail = [101 => 1, 102 => 1, 107 => 1, 109 => 1, 111 => 1, 112 => 1];
            if ($inc !== null && $inc !== 100) {
                if (isset($fail[$inc])) {
                    return ['ok' => true, 'passed' => 0, 'raw' => $r];
                }
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            if (!is_numeric($raw)) {
                return ['ok' => false, 'passed' => null, 'raw' => $r];
            }

            $s = (float)$raw;
            return [
                'ok'     => true,
                'passed' => $s >= 0.60 ? 1 : 0,
                'score'  => (int)round($s * 100),
                'raw'    => $r,
            ];
        },
    ],

    'test' => function (array $cfg) use ($baseUrl, $heads, $secrets, $post): array {
        $r = $post($baseUrl($cfg) . '/face-idcard/compare', [], $heads($cfg), 15, $secrets($cfg));
        if ($r['err'] !== '') {
            return ['ok' => false, 'level' => 'err', 'msg' => '连不上上游：' . mb_substr($r['err'], 0, 140, 'UTF-8')];
        }
        $http = (int)$r['http'];
        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游不认这个 AppCode，核对一下有没有复制全、接口有没有开通。'];
        }
        $c = (is_array($r['json']) && isset($r['json']['code'])) ? (int)$r['json']['code'] : $http;
        if ($http === 400 || $c === 400) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回 400，这是对的。'];
        }
        return ['ok' => false, 'level' => 'err', 'msg' => '上游回了 HTTP ' . $http . '，这一次没验出 AppCode 对不对。'];
    },
];