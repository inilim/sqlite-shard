<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\Parallel\Flow;
use Inilim\Parallel\Task;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\Test\TestCase;

/**
 * Настоящие состязательные сценарии (реальный параллелизм, inilim/parallel):
 * многораундовый стресс аренды свободных шардов.
 *
 * Кейсы RentTest/RentFreeTest/TryRentTest/TryRentFreeTest с пометкой «гонка»
 * ранее моделировали гонку последовательно двумя экземплярами Registry
 * (второй всегда стартует после фиксации первого) — окно «оба прочитали
 * свободный шард до записи» не воспроизводилось; теперь они тоже идут
 * параллельно, инлайново через {@see Flow::execAndGetTask()}. Этот класс
 * дополнительно гоняет инвариант на многих раундах: каждый воркер — отдельный
 * поток (parallel\Runtime) со своим соединением SQLite, старт выравнивается по
 * стартовой миллисекунде (цикл ожидания с шагом 1 мс), плюс небольшой джиттер,
 * чтобы несколько воркеров успевали прочитать один и тот же список свободных
 * шардов до того, как кто-то запишет аренду.
 *
 * Потоки (Runtime) создаются один раз до цикла раундов и переиспользуются:
 * дорогой подъём потока и загрузка автолоадера происходят один раз, поэтому
 * оффсет стартовой миллисекунды на тёплых потоках — сотни миллисекунд, а не
 * секунды (все воркеры гарантированно успевают встать на барьер).
 *
 * Главный инвариант: один шард не может быть выдан двум воркерам.
 *
 * Требуется расширение ext-parallel (extension_loaded('parallel'));
 * без него тесты пропускаются (markTestSkipped).
 *
 *  «Состязательные сценарии»
 */
final class RaceParallelTest extends TestCase
{
    /** раунды и количество воркеров для сценария «один свободный шард» */
    private const ROUNDS_ONE_FREE_SHARD = 25;
    private const WORKERS_ONE_FREE_SHARD = 6;

