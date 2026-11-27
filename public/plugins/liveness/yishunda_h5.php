<?php
if (!defined('UP_PLUGIN_LOAD')) {
    http_response_code(404);
    exit;
}

$baseUrl = function (array $cfg): string {
    $base = rtrim(trim((string)($cfg['host'] ?? '')), '/');
    if ($base === '') {
        $base = 'https://livenessh5.eshunda.cn';
    }
    foreach (['/getVerifyUrl', '/getResult'] as $tail) {
        if (substr($base, -strlen($tail)) === $tail) {
            return substr($base, 0, -strlen($tail));
        }
    }
    return $base;
};

$heads = function (array $cfg): array {
    return [
        'Authorization: APPCODE ' . trim((string)($cfg['appcode'] ?? '')),
        'X-Ca-Nonce: ' . bin2hex(random_bytes(8)),
    ];
};

$secrets = function (array $cfg): array {
    return [trim((string)($cfg['appcode'] ?? ''))];
};

$cleanType = function (string $t): string {
    $t = (string)preg_replace('~[^1-6]~', '', $t);
    if ($t === '' || strlen($t) > 6) {
        return '';
    }
    $chars = [];
    foreach (str_split($t) as $c) {
        $chars[$c] = true;
    }
    $chars = array_map('strval', array_keys($chars));
    $six   = array_search('6', $chars, true);
    if ($six !== false) {
        unset($chars[$six]);
        $chars[] = '6';
    }
    return implode('', $chars);
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

$codeOf = function (array $r): string {
    if (is_array($r['json']) && isset($r['json']['code'])) {
        return trim((string)$r['json']['code']);
    }
    return (string)(int)$r['http'];
};

return [
    'meta' => [
        'name'     => '阿里云市场 · 易顺达活体 H5',
        'vendor'   => '深圳市易顺达科技有限公司',
        'version'  => '1.0.0',
        'intro'    => '炫彩 / 动作 / 静默活体 H5，防 AI 换脸；查出结果时免费，只在发起时计费。',
        'usage'    => "在阿里云控制台给这个接口开通 AppCode，复制到「AppCode」里。\n"
            . "调用方的 living_type 原样传给上游：1 远近、2 眨眼、3 摇头、4 点头、5 张嘴、6 炫彩，\n"
            . "可以组合（如 26），6 必须排最后；不传就用下面的默认值。\n"
            . "发起后返回 verify_url，用户做完再查结果。",
        'buy_url'  => 'https://market.aliyun.com/detail/cmapi00071079',
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
                'key'      => 'host',
                'label'    => '接口地址',
                'type'     => 'url',
                'required' => false,
                'default'  => 'https://livenessh5.eshunda.cn',
                'placeholder' => 'https://livenessh5.eshunda.cn',
            ],
            [
                'key'         => 'living_type',
                'label'       => '默认活体类型',
                'type'        => 'text',
                'default'     => '26',
                'placeholder' => '例：26（先眨眼再炫彩）',
            ],
        ],
    ],

    'liveness' => [
        'start' => function (array $cfg, array $in) use ($baseUrl, $heads, $secrets, $cleanType, $codeOf, $post): array {
            $back = trim((string)($in['return_url'] ?? ''));
            if ($back === '') {
                return ['ok' => false, 'retry' => false, 'msg' => '这一单没准备好，重新发起一次。'];
            }

            $type = $cleanType((string)($in['living_type'] ?? ''));
            if ($type === '') {
                $type = $cleanType((string)($cfg['living_type'] ?? ''));
            }
            if ($type === '') {
                $type = '2';
            }

            $bizId = 'SF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));

            $r = $post($baseUrl($cfg) . '/getVerifyUrl', [
                'bizId'      => $bizId,
                'livingType' => $type,
                'returnUrl'  => $back,
            ], $heads($cfg), (int)($in['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            $code = $codeOf($r);
            if ($code !== '0000' && $code !== '200') {
                return [
                    'ok'    => false,
                    'retry' => !in_array($code, ['400', 'INVALID_PARAM', '401', '403'], true),
                    'raw'   => $r,
                ];
            }

            $d = (is_array($r['json']) && is_array($r['json']['data'] ?? null)) ? $r['json']['data'] : null;
            if ($d === null) {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            return [
                'ok'          => true,
                'order_no'    => trim((string)($d['orderNo'] ?? '')),
                'verify_url'  => trim((string)($d['verifyUrl'] ?? '')),
                'biz_id'      => $bizId,
                'living_type' => $type,
                'raw'         => $r,
            ];
        },

        'query' => function (array $cfg, string $orderNo, array $ctx) use ($baseUrl, $heads, $secrets, $codeOf, $post): array {
            $r = $post($baseUrl($cfg) . '/getResult', ['orderNo' => $orderNo], $heads($cfg), (int)($ctx['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['state' => 'error', 'retry' => true, 'raw' => $r];
            }

            $code = $codeOf($r);
            if ($code !== '200' && $code !== '0000') {
                return [
                    'state' => 'error',
                    'retry' => !in_array($code, ['400', 'INVALID_PARAM', '401', '403'], true),
                    'raw'   => $r,
                ];
            }

            $d = (is_array($r['json']) && is_array($r['json']['data'] ?? null)) ? $r['json']['data'] : null;
            if ($d === null || !array_key_exists('passed', $d)) {
                return ['state' => 'wait', 'retry' => false, 'raw' => $r];
            }

            $p = strtolower(trim((string)$d['passed']));
            if (in_array($p, ['t', 'true', '1', 'y', 'yes', 'pass', 'passed'], true)) {
                $u = trim((string)($d['bestImgUrl'] ?? ''));
                return [
                    'state' => 'pass',
                    'image' => $u !== '' ? ['url' => $u] : null,
                    'raw'   => $r,
                ];
            }
            if (in_array($p, ['f', 'false', '0', 'n', 'no', 'fail', 'failed'], true)) {
                return ['state' => 'fail', 'raw' => $r];
            }
            return ['state' => 'error', 'retry' => false, 'raw' => $r];
        },
    ],

    'test' => function (array $cfg) use ($baseUrl, $heads, $secrets, $post): array {
        $r = $post($baseUrl($cfg) . '/getVerifyUrl', [], $heads($cfg), 15, $secrets($cfg));
        if ($r['err'] !== '') {
            return ['ok' => false, 'level' => 'err', 'msg' => '连不上上游：' . mb_substr($r['err'], 0, 140, 'UTF-8')];
        }
        $http = (int)$r['http'];
        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游不认这个 AppCode，核对一下有没有复制全、接口有没有开通。'];
        }
        $code = (is_array($r['json']) && isset($r['json']['code'])) ? trim((string)$r['json']['code']) : '';
        if (in_array($code, ['400', 'INVALID_PARAM'], true)) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回参数错误，这是对的。'];
        }
        if ($code === '0000' || $code === '200') {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游接受了空参数，地址可能不是这个活体接口，核对一下。'];
        }
        return ['ok' => false, 'level' => 'err', 'msg' => '上游回了 HTTP ' . $http . '，这一次没验出 AppCode 对不对。'];
    },
];