<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\Plugin;
use app\service\Settings;
use app\service\Task;
use think\Response;

final class Live extends BaseController
{
    public function index(): Response
    {
        if (Settings::needUpgrade()) {
            return _f7(503, '正在升级', '<h1>正在升级</h1><p>站点正在升级，稍后再打开这一页。</p>', 'warn');
        }

        $t  = trim((string)($this->request->get('t', '')));
        $od = trim((string)($this->request->get('order_no', $this->request->get('orderNo', ''))));
        $row = null;
        $key = '';
        if ($t !== '') {
            $row = Task::byToken($t);
            $key = 't=' . rawurlencode($t);
        } elseif ($od !== '') {
            $row = Task::byOrder($od);
            $key = 'order_no=' . rawurlencode($od);
        }

        $noUp = false;
        if ($row !== null && (string)$row['state'] === 'wait') {
            if (!Plugin::ready()) {
                $noUp = true;
            } else {
                $row = Task::status($row, true, 12);
            }
        }

        $state = $this->stateOf($row, $noUp);
        if ((string)$this->request->get('act', '') === 'ask') {
            return _f22(200, [
                'code'  => 0,
                'state' => $state,
                'done'  => $state !== 'wait',
                'html'  => $this->block($row, $noUp),
            ]);
        }

        $ask = _f23() . '?' . ($key !== '' ? $key . '&' : '') . 'act=ask';
        $askJson = (string)json_encode($ask, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>认证结果</title>'
            . '<style>*{box-sizing:border-box}html,body{height:100%}'
            . 'body{margin:0;background:#f4f6fb;color:#172033;font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",system-ui,sans-serif}'
            . '.lvw{min-height:100%;display:flex;align-items:center;justify-content:center;padding:20px}'
            . '.lvb{width:100%;max-width:380px;background:#fff;border:1px solid #e3e8f0;border-radius:10px;padding:32px 24px;text-align:center}'
            . '.lvb h1{font-size:22px;line-height:1.35;margin:0;font-weight:650;color:#172033}'
            . '.lvb p{margin:10px 0 0;color:#68738a;font-size:14px;overflow-wrap:anywhere}'
            . '.lvb[data-state="pass"] h1{color:#00b42a}.lvb[data-state="fail"] h1{color:#f53f3f}'
            . '.lvb[data-state="wait"] h1{color:#2f5cf6}.lvb[data-state="warn"] h1{color:#b45309}'
            . '.res-ico{display:block;width:132px;height:132px;margin:0 auto 16px;filter:drop-shadow(0 8px 16px rgba(0,0,0,.04))}'
            . '.res-ico .stroke-shape{fill:none;stroke-width:5;stroke-linecap:round;stroke-linejoin:round}'
            . '.res-ico.ok .shield-bg{fill:rgba(0,180,42,.12)}.res-ico.bad .shield-bg{fill:rgba(245,63,63,.12)}'
            . '.res-ico.ok .shield-line{stroke:#00b42a;stroke-dasharray:320;stroke-dashoffset:320;animation:drawShape 1s cubic-bezier(.4,0,.2,1) .1s forwards}'
            . '.res-ico.ok .check-line{stroke:#00b42a;stroke-dasharray:50;stroke-dashoffset:50;animation:drawShape .6s cubic-bezier(.175,.885,.32,1.275) .8s forwards}'
            . '.res-ico.bad .shield-line{stroke:#f53f3f;stroke-dasharray:320;stroke-dashoffset:320;animation:drawShape 1s cubic-bezier(.4,0,.2,1) .1s forwards}'
            . '.res-ico.bad .cross-line{stroke:#f53f3f;stroke-dasharray:40;stroke-dashoffset:40}'
            . '.res-ico.bad .cross-1{animation:drawShape .3s cubic-bezier(.65,0,.45,1) .8s forwards}'
            . '.res-ico.bad .cross-2{animation:drawShape .3s cubic-bezier(.65,0,.45,1) 1.1s forwards}'
            . '.res-ico.bad{animation:errorShake .4s cubic-bezier(.36,.07,.19,.97) 1.4s both}'
            . '@keyframes drawShape{to{stroke-dashoffset:0}}'
            . '@keyframes errorShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}'
            . '@media (prefers-reduced-motion:reduce){.res-ico,.res-ico *{animation:none!important;stroke-dashoffset:0!important}}'
            . '@media (max-width:420px){.lvw{padding:16px}.lvb{padding:28px 20px}.res-ico{width:108px;height:108px;margin-bottom:12px}}</style></head><body>'
            . '<main class="lvw"><div class="lvb" id="resbox" data-state="' . _f14($state) . '">' . $this->block($row, $noUp) . '</div></main>'
            . '<script>(function(){var box=document.getElementById("resbox");if(!box){return}'
            . 'var ask=' . $askJson . ',n=0;'
            . 'function tick(){if(n>=120||box.getAttribute("data-state")!=="wait"){return}n++;'
            . 'fetch(ask,{cache:"no-store",credentials:"omit",headers:{Accept:"application/json"}})'
            . '.then(function(r){return r.json()}).then(function(j){'
            . 'if(!j||typeof j.html!=="string"){throw new Error("bad")}'
            . 'box.innerHTML=j.html;box.setAttribute("data-state",j.state||"error");'
            . 'if(!j.done){setTimeout(tick,3000)}}).catch(function(){setTimeout(tick,4000)})}'
            . 'if(box.getAttribute("data-state")==="wait"){setTimeout(tick,3000)}})();</script></body></html>';

        return response($html, 200, [
            'Content-Type'           => 'text/html; charset=utf-8',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'no-referrer',
        ]);
    }

    private function stateOf(?array $row, bool $noUp): string
    {
        if ($noUp) {
            return 'warn';
        }
        return $row === null ? 'warn' : (string)$row['state'];
    }

    private function shieldIcon(string $kind): string
    {
        $d   = 'M50 8 L 85 22 L 85 52 Q 85 82 50 92 Q 15 82 15 52 L 15 22 Z';
        $cls = $kind === 'bad' ? 'bad' : 'ok';
        $mark = $cls === 'ok'
            ? '<path class="stroke-shape check-line" d="M34 52 L 45 63 L 66 38"/>'
            : '<path class="stroke-shape cross-line cross-1" d="M38 42 L 62 66"/>'
                . '<path class="stroke-shape cross-line cross-2" d="M62 42 L 38 66"/>';
        return '<svg class="res-ico ' . $cls . '" viewBox="0 0 100 100" aria-hidden="true">'
            . '<path class="shield-bg" d="' . $d . '"/>'
            . '<path class="stroke-shape shield-line" d="' . $d . '"/>'
            . $mark
            . '</svg>';
    }

    private function block(?array $row, bool $noUp): string
    {
        if ($noUp) {
            return '<h1>先等一下</h1><p>站点还没配置好上游服务，稍后再打开这一页。</p>';
        }
        if ($row === null) {
            return '<h1>链接不完整</h1><p>回到刚才的页面，重新打开结果页。</p>';
        }
        $state = (string)$row['state'];
        $code  = (int)$row['code'];
        if ($state === 'pass') {
            return $this->shieldIcon('ok') . '<h1>认证通过</h1>';
        }
        if ($state === 'fail') {
            return $this->shieldIcon('bad') . '<h1>认证没通过</h1><p>回到刚才的页面重新试一次。</p>';
        }
        if ($state === 'error') {
            if ($code === 4002) {
                return '<h1>认证超时</h1><p>回到刚才的页面重新发起。</p>';
            }
            return '<h1>这次没拿到结果</h1><p>回到刚才的页面重新发起一次。</p>';
        }
        return '<h1>正在等结果</h1>';
    }
}
