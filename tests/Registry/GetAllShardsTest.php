<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::getAllShards()}
 *
 *  «getAllShards»
 */
final class GetAllShardsTest extends TestCase
{
    // После create() с minShards=5 метод возвращает 5 шардов.
    public function testReturnsAllShardsAfterCreate(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        self::assertCount(5, $reg->getAllShards());
    }

    // После createNewShards(2) возвращает 7 шардов; два новых присутствуют.
    public function testIncludesNewlyCreatedShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $before = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $reg->createNewShards(2);
        $afterIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        self::assertCount(7, $afterIds);
        foreach ($before as $old) {
            self::assertContains($old, $afterIds);
        }
        self::assertCount(2, \array_diff($afterIds, $before));
    }

    // Все элементы — Shard; их id соответствуют созданным, reg указывает на
    // текущий Registry.
    public function testEachElementIsShardReferencingCurrentRegistry(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        self::assertCount(5, $ids);

        foreach ($reg->getAllShards() as $shard) {
            self::assertInstanceOf(Shard::class, $shard);
            self::assertContains($shard->id, $ids);
            self::assertSame($reg, $shard->reg);
        }
    }

    // Шарды в любом состоянии аренды присутствуют в результате.
    public function testReturnsShardsInAnyState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $reg->rent($all[0]);                                  // занятый
        $reg->rent($all[1]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry); // просроченный
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }
        $reg->rent($all[2]);
        $reg->release($all[2]);                               // освобождённый

        self::assertCount(5, $reg->getAllShards());
    }

    // Ключи массива — последовательные целые числа 0..N-1.
    public function testKeysAreSequentialIntegers(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        self::assertSame(\range(0, 4), \array_keys($reg->getAllShards()));
    }

    // Пустой реестр (все строки удалены) → пустой массив.
    public function testEmptyRegistryReturnsEmptyArray(): void
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

        self::assertSame([], $reg->getAllShards());
    }

    // Порядок не гарантирован — метод сравнивает набор id.
    public function testOrderIsNotGuaranteed(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $reg->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $ids);
        \sort($expected);
        self::assertSame($expected, $actual);
    }

    // Два последовательных вызова дают разные объекты Shard с одним набором id.
    public function testEachCallReturnsNewShardObjects(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $first = $reg->getAllShards();
        $second = $reg->getAllShards();

        self::assertNotSame($first[0], $second[0]);
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $first);
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $second);
        \sort($expected);
        self::assertSame($expected, $actual);
    }

    // Шард с удалённым файлом, но с записью, всё равно присутствует.
    public function testShardWithMissingFileIsStillReturned(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertCount(5, $reg->getAllShards());
        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards()));
    }

    // Метод не меняет состояние аренды, количество шардов и файлы.
    public function testDoesNotChangeState(): void
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

        $reg->getAllShards();

        self::assertSame(5, $reg->getCountShards());
        self::assertTrue($reg->rentValid($busy));
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }
}
