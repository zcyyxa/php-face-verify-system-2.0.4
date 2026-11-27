<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\Calls;
use app\service\Image;
use app\service\Plugin;
use app\service\Settings;
use app\service\Task;
use think\Response;

final class ApiV4 extends BaseController
{
    public function facehy(): Response
    {
        if (($pre = $this->preflight()) !== null) {
            return $pre;
        }
        if (!$this->method(['GET', 'POST'])) {
            return $this->out(Calls::ctx('facehy'), 405, 405, '这个地址只收 GET / POST。');
        }
        $ctx = Calls::ctx('facehy');
        if (($gate = $this->gate($ctx)) !== null) {
            return $gate;
        }
        if (!Plugin::compareReady()) {
            return $this->out($ctx, 503, 503, '接口还没配置好上游服务，暂时不可用。');
        }
        $p = $this->person($ctx);
        if ($p instanceof Response) {
            return $p;
        }
        [$name, $idc] = $p;
        $img = $this->faceImage($ctx);
        if ($img instanceof Response) {
            return $img;
        }

        if ($img['url'] === '' && $img['base64'] !== '') {
            $st = Image::store(['base64' => $img['base64'], 'mime' => $img['mime']], 'direct:' . sha1($img['base64']));
            if (empty($st['ok'])) {
                return $this->out($ctx, 400, 400, 'file_base64 不是有效的 JPG / PNG。');
            }
            $img['url']    = _f1((string)$st['rel']);
            $img['base64'] = (string)$st['b64'];
            $img['mime']   = (string)$st['mime'];
        } elseif ($img['url'] !== '' && $img['base64'] === '') {
            $st = Image::store(['url' => $img['url']], 'direct:' . sha1($img['url']));
            if (!empty($st['ok'])) {
                $img['base64'] = (string)$st['b64'];
                $img['mime']   = (string)$st['mime'];
            }
        }

        $r = Plugin::compare([
            'name'             => $name,
            'idcard'           => $idc,
            'image_url'        => (string)$img['url'],
            'image_base64'     => (string)$img['base64'],
            'image_mime'       => (string)$img['mime'],
            'image_source_url' => (string)$img['url'],
        ]);
        $ctx['up_req']  = (string)$r['raw']['req'];
        $ctx['up_resp'] = (string)$r['raw']['resp'];

        if (!$r['ok']) {
            return $this->out($ctx, 502, 502, '上游服务暂时不可用，稍后再试。');
        }
        if ($r['passed'] === null) {
            return $this->out($ctx, 502, 502, '上游返回的内容不完整，稍后再试。');
        }
        $verify = (int)$r['passed'];
        $score  = $r['score'] === null ? 0 : (int)round((float)$r['score']);
        $score  = max(0, min(100, $score));
        $say    = Plugin::cleanSay((string)$r['msg'], $verify === 1 ? '一致。' : '不一致。');
        return $this->out($ctx, 200, 200, $say, [
            'score'   => $score,
            'verify'  => $verify,
            'message' => $say,
        ]);
    }

    public function faceStart(): Response
    {
        if (($pre = $this->preflight()) !== null) {
            return $pre;
        }
        if (!$this->method(['POST'])) {
            return $this->out(Calls::ctx('face_start'), 405, 405, '这个地址只收 POST。');
        }
        $ctx = Calls::ctx('face_start');
        if (($gate = $this->gate($ctx)) !== null) {
            return $gate;
        }
        if (!Plugin::ready()) {
            return $this->out($ctx, 503, 503, '接口还没配置好上游服务，暂时不可用。');
        }
        $p = $this->person($ctx);
        if ($p instanceof Response) {
            return $p;
        }
        [$name, $idc] = $p;
        $living = $this->livingType($ctx);
        if ($living instanceof Response) {
            return $living;
        }
        $back = $this->returnUrlOpt($ctx);
        if ($back instanceof Response) {
            return $back;
        }

        $task   = Task::new('v4', $name, $idc);
        $taskId = (int)$task['id'];
        if ($back === '') {
            $back = _f23() . '?t=' . rawurlencode((string)$task['token']);
        }

        $cmd = Plugin::start([
            'name'        => $name,
            'idcard'      => $idc,
            'return_url'  => $back,
            'living_type' => $living,
            'ip'          => _f9(),
        ]);
        $ctx['up_req']  = (string)$cmd['raw']['req'];
        $ctx['up_resp'] = (string)$cmd['raw']['resp'];

        if (!$cmd['ok']) {
            if ($taskId > 0) {
                Task::delete($taskId);
            }
            if (!empty($cmd['retry'])) {
                return $this->out($ctx, 502, 502, '上游服务暂时连不上，稍后再试。');
            }
            $msg = (string)$cmd['msg'] !== '' ? (string)$cmd['msg'] : '上游服务暂时不可用，稍后再试。';
            return $this->out($ctx, 502, 502, $msg);
        }

        $order = (string)$cmd['order_no'];
        Task::update($taskId, [
            'order_no' => $order,
            'state'    => 'wait',
            'code'     => 1001,
            'msg'      => '让用户去做人脸采集；活体出图之后本站自动做实名比对。',
        ]);
        $ctx['order_no'] = $order;
        $real = trim((string)$cmd['living_type']);
        $say  = Settings::siteName() . '：打开 verify_url 完成活体，再用 order_no 查询结果。';
        return $this->out($ctx, 200, 200, $say, [
            'order_no'    => $order,
            'verify_url'  => (string)$cmd['verify_url'],
            'biz_id'      => (string)$cmd['biz_id'],
            'living_type' => $real !== '' ? $real : $living,
        ]);
    }

