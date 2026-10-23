<?php
declare(strict_types=1);

namespace BharatSeo\Tests;

/** Minimal dependency-free test base (no Composer/PHPUnit required on shared hosting). */
abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertTrue(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== true) {
            throw new AssertionFailed($msg ?: 'Expected true, got ' . var_export($v, true));
        }
    }

    protected function assertFalse(mixed $v, string $msg = ''): void
    {
        $this->assertions++;
        if ($v !== false) {
            throw new AssertionFailed($msg ?: 'Expected false, got ' . var_export($v, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertNotSame(mixed $a, mixed $b, string $msg = ''): void
    {
        $this->assertions++;
        if ($a === $b) {
            throw new AssertionFailed($msg ?: 'Values should differ: ' . var_export($a, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Expected to find '$needle' in '" . mb_substr($haystack, 0, 300) . "'");
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Did not expect '$needle'");
        }
    }

    protected function assertGreaterOrEqual(int|float $min, int|float $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($actual < $min) {
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Expected >= $min, got $actual");
        }
    }

    protected function assertLess(int|float $max, int|float $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($actual >= $max) {
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Expected < $max, got $actual");
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $msg = ''): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        throw new AssertionFailed(($msg ? $msg . ' — ' : '') . "Expected $class to be thrown");
    }
}

final class AssertionFailed extends \RuntimeException
{
}

final class SkipTest extends \RuntimeException
{
}
