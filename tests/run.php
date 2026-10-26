<?php
declare(strict_types=1);

/**
 * Student Flatmate Finder test runner.
 *   php tests/run.php                 # all suites
 *   php tests/run.php Unit            # one suite (Unit | Integration | Http)
 *   php tests/run.php Integration Queue   # filter by class-name substring
 *
 * Integration/Http suites need a disposable MySQL/MariaDB database (it is DROPPED and recreated):
 *   FM_TEST_DB_HOST=127.0.0.1 FM_TEST_DB_PORT=3306 FM_TEST_DB_USER=... FM_TEST_DB_PASS=... FM_TEST_DB_NAME=fm_test
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
define('FLATMATES_TESTING', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/Support/TestEnv.php';

// Any PHP warning/notice/deprecation fails the test (App::boot re-registers an equivalent handler in debug mode).
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$suites = ['Unit', 'Integration', 'Http'];
$only = $argv[1] ?? null;
$filter = $argv[2] ?? null;
if ($only !== null && in_array($only, $suites, true)) {
    $suites = [$only];
} elseif ($only !== null) {
    $filter = $only;
}

$total = $passed = $failed = $skipped = $assertions = 0;
$failures = [];
$started = microtime(true);
foreach ($suites as $suite) {
    $files = glob(__DIR__ . '/' . $suite . '/*Test.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        require_once $file;
        $class = 'Flatmates\\Tests\\' . $suite . '\\' . basename($file, '.php');
        if ($filter && stripos($class, $filter) === false) {
            continue;
        }
        if (method_exists($class, 'setUpBeforeClass')) {
            try {
                $class::setUpBeforeClass();
            } catch (\Flatmates\Tests\SkipTest $e) {
                $n = count(array_filter(get_class_methods($class), static fn($m) => str_starts_with($m, 'test')));
                $skipped += $n;
                $total += $n;
                fwrite(STDOUT, "SKIP  $class ($n tests): {$e->getMessage()}\n");
                continue;
            }
        }
        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $total++;
            $t = new $class();
            try {
                $t->setUp();
                $t->$method();
                $passed++;
                fwrite(STDOUT, "PASS  $suite › " . basename($file, '.php') . "::$method\n");
            } catch (\Flatmates\Tests\SkipTest $e) {
                $skipped++;
                fwrite(STDOUT, "SKIP  $suite › " . basename($file, '.php') . "::$method — {$e->getMessage()}\n");
            } catch (\Throwable $e) {
                $failed++;
                $where = basename($e->getFile()) . ':' . $e->getLine();
                foreach ($e->getTrace() as $frame) {
                    if (isset($frame['file']) && realpath($frame['file']) === realpath($file)) {
                        $where .= ' (test line ' . $frame['line'] . ')';
                        break;
                    }
                }
                $failures[] = "$class::$method\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . $where;
                fwrite(STDOUT, "FAIL  $suite › " . basename($file, '.php') . "::$method\n");
            } finally {
                try {
                    $t->tearDown();
                } catch (\Throwable) {
                }
                $assertions += $t->assertions;
            }
        }
        if (method_exists($class, 'tearDownAfterClass')) {
            $class::tearDownAfterClass();
        }
    }
}
fwrite(STDOUT, sprintf("\n%d tests, %d passed, %d failed, %d skipped, %d assertions in %.1fs\n", $total, $passed, $failed, $skipped, $assertions, microtime(true) - $started));
foreach ($failures as $f) {
    fwrite(STDOUT, "\n✗ $f\n");
}
exit($failed > 0 ? 1 : 0);
