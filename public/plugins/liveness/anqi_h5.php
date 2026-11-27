<?php
if (!defined('UP_PLUGIN_LOAD')) {
    http_response_code(404);
    exit;
}

$baseUrl = function (array $cfg): string {
    $base = rtrim(trim((string)($cfg['host'] ?? '')), '/');
    if ($base === '') {
        $base = 'https://jmxgjmhtrz.market.alicloudapi.com';
    }
    foreach (['/lifecheck/init-v2.1', '/lifecheck/result-v2.1', '/lifecheck/init', '/lifecheck/result'] as $tail) {
        if (substr($base, -strlen($tail)) === $tail) {
            return substr($base, 0, -strlen($tail));
        }
    }
    return $base;
};

$heads = function (array $cfg): array {
    return ['Authorization: APPCODE ' . trim((string)($cfg['appcode'] ?? ''))];
};

$secrets = function (array $cfg): array {
    return [trim((string)($cfg['appcode'] ?? ''))];
};

$flag = function (string $v): string {
    return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
};

$actionsOf = function (string $type): array {
    $map = ['2' => 'BlinkEye', '3' => 'ShakeHead', '4' => 'NodHead', '5' => 'OpenMouth'];
    $out = [];
    foreach (str_split($type) as $d) {
        if (isset($map[$d])) {
            $out[] = $map[$d];
        }
    }
    return $out;
};

