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

/**
 * {@see Registry::heartbeat_m2()}
 *
 *  «heartbeat_m2»
 */
final class HeartbeatM2Test extends TestCase
{
    // Остаток ~20 (ago(10)) > minSecHeartbeat=10: возвращает тот же объект, но
    // НЕ обновляет updated_at — leftTime остаётся ~20, а не ~30.
    public function testDoesNotRenewWhenLeftTimeAboveThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~20

        $result = $reg->heartbeat_m2($shard);

        self::assertSame($reg, $result);
        self::assertGreaterThanOrEqual(18, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(20, $reg->leftTimeUntilRelease($shard));
    }

    // Остаток ~5 (ago(25)) <= 10: продлевает — leftTime ~30.
    public function testRenewsWhenLeftTimeBelowThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~5

        $reg->heartbeat_m2($shard);

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Остаток ровно 10 (ago(20)): продлевает (граница <=).
    public function testRenewsAtExactThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 20, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ровно ~10

        $reg->heartbeat_m2($shard);

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Остаток ~12 (ago(18)) > 10: не продлевает — остаётся ~18 (чувствительно
    // к пересечению секунды, запас по обе стороны).
    public function testDoesNotRenewJustAboveThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 18, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~12

        $reg->heartbeat_m2($shard);

        self::assertGreaterThanOrEqual(10, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(12, $reg->leftTimeUntilRelease($shard));
    }

    // Свободный шард → ShardRentLostException.
    public function testFreeShardThrowsRentLost(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat_m2($shard);
    }

    // Просроченный активный (остаток 0) → ShardRentLostException; updated_at не
    // меняется.
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
        } finally {
            $ipdo->close();
        }

        try {
            $reg->heartbeat_m2($shard);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException) {
            self::assertFalse($reg->rentValid($shard));
        }
    }

    // Освобождённый шард → ShardRentLostException.
    public function testReleasedShardThrowsRentLost(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat_m2($shard);
    }

    // Несуществующий шард → ShardNotFoundException.
    public function testNonexistentShardThrowsNotFound(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->heartbeat_m2(new Shard('no-such', $reg));
    }

    // setMinSecHeartbeat(5): остаток ~20 — без продления; остаток ~3 —
    // продление; остаток ровно 5 — продление.
    public function testCustomThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $reg->setMinSecHeartbeat(5);
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        // остаток ~20 > 5 — без продления
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(18, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(20, $reg->leftTimeUntilRelease($shard));

        // остаток ~10 > 5 — без продления
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 20, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(8, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(10, $reg->leftTimeUntilRelease($shard));

        // остаток ровно ~5 — продление
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Малый порог: setMinSecHeartbeat(3) — остаток ~5 без продления; остаток ~2
    // (граница секунды с запасом) — продление.
    public function testSmallThreshold(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $reg->setMinSecHeartbeat(3);
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~5 > 3
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(3, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(5, $reg->leftTimeUntilRelease($shard));

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 28, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~2 <= 3
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Порог по умолчанию — 10: reg->minSecHeartbeat === 10; остаток ~20 — без
    // продления, остаток ~5 — с продлением.
    public function testDefaultThresholdIsTen(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertSame(10, $reg->minSecHeartbeat);
    }

    // Накопления не происходит: после успешного продления остаток снова выше
    // порога, повторный немедленный вызов не продлевает (остаток ~30, не 60).
    public function testNoRenewalAccumulation(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~5
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
        self::assertLessThan(40, $reg->leftTimeUntilRelease($shard));
    }

    // Изменение rent_timeout меняет интерпретацию: ago(24) при 30 → остаток ~6
    // → продление; при 60 → остаток ~36 > 10 → без продления.
    public function testChangingRentTimeoutChangesThresholdReachability(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 24, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~6
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        $reg->setRentTimeout(60);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 24, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~36
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(34, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(36, $reg->leftTimeUntilRelease($shard));
        self::assertLessThan(40, $reg->leftTimeUntilRelease($shard));
    }

    // minSecHeartbeat >= rent_timeout: с очень маленьким таймаутом каждый вызов
    // продлевает.
    public function testThresholdLargerThanTimeout(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
            rentTimeout: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $reg->setMinSecHeartbeat(10);
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(3, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(5, $reg->leftTimeUntilRelease($shard));

        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(3, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(5, $reg->leftTimeUntilRelease($shard));
    }

    // Если при остатке > порога метод не продлевает, аренда в итоге истекает.
    public function testEventuallyExpiresWithoutRenewal(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~20, нет продления
        $reg->heartbeat_m2($shard);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // просрочен
        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat_m2($shard);
    }

    // При неудаче шард не переводится в неактивное (для свободного — остаётся
    // свободным).
    public function testFailureDoesNotDeactivateShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        try {
            $reg->heartbeat_m2($shard);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException) {
            self::assertTrue($reg->isFree($shard));
            self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
        }
    }

    // Цепочка heartbeat_m2->heartbeat_m2 для активного шарда успешна.
    public function testChainingSucceedsForActiveShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $result = $reg->heartbeat_m2($shard)->heartbeat_m2($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->rentValid($shard));
    }
}
