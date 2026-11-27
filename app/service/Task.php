<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

final class Task
{
    public static function new(string $kind, string $name = '', string $idcard = ''): array
    {
        self::sweep();
        $now    = date('Y-m-d H:i:s');
        $token  = bin2hex(random_bytes(16));
        $expire = date('Y-m-d H:i:s', time() + Settings::liveTtl());
        $live   = Plugin::active('liveness');
        $cmp    = Plugin::active('compare');
        $id     = (int)Db::name('live_tasks')->insertGetId([
            'token'       => $token,
            'kind'        => $kind,
            'name'        => $name,
            'idcard'      => $idcard,
            'order_no'    => '',
            'state'       => 'wait',
            'code'        => 0,
            'msg'         => '',
            'score'       => null,
            'passed'      => null,
            'cmp_done'    => 0,
            'image_rel'   => '',
            'image_b64'   => '',
            'image_mime'  => '',
            'image_up'    => '',
            'live_plugin' => $live,
            'cmp_plugin'  => $cmp,
            'created_at'  => $now,
            'updated_at'  => $now,
            'expire_at'   => $expire,
        ]);
        return [
            'id'          => $id,
            'token'       => $token,
            'kind'        => $kind,
            'name'        => $name,
            'idcard'      => $idcard,
            'order_no'    => '',
            'state'       => 'wait',
            'code'        => 0,
            'msg'         => '',
            'score'       => null,
            'passed'      => null,
            'cmp_done'    => 0,
            'image_rel'   => '',
            'image_b64'   => '',
            'image_mime'  => '',
            'image_up'    => '',
            'live_plugin' => $live,
            'cmp_plugin'  => $cmp,
            'created_at'  => $now,
            'updated_at'  => $now,
            'expire_at'   => $expire,
        ];
    }

    public static function byToken(string $token): ?array
    {
        if (!preg_match('~^[a-f0-9]{32}$~', $token)) {
            return null;
        }
        $r = Db::name('live_tasks')->where('token', $token)->find();
        return is_array($r) ? $r : null;
    }

    public static function byOrder(string $order): ?array
    {
        $order = trim($order);
        if ($order === '' || strlen($order) > 64) {
            return null;
        }
        $r = Db::name('live_tasks')->where('order_no', $order)->order('id', 'desc')->find();
        return is_array($r) ? $r : null;
    }

