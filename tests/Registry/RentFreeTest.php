<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\Parallel\Flow;
use Inilim\Parallel\Task;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFreeException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::rentFree()}
 *
 *  «rentFree»
 */
final class RentFreeTest extends TestCase
{
    // При нескольких свободных шардах rentFree арендует ровно один: свободных
    // остаётся 4 из 5, остальные свободны; возвращённый шард ссылается на тот же
    // Registry (assertSame).
    public function testRentFreeRentsExactlyOneOfFreeShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        self::assertCount(5, $reg->getFreeShards());

        $shard = $reg->rentFree();

        self::assertInstanceOf(Shard::class, $shard);
        self::assertSame($reg, $shard->reg);
        self::assertTrue($reg->rentValid($shard));
        self::assertFalse($reg->isFree($shard));
        self::assertCount(4, $reg->getFreeShards());
        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
        foreach ($reg->getFreeShards() as $other) {
            self::assertTrue($reg->isFree($other));
        }
    }

    // При одном свободном среди занятых rentFree возвращает именно этот шард:
    // арендуем 4 из 5, оставшийся свободный — результат rentFree.
    public function testRentFreeReturnsTheOnlyFreeShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $free = $reg->getFreeShards();
        self::assertCount(5, $free);

        $rentedIds = [];
        foreach (\array_slice($free, 0, 4) as $shard) {
            $reg->rent($shard);
            $rentedIds[] = $shard->id;
        }
        $remainingId = \array_values(\array_diff(\array_map(static fn(Shard $s): string => $s->id, $free), $rentedIds))[0];

        $result = $reg->rentFree();

        self::assertSame($remainingId, $result->id);
        self::assertFalse($reg->isFree($result));
        self::assertTrue($reg->rentValid($result));
    }

    // Если свободных нет, rentFree выбрасывает ShardNotFreeException с сообщением
    // 'There are no free shards'; количество и состояние шардов не меняются.
    public function testRentFreeWithNoFreeShardsThrowsAndKeepsState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        $countBefore = $reg->getCountShards();
        foreach ($shards as $shard) {
            $reg->rent($shard);
        }

        try {
            $reg->rentFree();
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException $e) {
            self::assertSame('There are no free shards', $e->getMessage());
        }

        self::assertSame($countBefore, $reg->getCountShards());
        foreach ($shards as $shard) {
            self::assertTrue($reg->rentValid($shard));
        }
    }

    // Пустой реестр (удалены все строки из shards): тот же ShardNotFreeException
    // с тем же сообщением 'There are no free shards'.
    public function testRentFreeOnEmptyRegistryThrows(): void
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

        $this->expectException(ShardNotFreeException::class);
        $this->expectExceptionMessage('There are no free shards');
        $reg->rentFree();
    }

    // Если есть только просроченные активные шарды (все rent + setUpdatedAt
    // ago(40) при таймауте 30), rentFree возвращает один из них и успешно
    // арендует — leftTime снова ~30.
    public function testRentFreeRentsExpiredActiveShard(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        foreach ($reg->getAllShards() as $shard) {
            $reg->rent($shard);
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shard->id]);
            } finally {
                $ipdo->close();
            }
        }

        $result = $reg->rentFree();

        self::assertInstanceOf(Shard::class, $result);
        self::assertTrue($reg->rentValid($result));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($result));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($result));
    }

    // Освобождённый шард — кандидат на аренду: арендуем все 5, освобождаем один,
    // rentFree возвращает именно его.
    public function testRentFreeReturnsReleasedShard(): void
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
        // освобождаем один — остальные заняты
        $target = $shards[0];
        $reg->release($target);

        $result = $reg->rentFree();

        self::assertSame($target->id, $result->id);
        self::assertTrue($reg->rentValid($result));
    }

    // Последовательные вызовы арендуют разные свободные шарды; когда свободные
    // заканчиваются (5 из 5), следующий вызов бросает ShardNotFreeException.
    public function testConsecutiveRentFreeCallsRentDifferentShardsUntilExhausted(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $rentedIds = [];

        for ($i = 0; $i < 5; $i++) {
            $shard = $reg->rentFree();
            self::assertNotContains($shard->id, $rentedIds);
            $rentedIds[] = $shard->id;
        }

        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $rentedIds);
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $allIds);
        \sort($expected);
        self::assertSame($expected, $actual);

        $this->expectException(ShardNotFreeException::class);
        $this->expectExceptionMessage('There are no free shards');
        $reg->rentFree();
    }

    // После успешного вызова getFreeShards не содержит арендованный шард,
    // количество свободных уменьшилось на 1.
    public function testFreeShardsShrinkByOneAfterRentFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $freeBefore = \count($reg->getFreeShards());

        $shard = $reg->rentFree();

        $freeAfter = $reg->getFreeShards();
        self::assertCount($freeBefore - 1, $freeAfter);
        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $freeAfter));
    }

    // Без гонки rentFree берёт первый элемент getFreeShards — шард с наименьшим
    // updated_at: из трёх свободных (ago(50)/ago(40)/ago(30)) выбирается ago(50)
    // (максимально старый); остальные два заняты арендой.
    public function testRentFreePicksOldestFreeShard(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shards = $reg->getAllShards();
        self::assertCount(5, $shards);

        // два шарда арендуем (заняты), трём свободным ставим разные updated_at
        $reg->rent($shards[3]);
        $reg->rent($shards[4]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 50, 'id' => $shards[0]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shards[1]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 30, 'id' => $shards[2]->id]);
        } finally {
            $ipdo->close();
        }

        $result = $reg->rentFree();

        self::assertSame($shards[0]->id, $result->id);
        self::assertTrue($reg->rentValid($result));
    }

    // Отсутствие файла у кандидата не мешает аренде: unlink файла первого
    // свободного шарда, rentFree всё равно возвращает его и успешно арендует.
    public function testRentFreeSucceedsWhenCandidateShardFileMissing(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $free = $reg->getFreeShards();
        self::assertNotEmpty($free);
        $candidate = $free[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $candidate->id)));

        $result = $reg->rentFree();

        self::assertSame($candidate->id, $result->id);
        self::assertTrue($reg->rentValid($result));
    }

    // Изменение rent_timeout меняет набор кандидатов и результат: активный
    // с ago(20) при таймауте 30 не кандидат (rentFree падает), после
    // setRentTimeout(10) rentFree возвращает именно его.
    public function testChangingRentTimeoutChangesRentFreeCandidate(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $target = $reg->getAllShards()[0];

        // арендуем целевой шард (активен) и все остальные — свежими
        foreach ($reg->getAllShards() as $shard) {
            $reg->rent($shard);
        }
        // целевому шарду ставим ago(20): при таймауте 30 он занят...
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 20, 'id' => $target->id]);
        } finally {
            $ipdo->close();
        }

        self::assertNotContains($target->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
        try {
            $reg->rentFree();
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException) {
        }

        // ...а при таймауте 10 становится единственным кандидатом
        $reg->setRentTimeout(10);

        $result = $reg->rentFree();
        self::assertSame($target->id, $result->id);
        self::assertTrue($reg->rentValid($result));
    }

    // Гонка всех кандидатов (настоящий параллелизм): два потока одновременно
    // вызывают rentFree, когда свободен ровно один шард (minShards = 1).
    // Ожидается: ровно один вызов возвращает Shard, второй выбрасывает
    // ShardNotFreeException с сообщением 'There are no free shards' (у каждого
    // tryRent в проигравшем — false, либо кандидатов уже не осталось).
    public function testRentFreeRaceAllCandidatesRentedByAnotherInstance(): void
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
                $shard = $reg->rentFree();
                return ['ok' => true, 'id' => $shard->id, 'message' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'id' => null, 'message' => \get_class($e) . '|' . $e->getMessage()];
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
                $errors[] = $r['message'];
            }
        }
        self::assertCount(1, $winners, 'ровно один rentFree должен выиграть: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $errors);
        self::assertStringStartsWith(ShardNotFreeException::class, $errors[0]);
        self::assertStringContainsString('There are no free shards', $errors[0]);

        // итоговое состояние: единственный шард занят победителем
        $shard = new Shard($winners[0], $regA);
        self::assertTrue($regA->rentValid($shard));
        self::assertCount(0, $regA->getFreeShards());
    }

    // rentFree не создаёт новые шарды: количество до и после одинаково.
    public function testRentFreeDoesNotCreateShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $countBefore = $reg->getCountShards();

        $reg->rentFree();

        self::assertSame($countBefore, $reg->getCountShards());
    }

    // При успехе метод возвращает именно Shard (assertInstanceOf), а не null.
    public function testRentFreeReturnsShardInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $shard = $reg->rentFree();

        self::assertInstanceOf(Shard::class, $shard);
    }
}
