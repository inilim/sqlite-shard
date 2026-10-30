<?php

declare(strict_types=1);

/**
 * Benchmark: registry SQLite contention as worker count grows.
 *
 * Each worker repeatedly rents and releases a shard. Rent/release performs
 * UPDATEs on the registry database, so this measures registry throughput and
 * lock contention rather than shard write throughput.
 *
 * Usage:
 *   php bench/benchmark_registry.php <duration_sec> [threads_list]
 *
 * Example:
 *   php bench/benchmark_registry.php 5 1,2,4,8,16
 *
 * Modes:
 *   - registry journal_mode=DELETE (no WAL)
 *   - registry journal_mode=WAL
 */

use Inilim\Parallel\PoolFlow;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\HelperService;
use Inilim\SqliteShard\Registry;
use Inilim\Tool\Arr;
use Inilim\Tool\FS;
use Inilim\Tool\LarStr;
use Inilim\Tool\Num;
use Inilim\Tool\Path;
use Inilim\Tool\Time;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../vendor/autoload.php';

$durationSec = (int)($argv[1] ?? 3);
if ($durationSec < 1) {
    fwrite(STDERR, "Usage: php bench/benchmark_registry.php <duration_sec> [threads_list]\n");
    fwrite(STDERR, "Example: php bench/benchmark_registry.php 5 1,2,4,8,16\n");
    exit(1);
}

$threadsArg = $argv[2] ?? '1,2,4,8,16';
$threadsList = [];
foreach (\explode(',', $threadsArg) as $part) {
    $part = LarStr::trim($part);
    if ('' === $part) {
        continue;
    }
    if (!Num::isNumeric($part) || (int)$part < 1) {
        fwrite(STDERR, "Invalid thread count: {$part}\n");
        exit(1);
    }
    $threadsList[] = (int)$part;
}
if ([] === $threadsList) {
    fwrite(STDERR, "Empty thread list\n");
    exit(1);
}
$threadsList = Arr::unique($threadsList);
sort($threadsList);

$vendorAutoload = Path::normalize(Path::realPath(__DIR__ . '/../vendor/autoload.php'));
$tmpDir = Path::normalize(__DIR__ . '/tmp');

if (!FS::isDir($tmpDir) && !mkdir($tmpDir, 0777, true) && !FS::isDir($tmpDir)) {
    fwrite(STDERR, "Cannot create directory: {$tmpDir}\n");
    exit(1);
}

function registry_bench_clean_dir(string $dir): void
{
    if (!FS::isDir($dir)) {
        mkdir($dir, 0777, true);
        return;
    }

    foreach (new Finder()->in($dir)->files()->name('*.sqlite*') as $spl) {
        $ptf = $spl->getRealPath();
        unset($spl);
        FS::unlink($ptf);
    }
}

/**
 * @return array{ok:int, miss:int, errors:int, errorMessages:list<string>, elapsed:float}
 */
function registry_bench_run_pool(int $threads, \Closure $task, mixed ...$args): array
{
    $startMs = Time::unixMs();
    $ok = 0;
    $miss = 0;
    $errorCount = 0;
    $errorMessages = [];

    $pool = new PoolFlow($threads);
    $pool->setHandler(static function (mixed $result) use (&$ok, &$miss): void {
        if (is_array($result)) {
            $ok += (int)($result['ok'] ?? 0);
            $miss += (int)($result['miss'] ?? 0);
        }
    });
    $pool->setHandlerError(static function (\Throwable $e) use (&$errorCount, &$errorMessages): void {
        $errorCount++;
        if (count($errorMessages) < 3) {
            $errorMessages[] = $e->getMessage();
        }
    });

    foreach ($pool as $flow) {
        $flow->exec($task, ...$args);
    }
    $pool->waitNative();

    $elapsedMs = Time::unixMs() - $startMs;

    return [
        'ok' => $ok,
        'miss' => $miss,
        'errors' => $errorCount,
        'errorMessages' => $errorMessages,
        'elapsed' => $elapsedMs / 1000,
    ];
}

/**
 * @return array{ok:int, miss:int, errors:int, errorMessages:list<string>, elapsed:float}
 */
