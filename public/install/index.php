<?php

require dirname(__DIR__, 2) . '/version.php';

$root = dirname(__DIR__, 2);

if (is_file(__DIR__ . '/../storage/install.lock')) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title>'
        . '<div style="font:16px/1.7 system-ui,\'Microsoft YaHei\',sans-serif;max-width:520px;margin:90px auto;padding:0 20px">'
        . '<h2 style="font-size:20px;margin:0 0 8px">没有这一页</h2></div>';
    exit;
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

session_name('SFINST');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

const AGREEMENT_VER = 'V1.0';

function _f17($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function _f13(string $p): bool
{
    return is_dir($p) && is_writable($p);
}

function _f33(string $file, PDO $pdo): void
{
    $sql = (string)file_get_contents($file);
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    $buf = '';
    foreach (preg_split('~\r\n|\n|\r~', $sql) ?: [] as $line) {
        $t = ltrim($line);
        if ($t === '' || strpos($t, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $pdo->exec(rtrim(trim($buf), ';'));
            $buf = '';
        }
    }
}

function _f15(string $v): string
{
    if ($v === '' || preg_match('~^[A-Za-z0-9_./:@+-]*$~', $v)) {
        return $v;
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

function _f40(): array
{
    if (PHP_SAPI === 'cli-server') {
        return [true, '本机内置服务器'];
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return [false, '拿不到访问地址'];
    }
    $self = (string)($_SERVER['SCRIPT_NAME'] ?? '/install/index.php');
    $base = str_replace('\\', '/', dirname(dirname($self)));
    $base = ($base === '/' || $base === '.') ? '' : rtrim($base, '/');

    $ch = curl_init($scheme . '://' . $host . $base . '/rewrite-check');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'SFR-Installer/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/plain'],
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    if (trim($body) === 'ok') {
        return [true, '已生效'];
    }
    return [false, '未配置'];
}

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

function _f41(): array
{
    $root = dirname(__DIR__, 2);
    $rw = _f40();
    $mustExts = ['pdo_mysql', 'curl', 'mbstring', 'openssl', 'fileinfo'];
    $checks = [
        ['PHP 版本', '>= 8.0', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>='), true],
    ];
    foreach ($mustExts as $ext) {
        $checks[] = ['扩展 ' . $ext, '必需', extension_loaded($ext) ? '已加载' : '未加载', extension_loaded($ext), true];
    }
    $checks[] = ['扩展 zip', '升级包用', extension_loaded('zip') ? '已加载' : '未加载', extension_loaded('zip'), false];
    $checks[] = ['站点根目录可写', '必需（.env）', _f13($root) ? '可写' : '不可写', _f13($root), true];
    $checks[] = ['public/storage 可写', '必需', _f13(__DIR__ . '/../storage') ? '可写' : '不可写', _f13(__DIR__ . '/../storage'), true];
    $checks[] = ['public/plugins 可写', '必需', _f13(__DIR__ . '/../plugins') ? '可写' : '不可写', _f13(__DIR__ . '/../plugins'), true];
    $checks[] = ['public/uploads/face 可写', '必需', _f13(__DIR__ . '/../uploads/face') ? '可写' : '不可写', _f13(__DIR__ . '/../uploads/face'), true];
    $checks[] = ['建库脚本可读', '必需', is_readable($root . '/install/schema.sql') ? '可读' : '不可读', is_readable($root . '/install/schema.sql'), true];
    $checks[] = ['伪静态', '必须配置', $rw[1], $rw[0], true];
    $envOk = true;
    foreach ($checks as $c) {
        if ($c[4] && !$c[3]) {
            $envOk = false;
        }
    }
    return [$checks, $envOk, $rw[0]];
}

[$checks, $envOk, $rwOk] = _f41();

$AGREE_TEXT = <<<TXT
PHP人脸实名分发系统 安装部署与使用许可协议

（适配：源码可见模式、禁止商业运营、明确等保三级合规义务、平台方免责、用户独立承担人脸信息处理法律责任）

版本号：{VER}
生效日期：{DATE}

重要提示
本协议为源码可见（Source-Available）许可协议，不属于 OSI 定义的开源许可证。
本软件仅作为技术工具提供，平台方不提供任何等保三级测评、资质代办、合规背书服务。
您下载、安装、部署、修改、运行本软件，即视为已完整阅读、充分理解并自愿接受本协议全部条款，特别是【禁止商业使用、等保合规义务、责任限制、免责声明、个人信息保护责任】条款。
如您不同意本协议全部内容，请立即停止获取、安装、使用本软件，并彻底删除所有副本。

第一条 定义
1.1 本软件：指平台方发布的PHP人脸实名分发系统全部源码、程序、配置文件、接口文档、部署脚本、衍生修改版本等全部资料。
1.2 第三方核验服务：本软件对接的活体检测、人脸比对、姓名+身份证号+人像三要素核验等第三方 API 接口服务；第三方服务不属于本软件，其资质、稳定性、核验结果、数据安全由对应服务商独立承担责任。
1.3 使用者（您）：下载、安装、部署、二次修改、运行本软件的自然人、企业、机构。
1.4 衍生作品：您修改、二次开发、封装本软件形成的新程序、系统、SaaS 服务、对外交付项目。
1.5 商业使用：以直接或间接盈利为目的使用本软件，包括但不限于：对外提供收费实名认证、活体核验服务；将本软件打包进收费项目交付客户；搭建 SaaS 平台对外收费运营；依托本软件开展商业化身份核验业务。
1.6 敏感个人信息：人脸图像、人脸特征、身份证号、姓名等生物识别信息与身份信息，受《个人信息保护法》《人脸识别技术应用安全管理办法》严格监管。
1.7 网络安全等级保护三级（等保三级）：依据《网络安全法》《信息安全技术 网络安全等级保护基本要求》，处理大规模人脸生物特征信息的信息系统，依法应当完成等保三级定级、备案、测评、整改的强制性合规要求。

第二条 许可授权范围
2.1 平台方授予您非独占、不可转让、不可再许可、可随时撤销的有限使用权。
2.2 授权用途严格限定：仅可用于内部测试、技术研究、非商业场景学习验证。
2.3 本软件为源码可见软件，您有权在非商业前提下查看、修改源码形成衍生作品，但衍生作品同样受本协议全部条款约束，不得通过改名、二次封装规避本协议限制。
2.4 除本协议明确授予的权利外，平台方保留本软件全部著作权、商标、专利、商业秘密等一切知识产权。本协议不构成任何商标、专利授权。

第三条 核心禁止条款
3.1 严格禁止商业使用
未经平台方另行签署书面《商业授权协议》并支付许可费用，严禁任何商业使用行为。禁止行为包含但不限于：
（1）对外提供收费或免费面向公众的人脸实名核验服务；
（2）将本软件作为产品、项目组件向客户交付、售卖、出租；
（3）搭建 SaaS、私有化托管、代运维服务并收取费用；
（4）利用本软件开展网贷、开户、实名注册等面向不特定公众的业务。
3.2 等保三级合规强制义务
您充分知悉：本软件具备采集、存储、传输人脸生物特征、身份证信息的能力，若您将本软件投入生产环境、面向用户收集人脸等敏感个人信息，您运营的系统依法必须完成网络安全等级保护三级备案与测评。
（1）平台方仅提供软件程序，不提供等保三级测评、整改、备案代办服务，不提供等保合规资质背书；
（2）如您擅自将本软件用于生产环境、收集终端用户人脸信息，您自行承担完成等保三级定级、备案、测评、安全整改的全部义务、时间与费用；
（3）若您未取得等保三级相关合规资质，不得将本软件用于面向不特定公众收集人脸、身份证信息的生产业务；
（4）因未完成等保测评、违规上线运营、违规收集人脸信息引发的一切行政处罚、民事赔偿、刑事风险，全部由您独立承担，与平台方无关。
3.3 其他禁止行为
（1）不得移除、篡改本协议、版权声明、免责标识；分发衍生作品必须完整保留全部协议文本；
（2）不得利用本软件实施身份冒用、绕过活体检测、电信诈骗、非法买卖个人信息等违法活动；
（3）不得反向工程、破解软件授权校验、安全限制机制；
（4）不得宣称本软件、平台方为您提供合规资质、等保认证、法律合规担保。

第四条 个人信息保护责任划分
4.1 当您使用本软件收集、处理人脸、身份证等敏感个人信息时，您是法定个人信息处理者；平台方仅作为软件工具提供方，不参与您的数据处理决策，不承担个人信息处理者法律责任。
4.2 您独立承担全部法定义务：
（1）采集人脸信息前，向信息主体完整告知处理规则，取得单独、明示同意；处理未成年人信息需监护人同意；
（2）开展个人信息保护影响评估，留存评估记录；
（3）落实数据加密、访问控制、日志审计、入侵防护等安全措施；发生数据泄露第一时间处置并履行报告义务；
（4）如委托第三方运维、存储数据，需签署数据处理协议，监督受托方安全义务；
（5）严格遵守人脸信息存储、删除、留存期限相关法律规定。
4.3 第三方核验接口返回的数据、核验结果，由对应服务商负责；因第三方接口故障、数据泄露、核验错误产生的损失，平台方不承担责任。

第五条 免责声明
5.1 按「现状」提供：本软件按照 AS IS（现状）提供，平台方不作任何明示或默示担保，包括但不限于：软件持续可用、无漏洞、核验结果 100% 准确、满足等保三级标准、适配所有业务场景。
5.2 核验结果仅为技术参考：本软件输出的活体检测、三要素核验结果仅为技术层面比对结果，不构成法定身份认定结论，不得作为唯一业务决策依据。
5.3 等保合规风险自担：平台方明确告知您，面向公众收集人脸信息需要等保三级合规。您明知该合规要求，仍擅自上线运营、未完成等保测评所产生的一切行政罚款、诉讼赔偿、业务关停损失，均由您自行承担。
5.4 责任上限：在法律允许最大范围内，平台方不对任何直接、间接、惩罚性损失（利润损失、数据丢失、业务中断、行政处罚等）承担责任。平台方累计赔偿责任上限为 0 元（非商业使用场景）。
5.5 本协议免责条款，不免除法律强制性规定不可免除的责任。
5.6 若因您违规商用、未完成等保合规、违法收集人脸信息，导致第三方（监管部门、用户、服务商）向平台方追责、索赔、处罚，您须全额赔偿平台方全部损失（律师费、罚金、诉讼费等），并负责消除全部不利影响。

第六条 知识产权
6.1 本软件全部著作权、知识产权归属平台方。您修改产生的衍生作品，不取得原软件知识产权；衍生作品对外分发仍需遵守本协议。
6.2 未经书面许可，您不得使用平台方企业名称、商标、LOGO 进行宣传、背书、商业推广。

第七条 协议终止
7.1 出现下列情形，平台方有权单方面立即终止授权，无需提前通知：
（1）您违反本协议禁止商业使用、等保合规相关条款；
（2）您利用本软件从事违法活动；
（3）监管部门要求平台方停止授权。
7.2 协议终止后，您必须立即停止使用、删除本软件所有源码、程序、衍生版本。
7.3 第五条、第六条、第七条、第八条在协议终止后持续有效。

第八条 争议解决与其他
8.1 本协议适用中华人民共和国法律。
8.2 因本协议产生纠纷，双方优先协商；协商不成，提交平台方所在地人民法院诉讼解决。
8.3 平台方可更新本协议，更新版本公示后即生效；您继续使用视为接受新版协议。
8.4 本协议部分条款无效，不影响其余条款效力。

使用者确认
本人/本单位已完整阅读、充分理解本协议全部条款，尤其清楚知晓：
1、本软件禁止未经授权的商业使用；
2、若使用本软件收集人脸、身份证信息开展对外业务，依法需要完成网络安全等级保护三级备案与测评，平台方不提供任何等保资质与合规担保；
3、所有合规风险、法律责任、行政处罚均由使用者自行承担，平台方不承担任何责任。
TXT;
$AGREE_TEXT = str_replace(['{VER}', '{DATE}'], [AGREEMENT_VER, date('Y-m-d')], $AGREE_TEXT);

function _f38(bool $ok, string $msg, array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function _f39(): array
{
    $j = json_decode((string)file_get_contents('php://input'), true);
    return is_array($j) ? $j : $_POST;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_GET['act'])) {
    $act = (string)$_GET['act'];
    if (!hash_equals((string)$_SESSION['csrf'], (string)(_f39()['csrf'] ?? ''))) {
        _f38(false, '页面放久了，刷新一下再试。');
    }

    if ($act === 'env') {
        [$ck, $ok, $rwOk] = _f41();
        $list = [];
        foreach ($ck as $c) {
            $list[] = ['n' => $c[0], 'r' => $c[1], 'v' => $c[2], 'ok' => $c[3], 'must' => $c[4]];
        }
        _f38(true, '', ['checks' => $list, 'ok' => $ok, 'rewrite' => $rwOk]);
    }

    if ($act === 'test') {
        $p = _f39();
        $h = trim((string)($p['db_host'] ?? ''));
        $port = (int)($p['db_port'] ?? 0);
        $name = trim((string)($p['db_name'] ?? ''));
        if ($h === '' || !preg_match('~^[a-zA-Z0-9_.-]+$~', $h) || strpos($h, '..') !== false) {
            _f38(false, '数据库地址不对。');
        }
        if ($port < 1 || $port > 65535) {
            _f38(false, '数据库端口不对。');
        }
        if (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $name)) {
            _f38(false, '数据库名只能用字母、数字、下划线和短横线。');
        }
        try {
            $pdo = new PDO('mysql:host=' . $h . ';port=' . $port . ';charset=utf8mb4', trim((string)($p['db_user'] ?? '')), (string)($p['db_pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 8,
            ]);
        } catch (Throwable $ex) {
            _f38(false, '连不上数据库：' . $ex->getMessage());
        }
        $st = $pdo->query('SHOW DATABASES LIKE \'' . str_replace(['_', '%'], ['\\_', '\\%'], $name) . '\'');
        $exists = $st && $st->fetchColumn() !== false;
        _f38(true, $exists ? '连接正常，数据库已存在。' : '连接正常，库不存在，安装时会创建。');
    }

    if ($act === 'install') {
        $p = _f39();
        $form = [
            'db_host'   => trim((string)($p['db_host'] ?? '')),
            'db_port'   => (string)($p['db_port'] ?? '3306'),
            'db_name'   => trim((string)($p['db_name'] ?? '')),
            'db_user'   => trim((string)($p['db_user'] ?? '')),
            'db_pass'   => (string)($p['db_pass'] ?? ''),
            'db_pre'    => (string)($p['db_pre'] ?? ''),
            'db_charset'=> (string)($p['db_charset'] ?? 'utf8mb4'),
            'admin_user'=> trim((string)($p['st_admin'] ?? '')),
            'site_name' => trim((string)($p['site_name'] ?? '')),
            'site_url'  => trim((string)($p['site_url'] ?? '')),
        ];
        $pwd  = (string)($p['st_pass'] ?? '');
        $pwd2 = (string)($p['st_pass2'] ?? '');

        $err = '';
        if (!preg_match('~^[a-zA-Z0-9_.-]+$~', $form['db_host']) || strpos($form['db_host'], '..') !== false) {
            $err = '数据库地址不对。';
        } elseif ((int)$form['db_port'] < 1 || (int)$form['db_port'] > 65535) {
            $err = '数据库端口不对。';
        } elseif (!preg_match('~^[A-Za-z0-9_-]{1,64}$~', $form['db_name'])) {
            $err = '数据库名只能用字母、数字、下划线和短横线。';
        } elseif (preg_match('~[\r\n]~', $form['db_pass'])) {
            $err = '数据库密码里不能有换行。';
        } elseif ($form['db_pre'] !== '' && !preg_match('~^[A-Za-z0-9_]*$~', $form['db_pre'])) {
            $err = '表前缀只能用字母、数字、下划线。';
        } elseif (!in_array($form['db_charset'], ['utf8mb4', 'utf8'], true)) {
            $err = '字符集不对。';
        } elseif (!preg_match('~^[a-zA-Z0-9_]{3,32}$~', $form['admin_user'])) {
            $err = '管理员账号用 3–32 位字母、数字或下划线。';
        } elseif (strlen($pwd) < 8) {
            $err = '管理员密码至少 8 位。';
        } elseif ($pwd !== $pwd2) {
            $err = '两次输入的管理员密码不一样。';
        } elseif ($form['site_name'] === '' || mb_strlen($form['site_name'], 'UTF-8') > 40) {
            $err = '站点名称要填，最多 40 个字。';
        } elseif (!preg_match('~^https?://~i', $form['site_url'])) {
            $err = '站点地址要以 http:// 或 https:// 开头。';
        }

        if ($err === '') {
            foreach ($checks as $c) {
                if ($c[4] && !$c[3]) {
                    $err = '环境检测没过：' . $c[0] . '（' . $c[2] . '）。';
                    break;
                }
            }
        }

        $notes = [];
        if ($err === '') {
            try {
                $notes[] = '连接数据库';
                $dsn = 'mysql:host=' . $form['db_host'] . ';port=' . (int)$form['db_port'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $form['db_user'], $form['db_pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 8,
                ]);
                $notes[] = '创建数据库';
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $form['db_name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec('USE `' . $form['db_name'] . '`');
                $notes[] = '建表';
                _f33($root . '/install/schema.sql', $pdo);

                $now = date('Y-m-d H:i:s');
                $notes[] = '写入站点配置';
                $set = $pdo->prepare('INSERT INTO settings (`k`,`v`,`updated_at`) VALUES (?,?,?)');
                foreach ([
                    'site_name' => $form['site_name'],
                    'site_url'  => rtrim($form['site_url'], '/'),
                    'live_ttl'  => '300',
                    'api_id'    => 'AK' . strtoupper(bin2hex(random_bytes(8))),
                    'api_key'   => bin2hex(random_bytes(24)),
                    'db_ver'    => APP_VERSION,
                ] as $k => $v) {
                    $set->execute([$k, $v, $now]);
                }
                $notes[] = '创建管理员账号';
                $pdo->prepare('INSERT INTO admins (`username`,`pwd`,`created_at`) VALUES (?,?,?)')
                    ->execute([$form['admin_user'], password_hash($pwd, PASSWORD_DEFAULT), $now]);

                $notes[] = '写入配置文件';
                $env = "APP_DEBUG=false\n"
                    . "APP_TIMEZONE=Asia/Shanghai\n"
                    . "DB_DRIVER=mysql\n"
                    . "DB_HOST=" . _f15($form['db_host']) . "\n"
                    . "DB_PORT=" . (int)$form['db_port'] . "\n"
                    . "DB_NAME=" . _f15($form['db_name']) . "\n"
                    . "DB_USER=" . _f15($form['db_user']) . "\n"
                    . "DB_PASS=" . _f15($form['db_pass']) . "\n"
                    . "DB_CHARSET=" . $form['db_charset'] . "\n"
                    . "DB_PREFIX=" . _f15($form['db_pre']) . "\n"
                    . "SESSION_NAME=SFRID1\n";
                if (@file_put_contents($root . '/.env', $env, LOCK_EX) === false) {
                    throw new RuntimeException('写不了根目录的 .env，检查目录权限。');
                }

                $machine = md5(__DIR__ . '|' . php_uname() . '|' . $root);
                @file_put_contents(
                    __DIR__ . '/agreement.log',
                    date('Y-m-d H:i:s') . "\t" . AGREEMENT_VER . "\t"
                        . (string)($_SERVER['REMOTE_ADDR'] ?? '') . "\t" . $machine . "\n",
                    FILE_APPEND | LOCK_EX
                );

                $notes[] = '写入安装锁';
                $lock = "installed_at=" . $now . "\nversion=" . APP_VERSION . "\n";
                if (@file_put_contents(__DIR__ . '/../storage/install.lock', $lock, LOCK_EX) === false) {
                    throw new RuntimeException('写不了 public/storage/install.lock。');
                }
                _f38(true, '安装完成。', ['notes' => $notes]);
            } catch (Throwable $ex) {
                error_log('[install] ' . $ex->getMessage());
                _f38(false, '安装失败：' . $ex->getMessage(), ['notes' => $notes]);
            }
        }
        _f38(false, $err);
    }
    _f38(false, '没有这个操作。');
}

$csrf = (string)$_SESSION['csrf'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>安装向导 · <?= _f17(SITE_NAME_FALLBACK) ?></title>
<link rel="icon" href="/assets/brand/logo-mark.webp" type="image/webp">
<style>
:root{
  --bg:#ffffff;
  --ink:#1d222b;
  --mut:#6d7684;
  --line:#e5e8ec;
  --pri:#4f6ef7;
  --pri-dk:#3f58c6;
  --ok:#189a58;
  --err:#d4453d;
  --warn:#c98a1b;
  --r:10px;
}
*{margin:0;padding:0;box-sizing:border-box}
html{scrollbar-gutter:stable}
body{
  min-height:100vh;
  background:var(--bg);color:var(--ink);
  font:15px/1.65 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif;
}
.stage{
  min-height:100vh;display:flex;flex-direction:column;
  justify-content:center;align-items:center;
  padding:48px 20px;
}
.shell{width:100%;max-width:840px}
.brand{
  display:flex;align-items:center;gap:10px;
  margin-bottom:14px;
  padding-left:2px;
}
.brand img,.brand svg{height:30px;width:auto;display:block}
.brand b{font-size:16px;font-weight:600;letter-spacing:.01em}
.brand span{color:var(--mut);font-size:12px;margin-left:2px}
.stepper{
  display:flex;align-items:center;
  border:1px solid var(--line);border-radius:var(--r) var(--r) 0 0;
  background:var(--bg);
  padding:14px 18px;
  overflow-x:auto;white-space:nowrap;scrollbar-width:none;
}
.stepper::-webkit-scrollbar{display:none}
.st{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;position:relative}
.st .dot{
  width:24px;height:24px;border-radius:50%;flex:0 0 auto;
  display:flex;align-items:center;justify-content:center;
  border:1.5px solid var(--line);background:var(--bg);
  color:var(--mut);font-size:12px;font-weight:600;
  transition:background .2s,border-color .2s,color .2s;
}
.st .lb{font-size:13px;color:var(--mut)}
.st.cur .dot{border-color:var(--pri);background:var(--pri);color:#fff}
.st.cur .lb{color:var(--ink);font-weight:600}
.st.done .dot{border-color:var(--ok);background:var(--ok);color:#fff}
.st.done .lb{color:var(--ink)}
.st::after{content:"";width:22px;height:1.5px;background:var(--line);margin:0 8px;flex:0 0 auto}
.st:last-child::after{display:none}
.st.done::after{background:var(--ok)}
.panel{
  border:1px solid var(--line);border-top:none;border-radius:0 0 var(--r) var(--r);
  background:var(--bg);
  box-shadow:0 12px 32px rgba(29,34,43,.06);
  padding:34px 44px 36px;
}
.step{display:none}
.step.on{display:block;animation:stepin .28s ease}
@keyframes stepin{
  from{opacity:0;transform:translateY(10px)}
  to{opacity:1;transform:none}
}
@media (prefers-reduced-motion:reduce){
  .step.on{animation:none}
}
.panel h2{font-size:17px;font-weight:600;margin-bottom:16px}
.agree-box{
  border:1px solid var(--line);border-radius:8px;
  padding:16px;height:300px;overflow-y:auto;font-size:13.5px;color:#3c4450;
  white-space:pre-wrap;background:#fafbfc;line-height:1.75;
}
.chk{border:1px solid var(--line);border-radius:8px;overflow:hidden}
.chk-row{
  display:flex;align-items:center;gap:10px;
  padding:10px 16px;border-bottom:1px solid var(--line);
}
.chk-row:last-child{border-bottom:none}
.chk-row .nm{flex:1;font-size:14px}
.chk-row .val{color:var(--mut);font-size:13px;font-variant-numeric:tabular-nums}
.dot-s{width:10px;height:10px;border-radius:50%;flex:0 0 auto;background:var(--line)}
.dot-s.ok{background:var(--ok)}
.dot-s.bad{background:var(--err)}
.chk-row.bad .nm{color:var(--err)}
.frm{display:grid;grid-template-columns:1fr 1fr;gap:18px 24px}
.frm .full{grid-column:1 / -1}
.fi label{display:block;font-size:13px;color:var(--mut);margin-bottom:6px}
.fi input,.fi select{
  width:100%;height:44px;padding:0 14px;font-size:15px;
  border:1px solid var(--line);border-radius:8px;color:var(--ink);
  background:#fafbfc;
  transition:border-color .15s,background .15s;
}
.fi select{padding:0 10px}
.fi input:focus,.fi select:focus{outline:none;border-color:var(--pri);background:var(--bg)}
.form-err{color:var(--err);font-size:13px;min-height:20px;margin-top:6px}
.acts{display:flex;justify-content:center;gap:12px;margin-top:24px}
.btn{
  min-width:128px;height:42px;padding:0 26px;
  border:none;border-radius:8px;background:var(--pri);color:#fff;
  font-size:14px;font-weight:500;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;
  line-height:1;white-space:nowrap;
  text-decoration:none;
  transition:background .15s;
}
.btn:hover{background:var(--pri-dk)}
.btn:disabled{background:var(--line);color:#9aa2ad;cursor:not-allowed}
.btn.ghost{background:var(--bg);color:var(--ink);border:1px solid var(--line)}
.btn.ghost:hover{background:#f6f7f9}
.btn.sm{min-width:104px;height:38px;font-size:13px}
.pbar{height:6px;border-radius:3px;background:#eef0f3;overflow:hidden;margin:4px 0 16px}
.pbar i{display:block;height:100%;width:0;background:var(--pri);transition:width .3s}
.ptask{
  display:flex;align-items:center;gap:10px;padding:9px 4px;
  border-bottom:1px solid var(--line);font-size:14px;
}
.ptask:last-child{border-bottom:none}
.ptask .st-t{margin-left:auto;color:var(--mut);font-size:13px}
.ptask.ok .st-t{color:var(--ok)}
.ptask.bad .st-t{color:var(--err)}
.mask{
  display:none;position:fixed;inset:0;z-index:60;
  background:rgba(20,25,35,.45);
  align-items:center;justify-content:center;padding:20px;
}
.mask.on{display:flex}
.modal{
  width:100%;max-width:620px;max-height:88vh;
  background:var(--bg);border-radius:12px;
  box-shadow:0 24px 60px rgba(0,0,0,.2);
  display:flex;flex-direction:column;
}
.modal-h{padding:18px 22px;font-size:16px;font-weight:600;border-bottom:1px solid var(--line)}
.modal-b{padding:18px 22px;overflow-y:auto}
.modal-f{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px;border-top:1px solid var(--line)}
.code-blk{border:1px solid var(--line);border-radius:8px;overflow:hidden;margin-bottom:14px}
.code-blk:last-child{margin-bottom:0}
.code-top{
  display:flex;align-items:center;justify-content:space-between;
  padding:7px 12px;background:#fafbfc;border-bottom:1px solid var(--line);
  font-size:13px;color:var(--mut);
}
.code-top .copy{
  height:28px;padding:0 12px;border:1px solid var(--line);border-radius:6px;
  background:var(--bg);color:var(--ink);font-size:12px;cursor:pointer;
}
.code-top .copy:hover{background:#f6f7f9}
.code-blk pre{
  margin:0;padding:12px 14px;background:var(--bg);
  font:12.5px/1.7 Consolas,"Courier New",monospace;color:#2b3440;
  overflow-x:auto;white-space:pre;
}
.done-box{text-align:center;padding:20px 0 4px}
.done-box svg{width:52px;height:52px;margin-bottom:12px}
.done-box h2{margin-bottom:4px}
@media (max-width:640px){
  .stage{padding:28px 14px;justify-content:flex-start}
  .panel{padding:20px 16px 22px}
  .frm{grid-template-columns:1fr}
  .fi input{font-size:16px;height:44px}
  .acts .btn{flex:1;min-width:0;white-space:nowrap}
  .agree-box{height:220px}
  .st .lb{display:none}
  .st.cur .lb{display:inline}
}
</style>
</head>
<body>

<div class="stage">
<div class="shell">

  <div class="brand">
    <img src="/assets/brand/logo.webp" alt="LOGO">
    <b>系统安装</b><span>Install Wizard</span>
  </div>

  <nav class="stepper" id="stepper">
    <div class="st cur" data-s="0"><span class="dot">1</span><span class="lb">服务协议</span></div>
    <div class="st" data-s="1"><span class="dot">2</span><span class="lb">环境检测</span></div>
    <div class="st" data-s="2"><span class="dot">3</span><span class="lb">数据配置</span></div>
    <div class="st" data-s="3"><span class="dot">4</span><span class="lb">站点配置</span></div>
    <div class="st" data-s="4"><span class="dot">5</span><span class="lb">开始安装</span></div>
  </nav>

  <section class="step on" data-p="0">
    <div class="panel">
      <div class="agree-box" id="agreeText"><?= _f17($AGREE_TEXT) ?></div>
      <div class="acts">
        <button class="btn" id="btnAgree" disabled>同意并继续</button>
      </div>
    </div>
  </section>

  <section class="step" data-p="1">
    <div class="panel">
      <div class="chk">
        <?php foreach ($checks as $c): ?>
            <div class="chk-row<?= $c[3] ? '' : ' bad' ?>">
                <span class="dot-s<?= $c[3] ? ' ok' : ' bad' ?>"></span>
                <span class="nm"><?= _f17($c[0]) ?></span>
                <span class="val"><?= _f17($c[1]) ?></span>
                <span class="val"><?= _f17($c[2]) ?></span>
            </div>
        <?php endforeach; ?>
      </div>
      <div class="form-err" id="envErr"></div>
      <div class="acts">
        <button class="btn ghost" id="btnEnvBack">上一步</button>
        <button class="btn sm ghost" id="btnRwCfg"<?= $rwOk ? ' style="display:none"' : '' ?>>伪静态配置</button>
        <button class="btn sm ghost" id="btnRechk">重新检测</button>
        <button class="btn" id="btnToDb"<?= $envOk ? '' : ' disabled' ?>>继续</button>
      </div>
    </div>
  </section>

  <section class="step" data-p="2">
    <div class="panel">
      <div class="frm">
        <div class="fi"><label for="dbHost">数据库地址</label><input id="dbHost" value="127.0.0.1"></div>
        <div class="fi"><label for="dbPort">端口</label><input id="dbPort" value="3306" inputmode="numeric"></div>
        <div class="fi"><label for="dbName">数据库名</label><input id="dbName"></div>
        <div class="fi"><label for="dbUser">用户名</label><input id="dbUser" value="root"></div>
        <div class="fi full"><label for="dbPass">密码</label><input id="dbPass" type="password" autocomplete="new-password"></div>
        <div class="fi"><label for="dbPre">表前缀</label><input id="dbPre" value=""></div>
        <div class="fi"><label for="dbCharset">字符集</label>
          <select id="dbCharset"><option>utf8mb4</option><option>utf8</option></select>
        </div>
      </div>
      <div class="form-err" id="dbErr"></div>
      <div class="acts">
        <button class="btn ghost" id="btnDbBack">上一步</button>
        <button class="btn ghost" id="btnDbTest">测试连接</button>
        <button class="btn" id="btnDbNext" disabled>继续</button>
      </div>
    </div>
  </section>

  <section class="step" data-p="3">
    <div class="panel">
      <div class="frm">
        <div class="fi full"><label for="stName">站点名称</label><input id="stName" value="<?= _f17(SITE_NAME_FALLBACK) ?>" maxlength="40"></div>
        <div class="fi full"><label for="stUrl">站点地址</label><input id="stUrl" value="<?= _f17($scheme . '://' . $host) ?>"></div>
        <div class="fi"><label for="stAdmin">管理员账号</label><input id="stAdmin" value="admin" autocomplete="username"></div>
        <div class="fi"><label for="stPass">管理员密码</label><input id="stPass" type="password" minlength="8" autocomplete="new-password"></div>
        <div class="fi"><label for="stPass2">确认密码</label><input id="stPass2" type="password" autocomplete="new-password"></div>
      </div>
      <div class="form-err" id="stErr"></div>
      <div class="acts">
        <button class="btn ghost" id="btnStBack">上一步</button>
        <button class="btn" id="btnStNext">开始安装</button>
      </div>
    </div>
  </section>

  <section class="step" data-p="4">
    <div class="panel" id="panelRun">
      <div class="pbar"><i id="pfill"></i></div>
      <div id="taskList"></div>
      <div class="form-err" id="runErr"></div>
      <div class="acts">
        <button class="btn ghost" id="btnRunBack">上一步</button>
      </div>
    </div>
    <div class="panel done-box" id="panelDone" style="display:none">
      <svg viewBox="0 0 56 56" fill="none" aria-hidden="true">
        <circle cx="28" cy="28" r="26" fill="#e8f6ee"/>
        <path d="M17 29l8 8 14-16" stroke="#189a58" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      </svg>
      <h2>安装完成</h2>
      <div class="acts">
        <a class="btn" href="/">进入后台</a>
      </div>
    </div>
  </section>

</div>
</div>

<div class="mask" id="rwMask">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rwTitle">
    <div class="modal-h" id="rwTitle">伪静态配置</div>
    <div class="modal-b">
      <div class="code-blk">
        <div class="code-top"><span>nginx</span><button class="copy" type="button" data-copy="rwNginx">复制</button></div>
        <pre id="rwNginx">location / {
    try_files $uri $uri/ /index.php?s=$uri&amp;$args;
}</pre>
      </div>
      <div class="code-blk">
        <div class="code-top"><span>Apache</span><button class="copy" type="button" data-copy="rwApache">复制</button></div>
        <pre id="rwApache">RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?s=$1 [QSA,PT,L]</pre>
      </div>
    </div>
    <div class="modal-f">
      <button class="btn ghost" id="btnRwClose">关闭</button>
      <button class="btn" id="btnRwRechk">重新检测</button>
    </div>
  </div>
</div>

<script>
(function(){
  var $ = function(id){ return document.getElementById(id); };
  var CSRF = <?= json_encode($csrf) ?>;

  /* 步骤切换 */
  var cur = 0;
  function show(n){
    cur = n;
    var steps = document.querySelectorAll('.step');
    for (var i = 0; i < steps.length; i++) steps[i].classList.toggle('on', i === n);
    var sts = document.querySelectorAll('.st');
    for (var i = 0; i < sts.length; i++) {
      var s = sts[i];
      s.classList.toggle('cur', i === n);
      s.classList.toggle('done', i < n);
      s.querySelector('.dot').textContent = i < n ? '✓' : String(i + 1);
    }
  }

  /* 第 1 步：协议倒计时 */
  var left = 5;
  var btnAgree = $('btnAgree');
  btnAgree.textContent = '同意并继续（' + left + 's）';
  var timer = setInterval(function(){
    left--;
    if (left <= 0) {
      clearInterval(timer);
      btnAgree.disabled = false;
      btnAgree.textContent = '同意并继续';
    } else {
      btnAgree.textContent = '同意并继续（' + left + 's）';
    }
  }, 1000);
  btnAgree.addEventListener('click', function(){ show(1); });
  $('btnEnvBack').addEventListener('click', function(){ show(0); });

  /* 第 2 步：环境检测。重新检测走接口就地重画，不重载整页 ——
     重载会把进度打回第 1 步、协议倒计时重来，用户回不到刚才的位置。 */
  function esc(s){
    return String(s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function renderEnv(d){
    var html = '';
    d.checks.forEach(function(c){
      html += '<div class="chk-row' + (c.ok ? '' : ' bad') + '">'
        + '<span class="dot-s ' + (c.ok ? 'ok' : 'bad') + '"></span>'
        + '<span class="nm">' + esc(c.n) + '</span>'
        + '<span class="val">' + esc(c.r) + '</span>'
        + '<span class="val">' + esc(c.v) + '</span>'
        + '</div>';
    });
    document.querySelector('.chk').innerHTML = html;
    $('btnToDb').disabled = !d.ok;
    $('btnRwCfg').style.display = d.rewrite ? 'none' : '';
    if (d.rewrite) closeRw();
  }
  function recheck(){
    var btn = $('btnRechk');
    btn.disabled = true;
    btn.textContent = '检测中';
    $('envErr').textContent = '';
    fetch('?act=env', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF })
    }).then(function(r){ return r.json(); }).then(function(j){
      btn.disabled = false;
      btn.textContent = '重新检测';
      if (j.ok) {
        renderEnv(j.data);
      } else {
        $('envErr').textContent = j.msg;
      }
    }).catch(function(){
      btn.disabled = false;
      btn.textContent = '重新检测';
      $('envErr').textContent = '网络没通，再试一次。';
    });
  }
  $('btnRechk').addEventListener('click', recheck);
  $('btnToDb').addEventListener('click', function(){ show(2); });

  /* 伪静态配置弹窗 */
  function openRw(){ $('rwMask').classList.add('on'); }
  function closeRw(){ $('rwMask').classList.remove('on'); }
  $('btnRwCfg').addEventListener('click', openRw);
  $('btnRwClose').addEventListener('click', closeRw);
  $('btnRwRechk').addEventListener('click', function(){ closeRw(); recheck(); });
  $('rwMask').addEventListener('click', function(e){ if (e.target === this) closeRw(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeRw(); });
  Array.prototype.forEach.call(document.querySelectorAll('.code-top .copy'), function(b){
    b.addEventListener('click', function(){
      var txt = $(b.getAttribute('data-copy')).textContent;
      var ok = function(){ b.textContent = '已复制'; setTimeout(function(){ b.textContent = '复制'; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(ok).catch(ok);
      } else {
        var ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        ok();
      }
    });
  });

  /* 第 3 步：数据库 */
  $('btnDbBack').addEventListener('click', function(){ show(1); });
  $('btnDbTest').addEventListener('click', function(){
    var btn = this;
    $('dbErr').textContent = '';
    btn.disabled = true;
    btn.textContent = '测试中';
    fetch('?act=test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: CSRF,
        db_host: $('dbHost').value.trim(),
        db_port: $('dbPort').value.trim(),
        db_name: $('dbName').value.trim(),
        db_user: $('dbUser').value.trim(),
        db_pass: $('dbPass').value
      })
    }).then(function(r){ return r.json(); }).then(function(j){
      btn.disabled = false;
      btn.textContent = '测试连接';
      if (j.ok) {
        $('btnDbNext').disabled = false;
        $('dbErr').textContent = '';
      } else {
        $('dbErr').textContent = j.msg;
      }
    }).catch(function(){
      btn.disabled = false;
      btn.textContent = '测试连接';
      $('dbErr').textContent = '网络没通，再试一次。';
    });
  });
  $('btnDbNext').addEventListener('click', function(){ show(3); });

  /* 第 4 步：站点配置 → 提交安装 */
  $('btnStBack').addEventListener('click', function(){ show(2); });
  $('btnStNext').addEventListener('click', function(){
    var err = $('stErr');
    err.textContent = '';
    var name = $('stName').value.trim();
    var url = $('stUrl').value.trim();
    var adm = $('stAdmin').value.trim();
    var pwd = $('stPass').value;
    if (!name || !adm) { err.textContent = '站点名称和管理员账号都要填。'; return; }
    if (!/^https?:\/\//i.test(url)) { err.textContent = '站点地址要以 http:// 或 https:// 开头。'; return; }
    if (pwd.length < 8) { err.textContent = '管理员密码至少 8 位。'; return; }
    if (pwd !== $('stPass2').value) { err.textContent = '两次输入的管理员密码不一样。'; return; }

    var btn = this;
    btn.disabled = true;
    show(4);
    runInstall({
      csrf: CSRF,
      db_host: $('dbHost').value.trim(),
      db_port: $('dbPort').value.trim(),
      db_name: $('dbName').value.trim(),
      db_user: $('dbUser').value.trim(),
      db_pass: $('dbPass').value,
      db_pre: $('dbPre').value.trim(),
      db_charset: $('dbCharset').value,
      site_name: name,
      site_url: url,
      st_admin: adm,
      st_pass: pwd,
      st_pass2: $('stPass2').value
    });
    btn.disabled = false;
  });
  $('btnRunBack').addEventListener('click', function(){ show(3); });

  /* 第 5 步：安装进度。任务清单先摆出来，服务端返回后逐条点亮 */
  var TASKS = ['连接数据库', '创建数据库', '建表', '写入站点配置', '创建管理员账号', '写入配置文件', '写入安装锁'];
  function renderTasks(idx, badIdx){
    var html = '';
    for (var i = 0; i < TASKS.length; i++) {
      var cls = '', st = '', txt = '待执行';
      if (badIdx === i)       { cls = ' bad'; st = 'bad'; txt = '失败'; }
      else if (i < idx)       { cls = ' ok'; st = 'ok'; txt = '完成'; }
      else if (i === idx)     { st = 'ok'; txt = '执行中'; }
      html += '<div class="ptask' + cls + '"><span class="dot-s ' + st + '"></span>'
        + '<span>' + TASKS[i] + '</span><span class="st-t">' + txt + '</span></div>';
    }
    $('taskList').innerHTML = html;
    $('pfill').style.width = Math.round(idx / TASKS.length * 100) + '%';
  }
  function runInstall(payload){
    renderTasks(0, -1);
    fetch('?act=install', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json(); }).then(function(j){
      var notes = (j.data && j.data.notes) || [];
      if (j.ok) {
        /* 服务端每一步都真实跑完了，按它回报的清单逐条点亮 */
        var done = 0;
        var iv = setInterval(function(){
          done++;
          renderTasks(done, -1);
          if (done >= TASKS.length) {
            clearInterval(iv);
            $('panelRun').style.display = 'none';
            $('panelDone').style.display = 'block';
          }
        }, 260);
      } else {
        var k = TASKS.length;
        for (var i = 0; i < notes.length; i++) {
          var t = TASKS.indexOf(notes[i]);
          if (t >= 0) k = t;
        }
        renderTasks(k, k);
        $('runErr').textContent = j.msg;
      }
    }).catch(function(){
      renderTasks(0, 0);
      $('runErr').textContent = '网络没通，再试一次。';
    });
  }

  show(0);
})();
</script>
</body>
</html>
