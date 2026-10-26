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
 * {@see Registry::createNewShard()}
 *
 *  «createNewShard»
 */
final class CreateNewShardTest extends TestCase
{
    // Возвращает один объект Shard; количество увеличивается ровно на 1.
    public function testReturnsOneShardAndIncrementsCount(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $countBefore = $reg->getCountShards();

        $shard = $reg->createNewShard();

        self::assertInstanceOf(Shard::class, $shard);
        self::assertSame($countBefore + 1, $reg->getCountShards());
        self::assertSame($reg, $shard->reg);
    }

    // Новый шард свободен, имеет файл {baseDir}/{id}.sqlite со схемой items.
    public function testNewShardIsFreeWithFileAndSchema(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        $shard = $reg->createNewShard();

        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));
        self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
        $ipdo = $reg->getIPDOByShard($shard);
        try {
            self::assertTrue($ipdo->existsTable('items'));
        } finally {
            $ipdo->close();
        }
    }

    // Три последовательных вызова дают разные шарды с уникальными id.
    public function testThreeCallsCreateThreeDistinctShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $shard = $reg->createNewShard();
            self::assertGreaterThanOrEqual(20, \strlen($shard->id));
            self::assertNotContains($shard->id, $ids);
            $ids[] = $shard->id;
        }

        self::assertCount(3, $ids);
        self::assertSame(4, $reg->getCountShards());
    }

    // Существующие шарды и их состояние не меняются.
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
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $reg->createNewShard();

        self::assertTrue($reg->rentValid($busy));
        $newIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        self::assertSame(6, \count($newIds));
        foreach ($oldIds as $id) {
            self::assertContains($id, $newIds);
        }
    }

    // Ошибка создания схемы: неправильный schema_sql → IPDOException;
    // частично созданный файл удаляется, запись не добавляется.
    public function testSchemaFailureThrowsAndCleansUp(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');
        \sort($filesBefore);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'schema_sql', 'v' => \json_encode(['value' => ['THIS IS NOT VALID SQL ...']])]);
        } finally {
            $ipdo->close();
        }

        try {
            $reg->createNewShard();
            self::fail('IPDOException expected');
        } catch (IPDOException $e) {
            self::assertSame(5, $reg->getCountShards());
            $filesAfter = \glob($this->tmpDir . '/*.sqlite');
            \sort($filesAfter);
            self::assertSame($filesBefore, $filesAfter);
        }
    }

    // Метод не меняет сохранённый min_shards (kv) и не создаёт лишних шардов.
    public function testDoesNotChangeMinShardsAndCreatesExactlyOne(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->createNewShard();
        $reg->createNewShard();

        self::assertSame(7, $reg->getCountShards());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(7, $discovered->getCountShards());
    }
}