    /** раунды, воркеры и свободные шарды для сценария «несколько свободных шардов» */
    private const ROUNDS_MULTI_SHARD = 15;
    private const WORKERS_MULTI_SHARD = 8;
    private const FREE_SHARDS_MULTI = 3;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\extension_loaded('parallel')) {
            self::markTestSkipped(
                'Для настоящего параллельного теста нужно расширение ext-parallel '
                    . '(inilim/parallel использует parallel\Runtime). '
                    . 'Установите ext-parallel либо используйте сборку PHP с ZTS.'
            );
        }
    }

    // Один свободный шард, несколько воркеров, много раундов: шард достаётся
    // ровно одному воркеру, остальные получают null, дублей нет ни в одном раунде.
    public function testOneFreeShardIsGrantedToExactlyOneWorker(): void
    {
        $freeShards = 1;
        $workers = self::WORKERS_ONE_FREE_SHARD;

        // воркер: отдельный поток, собственное соединение SQLite; сначала
        // поднимает автолоадер, затем ждёт стартовую миллисекунду (цикл 1 мс)
        $worker = static function (array $ctx, int $startMs): array {
            try {
                require $ctx['autoload'];

                // ждём наступления стартовой миллисекунды; итерация ожидания — 1 мс
                while ((int)\floor(\microtime(true) * 1000) < $startMs) {
                    \usleep(1000);
                }

                \usleep($ctx['jitterUs']); // мкс: лёгкий разброс внутри стартовой мс

                $reg = \Inilim\SqliteShard\Registry::discover($ctx['baseDir'], $ctx['name']);
                if (null === $reg) {
                    return ['ok' => false, 'id' => null, 'valid' => null, 'left' => null, 'error' => 'registry not found'];
                }

                $shard = $reg->tryRentFree();
                if (null === $shard) {
                    return ['ok' => false, 'id' => null, 'valid' => null, 'left' => null, 'error' => null];
                }

                return [
                    'ok' => true,
                    'id' => $shard->id,
                    'valid' => $reg->rentValid($shard),
                    'left' => $reg->leftTimeUntilRelease($shard),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'id' => null,
                    'valid' => null,
                    'left' => null,
                    'error' => \get_class($e) . ': ' . $e->getMessage(),
                ];
            }
        };

        // потоки создаются один раз до цикла раундов: подъём Runtime — самая
        // дорогая часть, и она больше не повторяется в каждом раунде
        /** @var Flow[] $flows */
        $flows = [];
        for ($i = 0; $i < $workers; $i++) {
            $flows[] = new Flow; // один Runtime = один поток
        }

        try {
            for ($round = 0; $round < self::ROUNDS_ONE_FREE_SHARD; $round++) {
                $name = 'one-free-' . $round;

                // реестр создаёт главный поток; воркеры только discover() + tryRentFree()
                $this->registerRegistry(Registry::create(new CreateConfig(
                    baseDir: $this->tmpDir,
                    schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                    name: $name,
                    minShards: $freeShards,
                    rentTimeout: 30,
                )));

                // тёплые потоки уже на барьере: оффсет покрывает только диспатч
                // задачи и открытие соединения, поэтому сотни миллисекунд, а не
                // секунды; все воркеры гарантированно успевают стать на барьер
                $startMs = (int)\floor(\microtime(true) * 1000) + 300;

                /** @var Task[] $tasks */
                $tasks = [];
                for ($i = 0; $i < $workers; $i++) {
                    $tasks[] = $flows[$i]->execAndGetTask($worker, [
                        'autoload' => \dirname(__DIR__, 2) . '/vendor/autoload.php',
                        'baseDir'  => $this->tmpDir,
                        'name'     => $name,
                        'jitterUs' => \random_int(0, 500),
                    ], $startMs);
                }

                $results = [];
                foreach ($tasks as $task) {
                    $task->waitNative();
                    $results[] = $task->value();
                }

                // --- проверки раунда ---
                self::assertSame(
                    $workers,
                    \count($results),
                    "round {$round}: expected results from all {$workers} workers",
                );

                $errors = [];
                $winnersById = [];

                foreach ($results as $i => $r) {
                    if (null !== $r['error']) {
                        $errors[$i] = $r['error'];
                        continue;
                    }
                    if (true === $r['ok']) {
                        $id = (string)$r['id'];
                        $winnersById[$id] = ($winnersById[$id] ?? 0) + 1;
                        self::assertTrue(
                            true === $r['valid'],
                            "round {$round}: winner #{$i} on shard {$id} has invalid rent",
                        );
                        self::assertGreaterThan(
                            0,
                            $r['left'],
                            "round {$round}: winner #{$i} on shard {$id} has no remaining lease",
                        );
                    }
                }

                self::assertSame(
                    [],
                    $errors,
                    'round ' . $round . ': workers failed: ' . \implode('; ', $errors),
                );

                // ГЛАВНЫЙ инвариант: один шард не может быть выдан нескольким воркерам.
                $duplicates = \array_filter($winnersById, static fn(int $c): bool => $c > 1);
                self::assertSame(
                    [],
                    $duplicates,
                    'round ' . $round . ': shard rented by several workers: ' . \json_encode($winnersById, \JSON_UNESCAPED_UNICODE),
                );

                $winners = \count($winnersById);
                self::assertLessThanOrEqual($freeShards, $winners, "round {$round}: more winners than free shards");
                if (1 === $freeShards) {
                    self::assertCount(1, $winnersById, "round {$round}: a single free shard must go to exactly one worker");
                }

                // согласованность: после раунда свободно ровно freeShards - победители
                // (аренда 30с за миллисекунды раунда истечь не успевает)
                $fresh = Registry::discover($this->tmpDir, $name);
                self::assertNotNull($fresh, "round {$round}: registry disappeared after the round");
                if (null !== $fresh) {
                    $this->registerRegistry($fresh);
                    self::assertSame(
                        $freeShards - $winners,
                        \count($fresh->getFreeShards()),
                        "round {$round}: free shards count mismatch after the round",
                    );
                }
            }
        } finally {
            // закрытие Runtime: close() ожидает завершения всех задач
            unset($flows);
        }
    }

    // Несколько свободных шардов, больше воркеров: один и тот же шард не может
    // быть выдан двум воркерам; каждый победитель держит валидную аренду.
    public function testNoShardIsRentedByTwoWorkersSimultaneously(): void
    {
        $freeShards = self::FREE_SHARDS_MULTI;
        $workers = self::WORKERS_MULTI_SHARD;

        // воркер: отдельный поток, собственное соединение SQLite; сначала
        // поднимает автолоадер, затем ждёт стартовую миллисекунду (цикл 1 мс)
        $worker = static function (array $ctx, int $startMs): array {
            try {
                require $ctx['autoload'];

                // ждём наступления стартовой миллисекунды; итерация ожидания — 1 мс
                while ((int)\floor(\microtime(true) * 1000) < $startMs) {
                    \usleep(1000);
                }

                \usleep($ctx['jitterUs']); // мкс: лёгкий разброс внутри стартовой мс

                $reg = \Inilim\SqliteShard\Registry::discover($ctx['baseDir'], $ctx['name']);
                if (null === $reg) {
                    return ['ok' => false, 'id' => null, 'valid' => null, 'left' => null, 'error' => 'registry not found'];
                }

                $shard = $reg->tryRentFree();
                if (null === $shard) {
                    return ['ok' => false, 'id' => null, 'valid' => null, 'left' => null, 'error' => null];
                }

                return [
                    'ok' => true,
                    'id' => $shard->id,
                    'valid' => $reg->rentValid($shard),
                    'left' => $reg->leftTimeUntilRelease($shard),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'id' => null,
                    'valid' => null,
                    'left' => null,
                    'error' => \get_class($e) . ': ' . $e->getMessage(),
                ];
            }
        };

        // потоки создаются один раз до цикла раундов: подъём Runtime — самая
        // дорогая часть, и она больше не повторяется в каждом раунде
        /** @var Flow[] $flows */
        $flows = [];
        for ($i = 0; $i < $workers; $i++) {
            $flows[] = new Flow; // один Runtime = один поток
        }

        try {
            for ($round = 0; $round < self::ROUNDS_MULTI_SHARD; $round++) {
                $name = 'multi-' . $round;

                // реестр создаёт главный поток; воркеры только discover() + tryRentFree()
                $this->registerRegistry(Registry::create(new CreateConfig(
                    baseDir: $this->tmpDir,
                    schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                    name: $name,
                    minShards: $freeShards,
                    rentTimeout: 30,
                )));

                // тёплые потоки уже на барьере: оффсет покрывает только диспатч
                // задачи и открытие соединения, поэтому сотни миллисекунд, а не
                // секунды; все воркеры гарантированно успевают стать на барьер
                $startMs = (int)\floor(\microtime(true) * 1000) + 300;

                /** @var Task[] $tasks */
                $tasks = [];
                for ($i = 0; $i < $workers; $i++) {
                    $tasks[] = $flows[$i]->execAndGetTask($worker, [
                        'autoload' => \dirname(__DIR__, 2) . '/vendor/autoload.php',
                        'baseDir'  => $this->tmpDir,
                        'name'     => $name,
                        'jitterUs' => \random_int(0, 500),
                    ], $startMs);
                }

                $results = [];
                foreach ($tasks as $task) {
                    $task->waitNative();
                    $results[] = $task->value();
                }

                // --- проверки раунда ---
                self::assertSame(
                    $workers,
                    \count($results),
                    "round {$round}: expected results from all {$workers} workers",
                );

                $errors = [];
                $winnersById = [];

                foreach ($results as $i => $r) {
                    if (null !== $r['error']) {
                        $errors[$i] = $r['error'];
                        continue;
                    }
                    if (true === $r['ok']) {
                        $id = (string)$r['id'];
                        $winnersById[$id] = ($winnersById[$id] ?? 0) + 1;
                        self::assertTrue(
                            true === $r['valid'],
                            "round {$round}: winner #{$i} on shard {$id} has invalid rent",
                        );
                        self::assertGreaterThan(
                            0,
                            $r['left'],
                            "round {$round}: winner #{$i} on shard {$id} has no remaining lease",
                        );
                    }
                }

                self::assertSame(
                    [],
                    $errors,
                    'round ' . $round . ': workers failed: ' . \implode('; ', $errors),
                );

                // ГЛАВНЫЙ инвариант: один шард не может быть выдан нескольким воркерам.
                $duplicates = \array_filter($winnersById, static fn(int $c): bool => $c > 1);
                self::assertSame(
                    [],
                    $duplicates,
                    'round ' . $round . ': shard rented by several workers: ' . \json_encode($winnersById, \JSON_UNESCAPED_UNICODE),
                );

                $winners = \count($winnersById);
                self::assertLessThanOrEqual($freeShards, $winners, "round {$round}: more winners than free shards");

                // согласованность: после раунда свободно ровно freeShards - победители
                // (аренда 30с за миллисекунды раунда истечь не успевает)
                $fresh = Registry::discover($this->tmpDir, $name);
                self::assertNotNull($fresh, "round {$round}: registry disappeared after the round");
                if (null !== $fresh) {
                    $this->registerRegistry($fresh);
                    self::assertSame(
                        $freeShards - $winners,
                        \count($fresh->getFreeShards()),
                        "round {$round}: free shards count mismatch after the round",
                    );
                }
            }
        } finally {
            // закрытие Runtime: close() ожидает завершения всех задач
            unset($flows);
        }
    }
}
