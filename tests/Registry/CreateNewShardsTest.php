<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\KeyNotFoundKvException;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::createNewShards()}
 *
 *  «createNewShards»
 */
final class CreateNewShardsTest extends TestCase
{
    // Вызов без аргумента создаёт ровно один шард и возвращает массив с одним
    // элементом.
    public function testDefaultCountIsOne(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $countBefore = $reg->getCountShards();

        $result = $reg->createNewShards();

        self::assertCount(1, $result);
        self::assertSame($countBefore + 1, $reg->getCountShards());
    }

    // count=3 возвращает массив из трёх элементов с последовательными ключами.
    public function testCountThreeReturnsThreeShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $result = $reg->createNewShards(3);

        self::assertCount(3, $result);
        self::assertSame([0, 1, 2], \array_keys($result));
        self::assertSame(8, $reg->getCountShards());
    }

    // У всех возвращённых шардов уникальные id вида UUID v4 и reg === Registry.
    public function testUniqueUuidIdsAndSameRegistryReference(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $result = $reg->createNewShards(5);

        $ids = [];
        foreach ($result as $shard) {
            self::assertInstanceOf(Shard::class, $shard);
            self::assertSame($reg, $shard->reg);
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $shard->id,
            );
            $ids[] = $shard->id;
        }
        self::assertCount(5, \array_unique($ids));
    }

    // Новые шарды свободны, неактивны и входят в getFreeShards.
    public function testNewShardsAreFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $created = $reg->createNewShards(2);

        $freeIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());
        foreach ($created as $shard) {
            self::assertTrue($reg->isFree($shard));
            self::assertFalse($reg->rentValid($shard));
            self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
            self::assertContains($shard->id, $freeIds);
        }
    }

    // Для каждого нового шарда существует файл со схемой items.
    public function testEachNewShardHasFileWithSchema(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        foreach ($reg->createNewShards(2) as $shard) {
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
            $ipdo = $reg->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // Несколько SQL-инструкций schemaSql применяются в порядке.
    public function testMultipleSchemaStatementsAppliedInOrder(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [
                'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
                'CREATE INDEX idx_name ON items(name)',
            ],
            name: 'test',
            minShards: 1,
        )));

        $shard = $reg->createNewShard();

        $ipdo = $reg->getIPDOByShard($shard);
        try {
            self::assertTrue($ipdo->existsTable('items'));
            $row = $ipdo->exec(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_name'",
                [],
                \Inilim\IPDO\IPDOSQLite::FETCH_ONCE_NUM,
            );
            self::assertIsArray($row);
            self::assertNotCount(0, $row);
        } finally {
            $ipdo->close();
        }
    }

    // Схема применяется к каждому шарду отдельно: запись, вставленная в первый
    // шард, отсутствует во втором.
    public function testSchemaIsAppliedPerShardIsolated(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        [$a, $b] = $reg->createNewShards(2);

        $ipdoA = $reg->getIPDOByShard($a);
        try {
            $ipdoA->exec("INSERT INTO items (name) VALUES ('x')", [], \Inilim\IPDO\IPDOSQLite::FETCH_VOID);
        } finally {
            $ipdoA->close();
        }

        $ipdoB = $reg->getIPDOByShard($b);
        try {
            $row = $ipdoB->exec('SELECT COUNT(*) FROM items', [], \Inilim\IPDO\IPDOSQLite::FETCH_ONCE_NUM);
            self::assertSame(0, (int)$row[0]);
        } finally {
            $ipdoB->close();
        }
    }

    // Пустая схема ([]) — корректные пустые SQLite-файлы.
    public function testEmptySchemaProducesValidEmptyFiles(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [],
            name: 'test',
            minShards: 1,
        )));

        $shard = $reg->createNewShard();

        $ipdo = $reg->getIPDOByShard($shard);
        try {
            $row = $ipdo->exec(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
                [],
                \Inilim\IPDO\IPDOSQLite::FETCH_ONCE_NUM,
            );
            self::assertSame(0, (int)$row[0]);
            self::assertFalse($ipdo->existsTable('items'));
        } finally {
            $ipdo->close();
        }
    }

    // Уже существующие шарды и их состояние не меняются.
    public function testExistingShardsUntouched(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);

        $oldShards = $reg->getAllShards();
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $oldShards);
        $oldFiles = [];
        foreach ($oldIds as $id) {
            $oldFiles[$id] = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $id));
            self::assertFileExists($oldFiles[$id]);
        }

        $reg->createNewShards(2);

        self::assertTrue($reg->rentValid($busy));
        $afterShards = $reg->getAllShards();
        self::assertCount(7, $afterShards);
        $afterIds = \array_map(static fn(Shard $s): string => $s->id, $afterShards);
        foreach ($oldIds as $id) {
            self::assertContains($id, $afterIds);
            self::assertFileExists($oldFiles[$id]);
        }
        foreach ($afterShards as $shard) {
            if ($shard->id === $busy->id) {
                self::assertFalse($reg->isFree($shard));
            } elseif (\in_array($shard->id, $oldIds, true)) {
                self::assertTrue($reg->isFree($shard));
            }
        }
    }

    // Несколько вызовов: массив первого вызова остаётся неизменным после второго.
    public function testResultOfFirstCallNotAffectedBySecond(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $first = $reg->createNewShards(2);
        $firstIds = \array_map(static fn(Shard $s): string => $s->id, $first);

        $reg->createNewShards(2);

        self::assertSame($firstIds, \array_map(static fn(Shard $s): string => $s->id, $first));
    }

    // count=0 → InvalidArgumentException; ничего не создаётся.
    public function testZeroThrowsInvalidArgumentException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        try {
            $reg->createNewShards(0);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(5, $reg->getCountShards());
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    // count=-1 → InvalidArgumentException; ничего не создаётся.
    public function testNegativeThrowsInvalidArgumentException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        try {
            $reg->createNewShards(-1);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(5, $reg->getCountShards());
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    // Не-int при strict_types=1 → TypeError; ничего не создаётся.
    public function testNonIntThrowsTypeError(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        foreach ([1.5, '2', true, null] as $bad) {
            try {
                $reg->createNewShards($bad);
                self::fail('TypeError expected');
            } catch (\TypeError) {
            }
        }

        self::assertSame(5, $reg->getCountShards());
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    // Некорректный schema_sql → IPDOException; частично созданные файлы
    // удаляются (запись не добавляется).
    public function testInvalidSchemaSqlThrowsAndCleansUp(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'schema_sql', 'v' => \json_encode(['value' => ['BREAK SQL']])]);
        } finally {
            $ipdo->close();
        }

        try {
            $reg->createNewShards(1);
            self::fail('IPDOException expected');
        } catch (IPDOException) {
            self::assertSame(5, $reg->getCountShards());
            self::assertEqualsCanonicalizing(
                $oldIds,
                \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards()),
            );
            self::assertCount(6, \glob($this->tmpDir . '/*.sqlite'));
        }
    }

    // Ошибка на втором шарде (триггер, запрещающий вторую запись): создаётся
    // только один новый шард, файл второго удаляется.
    public function testMultiShardFailureKeepsSuccessfulAndCleansFailed(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            // блокирует вставку, когда записей уже 6 (5 старых + 1 новый) —
            // то есть вторая запись вызова createNewShards(2) упадёт
            $ipdo->exec(
                "CREATE TRIGGER no_second BEFORE INSERT ON shards "
                    . "WHEN (SELECT COUNT(*) FROM shards) >= 6 "
                    . "BEGIN SELECT RAISE(ABORT, 'no second'); END",
                [],
                \Inilim\IPDO\IPDOSQLite::FETCH_VOID,
            );
        } finally {
            $ipdo->close();
        }

        try {
            $reg->createNewShards(2);
            self::fail('IPDOException expected');
        } catch (IPDOException) {
            // первый шард создан, второй — нет
            self::assertSame(6, $reg->getCountShards());

            $afterShards = $reg->getAllShards();
            $afterIds = \array_map(static fn(Shard $s): string => $s->id, $afterShards);
            $newIds = \array_values(\array_diff($afterIds, $oldIds));
            self::assertCount(1, $newIds);

            $newShard = null;
            foreach ($afterShards as $shard) {
                if ($shard->id === $newIds[0]) {
                    $newShard = $shard;
                    break;
                }
            }
            self::assertNotNull($newShard);
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $newShard->id)));
            self::assertTrue($reg->isFree($newShard));
        }

        // суммарный набор .sqlite: 5 старых шардов + реестр + 1 успешный новый
        self::assertCount(7, \glob($this->tmpDir . '/*.sqlite'));
    }

    // Отсутствует ключ schema_sql → KeyNotFoundKvException ДО создания файлов.
    public function testMissingSchemaSqlKeyThrowsKeyNotFound(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM kv WHERE k = {k}', ['k' => 'schema_sql']);
        } finally {
            $ipdo->close();
        }
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        try {
            $reg->createNewShards(1);
            self::fail('KeyNotFoundKvException expected');
        } catch (KeyNotFoundKvException) {
            self::assertSame(5, $reg->getCountShards());
            self::assertEqualsCanonicalizing(
                $oldIds,
                \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards()),
            );
            self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
        }
    }

    // count=20 создаёт 20 уникальных шардов, каждый со своим файлом.
    public function testManyShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));

        $created = $reg->createNewShards(20);

        self::assertCount(20, $created);
        $ids = [];
        foreach ($created as $shard) {
            $ids[] = $shard->id;
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
        }
        self::assertCount(20, \array_unique($ids));
        self::assertSame(21, $reg->getCountShards());
    }

    // После успешного вызова в baseDir нет лишних файлов: только реестр и шарды.
    public function testNoStrayFilesAfterSuccess(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $reg->createNewShards(2);

        $files = \glob($this->tmpDir . '/*.sqlite');
        self::assertCount(8, $files); // реестр + 7 шардов
        foreach ($files as $file) {
            $base = \basename($file);
            self::assertMatchesRegularExpression(
                '/^(registry-.*\.sqlite|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.sqlite)$/',
                $base,
                'Неожиданный файл: ' . $base,
            );
        }
    }
}