    public function faceQuery(): Response
    {
        if (($pre = $this->preflight()) !== null) {
            return $pre;
        }
        if (!$this->method(['POST'])) {
            return $this->out(Calls::ctx('face_query'), 405, 405, '这个地址只收 POST。');
        }
        $ctx = Calls::ctx('face_query');
        if (($gate = $this->gate($ctx)) !== null) {
            return $gate;
        }
        if (!Plugin::ready()) {
            return $this->out($ctx, 503, 503, '接口还没配置好上游服务，暂时不可用。');
        }
        $order = $this->orderNo($ctx);
        if ($order instanceof Response) {
            return $order;
        }
        $ctx['order_no'] = $order;

        $task = Task::byOrder($order);
        if ($task === null) {
            return $this->out($ctx, 404, 404, '没有这一单，重新发起一次就行。');
        }
        $res  = Task::advance($task, 15);
        $task = $res['row'];
        $ctx['up_req']  = (string)$res['up_req'];
        $ctx['up_resp'] = (string)$res['up_resp'];

        if ((string)$task['state'] === 'wait' || !empty($res['retry'])) {
            return $this->out($ctx, 503, 503, '结果还没出来，等几秒再查。');
        }
        if ((string)$task['state'] === 'error') {
            $msg = (string)$task['msg'] !== '' ? (string)$task['msg'] : '这一次没拿到结论，重新发起一单就行。';
            $hc  = (int)$task['code'] === 4002 ? 504 : 502;
            return $this->out($ctx, $hc, $hc, $msg);
        }

        $img = (string)$task['image_rel'] !== '' ? _f1((string)$task['image_rel']) : '';
        $say = (string)$task['msg'] !== '' ? (string)$task['msg'] : ((int)$task['passed'] === 1 ? '核验通过。' : '核验不通过。');
        $failCode = (int)$task['code'];
        $resultCode = 'FACE_MISMATCH';
        if ((int)$task['passed'] === 1) {
            $resultCode = 'OK';
        } elseif ($failCode === 2009) {
            $resultCode = 'LIVENESS_FAIL';
        } elseif ($failCode === 2008) {
            $resultCode = 'COMPARE_ERROR';
        }
        $data = [
            'order_no'    => $order,
            'passed'      => (int)$task['passed'] === 1 ? 1 : 0,
            'result_code' => $resultCode,
            'image_url'   => $img,
            'message'     => $say,
        ];
        if ($task['score'] !== null && $task['score'] !== '') {
            $data['score'] = (float)$task['score'];
        }
        return $this->out($ctx, 200, 200, $say, $data);
    }