    public static function update(int $id, array $f): void
    {
        $allow = [
            'name', 'idcard', 'order_no', 'state', 'code', 'msg', 'score', 'passed',
            'cmp_done', 'image_rel', 'image_b64', 'image_mime', 'image_up', 'live_plugin', 'cmp_plugin',
        ];
        $patch = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $f)) {
                $patch[$k] = $f[$k];
            }
        }
        if ($patch === []) {
            return;
        }
        $patch['updated_at'] = date('Y-m-d H:i:s');
        Db::name('live_tasks')->where('id', $id)->update($patch);
    }

    public static function delete(int $id): void
    {
        Db::name('live_tasks')->where('id', $id)->delete();
    }

    public static function sweep(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $ids = Db::name('live_tasks')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - 86400))
                ->order('id', 'asc')
                ->limit(200)
                ->column('id');
            if ($ids) {
                Db::name('live_tasks')->whereIn('id', $ids)->delete();
            }
        } catch (Throwable $ex) {
            error_log('[task_sweep] ' . $ex->getMessage());
        }
    }

    public static function expired(array $row): bool
    {
        $created = strtotime((string)($row['created_at'] ?? ''));
        if ($created !== false && $created + Settings::liveTtl() < time()) {
            return true;
        }
        $t = strtotime((string)($row['expire_at'] ?? ''));
        return $t !== false && $t < time();
    }

    private static function joinUp(string $a, string $b): string
    {
        if ($a === '') {
            return $b;
        }
        if ($b === '') {
            return $a;
        }
        return $a . "\n\n===== 实名比对 =====\n\n" . $b;
    }

    private static function fetchLiveness(array $row, int $timeout = 20): array
    {
        $out = [
            'state' => 'error', 'code' => 4001, 'msg' => '这一次没拿到结论，重新发起一单就行。',
            'passed' => null, 'score' => null, 'image_rel' => '', 'image_b64' => '', 'image_mime' => '',
            'image_up' => '', 'image_err' => '', 'up_req' => '', 'up_resp' => '', 'http' => 0, 'retry' => false,
        ];
        $order = (string)($row['order_no'] ?? '');
        $lv    = Plugin::query($order, (string)($row['live_plugin'] ?? ''), [
            'name'    => (string)($row['name'] ?? ''),
            'idcard'  => (string)($row['idcard'] ?? ''),
            'timeout' => $timeout,
        ]);
        $out['up_req']  = (string)$lv['raw']['req'];
        $out['up_resp'] = (string)$lv['raw']['resp'];
        $out['http']    = (int)$lv['raw']['http'];

        if ((string)$lv['plugin'] === '') {
            $out['code'] = 4003;
            $out['msg']  = '活体上游没配置好，本次认证失败，请重新发起。';
            return $out;
        }
        if (!empty($lv['retry'])) {
            $out['retry'] = true;
            return $out;
        }

        $state = (string)$lv['state'];
        if ($state === 'wait') {
            $out['state'] = 'wait';
            $out['code']  = 1002;
            $out['msg']   = '结果还没出来，等几秒再刷新。';
            return $out;
        }
        if ($state === 'fail') {
            $out['state']  = 'fail';
            $out['code']   = 2009;
            $out['passed'] = 0;
            $out['msg']    = '活体检测没通过，没有可比对的人脸图，重新做一次。';
            if ($lv['score'] !== null) {
                $out['score'] = $lv['score'];
            }
            return $out;
        }
        if ($state !== 'pass') {
            $out['code'] = (int)$lv['raw']['http'] === 400 ? 4003 : 4001;
            $out['msg']  = (int)$lv['raw']['http'] === 400
                ? '上游没受理这一单，重新发起一次就行。'
                : '这一次没拿到结论，重新发起一单就行。';
            return $out;
        }

        $out['state']  = 'live_pass';
        $out['code']   = 1008;
        $out['passed'] = 1;
        $out['msg']    = '活体检测过了，正在拿这张脸做实名比对。';
        if ($lv['score'] !== null) {
            $out['score'] = $lv['score'];
        }

        $img = $lv['image'];
        if (!is_array($img)) {
            $out['image_err'] = 'missing';
            return $out;
        }
        $store = Image::store($img, 'order:' . $order);
        if (empty($store['ok'])) {
            $out['image_err'] = (string)$store['err'] === 'missing' ? 'missing' : 'save';
            return $out;
        }
        $out['image_rel']  = (string)$store['rel'];
        $out['image_b64']  = (string)$store['b64'];
        $out['image_mime'] = (string)$store['mime'];
        $out['image_up']   = (string)($img['url'] ?? '');
        return $out;
    }

    private static function compare(string $name, string $idcard, array $img, int $timeout = 20, string $plugin = ''): array
    {
        $out = [
            'state' => 'error', 'code' => 4001, 'msg' => '这一次没拿到结论，重新发起一单就行。',
            'passed' => null, 'score' => null, 'result_code' => '', 'up_req' => '', 'up_resp' => '', 'http' => 0, 'retry' => false,
        ];
        $r = Plugin::compare([
            'name'             => $name,
            'idcard'           => $idcard,
            'image_url'        => (string)($img['url'] ?? ''),
            'image_base64'     => (string)($img['base64'] ?? ''),
            'image_mime'       => (string)($img['mime'] ?? ''),
            'image_source_url' => (string)($img['source_url'] ?? ''),
        ], $plugin);
        $out['up_req']  = (string)$r['raw']['req'];
        $out['up_resp'] = (string)$r['raw']['resp'];
        $out['http']    = (int)$r['raw']['http'];

        if ($r['error'] === 'no_plugin' || $r['error'] === 'no_handler') {
            $out['state']  = 'fail';
            $out['code']   = 2008;
            $out['passed'] = 0;
            $out['msg']    = '实名核验上游没配置好，本次不通过，请重新发起。';
            return $out;
        }
        if (!$r['ok']) {
            $out['state']  = 'fail';
            $out['code']   = 2008;
            $out['passed'] = 0;
            $out['msg']    = '实名比对没完成，本次不通过，请重新发起。';
            return $out;
        }
        if ($r['passed'] === null) {
            $out['state']  = 'fail';
            $out['code']   = 2008;
            $out['passed'] = 0;
            $out['msg']    = '实名比对返回的结果看不懂，本次不通过，请重新发起。';
            return $out;
        }
        $pass = (int)$r['passed'] === 1;
        $out['state']  = $pass ? 'pass' : 'fail';
        $out['code']   = $pass ? 2001 : 2002;
        $out['passed'] = $pass ? 1 : 0;
        $out['msg']    = $pass ? '核验通过。' : '核验不通过：不是同一个人。';
        $out['result_code'] = $pass ? 'OK' : 'FACE_MISMATCH';
        if ($r['score'] !== null) {
            $out['score'] = $r['score'];
        }
        return $out;
    }

    public static function applyResult(array $row, array $res): array
    {
        $patch = [
            'state'     => (string)$res['state'],
            'code'      => (int)$res['code'],
            'msg'       => mb_substr((string)$res['msg'], 0, 250, 'UTF-8'),
            'score'     => $res['score'],
            'passed'    => $res['passed'],
            'image_rel' => (string)($res['image_rel'] ?? ''),
        ];
        foreach (['image_b64', 'image_mime', 'image_up'] as $k) {
            if (array_key_exists($k, $res)) {
                $patch[$k] = (string)$res[$k];
            }
        }
        if (array_key_exists('cmp_done', $res)) {
            $patch['cmp_done'] = (int)$res['cmp_done'];
        }
        self::update((int)$row['id'], $patch);
        return array_merge($row, $patch, ['updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function markExpired(array $row): array
    {
        return self::applyResult($row, [
            'state' => 'error', 'code' => 4002, 'msg' => '核验超时，本次认证失败，请重新发起。',
            'score' => null, 'passed' => null, 'image_rel' => '', 'image_b64' => '', 'image_mime' => '', 'image_up' => '',
        ]);
    }

    public static function claimCompare(int $id): bool
    {
        try {
            $n = Db::name('live_tasks')
                ->where('id', $id)
                ->where('cmp_done', 0)
                ->update(['cmp_done' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            return (int)$n === 1;
        } catch (Throwable $ex) {
            error_log('[task_claim] ' . $ex->getMessage());
            return false;
        }
    }

    public static function advance(array $row, int $timeout = 20): array
    {
        $out = ['row' => $row, 'up_req' => '', 'up_resp' => '', 'retry' => false];

        if ((string)$row['state'] !== 'wait') {
            return $out;
        }
        if ((string)$row['order_no'] === '') {
            $out['row'] = self::applyResult($row, [
                'state' => 'error', 'code' => 4001, 'msg' => '这一单还没拿到上游单号，重新发起一次就行。',
                'score' => null, 'passed' => null, 'image_rel' => '',
            ]);
            return $out;
        }
        if (self::expired($row)) {
            $out['row'] = self::markExpired($row);
            return $out;
        }

        if ((int)($row['cmp_done'] ?? 0) === 1) {
            $upd = strtotime((string)($row['updated_at'] ?? ''));
            if ($upd !== false && time() - $upd < 60) {
                $out['retry'] = true;
                return $out;
            }
            $out['row'] = self::applyResult($row, [
                'state' => 'fail', 'code' => 2008, 'msg' => '实名比对没有完成，本次不通过，请重新发起。',
                'score' => null, 'passed' => 0, 'image_rel' => (string)($row['image_rel'] ?? ''), 'cmp_done' => 1,
            ]);
            return $out;
        }

        $live = self::fetchLiveness($row, $timeout);
        $out['up_req']  = (string)$live['up_req'];
        $out['up_resp'] = (string)$live['up_resp'];

        if (!empty($live['retry'])) {
            $out['retry'] = true;
            return $out;
        }
        if ($live['state'] === 'wait') {
            $out['row'] = self::applyResult($row, $live);
            return $out;
        }
        if ($live['state'] !== 'live_pass') {
            $out['row'] = self::applyResult($row, $live);
            return $out;
        }

        if ((string)$live['image_rel'] === '') {
            $out['row'] = self::applyResult($row, [
                'state' => 'error', 'code' => 2005,
                'msg' => $live['image_err'] === 'save'
                    ? '活体过了，但人脸图没存下来，重新做一次。'
                    : '活体过了，但上游没给人脸图，重新做一次。',
                'score' => null, 'passed' => null, 'image_rel' => '',
                'image_b64' => '', 'image_mime' => '', 'image_up' => '', 'cmp_done' => 1,
            ]);
            return $out;
        }

        $name = trim((string)($row['name'] ?? ''));
        $idc  = trim((string)($row['idcard'] ?? ''));
        if ($name === '' || $idc === '') {
            $out['row'] = self::applyResult($row, [
                'state' => 'error', 'code' => 4001, 'msg' => '这一单没带姓名或证件号，重新发起一次就行。',
                'score' => null, 'passed' => null, 'image_rel' => (string)$live['image_rel'], 'cmp_done' => 1,
            ]);
            return $out;
        }

        if (!self::claimCompare((int)$row['id'])) {
            $out['retry'] = true;
            return $out;
        }

        $img = [
            'url'        => _f1((string)$live['image_rel']),
            'base64'     => (string)$live['image_b64'],
            'mime'       => (string)$live['image_mime'],
            'source_url' => (string)$live['image_up'],
        ];
        if ($img['base64'] === '' && (string)$live['image_rel'] !== '') {
            $b = Image::b64OfRel((string)$live['image_rel']);
            if (!empty($b['ok'])) {
                $img['base64'] = (string)$b['b64'];
                if ($img['mime'] === '') {
                    $img['mime'] = (string)$b['mime'];
                }
            }
        }
        $cmp = self::compare($name, $idc, $img, $timeout, (string)($row['cmp_plugin'] ?? ''));
        $out['up_req']  = self::joinUp($out['up_req'], (string)$cmp['up_req']);
        $out['up_resp'] = self::joinUp($out['up_resp'], (string)$cmp['up_resp']);

        if ($cmp['state'] !== 'pass' && $cmp['state'] !== 'fail') {
            $cmp['state']  = 'fail';
            $cmp['code']   = 2008;
            $cmp['passed'] = 0;
            if ((string)$cmp['msg'] === '') {
                $cmp['msg'] = '实名比对没有完成，本次不通过，请重新发起。';
            }
        }
        $cmp['image_rel']  = (string)$live['image_rel'];
        $cmp['image_b64']  = (string)($img['base64'] ?? '');
        $cmp['image_mime'] = (string)($img['mime'] ?? '');
        $cmp['image_up']   = (string)$live['image_up'];
        $cmp['cmp_done']   = 1;
        $out['row'] = self::applyResult($row, $cmp);
        return $out;
    }

    public static function status(array $row, bool $query = true, int $timeout = 15): array
    {
        if ((string)$row['state'] !== 'wait' || !$query) {
            return $row;
        }
        $r = self::advance($row, $timeout);
        return $r['row'];
    }
}
