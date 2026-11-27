<?php
if (!defined('UP_PLUGIN_LOAD')) {
    http_response_code(404);
    exit;
}

$baseUrl = function (array $cfg): string {
    $base = rtrim(trim((string)($cfg['host'] ?? '')), '/');
    if ($base === '') {
        $base = 'https://kzlive.market.alicloudapi.com';
    }
    foreach (['/api/liveness/h5/token', '/api/liveness/h5/result', '/api/liveness/h5'] as $tail) {
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

$bool = function (string $v): string {
    return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true) ? 'true' : 'false';
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

$data = function ($j): ?array {
    if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
        return null;
    }
    return $j['data'];
};

$codeOf = function (array $r): int {
    if (is_array($r['json']) && isset($r['json']['code'])) {
        return (int)$r['json']['code'];
    }
    return (int)$r['http'];
};

return [
    'meta' => [
        'name'     => '阿里云市场 · 活体 H5',
        'vendor'   => '杭州快证签科技有限公司',
        'version'  => '1.0.0',
        'intro'    => '阿里云云市场的动作活体 H5：拉起采集页，做完返回一张人脸图，再交给比对插件。',
        'usage'    => "在阿里云控制台给这个接口开通 AppCode，复制到「AppCode」里。\n"
            . "动作样式、指定动作和采集页的几个开关都在下面，按套餐文档填。\n"
            . "发起后返回 verify_url，用户做完再查结果；单号有效期 2 小时。",
        'buy_url'  => 'https://market.aliyun.com/detail/cmapi00071987',
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
                'default'  => 'https://kzlive.market.alicloudapi.com',
                'placeholder' => 'https://kzlive.market.alicloudapi.com',
            ],
            [
                'key'     => 'style',
                'label'   => '动作样式',
                'type'    => 'select',
                'default' => 'head',
                'options' => [
                    'head'  => '头部动作 + 局部动作',
                    'local' => '局部动作',
                ],
            ],
            [
                'key'         => 'title',
                'label'       => '采集页标题',
                'type'        => 'text',
                'placeholder' => '最多 32 个字',
            ],
            [
                'key'         => 'action_live_list',
                'label'       => '指定动作',
                'type'        => 'text',
                'placeholder' => 'LookLeft,LookRight',
            ],
            [
                'key'     => 'wx_mini_program',
                'label'   => '微信小程序',
                'type'    => 'switch',
                'default' => '',
            ],
            [
                'key'     => 'action_mutex',
                'label'   => '动作互斥',
                'type'    => 'switch',
                'default' => '',
            ],
            [
                'key'     => 'anti_camera_hack',
                'label'   => '防摄像头劫持',
                'type'    => 'switch',
                'default' => '',
            ],
            [
                'key'     => 'show_success',
                'label'   => '展示成功页',
                'type'    => 'switch',
                'default' => '',
            ],
            [
                'key'     => 'show_fail',
                'label'   => '展示失败页',
                'type'    => 'switch',
                'default' => '1',
            ],
            [
                'key'     => 'hide_guide_page',
                'label'   => '隐藏引导页',
                'type'    => 'switch',
                'default' => '',
            ],
            [
                'key'     => 'fore_live_on',
                'label'   => '前端小模型活体',
                'type'    => 'switch',
                'default' => '1',
            ],
            [
                'key'     => 'back_live_on',
                'label'   => '云端大模型活体',
                'type'    => 'switch',
                'default' => '',
            ],
        ],
    ],

    'liveness' => [
        'start' => function (array $cfg, array $in) use ($baseUrl, $heads, $secrets, $bool, $post): array {
            $back = trim((string)($in['return_url'] ?? ''));
            if ($back === '') {
                return ['ok' => false, 'retry' => false, 'msg' => '这一单没准备好，重新发起一次。'];
            }

            $styleKey = trim((string)($cfg['style'] ?? ''));
            $style = ['head' => '1', 'local' => '2'][$styleKey]
                ?? (in_array($styleKey, ['1', '2'], true) ? $styleKey : '1');

            $params = [
                'returnUrl' => $back,
                'style'     => $style,
                'wxMiniProgram'  => $bool((string)($cfg['wx_mini_program'] ?? '')),
                'actionMutex'    => $bool((string)($cfg['action_mutex'] ?? '')),
                'antiCameraHack' => $bool((string)($cfg['anti_camera_hack'] ?? '')),
                'showSuccess'    => $bool((string)($cfg['show_success'] ?? '')),
                'showFail'       => $bool((string)($cfg['show_fail'] ?? '')),
                'hideGuidePage'  => $bool((string)($cfg['hide_guide_page'] ?? '')),
                'foreLiveOn'     => $bool((string)($cfg['fore_live_on'] ?? '')),
                'backLiveOn'     => $bool((string)($cfg['back_live_on'] ?? '')),
            ];
            $title = trim((string)($cfg['title'] ?? ''));
            if ($title !== '') {
                $params['title'] = mb_substr($title, 0, 32, 'UTF-8');
            }

            $acts = preg_replace('~[^A-Za-z,]~', '', (string)($cfg['action_live_list'] ?? ''));
            $acts = trim((string)preg_replace('~,+~', ',', (string)$acts), ',');
            if ($acts !== '') {
                $params['actionLiveList'] = $acts;
            }

            $r    = $post($baseUrl($cfg) . '/api/liveness/h5/token', $params, $heads($cfg), (int)($in['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            $j    = $r['json'];
            $code = (is_array($j) && isset($j['code'])) ? (int)$j['code'] : (int)$r['http'];
            if ($code !== 200 || !is_array($j) || (isset($j['success']) && !$j['success'])) {
                return [
                    'ok'    => false,
                    'retry' => !in_array($code, [400, 401, 403, 404], true),
                    'raw'   => $r,
                ];
            }

            $d = (is_array($j) && is_array($j['data'] ?? null)) ? $j['data'] : null;
            if ($d === null) {
                return ['ok' => false, 'retry' => true, 'raw' => $r];
            }

            return [
                'ok'          => true,
                'order_no'    => trim((string)($d['orderNo'] ?? '')),
                'verify_url'  => trim((string)($d['url'] ?? '')),
                'biz_id'      => '',
                'living_type' => '',
                'raw'         => $r,
            ];
        },

        'query' => function (array $cfg, string $orderNo, array $ctx) use ($baseUrl, $heads, $secrets, $data, $codeOf, $post): array {
            $r    = $post($baseUrl($cfg) . '/api/liveness/h5/result', ['orderNo' => $orderNo], $heads($cfg), (int)($ctx['timeout'] ?? 20), $secrets($cfg));
            if ($r['err'] !== '') {
                return ['state' => 'error', 'retry' => true, 'raw' => $r];
            }

            $code = $codeOf($r);
            if ($code === 400) {
                return ['state' => 'error', 'retry' => false, 'raw' => $r];
            }
            if ($code === 401 || $code === 403) {
                return ['state' => 'error', 'retry' => false, 'raw' => $r];
            }
            if ($code !== 200) {
                return ['state' => 'error', 'retry' => true, 'raw' => $r];
            }

            $d = $data($r['json']);
            if ($d === null || !array_key_exists('result', $d)) {
                return ['state' => 'wait', 'retry' => false, 'raw' => $r];
            }

            $res = (int)$d['result'];
            if ($res === 2) {
                return ['state' => 'wait', 'retry' => false, 'raw' => $r];
            }
            if ($res === 1) {
                return ['state' => 'fail', 'raw' => $r];
            }
            if ($res !== 0) {
                return ['state' => 'error', 'retry' => false, 'raw' => $r];
            }

            $u = trim((string)($d['faceImageUrl'] ?? ''));
            return [
                'state' => 'pass',
                'image' => $u !== '' ? ['url' => $u] : null,
                'raw'   => $r,
            ];
        },
    ],

    'test' => function (array $cfg) use ($baseUrl, $heads, $secrets, $post): array {
        $r = $post($baseUrl($cfg) . '/api/liveness/h5/token', [], $heads($cfg), 15, $secrets($cfg));
        if ($r['err'] !== '') {
            return ['ok' => false, 'level' => 'err', 'msg' => '连不上阿里云：' . mb_substr($r['err'], 0, 140, 'UTF-8')];
        }
        $c = (is_array($r['json']) && isset($r['json']['code'])) ? (int)$r['json']['code'] : (int)$r['http'];
        if ($c === 400) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'AppCode 通过了 —— 参数故意留空，上游回 400，这是对的。'];
        }
        if ($c === 401) {
            return ['ok' => false, 'level' => 'err', 'msg' => '阿里云不认这个 AppCode，核对一下有没有复制全。'];
        }
        if ($c === 403) {
            return ['ok' => false, 'level' => 'err', 'msg' => '这个 AppCode 没开通活体接口，去云市场确认一下订单。'];
        }
        if ($c === 200) {
            return ['ok' => false, 'level' => 'err', 'msg' => '上游接受了空参数，地址可能不是这个活体接口，核对一下。'];
        }
        return ['ok' => false, 'level' => 'err', 'msg' => '上游回了 HTTP ' . $c . '，这一次没验出 AppCode 对不对。'];
    },
];
