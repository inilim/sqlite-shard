<?php

declare(strict_types=1);

/**
 * Benchmark: parallel writes to a single SQLite file vs. sharded writes.
 *
 * Usage:
 *   php bench/benchmark.php <threads> <duration_sec>
 *
 * Example:
 *   php bench/benchmark.php 8 10
 *
 * Scenarios:
 *   1. Single SQLite file, journal_mode=DELETE (no WAL)
 *   2. Single SQLite file, journal_mode=WAL
 *   3. Shards via Inilim\SqliteShard, journal_mode=DELETE (no WAL)
 *   4. Shards via Inilim\SqliteShard, journal_mode=WAL
 */

use Inilim\IPDO\IPDOSQLite;
use Inilim\Parallel\PoolFlow;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\Tool\File;
use Inilim\Tool\FS;
use Inilim\Tool\Path;
use Inilim\Tool\Time;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../vendor/autoload.php';

// --- args ---------------------------------------------------------------

$threads = (int)($argv[1] ?? 0);
$durationSec = (int)($argv[2] ?? 0);

if ($threads < 1 || $durationSec < 1) {
    fwrite(STDERR, "Usage: php bench/benchmark.php <threads> <duration_sec>\n");
    fwrite(STDERR, "Example: php bench/benchmark.php 8 10\n");
    exit(1);
}

$vendorAutoload = Path::normalize(Path::realPath(__DIR__ . '/../vendor/autoload.php'));
$tmpDir = Path::normalize(__DIR__ . '/tmp');

if (!FS::isDir($tmpDir) && !\mkdir($tmpDir, 0777, true) && !FS::isDir($tmpDir)) {
    fwrite(STDERR, "Cannot create directory: {$tmpDir}\n");
    exit(1);
}

$schemaSql = 'CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)';
$len_line = 100;

// --- helpers ------------------------------------------------------------

function clean_dir(string $dir): void
{
    if (!FS::isDir($dir)) {
        \mkdir($dir, 0777, true);
        return;
    }
    foreach (new Finder()->in($dir)->files()->name('*.sqlite*') as $spl) {
        $ptf = $spl->getRealPath();
        unset($spl);
        FS::unlink($ptf);
    }
}

/**
 * @return array{total:int, errors:int, errorMessages:list<string>, elapsed:float}
 */
function run_pool(int $threads, \Closure $task, mixed ...$args): array
{
    $startMs = Time::unixMs();
    $total = 0;
    $errorCount = 0;
    $errorMessages = [];

    $pool = new PoolFlow($threads);
    $pool->setHandler(static function (mixed $result) use (&$total): void {
        if (\is_array($result)) {
            $total += (int)($result['ok'] ?? 0);
        } elseif (\is_int($result)) {
            $total += $result;
        }
    });
    $pool->setHandlerError(static function (\Throwable $e) use (&$errorCount, &$errorMessages): void {
        $errorCount++;
        if (\count($errorMessages) < 3) {
            $errorMessages[] = $e->getMessage();
        }
    });

    foreach ($pool as $flow) {
        $flow->exec($task, ...$args);
    }
    $pool->waitNative();

    $elapsedMs = Time::unixMs() - $startMs;

    return [
        'total' => $total,
        'errors' => $errorCount,
        'errorMessages' => $errorMessages,
        'elapsed' => $elapsedMs / 1000,
    ];
}

// --- scenarios ----------------------------------------------------------

/**
 * Parallel writes to one SQLite file.
 *
 * @return array{total:int, errors:int, errorMessages:list<string>, elapsed:float}
 */
