<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::release()}
 *
 *  «release»
 */
final class ReleaseTest extends TestCase
{
    // Для арендованного шарда возвращает тот же Registry; шард становится
    // свободным и неактивным.
    public function testReleasedBusyShardBecomesFree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $result = $reg->release($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));
        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
    }

    // updated_at обновляется при освобождении активного: освобождённый шард
    // теряет аренду и идёт позже более старого свободного в getFreeShards.
    public function testActiveShardGetsFreshTimestampOnRelease(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        // a: арендован и застарен
        $reg->rent($all[0]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[0]->id]);
        } finally {
            $ipdo->close();
        }
        // b: свободен и тоже старый
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 50, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }

        $reg->release($all[0]);

        // теперь у a свежий updated_at, у b — ago(50):
        // в getFreeShards (по возрастанию updated_at) b идёт первым, a — за ним
        $free = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());
        self::assertSame($all[1]->id, $free[0]);
        self::assertGreaterThan(\array_search($all[1]->id, $free, true), \array_search($all[0]->id, $free, true));
    }

    // Для уже свободного шарда — no-op: не бросает, возвращает тот же объект,
    // updated_at не меняется (порядок в getFreeShards прежний).
    public function testReleaseOfFreeShardDoesNotTouchTimestamp(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[0]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 50, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }

        $result = $reg->release($all[0]);

        self::assertSame($reg, $result);
        $free = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());
        self::assertSame($all[0]->id, $free[0]); // самый старый — по-прежнему первый
        self::assertSame($all[1]->id, $free[1]);
    }

    // Для просроченного активного шарда переводит в неактивное и обновляет
    // updated_at на текущий.
    public function testReleaseOfExpiredActiveShard(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->release($shard);

        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
        self::assertTrue($reg->isFree($shard));
    }

    // Несуществующий шард → ShardNotFoundException.
    public function testNonexistentShardThrows(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->release(new Shard('no-such', $reg));
    }

    // Количество шардов и файл не меняются; existsShard остаётся true.
    public function testDoesNotRemoveShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        $reg->release($shard);

        self::assertSame(5, $reg->getCountShards());
        self::assertTrue($reg->existsShard($shard));
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    // Освобождённый шард можно снова арендовать.
    public function testReleasedShardCanBeRentedAgain(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $reg->release($shard);
        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
    }

    // Повторный release не бросает и не меняет updated_at.
    public function testRepeatedReleaseIsNoOp(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[0]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 50, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }

        $reg->release($all[0]);
        $reg->release($all[0]);

        $free = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());
        self::assertSame($all[0]->id, $free[0]); // самый старый — по-прежнему первый
        self::assertSame($all[1]->id, $free[1]);
    }

    // После release heartbeat и heartbeat_m2 бросают ShardRentLostException.
    public function testHeartbeatAfterReleaseThrowsRentLost(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        foreach (['heartbeat', 'heartbeat_m2'] as $method) {
            $thrown = false;
            try {
                $reg->{$method}($shard);
                self::fail(ucfirst($method) . ' should throw ShardRentLostException');
            } catch (ShardRentLostException) {
                $thrown = true;
            }
            self::assertTrue($thrown, ucfirst($method) . ' should throw ShardRentLostException');
        }
    }

    // Освобождение одного шарда не меняет состояние других активных.
    public function testOtherActiveShardsUntouched(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $all = $reg->getAllShards();

        $reg->rent($all[0]);
        $reg->rent($all[1]);
        $leftBefore = $reg->leftTimeUntilRelease($all[1]);

        $reg->release($all[0]);

        self::assertGreaterThanOrEqual($leftBefore - 2, $reg->leftTimeUntilRelease($all[1]));
        self::assertLessThanOrEqual($leftBefore, $reg->leftTimeUntilRelease($all[1]));
        self::assertTrue($reg->rentValid($all[1]));
    }

    // Отсутствие файла шарда не мешает освобождению.
    public function testMissingFileDoesNotPreventRelease(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        $reg->release($shard);

        self::assertTrue($reg->isFree($shard));
    }

    // Неактивный шард с updated_at в будущем остаётся неактивным после release.
    public function testReleaseOfInactiveFutureShardKeepsItInactive(): void
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

        $reg->release($shard);

        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
        self::assertTrue($reg->isFree($shard));
    }

    // Цепочка release->release не бросает и возвращает Registry.
    public function testChainingIsSupported(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $result = $reg->release($shard)->release($shard);

        self::assertSame($reg, $result);
    }
}
