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
 * {@see Registry::leftTimeUntilRelease()}
 *
 *  «leftTimeUntilRelease»
 */
final class LeftTimeUntilReleaseTest extends TestCase
{
    // Только что созданный свободный шард → -1.
    public function testFreeShardReturnsMinusOne(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertSame(-1, $reg->leftTimeUntilRelease($reg->getAllShards()[0]));
    }

    // Освобождённый шард → -1.
    public function testReleasedShardReturnsMinusOne(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
    }

    // Неактивный шард с updated_at в будущем → -1.
    public function testInactiveShardWithFutureTimestampReturnsMinusOne(): void
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

        self::assertSame(-1, $reg->leftTimeUntilRelease($shard));
    }

    // Только что арендованный шард → около rent_timeout (30).
    public function testFreshlyRentedReturnsAboutRentTimeout(): void
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

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Активный с ago(10) при таймауте 30 → примерно 20.
    public function testActiveTenSecondsAgoReturnsTwenty(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertGreaterThanOrEqual(18, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(20, $reg->leftTimeUntilRelease($shard));
    }

    // Активный с updated_at ровно time()-rent_timeout → 0.
    public function testBoundaryReturnsZero(): void
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

        self::assertSame(0, $reg->leftTimeUntilRelease($shard));
    }

    // Активный со старым updated_at (ago(100)) → 0, не отрицательное.
    public function testExpiredActiveReturnsZero(): void
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

        self::assertSame(0, $reg->leftTimeUntilRelease($shard));
    }

    // Активный с updated_at в будущем → больше rent_timeout.
    public function testActiveWithFutureTimestampReturnsMoreThanTimeout(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() + 3600, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertGreaterThan(30, $reg->leftTimeUntilRelease($shard));
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
        $reg->leftTimeUntilRelease(new Shard('no-such', $reg));
    }

    // Изменение rent_timeout меняет результат: ago(10) при 30 даёт ~20, при 10
    // даёт 0, при 300 (после обновления времени) — ~290.
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        self::assertGreaterThanOrEqual(18, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(20, $reg->leftTimeUntilRelease($shard));

        $reg->setRentTimeout(10);
        self::assertSame(0, $reg->leftTimeUntilRelease($shard));

        $reg->setRentTimeout(300);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        self::assertGreaterThanOrEqual(288, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(300, $reg->leftTimeUntilRelease($shard));
    }

    // Успешный heartbeat сбрасывает остаток к ~30; успешный heartbeat_m2 — тоже.
    public function testHeartbeatResetsLeftTime(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->heartbeat($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~5 <= 10
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Если heartbeat_m2 не продлевал (остаток > порога), остаток не сбрасывается:
    // остаётся ~20, а не ~30.
    public function testHeartbeatM2WithoutRenewalKeepsLeftTime(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        } // остаток ~20 > 10

        $reg->heartbeat_m2($shard);

        self::assertGreaterThanOrEqual(18, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(20, $reg->leftTimeUntilRelease($shard));
        self::assertLessThan(24, $reg->leftTimeUntilRelease($shard));
    }

    // После повторного rent остаток снова около 30.
    public function testRentAgainResetsLeftTime(): void
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
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 5, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->rent($shard);
        self::assertGreaterThanOrEqual(0, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(1, $reg->leftTimeUntilRelease($shard));
    }

    // Отсутствие файла шарда не влияет на результат.
    public function testMissingFileDoesNotAffectResult(): void
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
        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // minSecHeartbeat и результирующий таймер не связаны.
    public function testMinSecHeartbeatDoesNotAffectLeftTime(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $reg->setMinSecHeartbeat(1);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        $reg->setMinSecHeartbeat(60);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Всегда возвращается int: -1 для свободного, >= 0 для активного.
    public function testAlwaysReturnsInt(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));
        $shard = $reg->getAllShards()[0];

        self::assertIsInt($reg->leftTimeUntilRelease($shard));
        $reg->rent($shard);
        self::assertIsInt($reg->leftTimeUntilRelease($shard));
        self::assertGreaterThanOrEqual(0, $reg->leftTimeUntilRelease($shard));
    }
}