function bench_single(string $dir, string $vendorAutoload, int $threads, int $durationSec, bool $wal): array
{
    clean_dir($dir);
    $ptf = $dir . '/items.sqlite';

    // IPDOSQLite requires the file to exist before opening it.
    File::put($ptf, '');

    $ipdo = \DI(IPDOSQLite::class, [$ptf]);
    $ipdo->exec('CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    $ipdo->setJournalMode($wal ? 'wal' : 'delete');
    $ipdo->close();

    $endMs = Time::unixMs() + $durationSec * 1000;

    $fn_task = static function (string $ptf, string $vendorAutoload, int $endMs): array {
        require_once $vendorAutoload;

        $ipdo = new IPDOSQLite($ptf);
        $ok = 0;
        $err = 0;
        // $unixMs = Time::unixMs();
        $unixMs = Time::__asClosure('unixMs');
        while ($unixMs() < $endMs) {
            try {
                $ipdo->exec(
                    'INSERT INTO items (name) VALUES ({name})',
                    ['name' => 'x']
                );
                $ok++;
            } catch (\Throwable $e) {
                $err++;
            }
        }
        $ipdo->close();
        return ['ok' => $ok, 'err' => $err];
    };

    return run_pool($threads, $fn_task, $ptf, $vendorAutoload, $endMs);
}

/**
 * Parallel writes through the shard library - each thread gets its own shard file.
 *
 * @return array{total:int, errors:int, errorMessages:list<string>, elapsed:float}
 */
function bench_shards(string $dir, string $vendorAutoload, int $threads, int $durationSec, bool $wal): array
{
    clean_dir($dir);

    $name = 'bench';
    $schemaSql = 'CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)';
    $rentTimeout = \max(300, $durationSec * 2 + 60);

    $config = new CreateConfig($dir, $schemaSql, $name, $threads, $rentTimeout);
    $reg = Registry::create($config);
    $reg->closeConnect();
    unset($reg);

    $endMs = Time::unixMs() + $durationSec * 1000;

    $fn_task = static function (
        string $dir,
        string $vendorAutoload,
        int $endMs,
        bool $wal,
        string $name,
    ): array {
        require_once $vendorAutoload;

        $reg = Registry::discover($dir, $name);
        $shard = $reg->tryRentFree();
        $reg->closeConnect();

        if (null === $shard) {
            throw new \RuntimeException('Failed to rent a shard');
        }

        $ok = 0;
        $err = 0;
        // $unixMs = Time::unixMs();
        $unixMs = Time::__asClosure('unixMs');
        $ipdo = $shard->getIPDO();
        $ipdo->setJournalMode($wal ? 'wal' : 'delete');

        try {
            while ($unixMs() < $endMs) {
                try {
                    $ipdo->exec('INSERT INTO items (name) VALUES ({name})', ['name' => 'x']);
                    $ok++;
                } catch (\Throwable $e) {
                    $err++;
                }
            }
        } finally {
            $ipdo->close();
            $shard->release();
        }

        return ['ok' => $ok, 'err' => $err];
    };

    return run_pool(
        $threads,
        $fn_task,
        $dir,
        $vendorAutoload,
        $endMs,
        $wal,
        $name,
    );
}

// --- run ----------------------------------------------------------------

$scenarios = [
    ['label' => 'Single SQLite file (no WAL)', 'fn' => \bench_single(...), 'wal' => false],
    ['label' => 'Shards via library (no WAL)', 'fn' => \bench_shards(...), 'wal' => false],
    ['label' => 'Single SQLite file (WAL)',    'fn' => \bench_single(...), 'wal' => true],
    ['label' => 'Shards via library (WAL)',    'fn' => \bench_shards(...), 'wal' => true],
];

echo "Benchmark: parallel SQLite writes\n";
echo "threads:  {$threads}\n";
echo "duration: {$durationSec}s\n";
echo str_repeat('=', $len_line) . "\n";

$summary = [];

foreach ($scenarios as $i => $scenario) {
    $dir = $tmpDir . '/scenario_' . $i;
    $result = ($scenario['fn'])($dir, $vendorAutoload, $threads, $durationSec, $scenario['wal']);

    $rate = $result['elapsed'] > 0 ? $result['total'] / $result['elapsed'] : 0.0;

    echo "\n=== {$scenario['label']} ===\n";
    \printf("duration:      %.2fs\n", $result['elapsed']);
    \printf("total inserts: %d\n", $result['total']);
    \printf("inserts/sec:   %.2f\n", $rate);
    \printf("errors:        %d\n", $result['errors']);
    foreach ($result['errorMessages'] as $msg) {
        echo "  - {$msg}\n";
    }

    $summary[] = [
        'label' => $scenario['label'],
        'total' => $result['total'],
        'rate' => $rate,
        'growth' => 0.0,
        'errors' => $result['errors'],
    ];
}

$baselineRate = $summary[0]['rate'] ?? 0.0;

foreach ($summary as &$row) {
    $row['growth'] = $baselineRate > 0.0
        ? (($row['rate'] / $baselineRate) - 1.0) * 100.0
        : 0.0;
}
unset($row);

echo "\n" . str_repeat('=', $len_line) . "\n";
echo "Summary\n";
echo str_repeat('-', $len_line) . "\n";
\printf("%-32s %12s %14s %10s %8s\n", 'scenario', 'inserts', 'inserts/sec', 'growth %', 'errors');
echo str_repeat('-', $len_line) . "\n";
foreach ($summary as $row) {
    \printf("%-32s %12d %14.2f %+9.2f%% %8d\n", $row['label'], $row['total'], $row['rate'], $row['growth'], $row['errors']);
}
echo str_repeat('=', $len_line) . "\n";
