<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::getAllShardAsIPDO()}
 *
 *  «getAllShardAsIPDO»
 */
final class GetAllShardAsIPDOTest extends TestCase
{
    // Возвращает \Generator.
    public function testReturnsGenerator(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertInstanceOf(\Generator::class, $reg->getAllShardAsIPDO());
    }

    // Пустой реестр → ноль пар.
    public function testEmptyRegistryYieldsNothing(): void
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

        self::assertSame([], \iterator_to_array($reg->getAllShardAsIPDO()));
    }

    // Для N шардов — N пар; значения — объекты IPDOSQLite, ключи — id.
    public function testYieldsIdToIPDOPairs(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsIPDO());

        self::assertCount(5, $pairs);
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        foreach ($pairs as $id => $ipdo) {
            self::assertContains($id, $ids);
            self::assertInstanceOf(IPDOSQLite::class, $ipdo);
        }
    }

    // Каждый объект указывает на файл шарда и открывает схему items.
    public function testEachIPDOPointsToShardFileWithSchema(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        foreach ($reg->getAllShardAsIPDO() as $id => $ipdo) {
            $shard = null;
            foreach ($reg->getAllShards() as $s) {
                if ($s->id === $id) {
                    $shard = $s;
                    break;
                }
            }
            self::assertNotNull($shard);
            self::assertSame(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)), Path::normalize($ipdo->getMainFile()));
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // Изоляция: запись в первый шард не видна во втором.
    public function testShardsAreIsolated(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsIPDO());
        $ids = \array_keys($pairs);
        $first = $pairs[$ids[0]];
        try {
            $first->exec("INSERT INTO items (name) VALUES ('iso')", [], IPDOSQLite::FETCH_VOID);
        } finally {
            $first->close();
        }

        $second = $pairs[$ids[1]];
        try {
            $row = $second->exec("SELECT COUNT(*) FROM items WHERE name = 'iso'", [], IPDOSQLite::FETCH_ONCE_NUM);
            self::assertSame(0, (int)$row[0]);
        } finally {
            $second->close();
        }
    }

    // Шарды в любом состоянии аренды присутствуют.
    public function testIncludesShardsInAnyRentState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $reg->rent($all[0]);
        $reg->rent($all[1]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }
        $reg->rent($all[2]);
        $reg->release($all[2]);

        $count = 0;
        foreach ($reg->getAllShardAsIPDO() as $ipdo) {
            $ipdo->close();
            $count++;
        }
        self::assertSame(5, $count);
    }

    // Ленивость: итерация начинается явным rewind() — список шардов
    // фиксируется в этот момент.
    public function testLaziness(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        // до начала итерации — новый шард попадает
        $gen = $reg->getAllShardAsIPDO();
        $reg->createNewShards(1);
        self::assertCount(6, \iterator_to_array($gen));

        // после начала итерации — новый шард уже не попадает
        $gen2 = $reg->getAllShardAsIPDO();
        $gen2->rewind(); // старт итерации — отсюда список шардов фиксирован
        $first = $gen2->current();
        self::assertInstanceOf(IPDOSQLite::class, $first);
        $first->close();

        $reg->createNewShards(1);

        $count = 0; // первый элемент уже прочитан через current() — считаем с него
        while ($gen2->valid()) {
            $gen2->current()->close();
            $count++;
            $gen2->next();
        }
        self::assertSame(6, $count);
    }

    // Удалённый файл: пара выдаётся, первая операция объекта бросает
    // IPDOException.
    public function testDeletedShardFileThrowsOnOperation(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));
        $shard = $reg->getAllShards()[0];
        $ptf = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id));
        self::assertFileExists($ptf);
        \unlink($ptf);

        $pairs = \iterator_to_array($reg->getAllShardAsIPDO());
        self::assertCount(1, $pairs);
        $ipdo = $pairs[$shard->id];

        $this->expectException(IPDOException::class);
        $ipdo->exec('SELECT 1');
    }

    // Каждый вызов метода создаёт новые объекты; соединение ленивое.
    public function testEachCallReturnsFreshLazyInstances(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $a = $reg->getAllShardAsIPDO()->current();
        $b = $reg->getAllShardAsIPDO()->current();

        self::assertNotSame($a, $b);
        self::assertFalse($a->isConnected());
        self::assertFalse($b->isConnected());

        $a->exec('SELECT 1');
        self::assertTrue($a->isConnected());
    }

    // close() одного объекта не влияет на другие.
    public function testCloseDoesNotAffectOthers(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 2,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsIPDO());
        $objects = \array_values($pairs);
        $objects[0]->close();

        $objects[1]->exec('SELECT 1');
        self::assertTrue($objects[1]->isConnected());
    }

    // Метод не меняет состояние аренды и не создаёт файлы.
    public function testDoesNotChangeStateOrCreateFiles(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');
        \sort($filesBefore);

        foreach ($reg->getAllShardAsIPDO() as $ipdo) {
            $ipdo->close();
        }

        self::assertTrue($reg->rentValid($busy));
        $filesAfter = \glob($this->tmpDir . '/*.sqlite');
        \sort($filesAfter);
        self::assertSame($filesBefore, $filesAfter);
    }

    // Набор ключей совпадает с набором id всех шардов.
    public function testKeySetMatchesAllShardIds(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsIPDO());

        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, \array_keys($pairs));
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards()));
        \sort($expected);
        self::assertSame($expected, $actual);
    }
}