function registry_bench_run(string $dir, string $vendorAutoload, int $threads, int $durationSec, bool $wal): array
{
    registry_bench_clean_dir($dir);

    $name = 'registry_bench_' . ($wal ? 'wal' : 'delete') . '_' . $threads;
    $schemaSql = 'CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)';
    $rentTimeout = max(30, $durationSec * 2 + 60);

    $config = new CreateConfig($dir, $schemaSql, $name, $threads, $rentTimeout);
    $reg = Registry::create($config);

    try {
        $helper = new HelperService;
        $ipdoReg = $helper->getRegIPDO($reg);
        $ipdoReg->setJournalMode($wal ? 'wal' : 'delete');
    } finally {
        $reg->closeConnect();
    }
    unset($reg);

    $endMs = Time::unixMs() + $durationSec * 1000;

    $fnTask = static function (
        string $dir,
        string $vendorAutoload,
        int $endMs,
        string $name,
    ): array {
        require_once $vendorAutoload;

        $reg = Registry::discover($dir, $name);
        if (null === $reg) {
            throw new \RuntimeException('Failed to discover registry');
        }

        $ok = 0;
        $miss = 0;
        $err = 0;

        try {
            while (Time::unixMs() < $endMs) {
                try {
                    $shard = $reg->tryRentFree();
                    if (null === $shard) {
                        $miss++;
                        continue;
                    }
                    $shard->release();
                    $ok++;
                } catch (\Throwable $e) {
                    $err++;
                    Time::sleepMs(1);
                }
            }
        } finally {
            $reg->closeConnect();
        }

        return ['ok' => $ok, 'miss' => $miss, 'err' => $err];
    };

    return registry_bench_run_pool(
        $threads,
        $fnTask,
        $dir,
        $vendorAutoload,
        $endMs,
        $name,
    );
}

$modes = [
    ['label' => 'registry journal_mode=DELETE (no WAL)', 'wal' => false],
    ['label' => 'registry journal_mode=WAL', 'wal' => true],
];

$lineLen = 120;
echo "Registry contention benchmark: rent+release cycles\n";
echo "duration per scenario: {$durationSec}s\n";
echo 'thread counts: ' . implode(', ', $threadsList) . "\n";
echo str_repeat('=', $lineLen) . "\n";

$summary = [];

foreach ($modes as $mode) {
    echo "\n=== {$mode['label']} ===\n";
    printf(
        "%-8s %14s %14s %10s %10s %10s\n",
        'threads',
        'rent+release',
        'cycles/sec',
        'misses',
        'errors',
        'elapsed'
    );

    $baselineRate = null;

    foreach ($threadsList as $threads) {
        $dir = $tmpDir . '/scenario_' . ($mode['wal'] ? 'wal' : 'not_wal') . '_' . $threads;
        $result = registry_bench_run($dir, $vendorAutoload, $threads, $durationSec, $mode['wal']);
        $rate = $result['elapsed'] > 0 ? $result['ok'] / $result['elapsed'] : 0.0;

        if (null === $baselineRate) {
            $baselineRate = $rate;
        }

        printf(
            "%-8d %14d %14.2f %10d %10d %9.2fs\n",
            $threads,
            $result['ok'],
            $rate,
            $result['miss'],
            $result['errors'],
            $result['elapsed']
        );

        foreach ($result['errorMessages'] as $msg) {
            echo "  - {$msg}\n";
        }

        $summary[] = [
            'mode' => $mode['label'],
            'threads' => $threads,
            'ok' => $result['ok'],
            'rate' => $rate,
            'miss' => $result['miss'],
            'errors' => $result['errors'],
            'elapsed' => $result['elapsed'],
        ];
    }
}

echo "\n" . str_repeat('=', $lineLen) . "\n";
echo "Summary\n";
echo str_repeat('-', $lineLen) . "\n";
printf(
    "%-38s %8s %14s %14s %10s %10s\n",
    'mode',
    'threads',
    'rent+release',
    'cycles/sec',
    'misses',
    'errors'
);
echo str_repeat('-', $lineLen) . "\n";

foreach ($summary as $row) {
    printf(
        "%-38s %8d %14d %14.2f %10d %10d\n",
        $row['mode'],
        $row['threads'],
        $row['ok'],
        $row['rate'],
        $row['miss'],
        $row['errors']
    );
}

echo str_repeat('=', $lineLen) . "\n";
