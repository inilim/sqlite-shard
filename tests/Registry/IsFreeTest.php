<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::isFree()}
 *
 *  «isFree»
 */
final class IsFreeTest extends TestCase
{
    public function testFreshlyCreatedShardIsFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        self::assertTrue($reg->isFree($shard));
    }

    public function testFreshActiveShardIsNotFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);

        self::assertFalse($reg->isFree($shard));
    }

    public function testActiveShardWithUpdatedAtOnBoundaryIsFree(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 30, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // граница <=: updated_at == time() - rent_timeout -> свободен
        self::assertTrue($reg->isFree($shard));
    }

    public function testActiveShardWithFreshUpdatedAtIsNotFree(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time(), 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertFalse($reg->isFree($shard));
    }

    public function testReleasedShardIsFreeRegardlessOfUpdatedAt(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $reg->release($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 1000, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertTrue($reg->isFree($shard));
    }

    public function testInactiveShardWithFutureUpdatedAtIsFree(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $reg->release($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() + 3600, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // active = 0 важнее времени
        self::assertTrue($reg->isFree($shard));
    }

    public function testNonexistentShardThrowsShardNotFoundException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->isFree(new Shard('no-such-id', $reg));
    }

    public function testChangingRentTimeoutChangesResult(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 20, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertFalse($reg->isFree($shard));

        $reg->setRentTimeout(10);

        self::assertTrue($reg->isFree($shard));
    }

    public function testHeartbeatKeepsShardBusyAndReleaseMakesItFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $reg->heartbeat($shard);

        self::assertFalse($reg->isFree($shard));

        $reg->release($shard);

        self::assertTrue($reg->isFree($shard));
    }

    public function testMissingShardFileDoesNotAffectResult(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $ptf = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id));
        self::assertFileExists($ptf);
        \unlink($ptf);

        self::assertTrue($reg->isFree($shard));
    }

    public function testMethodDoesNotChangeShardState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->isFree($shard);

        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
        self::assertFalse($reg->rentValid($shard));
    }

    public function testResultDefinedByRegistryWhoseMethodWasCalled(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        )));
        $regB = Registry::discover($this->tmpDir, 'A');

        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $id = $regB->getAllShards()[0]->id;

        // арендуем через B, вызываем isFree на B, но Shard указывает на A
        $regB->rent(new Shard($id, $regA));
        $shardPointingAtA = new Shard($id, $regA);

        self::assertFalse($regB->isFree($shardPointingAtA));
    }
}
