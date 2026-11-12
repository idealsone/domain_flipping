<?php

namespace App\Services;

/**
 * Reads the text back out of a PDF the site itself produced (Dompdf), so a template is only called ATS-friendly after
 * its exported file was shown to give back the right words in the right order (V7 §15, V5.0 §7). It handles what Dompdf
 * writes: Flate-compressed content streams, embedded subset fonts with ToUnicode maps, Tj/TJ/'/" text operators and
 * line moves. It is not a general PDF reader.
 */
class PdfText
{
    public static function extract(string $pdf): string
    {
        $objs = [];
        if (preg_match_all('/(\d+)\s+0\s+obj(.*?)endobj/s', $pdf, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $objs[(int) $o[1]] = $o[2];
            }
        }
        $stream = function (string $body): ?string {
            if (! preg_match('/stream\r?\n(.*)\r?\nendstream/s', $body, $s)) {
                return null;
            }
            $raw = $s[1];
            if (str_contains(substr($body, 0, (int) strpos($body, 'stream')), '/FlateDecode')) {
                $raw = @gzuncompress($raw) ?: (@gzinflate($raw) ?: (@gzinflate(substr($raw, 2)) ?: ''));
            }

            return $raw;
        };
        // Font resource names -> ToUnicode map, collected per page from the font dictionaries.
        $maps = [];
        foreach ($objs as $id => $body) {
            if (preg_match('/\/Type\s*\/Font/', $body) && preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $body, $t) && isset($objs[(int) $t[1]])) {
                $maps[$id] = self::cmap((string) $stream($objs[(int) $t[1]]));
            }
        }
        $out = '';
        foreach ($objs as $body) {
            if (! preg_match('/\/Type\s*\/Page[^s]/', $body)) {
                continue;
            }
            $fonts = [];
            // Fonts sit on the page, on its resources object, or are inherited from the parent /Pages node.
            $holders = [$body];
            if (preg_match('/\/Resources\s+(\d+)\s+0\s+R/', $body, $r) && isset($objs[(int) $r[1]])) {
                $holders[] = $objs[(int) $r[1]];
            }
            if (preg_match('/\/Parent\s+(\d+)\s+0\s+R/', $body, $r) && isset($objs[(int) $r[1]])) {
                $holders[] = $objs[(int) $r[1]];
            }
            foreach (array_reverse($holders) as $res) {
                if (preg_match('/\/Font\s*<<(.*?)>>/s', $res, $f) && preg_match_all('/\/(\w+)\s+(\d+)\s+0\s+R/', $f[1], $fm, PREG_SET_ORDER)) {
                    foreach ($fm as $x) {
                        $fonts[$x[1]] = $maps[(int) $x[2]] ?? null;
                    }
                }
            }
            $contents = [];
            if (preg_match('/\/Contents\s*\[([^\]]*)\]/', $body, $c)) {
                preg_match_all('/(\d+)\s+0\s+R/', $c[1], $cm);
                $contents = array_map('intval', $cm[1]);
            } elseif (preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $body, $c)) {
                $contents = [(int) $c[1]];
            }
            foreach ($contents as $cid) {
                $out .= self::run((string) $stream($objs[$cid] ?? ''), $fonts)."\n";
            }
        }

        return trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", $out)));
    }

    /** A ToUnicode map: code => text, or true for Dompdf's identity map (the code is the Unicode point). */
    private static function cmap(string $s): array|bool
    {
        if (preg_match('/beginbfrange\s*<0+>\s*<[Ff]{4}>\s*<0+>\s*endbfrange/', $s) && ! str_contains($s, 'beginbfchar')) {
            return true;
        }
        $map = [];
        $u = fn (string $hex) => mb_convert_encoding(hex2bin(strlen($hex) % 4 ? str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT) : $hex), 'UTF-8', 'UTF-16BE');
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $b)) {
            foreach ($b[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    $map[hexdec($x[1])] = $u($x[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $b)) {
            foreach ($b[1] as $blk) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $blk, $p, PREG_SET_ORDER);
                foreach ($p as $x) {
                    [$lo, $hi] = [hexdec($x[1]), hexdec($x[2])];
                    if ($x[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $x[3], $arr);
                        foreach ($arr[1] as $i => $h) {
                            $map[$lo + $i] = $u($h);
                        }
                    } else {
                        $start = hexdec(trim($x[3], '<>'));
                        for ($c = $lo; $c <= $hi && $c - $lo < 65536; $c++) {
                            $map[$c] = mb_chr($start + $c - $lo, 'UTF-8');
                        }
                    }
                }
            }
        }

        return $map;
    }

    private static function decode(string $bytes, $map): string
    {
        if ($map === null) {
            return $bytes;
        }
        $s = '';
        for ($i = 0; $i + 1 < strlen($bytes); $i += 2) {
            $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            $s .= $map === true ? (string) mb_chr($code, 'UTF-8') : ($map[$code] ?? '');
        }

        return $s;
    }

    /** Splits a content stream into PDF tokens: strings (literal and hex), arrays, names, numbers and operators. */
    private static function tokens(string $c): array
    {
        $t = [];
        $n = strlen($c);
        $i = 0;
        $stack = [[]];
        while ($i < $n) {
            $ch = $c[$i];
            if (ctype_space($ch)) {
                $i++;
            } elseif ($ch === '%') {
                while ($i < $n && $c[$i] !== "\n" && $c[$i] !== "\r") {
                    $i++;
                }
            } elseif ($ch === '(') {
                $depth = 1;
                $buf = '';
                $i++;
                while ($i < $n && $depth > 0) {
                    $x = $c[$i];
                    if ($x === '\\' && $i + 1 < $n) {
                        $y = $c[$i + 1];
                        if (strpos('01234567', $y) !== false) {
                            $oct = substr($c, $i + 1, 3);
                            $oct = preg_match('/^[0-7]{1,3}/', $oct, $om) ? $om[0] : $y;
                            $buf .= chr(octdec($oct) & 255);
                            $i += 1 + strlen($oct);
                            continue;
                        }
                        $buf .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f"][$y] ?? ($y === "\n" || $y === "\r" ? '' : $y);
                        $i += 2;
                        continue;
                    }
                    if ($x === '(') {
                        $depth++;
                    } elseif ($x === ')' && --$depth === 0) {
                        $i++;
                        break;
                    }
                    $buf .= $x;
                    $i++;
                }
                $stack[count($stack) - 1][] = ['s', $buf];
            } elseif ($ch === '<' && ($c[$i + 1] ?? '') !== '<') {
                $e = strpos($c, '>', $i);
                $e = $e === false ? $n : $e;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($c, $i + 1, $e - $i - 1));
                $stack[count($stack) - 1][] = ['s', (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex)];
                $i = $e + 1;
            } elseif ($ch === '[') {
                $stack[] = [];
                $i++;
            } elseif ($ch === ']') {
                $arr = count($stack) > 1 ? array_pop($stack) : [];
                $stack[count($stack) - 1][] = ['a', $arr];
                $i++;
            } else {
                preg_match('/\G(\/[^\s\/\[\]()<>{}%]*|[+-]?(?:\d+\.?\d*|\.\d+)|<<|>>|[^\s\/\[\]()<>{}%]+|.)/s', $c, $m, 0, $i);
                $w = $m[0];
                $i += strlen($w);
                if ($w[0] === '/') {
                    $stack[count($stack) - 1][] = ['n', substr($w, 1)];
                } elseif (is_numeric($w)) {
                    $stack[count($stack) - 1][] = ['d', (float) $w];
                } elseif (count($stack) === 1) {
                    $t[] = ['op', $w, $stack[0]];
                    $stack = [[]];
                }
            }
        }

        return $t;
    }

    /** Walks one content stream: text in drawing order, a new line whenever the text moves to another baseline. */
    private static function run(string $c, array $fonts): string
    {
        $out = '';
        $font = null;
        [$x, $y, $lastY] = [0.0, 0.0, null];
        $emit = function (string $s) use (&$out, &$lastY, &$y) {
            if ($lastY !== null && abs($y - $lastY) > 0.5) {
                $out .= "\n";
            } elseif ($lastY !== null && $out !== '' && ! str_ends_with($out, ' ') && ! str_ends_with($out, "\n")) {
                $out .= ' ';
            }
            $out .= $s;
            $lastY = $y;
        };
        foreach (self::tokens($c) as [, $op, $args]) {
            $num = fn ($k) => (float) ($args[$k][1] ?? 0);
            switch ($op) {
                case 'BT':
                    [$x, $y] = [0.0, 0.0];
                    break;
                case 'Tf':
                    $font = $args[0][1] ?? null;
                    break;
                case 'Td': case 'TD':
                    $x += $num(0);
                    $y += $num(1);
                    break;
                case 'Tm':
                    [$x, $y] = [$num(4), $num(5)];
                    break;
                case 'T*':
                    $y -= 1;
                    break;
                case 'Tj': case "'": case '"':
                    $last = end($args);
                    if ($last && $last[0] === 's') {
                        $emit(self::decode($last[1], $fonts[$font] ?? null));
                    }
                    break;
                case 'TJ':
                    $s = '';
                    foreach ($args[0][1] ?? [] as $part) {
                        if ($part[0] === 's') {
                            $s .= self::decode($part[1], $fonts[$font] ?? null);
                        } elseif ($part[0] === 'd' && $part[1] < -200) {
                            $s .= ' ';
                        }
                    }
                    $emit($s);
                    break;
            }
        }

        return $out;
    }
}
