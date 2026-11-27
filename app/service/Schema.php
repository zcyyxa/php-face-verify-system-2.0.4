<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class Schema
{
    public static function file(): string
    {
        return _f6() . '/install/schema.sql';
    }

    public static function runFile(): void
    {
        $sql = (string)file_get_contents(self::file());
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) {
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
                Db::execute(rtrim(trim($buf), ';'));
                $buf = '';
            }
        }
    }

    public static function upgrade(): array
    {
        if (strtolower((string)env('DB_DRIVER', 'mysql')) !== 'mysql') {
            return ['本机假库跳过结构比对。'];
        }
        $notes = [];
        $before = self::tables();
        self::runFile();
        $new = array_diff(self::tables(), $before);
        if ($new) {
            $notes[] = '新加的表：' . implode('、', $new);
        }
        foreach (self::defs() as $table => $def) {
            foreach (self::addMissing($table, $def) as $n) {
                $notes[] = $n;
            }
        }
        return $notes;
    }

    private static function tables(): array
    {
        $rows = Db::query('SHOW TABLES');
        $out  = [];
        foreach ($rows as $r) {
            $v = (string)reset($r);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        sort($out);
        return $out;
    }

    public static function defs(): array
    {
        $sql = (string)file_get_contents(self::file());
        $keep = [];
        foreach (preg_split('~\r\n|\n|\r~', $sql) ?: [] as $line) {
            if (strpos(ltrim($line), '--') !== 0) {
                $keep[] = $line;
            }
        }
        $text = implode("\n", $keep);
        $out  = [];
        if (!preg_match_all('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\((.*?)\)\s*ENGINE~is', $text, $m, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $one) {
            $cols = [];
            $keys = [];
            foreach (self::splitTop((string)$one[2]) as $part) {
                $p = trim($part);
                if ($p === '' || preg_match('~^PRIMARY\s+KEY~i', $p)) {
                    continue;
                }
                if (preg_match('~^(UNIQUE\s+KEY|KEY|INDEX)\s+`([^`]+)`\s*\(~i', $p, $k)) {
                    $keys[(string)$k[2]] = $p;
                    continue;
                }
                if (preg_match('~^`([^`]+)`\s+~', $p, $c)) {
                    $cols[(string)$c[1]] = $p;
                }
            }
            $out[(string)$one[1]] = ['cols' => $cols, 'keys' => $keys];
        }
        return $out;
    }

    private static function splitTop(string $s): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '`' || $ch === "'") {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    private static function addMissing(string $table, array $def): array
    {
        $notes = [];
        $cols = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
        foreach ($def['cols'] as $name => $sql) {
            if (isset($cols[strtolower($name)])) {
                continue;
            }
            Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN ' . $sql);
            $notes[] = '补列：' . $table . '.' . $name;
        }

        $idx = [];
        foreach (Db::query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`') as $r) {
            $idx[strtolower((string)$r['Key_name'])] = true;
        }
        foreach ($def['keys'] as $name => $sql) {
            if (isset($idx[strtolower($name)])) {
                continue;
            }
            Db::execute('ALTER TABLE `' . $table . '` ADD ' . $sql);
            $notes[] = '补索引：' . $table . '.' . $name;
        }
        return $notes;
    }
}
