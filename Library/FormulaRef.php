<?php

namespace Kanboard\Plugin\ExcelTemplate\Library;

/**
 * 엑셀 수식 / 범위 문자열 안의 셀 참조(A1, $B$2, Sheet1!A1:C3 ...)를 찾아 바꾸는 도우미
 */
class FormulaRef
{
    const REF_PATTERN = '/(?<![A-Za-z0-9_.\$!:\'\]\x{80}-\x{10FFFF}])((?:\'(?:[^\']|\'\')+\'|[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_.\x{80}-\x{10FFFF}]*)!)?(\$?)([A-Z]{1,3})(\$?)([0-9]+)(?::(\$?)([A-Z]{1,3})(\$?)([0-9]+))?(?![A-Za-z0-9_(!\x{80}-\x{10FFFF}])/u';

    public static function colToNum($col)
    {
        $n = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n;
    }

    public static function numToCol($n)
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m).$s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    /**
     * "B12" -> [2, 12]
     */
    public static function parseCell($ref)
    {
        if (preg_match('/^\$?([A-Z]{1,3})\$?([0-9]+)$/', $ref, $m)) {
            return array(self::colToNum($m[1]), (int) $m[2]);
        }
        return array(0, 0);
    }

    public static function cell($col, $row)
    {
        return self::numToCol($col).$row;
    }

    /**
     * 시트 이름 접두어 정리: "'My Sheet'!" -> "My Sheet"
     */
    public static function unquoteSheet($prefix)
    {
        if ($prefix === null || $prefix === '') {
            return null;
        }

        $name = substr($prefix, 0, -1);
        if (strlen($name) >= 2 && $name[0] === "'" && substr($name, -1) === "'") {
            $name = str_replace("''", "'", substr($name, 1, -1));
        }

        return $name;
    }

    public static function quoteSheet($name)
    {
        if (preg_match('/^[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_.\x{80}-\x{10FFFF}]*$/u', $name) && ! preg_match('/^[A-Z]{1,3}[0-9]+$/i', $name)) {
            return $name;
        }

        return "'".str_replace("'", "''", $name)."'";
    }

    /**
     * 수식 안의 모든 셀 참조에 콜백 적용 (문자열 리터럴 "..." 은 건너뜀)
     *
     * 콜백: function ($sheetName|null, array $ref) : array $ref (수정된 ref)
     *   $ref = ['c1','ca1','r1','ra1','range','c2','ca2','r2','ra2']
     */
    public static function map($formula, callable $callback)
    {
        if ($formula === '' || $formula === null) {
            return $formula;
        }

        $parts = preg_split('/("(?:[^"]|"")*")/u', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // 문자열 리터럴
            }

            $parts[$i] = preg_replace_callback(self::REF_PATTERN, function ($m) use ($callback) {
                $prefix = isset($m[1]) ? $m[1] : '';
                $ref = array(
                    'c1' => self::colToNum($m[3]),
                    'ca1' => $m[2] === '$',
                    'r1' => (int) $m[5],
                    'ra1' => $m[4] === '$',
                    'range' => isset($m[7]) && $m[7] !== '',
                );

                if ($ref['range']) {
                    $ref['c2'] = self::colToNum($m[7]);
                    $ref['ca2'] = $m[6] === '$';
                    $ref['r2'] = (int) $m[9];
                    $ref['ra2'] = $m[8] === '$';
                }

                $new = $callback(self::unquoteSheet($prefix), $ref);

                if ($new === null) {
                    return $m[0];
                }

                return $prefix.self::build($new);
            }, $part);
        }

        return implode('', $parts);
    }

    public static function build(array $ref)
    {
        $s = ($ref['ca1'] ? '$' : '').self::numToCol(max(1, $ref['c1'])).($ref['ra1'] ? '$' : '').max(1, $ref['r1']);

        if (! empty($ref['range'])) {
            $s .= ':'.($ref['ca2'] ? '$' : '').self::numToCol(max(1, $ref['c2'])).($ref['ra2'] ? '$' : '').max(1, $ref['r2']);
        }

        return $s;
    }

    /**
     * 상대 참조만 (dRow, dCol) 만큼 이동 (공유 수식 펼치기용)
     */
    public static function translate($formula, $dRow, $dCol)
    {
        if ($dRow === 0 && $dCol === 0) {
            return $formula;
        }

        return self::map($formula, function ($sheet, array $ref) use ($dRow, $dCol) {
            foreach (array('1', '2') as $n) {
                if ($n === '2' && empty($ref['range'])) {
                    break;
                }
                if (! $ref['ra'.$n]) {
                    $ref['r'.$n] += $dRow;
                }
                if (! $ref['ca'.$n]) {
                    $ref['c'.$n] += $dCol;
                }
            }
            return $ref;
        });
    }

    /**
     * 수식에서 시트 이름 바꾸기 (old -> new)
     */
    public static function renameSheet($formula, $old, $new)
    {
        if ($formula === '' || $formula === null || $old === $new) {
            return $formula;
        }

        $parts = preg_split('/("(?:[^"]|"")*")/u', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        $quotedOld = "'".str_replace("'", "''", $old)."'!";
        $replacement = self::quoteSheet($new).'!';

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }

            $part = str_replace($quotedOld, $replacement, $part);
            $part = preg_replace('/(?<![A-Za-z0-9_.\x{80}-\x{10FFFF}\'])'.preg_quote($old, '/').'!/u', str_replace('$', '\\$', $replacement), $part);
            $parts[$i] = $part;
        }

        return implode('', $parts);
    }
}
