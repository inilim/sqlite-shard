<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\Parallel\Flow;
use Inilim\Parallel\Task;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;

/**
 * {@see Registry::tryRentFree()}
 *
 *  «tryRentFree»
 */
final class TryRentFreeTest extends TestCase
{
    // При наличии свободного шарда возвращает этот Shard (assertInstanceOf),
    // и шард становится арендованным (rentValid true); возвращённый шард —
    // один из кандидатов getFreeShards и ссылается на тот же Registry.
    public function testTryRentFreeReturnsFreeShardAndRentsIt(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $free = $reg->getFreeShards();
        self::assertNotEmpty($free);

        $shard = $reg->tryRentFree();

        self::assertInstanceOf(Shard::class, $shard);
        self::assertSame($reg, $shard->reg);
        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $free));
        self::assertTrue($reg->rentValid($shard));
        self::assertFalse($reg->isFree($shard));
    }

    // Если свободных нет — возвращает null без исключения; состояние не
    // меняется: арендуем все 5 шардов, затем tryRentFree() === null,
    // rentValid у всех шардов остаётся true.
    public function testTryRentFreeWithNoFreeShardsReturnsNullAndKeepsState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        foreach ($shards as $shard) {
            $reg->rent($shard);
        }

        $result = $reg->tryRentFree();

        self::assertNull($result);
        self::assertSame(5, $reg->getCountShards());
        foreach ($shards as $shard) {
            self::assertTrue($reg->rentValid($shard));
        }
    }

    // Если все шарды заняты — null: арендуем все 5, свободных кандидатов нет,
    // tryRentFree возвращает null, аренда ни одного шарда не теряется.
    public function testTryRentFreeWhenAllShardsAreBusyReturnsNull(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        foreach ($reg->getAllShards() as $shard) {
            $reg->rent($shard);
        }
        self::assertSame([], $reg->getFreeShards());

        $result = $reg->tryRentFree();

        self::assertNull($result);
        foreach ($reg->getAllShards() as $shard) {
            self::assertTrue($reg->rentValid($shard));
        }
    }

    // Для пустого реестра (удалены все строки из shards) — null без исключения.
    public function testTryRentFreeOnEmptyRegistryReturnsNull(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        foreach ($reg->getAllShards() as $shard) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
            } finally {
                $ipdo->close();
            }
        }
        self::assertSame(0, $reg->getCountShards());

        $result = $reg->tryRentFree();

        self::assertNull($result);
    }

    // Если освободить арендованный шард, следующий вызов возвращает именно его:
    // арендуем все 5, освобождаем один (единственный свободный кандидат),
    // tryRentFree возвращает его id.
    public function testTryRentFreeReturnsReleasedShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        foreach ($shards as $shard) {
            $reg->rent($shard);
        }
        // освобождаем один из занятых — остальные 4 заняты
        $target = $shards[0];
        $reg->release($target);

        $result = $reg->tryRentFree();

        self::assertInstanceOf(Shard::class, $result);
        self::assertSame($target->id, $result->id);
        self::assertTrue($reg->rentValid($result));
    }

    // При последовательных вызовах возвращает шарды, пока они есть; после
    // исчерпания (5 из 5) следующий вызов возвращает null. Все id уникальны.
    public function testConsecutiveCallsRentDifferentShardsUntilExhausted(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $shard = $reg->tryRentFree();
            self::assertInstanceOf(Shard::class, $shard);
            self::assertNotContains($shard->id, $ids);
            $ids[] = $shard->id;
        }

        self::assertCount(5, $ids);
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $ids);
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $allIds);
        \sort($expected);
        self::assertSame($expected, $actual);

        $result = $reg->tryRentFree();
        self::assertNull($result);
    }

    // При null не меняет количество шардов, аренду и updated_at: запоминаем
    // count и leftTimeUntilRelease (отражает updated_at) / rentValid каждого
    // шарда до и после вызова — всё совпадает.
    public function testNullResultDoesNotChangeCountRentAndUpdatedAt(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        foreach ($shards as $shard) {
            $reg->rent($shard);
        }

        $countBefore = $reg->getCountShards();
        $stateBefore = [];
        foreach ($shards as $shard) {
            $stateBefore[$shard->id] = [
                $reg->leftTimeUntilRelease($shard),
                $reg->rentValid($shard),
            ];
        }

        self::assertNull($reg->tryRentFree());

        self::assertSame($countBefore, $reg->getCountShards());
        foreach ($shards as $shard) {
            self::assertSame($stateBefore[$shard->id][0], $reg->leftTimeUntilRelease($shard));
            self::assertSame($stateBefore[$shard->id][1], $reg->rentValid($shard));
        }
    }

    // Не превращает другие исключения в null: если таблица shards повреждена
    // (DROP TABLE shards), вызов tryRentFree пробрасывает IPDOException наружу,
    // а не возвращает null.
    public function testTryRentFreeDoesNotSwallowOtherExceptions(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DROP TABLE shards');
        } finally {
            $ipdo->close();
        }

        $this->expectException(IPDOException::class);
        $reg->tryRentFree();
    }

    // В «гонке», где все кандидаты проиграли аренду (настоящий параллелизм):
    // два потока одновременно вызывают tryRentFree при одном свободном шарде
    // (minShards = 1). Ожидается: один вызов возвращает Shard, второй — null;
    // один и тот же шард не может быть арендован обоими вызовами.
    public function testTryRentFreeRaceAllCandidatesRentedByAnotherInstance(): void
    {
        if (!\extension_loaded('parallel')) {
            self::markTestSkipped('Для настоящей параллельной гонки нужен ext-parallel (inilim/parallel).');
        }

        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));

        // стартовая миллисекунда с запасом в несколько секунд: воркеры успевают
        // прогрузиться (require autoload, подъём потока) до наступления старта
        $startMs = (int)\floor(\microtime(true) * 1000) + 3000;

        $ctx = [
            'autoload' => \dirname(__DIR__, 2) . '/vendor/autoload.php',
            'baseDir'  => $this->tmpDir,
        ];

        $worker = static function (array $ctx, int $startMs): array {
            try {
                require $ctx['autoload'];

                // ждём наступления стартовой миллисекунды; итерация ожидания — 1 мс
                while ((int)\floor(\microtime(true) * 1000) < $startMs) {
                    \usleep(1000);
                }
                $reg = Registry::discover($ctx['baseDir'], 'test');
                $shard = $reg->tryRentFree();
                return ['ok' => null !== $shard, 'id' => $shard?->id, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'id' => null, 'error' => \get_class($e)];
            }
        };

        /** @var Flow[] $flows */
        $flows = [];
        /** @var Task[] $tasks */
        $tasks = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $flow = new Flow; // один Runtime = один поток
                $flows[] = $flow;
                $tasks[] = $flow->execAndGetTask($worker, $ctx, $startMs);
            }

            $results = [];
            foreach ($tasks as $task) {
                $task->waitNative();
                $results[] = $task->value();
            }
        } finally {
            // закрытие Runtime: close() ожидает выполнения всех задач
            unset($tasks, $flows);
        }

        $winners = [];
        $errors = [];
        foreach ($results as $r) {
            if (true === $r['ok']) {
                $winners[] = $r['id'];
            } else {
                $errors[] = $r['error'];
            }
        }
        self::assertCount(1, $winners, 'ровно один tryRentFree должен выиграть: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));
        self::assertSame([null], $errors, 'проигравший получает null без исключений: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));

        // единственный шард занят одним арендатором
        $shard = new Shard($winners[0], $regA);
        self::assertTrue($regA->rentValid($shard));
        self::assertCount(0, $regA->getFreeShards());
    }

    // Возвращаемый тип — ?Shard: результат === null ИЛИ instanceof Shard
    // (проверяется и для случая со свободным шардом, и для пустого реестра).
    public function testReturnTypeIsNullableShard(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $shard = $reg->tryRentFree();
        self::assertTrue(null === $shard || $shard instanceof Shard);
        self::assertInstanceOf(Shard::class, $shard);

        // опустошаем реестр — тот же вызов даёт null, тип снова валиден
        foreach ($reg->getAllShards() as $existing) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $existing->id]);
            } finally {
                $ipdo->close();
            }
        }

        $emptyResult = $reg->tryRentFree();
        self::assertTrue(null === $emptyResult || $emptyResult instanceof Shard);
        self::assertNull($emptyResult);
    }

    // Метод не создаёт шарды: count до/после одинаков (5 → 5); также после
    // исчерпания свободных шардов количество остаётся 5.
    public function testTryRentFreeDoesNotCreateShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        self::assertSame(5, $reg->getCountShards());

        $rented = $reg->tryRentFree();
        self::assertInstanceOf(Shard::class, $rented);
        self::assertSame(5, $reg->getCountShards());

        // после исчерпания свободных шардов количество тоже не меняется
        foreach ($reg->getAllShards() as $shard) {
            if ($shard->id === $rented->id) {
                continue; // этот шард уже арендован tryRentFree
            }
            $reg->rent($shard);
        }
        self::assertNull($reg->tryRentFree());
        self::assertSame(5, $reg->getCountShards());
    }
}
