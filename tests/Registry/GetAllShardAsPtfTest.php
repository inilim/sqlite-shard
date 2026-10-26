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
 * {@see Registry::getAllShardAsPtf()}
 *
 *  «getAllShardAsPtf»
 */
final class GetAllShardAsPtfTest extends TestCase
{
    // Возвращает \Generator.
    public function testReturnsGenerator(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertInstanceOf(\Generator::class, $reg->getAllShardAsPtf());
    }

    // Пустой реестр → ноль пар (id => пусто).
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

        self::assertSame([], \iterator_to_array($reg->getAllShardAsPtf()));
    }

    // Для N шардов — ровно N пар; ключи — id, значения — пути {baseDir}/{id}.sqlite.
    public function testYieldsIdToPathPairs(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsPtf());

        self::assertCount(5, $pairs);
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        foreach ($pairs as $id => $ptf) {
            self::assertContains($id, $ids);
            $shard = null;
            foreach ($reg->getAllShards() as $s) {
                if ($s->id === $id) {
                    $shard = $s;
                    break;
                }
            }
            self::assertSame(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)), $ptf);
        }
    }

    // Каждый путь — файл, существующий на диске.
    public function testEachPathIsAnExistingFile(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        foreach ($reg->getAllShardAsPtf() as $id => $ptf) {
            self::assertStringEndsWith('/' . $id . '.sqlite', $ptf);
            self::assertFileExists($ptf);
        }
    }

    // Шарды в любом состоянии аренды присутствуют в результате.
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

        self::assertCount(5, \iterator_to_array($reg->getAllShardAsPtf()));
    }

    // Ленивость: шард, созданный ДО первой итерации, попадает в результат.
    public function testShardAddedBeforeIterationIsIncluded(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $gen = $reg->getAllShardAsPtf();
        $reg->createNewShards(1); // новый шард до первой итерации

        $pairs = \iterator_to_array($gen);
        self::assertCount(6, $pairs);
        self::assertSame(6, $reg->getCountShards());
    }

    // Шард, добавленный после начала итерации, не попадает.
    public function testShardAddedAfterIterationStartedIsNotIncluded(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $gen = $reg->getAllShardAsPtf();
        $first = $gen->current(); // запускает генератор
        self::assertIsString($first);

        $reg->createNewShards(1);

        // явный обход без rewind (foreach по запущенному генератору запрещён)
        $count = 0;
        do {
            $count++;
            $gen->next();
        } while ($gen->valid());

        self::assertSame(5, $count);
    }

    // Удалённый файл шарда не влияет на выдачу пары.
    public function testMissingFileStillYieldsPair(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        $pairs = \iterator_to_array($reg->getAllShardAsPtf());
        self::assertCount(5, $pairs);
        self::assertArrayHasKey($shard->id, $pairs);
    }

    // Повторная итерация того же генератора невозможна: после полного обхода
    // генератор закрыт.
    public function testGeneratorCannotBeReiterated(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $gen = $reg->getAllShardAsPtf();
        $first = \iterator_to_array($gen);
        self::assertCount(5, $first);

        $second = [];
        // повторная итерация закрытого генератора невозможна
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/already closed generator/');
        foreach ($gen as $id => $ptf) {
            $second[] = $id;
        }
    }

    // Порядок пар не гарантирован — сравнивается набор ключей-идентификаторов.
    public function testKeySetMatchesAllShardIds(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $pairs = \iterator_to_array($reg->getAllShardAsPtf());

        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, \array_keys($pairs));
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards()));
        \sort($expected);
        self::assertSame($expected, $actual);
    }

    // Метод не открывает соединения и не меняет состояние.
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

        $pairs = \iterator_to_array($reg->getAllShardAsPtf());

        self::assertTrue($reg->rentValid($busy));
        self::assertSame(5, $reg->getCountShards());
        self::assertCount(5, $pairs);
    }
}
