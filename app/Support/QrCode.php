<?php
declare(strict_types=1);

namespace Flatmates\Support;

/**
 * Minimal QR Code generator (ISO/IEC 18004): byte mode, error-correction level M, versions 1–10
 * (up to 213 bytes — plenty for a URL). Renders an SVG with no external dependencies.
 * Structure follows the well-known reference algorithm (finder/timing/alignment patterns,
 * Reed–Solomon over GF(256), zig-zag placement, 8 masks with penalty scoring).
 */
final class QrCode
{
    /** version => [ecc codewords per block, [[blocks, data codewords per block], ...]] for level M */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    public readonly int $version;
    public readonly int $size;
    public readonly int $mask;
    /** @var bool[][] [row][col] true = dark */
    private array $m = [];
    /** @var bool[][] */
    private array $fn = [];

    private function __construct(string $text, ?int $forceMask)
    {
        $len = strlen($text);
        $version = null;
        foreach (self::BLOCKS as $v => [$ecc, $groups]) {
            $cap = 0;
            foreach ($groups as [$n, $d]) {
                $cap += $n * $d;
            }
            if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $cap * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException('Text is too long for a QR code (max 213 bytes).');
        }
        $this->version = $version;
        $this->size = 17 + 4 * $version;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fn = $this->m;
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->codewords($text));
        if ($forceMask === null) {
            $best = 0;
            $bestScore = PHP_INT_MAX;
            for ($k = 0; $k < 8; $k++) {
                $this->applyMask($k);
                $this->drawFormat($k);
                $score = $this->penalty();
                if ($score < $bestScore) {
                    $best = $k;
                    $bestScore = $score;
                }
                $this->applyMask($k); // undo (XOR)
            }
            $forceMask = $best;
        }
        $this->mask = $forceMask;
        $this->applyMask($forceMask);
        $this->drawFormat($forceMask);
    }

    public static function encode(string $text, ?int $mask = null): self
    {
        return new self($text, $mask);
    }

    public function isDark(int $row, int $col): bool
    {
        return $this->m[$row][$col];
    }

    /** One string per row, '1' = dark (used by tests). */
    public function rows(): array
    {
        return array_map(static fn(array $r) => implode('', array_map(static fn($b) => $b ? '1' : '0', $r)), $this->m);
    }

    public function svg(int $border = 4, string $dark = '#142b4a', string $title = 'QR code'): string
    {
        $dim = $this->size + 2 * $border;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->m[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="' . htmlspecialchars($dark, ENT_QUOTES) . '"/></svg>';
    }

    // ---- construction ---------------------------------------------------------------------

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$this->version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0); // reserve format areas
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);
    }

    /** @return int[] final interleaved codewords */
    private function codewords(string $text): array
    {
        [$eccLen, $groups] = self::BLOCKS[$this->version];
        $capacity = 0;
        foreach ($groups as [$n, $d]) {
            $capacity += $n * $d;
        }
        $bits = '0100' . str_pad(decbin(strlen($text)), $this->version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($data) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }
        $blocks = [];
        $k = 0;
        $div = self::rsDivisor($eccLen);
        foreach ($groups as [$n, $d]) {
            for ($b = 0; $b < $n; $b++) {
                $chunk = array_slice($data, $k, $d);
                $k += $d;
                $blocks[] = [$chunk, self::rsRemainder($chunk, $div)];
            }
        }
        $out = [];
        $maxData = max(array_map(static fn($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as [$dat]) {
                if ($i < count($dat)) {
                    $out[] = $dat[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as [, $ecc]) {
                $out[] = $ecc[$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $coef) {
                $r[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $r;
    }

    private function drawCodewords(array $data): void
    {
        $s = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $s = $this->size;
        $p = 0;
        $lines = [];
        for ($i = 0; $i < $s; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $s; $j++) {
                $row .= $this->m[$i][$j] ? '1' : '0';
                $col .= $this->m[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) {
                $p += strlen($run) - 2;
            }
            $p += 40 * (preg_match_all('/(?=00001011101)|(?=10111010000)/', $line));
        }
        $dark = 0;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $dark += $this->m[$y][$x] ? 1 : 0;
                if ($y < $s - 1 && $x < $s - 1) {
                    $c = $this->m[$y][$x];
                    if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                        $p += 3;
                    }
                }
            }
        }
        $p += 10 * intdiv(abs($dark * 20 - $s * $s * 10) + $s * $s - 1, $s * $s) - 10;
        return $p;
    }
}