$post = function (string $url, array $params, array $headers, int $timeout, array $secrets): array {
    $body  = http_build_query($params);
    $lines = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
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

$codeOf = function (array $r): int {
    if (is_array($r['json']) && isset($r['json']['code'])) {
        return (int)$r['json']['code'];
    }
    return (int)$r['http'];
};

return [
    'meta' => [
        'name'     => '阿里云市场 · 安那其炫光活体',
        'vendor'   => '杭州安那其科技有限公司',
        'version'  => '1.0.0',
        'intro'    => '炫光静默活体 V2.1，也支持动作活体；采集完返回一张人脸图，再交给比对插件。',
        'usage'    => "在阿里云控制台给这个接口开通 AppCode，复制到「AppCode」里。\n"
            . "调用方传了 living_type 就按 2 眨眼 / 3 摇头 / 4 点头 / 5 张嘴 下发；\n"
            . "只传 6 走静默检测；1（远近）这家不支持，只传 1 时按下面的默认动作走。\n"
            . "发起后返回 verify_url，用户做完再查结果；token 有效期 2 小时。",
        'buy_url'  => 'https://market.aliyun.com/detail/cmapi00064004',
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
                'default'  => 'https://jmxgjmhtrz.market.alicloudapi.com',
                'placeholder' => 'https://jmxgjmhtrz.market.alicloudapi.com',
            ],
            [
                'key'     => 'action_style',
                'label'   => '默认动作样式',
                'type'    => 'select',
                'default' => 'auto',
                'options' => [
                    'auto'   => '随机组合（头部 + 局部）',
                    'local'  => '仅局部动作',
                    'custom' => '自定义动作序列',
                    'silent' => '静默无动作',
                ],
            ],
            [
                'key'         => 'actions',
                'label'       => '默认动作序列',
                'type'        => 'text',
                'placeholder' => 'BlinkEye,ShakeHead',
            ],
            [
                'key'     => 'action_mutex',
                'label'   => '动作互斥',
                'type'    => 'switch',
                'default' => '1',
            ],
            [
                'key'     => 'anti_camera_hack',
                'label'   => '防摄像头劫持',
                'type'    => 'switch',
                'default' => '1',
            ],
            [
                'key'         => 'title',
                'label'       => '采集页标题',
                'type'        => 'text',
                'placeholder' => '最多 32 个字',
            ],
        ],
    ],

    'liveness' => [
        'start' => function (array $cfg, array $in) use ($baseUrl, $heads, $secrets, $flag, $codeOf, $post, $actionsOf): array {
            $back = trim((string)($in['return_url'] ?? ''));
            if ($back === '') {
                return ['ok' => false, 'retry' => false, 'msg' => '这一单没准备好，重新发起一次。'];
            }

            $type   = preg_replace('~[^1-6]~', '', (string)($in['living_type'] ?? ''));
            $digits = str_split((string)$type);
            $mapped = $actionsOf((string)$type);

            if ($mapped !== []) {
                $style   = '3';
                $actions = implode(',', $mapped);
            } elseif (strpos((string)$type, '6') !== false) {
                $style   = '-1';
                $actions = '';
            } else {
                $key     = trim((string)($cfg['action_style'] ?? ''));
                $style   = ['auto' => '1', 'local' => '2', 'custom' => '3', 'silent' => '-1'][$key] ?? '1';
                $actions = '';
                if ($style === '3') {
                    $acts = preg_replace('~[^A-Za-z,]~', '', (string)($cfg['actions'] ?? ''));
                    $actions = trim((string)preg_replace('~,+~', ',', (string)$acts), ',');
                    if ($actions === '') {
                        $style = '1';
                    }
                }
            }

            $params = [
                'returnUrl'      => $back,
                'actionStyle'    => $style,
                'actionMutex'    => $flag((string)($cfg['action_mutex'] ?? '')),
                'antiCameraHack' => $flag((string)($cfg['anti_camera_hack'] ?? '')),
            ];
            if ($actions !== '') {
                $params['actions'] = $actions;
            }
            $title = trim((string)($cfg['title'] ?? ''));
            if ($title !== '') {
                $params['title'] = mb_substr($title, 0, 32, 'UTF-8');
            }

            $r = $post($baseUrl($cfg) . '/lifecheck/init-v2.1', $params, $heads($cfg), (int)($in['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            $code = $codeOf($r);
            if ($code !== 200) {
                return ['ok' => false, 'retry' => !in_array($code, [400, 401, 403, 404], true), 'raw' => $r];
            }

            $d = (is_array($r['json']) && is_array($r['json']['data'] ?? null)) ? $r['json']['data'] : null;
            if ($d === null) {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            $supported = ($digits !== [] && (count($mapped) === count($digits) || trim((string)$type) === '6'));
            $effective = $supported ? (string)$type : '';

            return [
                'ok'          => true,
                'order_no'    => trim((string)($d['token'] ?? '')),
                'verify_url'  => trim((string)($d['checkUrl'] ?? '')),
                'biz_id'      => trim((string)($r['json']['taskNo'] ?? '')),
                'living_type' => $effective,
                'raw'         => $r,
            ];
        },

        'query' => function (array $cfg, string $token, array $ctx) use ($baseUrl, $heads, $secrets, $codeOf, $post): array {
            $r = $post($baseUrl($cfg) . '/lifecheck/result-v2.1', ['token' => $token], $heads($cfg), (int)($ctx['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['state' => 'error', 'retry' => true, 'raw' => $r];
            }

            $code = $codeOf($r);
            if ($code === 201) {
                return ['state' => 'wait', 'retry' => false, 'raw' => $r];
            }
            if ($code === 202 || $code === 203) {
                return ['state' => 'fail', 'raw' => $r];
            }
            if ($code === 400 || $code === 401 || $code === 403 || $code === 404) {
                return ['state' => 'error', 'retry' => false, 'raw' => $r];
            }
            if ($code !== 200) {
                return ['state' => 'error', 'retry' => true, 'raw' => $r];
            }

            $d = (is_array($r['json']) && is_array($r['json']['data'] ?? null)) ? $r['json']['data'] : null;
            $u = $d !== null ? trim((string)($d['faceImageUrl'] ?? '')) : '';
            return [
                'state' => 'pass',
                'image' => $u !== '' ? ['url' => $u] : null,
                'raw'   => $r,
            ];
        },
    ],

    'test' => function (array $cfg) use ($baseUrl, $heads, $secrets, $post): array {
        $r = $post($baseUrl($cfg) . '/lifecheck/init-v2.1', [], $heads($cfg), 15, $secrets($cfg));
        if ($r['err'] !== '') {
            return ['ok' => false, 'level' => 'err', 'msg' => '连不上上游：' . mb_substr($r['err'], 0, 140, 'UTF-8')];
        }
        $http = (int)$r['http'];
        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游不认这个 AppCode，核对一下有没有复制全、接口有没有开通。'];
        }
        $c = (is_array($r['json']) && isset($r['json']['code'])) ? (int)$r['json']['code'] : $http;
        if ($c === 400) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回 400，这是对的。'];
        }
        if ($c === 200) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游接受了空参数，地址可能不是这个活体接口，核对一下。'];
        }
        return ['ok' => false, 'level' => 'err', 'msg' => '上游回了 HTTP ' . $http . '，这一次没验出 AppCode 对不对。'];
    },
];