    private function cors(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'X-App-Id, X-App-Key, Content-Type',
            'Access-Control-Max-Age'       => '600',
            'X-Content-Type-Options'       => 'nosniff',
            'Cache-Control'                => 'no-store',
        ];
    }

    private function preflight(): ?Response
    {
        if (strtoupper((string)$this->request->method()) !== 'OPTIONS') {
            return null;
        }
        return response('', 204, $this->cors());
    }

    private function method(array $allow): bool
    {
        return in_array(strtoupper((string)$this->request->method()), $allow, true);
    }

    private function out(array $ctx, int $http, int $code, string $msg, array $data = []): Response
    {
        $env = [
            'code'    => $code,
            'msg'     => $msg,
            'data'    => $data === [] ? (object)[] : $data,
            'trace'   => (string)$ctx['trace'],
            'cost'    => 0,
            'balance' => null,
        ];
        $json = (string)json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Calls::store($ctx, $http, $code, $msg, $json);
        return response($json, $http, $this->cors() + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function gate(array $ctx): ?Response
    {
        if (Settings::needUpgrade()) {
            return $this->out($ctx, 503, 503, '服务正在升级，稍后再试。');
        }
        $id = trim((string)($this->request->header('x-app-id', '') ?: ($_POST['appid'] ?? ($_GET['appid'] ?? ''))));
        $key = trim((string)($this->request->header('x-app-key', '') ?: ($_POST['appkey'] ?? ($_GET['appkey'] ?? ''))));
        if ($id === '' || $key === '') {
            return $this->out($ctx, 401, 401, 'AppID 和 AppKey 都要带：请求头 X-App-Id / X-App-Key，也可以放在 appid / appkey 参数里。');
        }
        $wantId  = (string)Settings::get('api_id', '');
        $wantKey = (string)Settings::get('api_key', '');
        if ($wantId === '' || $wantKey === '') {
            return $this->out($ctx, 503, 503, '接口还没配置密钥，先去管理页生成一把。');
        }
        $okId  = hash_equals($wantId, $id);
        $okKey = hash_equals($wantKey, $key);
        if (!$okId || !$okKey) {
            return $this->out($ctx, 401, 401, 'AppID 或 AppKey 不对。');
        }
        return null;
    }

    private function person(array $ctx): array|Response
    {
        $name = trim((string)($_POST['name'] ?? ($_GET['name'] ?? '')));
        $idc  = trim((string)($_POST['idcard'] ?? ($_GET['idcard'] ?? '')));
        if (!_f37($name)) {
            return $this->out($ctx, 400, 400, 'name 没给，或者超过 30 个字。');
        }
        if (!_f36($idc)) {
            return $this->out($ctx, 400, 400, 'idcard 要是 18 位身份证号，末位可以是 X。');
        }
        return [$name, strtoupper($idc)];
    }

    private function faceImage(array $ctx): array|Response
    {
        $url = trim((string)($_POST['file_url'] ?? ($_GET['file_url'] ?? '')));
        $b64 = trim((string)($_POST['file_base64'] ?? ($_POST['image_base64'] ?? ($_GET['file_base64'] ?? ($_GET['image_base64'] ?? '')))));
        if ($url !== '' && (!_f35($url) || strlen($url) > 1024)) {
            return $this->out($ctx, 400, 400, 'file_url 要是 http/https 开头的人脸图公网地址。');
        }
        if ($url === '' && $b64 === '') {
            return $this->out($ctx, 400, 400, 'file_url 和 file_base64 至少给一个。');
        }
        $mime = '';
        if ($b64 !== '' && stripos($b64, 'data:') === 0 && strpos($b64, ';') !== false) {
            $mime = substr($b64, 5, (int)strpos($b64, ';') - 5);
        }
        return ['url' => $url, 'base64' => $b64, 'mime' => $mime];
    }

    private function livingType(array $ctx): string|Response
    {
        $t = trim((string)($_POST['living_type'] ?? ($_POST['livingType'] ?? ($_GET['living_type'] ?? ($_GET['livingType'] ?? '')))));
        if ($t === '') {
            return '';
        }
        if (!preg_match('~^[1-6]+$~', $t) || strlen($t) > 6) {
            return $this->out($ctx, 400, 400, 'living_type 只能是 1-6 的组合，比如 26。');
        }
        if (count(array_unique(str_split($t))) !== strlen($t)) {
            return $this->out($ctx, 400, 400, 'living_type 里同一个动作不要重复。');
        }
        if (strpos($t, '6') !== false && substr($t, -1) !== '6') {
            return $this->out($ctx, 400, 400, 'living_type 里的 6（炫彩）必须放在最后。');
        }
        return $t;
    }

    private function returnUrlOpt(array $ctx): string|Response
    {
        $r = trim((string)($_POST['return_url'] ?? ($_POST['returnUrl'] ?? ($_GET['return_url'] ?? ($_GET['returnUrl'] ?? '')))));
        if ($r === '') {
            $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
            if ($ref !== '' && _f35($ref)) {
                $r = $ref;
            }
        }
        if ($r === '') {
            return '';
        }
        if (!_f35($r) || strlen($r) > 512) {
            return $this->out($ctx, 400, 400, 'return_url 要是 http/https 开头的地址，最多 512 个字符。');
        }
        return $r;
    }

    private function orderNo(array $ctx): string|Response
    {
        $o = trim((string)($_POST['order_no'] ?? ($_POST['orderNo'] ?? ($_GET['order_no'] ?? ($_GET['orderNo'] ?? '')))));
        if ($o === '') {
            return $this->out($ctx, 400, 400, 'order_no 没给。');
        }
        if (strlen($o) > 64) {
            return $this->out($ctx, 400, 400, 'order_no 太长，最多 64 个字符。');
        }
        return $o;
    }
}
