<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotRentException;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::heartbeat()}
 *
 *  «heartbeat»
 */
final class HeartbeatTest extends TestCase
{
    // Свежий активный шард: возвращает тот же объект, обновляет updated_at,
    // leftTime ~30, rentValid true.
    public function testHeartbeatRenewsFreshLease(): void
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

        $result = $reg->heartbeat($shard);

        self::assertSame($reg, $result);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
        self::assertTrue($reg->rentValid($shard));
    }

    // Активный с ago(20) (остаток ~10) — успешно продлевает.
    public function testHeartbeatRenewsAlmostExpiredLease(): void
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

        $reg->heartbeat($shard);

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Повторный вызов через секунду успешен, updated_at продвигается.
    public function testRepeatedHeartbeatAdvancesTimestamp(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 5, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->heartbeat($shard);
        $first = $this->updatedAt($cfg->ptfRegistry, $shard->id);

        \sleep(1);
        $reg->heartbeat($shard);
        $second = $this->updatedAt($cfg->ptfRegistry, $shard->id);

        self::assertGreaterThan($first, $second);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Свободный шард → ShardNotRentException; состояние не меняется.
    public function testFreeShardThrowsNotRent(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        try {
            $reg->heartbeat($shard);
            self::fail('ShardNotRentException expected');
        } catch (ShardNotRentException) {
            self::assertTrue($reg->isFree($shard));
            self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
        }
    }

    // Просроченный активный (ago(40)) → ShardRentLostException; остаётся
    // активным и просроченным, updated_at не меняется.
    public function testExpiredActiveThrowsRentLost(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shard->id]);
            $before = $ipdo->exec(
                'SELECT active, updated_at FROM shards WHERE id = {id}',
                ['id' => $shard->id],
                IPDO::FETCH_ONCE_NUM
            );
        } finally {
            $ipdo->close();
        }

        try {
            $reg->heartbeat($shard);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException) {
            self::assertFalse($reg->rentValid($shard));
            self::assertSame(0, $reg->leftTimeUntilRelease($shard));

            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $after = $ipdo->exec(
                    'SELECT active, updated_at FROM shards WHERE id = {id}',
                    ['id' => $shard->id],
                    IPDO::FETCH_ONCE_NUM
                );
            } finally {
                $ipdo->close();
            }

            self::assertSame((int) $before[0], (int) $after[0]);
            self::assertSame((int) $before[1], (int) $after[1]);
        }
    }

    // Несуществующий шард → ShardNotFoundException (а не ShardRentLostException).
    public function testNonexistentShardThrowsNotFound(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->heartbeat(new Shard('no-such', $reg));
    }

    // Освобождённый шард → ShardNotRentException.
    public function testReleasedShardThrowsNotRent(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        $this->expectException(ShardNotRentException::class);
        $reg->heartbeat($shard);
    }

    // При rent_timeout=1 с ago(2) → ShardRentLostException; сразу после аренды
    // — успех.
    public function testTinyTimeout(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
            rentTimeout: 1,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $reg->heartbeat($shard); // свежий — успех

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 2, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat($shard);
    }

    // Активный с ago(30) (ровно граница) → ShardRentLostException (граница >).
    public function testBoundaryThrowsRentLost(): void
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

        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat($shard);
    }

    // При rent_timeout=PHP_INT_MAX продление успешно.
    public function testHugeRentTimeoutSucceeds(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
            rentTimeout: PHP_INT_MAX,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $reg->heartbeat($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThan(30, $reg->leftTimeUntilRelease($shard));
    }

    // Отсутствие файла шарда не мешает продлению.
    public function testMissingShardFileDoesNotPreventRenewal(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
        $reg->heartbeat($shard);

        self::assertTrue($reg->rentValid($shard));
    }

    // При неудаче состояние шарда и других шардов не меняется; шард не
    // переводится в неактивное.
    public function testFailedHeartbeatLeavesEverythingUnchanged(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $free = $reg->getAllShards()[0];
        $busy = $reg->getAllShards()[1];
        $reg->rent($busy);

        try {
            $reg->heartbeat($free);
            self::fail('ShardNotRentException expected');
        } catch (ShardNotRentException) {
        }

        self::assertTrue($reg->isFree($free));
        self::assertTrue($reg->rentValid($busy));
        self::assertFalse($reg->isFree($busy));
    }

    // Сообщение ShardRentLostException содержит id шарда.
    public function testExceptionMessageContainsShardId(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        try {
            $reg->heartbeat($shard);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException $e) {
            self::assertStringContainsString($shard->id, $e->getMessage());
        }
    }

    // Цепочка heartbeat->heartbeat для активного шарда не бросает и возвращает
    // тот же объект.
    public function testChainingSucceedsForActiveShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $result = $reg->heartbeat($shard)->heartbeat($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->rentValid($shard));
    }

    private function updatedAt(string $ptfRegistry, string $shardId): int
    {
        $ipdo = new IPDOSQLite($ptfRegistry);
        try {
            $row = $ipdo->exec(
                'SELECT updated_at FROM shards WHERE id = {id}',
                ['id' => $shardId],
                IPDO::FETCH_ONCE_NUM
            );
            return (int) $row[0];
        } finally {
            $ipdo->close();
        }
    }
}