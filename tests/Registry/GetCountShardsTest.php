<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::getCountShards()}
 *
 *  «getCountShards»
 */
final class GetCountShardsTest extends TestCase
{
    public function testAfterCreateWithMinShardsFiveReturnsFive(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));

        self::assertSame(5, $reg->getCountShards());
    }

    public function testAfterCreateNewShardsReturnsIncreasedCount(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));

        $reg->createNewShards(3);

        self::assertSame(8, $reg->getCountShards());
    }

    public function testRentHeartbeatReleaseDoNotChangeCount(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        self::assertSame(5, $reg->getCountShards());

        $reg->heartbeat($shard);
        self::assertSame(5, $reg->getCountShards());

        $reg->release($shard);
        self::assertSame(5, $reg->getCountShards());
    }

    public function testDeletingRowsChangesCount(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $shard = $reg->getAllShards()[0];
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertSame(4, $reg->getCountShards());

        foreach ($reg->getAllShards() as $s) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $s->id]);
            } finally {
                $ipdo->close();
            }
        }

        self::assertSame(0, $reg->getCountShards());
    }

    public function testReturnValueIsInt(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));

        $count = $reg->getCountShards();

        self::assertIsInt($count);
        self::assertSame(5, $count);
    }

    public function testValueEqualsNumberOfAllShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));

        self::assertSame(\count($reg->getAllShards()), $reg->getCountShards());
    }

    public function testMethodDoesNotCreateShardsUpToMinShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        // удалим запись, оставив min_shards = 5 в реестре
        $shard = $reg->getAllShards()[0];
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // метод не восстанавливает шарды до сохранённого минимума
        self::assertSame(4, $reg->getCountShards());
    }

    public function testShardWithMissingFileIsStillCounted(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertSame(5, $reg->getCountShards());
    }
}
