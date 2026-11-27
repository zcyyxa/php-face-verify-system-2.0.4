<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'site_name'       => SITE_NAME_FALLBACK,
            'site_url'        => '',
            'api_id'          => '',
            'api_key'         => '',
            'live_ttl'        => (string)LIVE_TTL_DEFAULT,
            'up_liveness'     => '',
            'up_compare'      => '',
            'db_ver'          => '',
            'upgrade_pending' => '',
        ];
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = self::defaults();
        try {
            foreach (Db::name('settings')->field('k,v')->select()->toArray() as $r) {
                $out[(string)$r['k']] = (string)$r['v'];
            }
        } catch (\Throwable $ex) {
        }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default;
    }

    public static function set(string $key, string $value): void
    {
        $now  = date('Y-m-d H:i:s');
        $row  = Db::name('settings')->where('k', $key)->find();
        if (is_array($row)) {
            Db::name('settings')->where('k', $key)->update(['v' => $value, 'updated_at' => $now]);
        } else {
            Db::name('settings')->insert(['k' => $key, 'v' => $value, 'updated_at' => $now]);
        }
        self::$cache = null;
    }

    public static function del(string $key): void
    {
        Db::name('settings')->where('k', $key)->delete();
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function siteName(): string
    {
        $v = trim((string)self::get('site_name', ''));
        return $v === '' ? SITE_NAME_FALLBACK : $v;
    }

    public static function needUpgrade(): bool
    {
        return (string)self::get('db_ver', '') !== APP_VERSION;
    }

    public static function liveTtl(): int
    {
        $v = (int)self::get('live_ttl', (string)LIVE_TTL_DEFAULT);
        if ($v < LIVE_TTL_MIN || $v > LIVE_TTL_MAX) {
            return LIVE_TTL_DEFAULT;
        }
        return $v;
    }
}
