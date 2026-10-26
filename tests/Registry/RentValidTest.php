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
 * {@see Registry::rentValid()}
 *
 *  «rentValid»
 */
final class RentValidTest extends TestCase
{
    // Только что арендованный шард → true.
    public function testFreshlyRentedReturnsTrue(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
    }

    // Только что созданный свободный шард → false.
    public function testFreeShardReturnsFalse(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertFalse($reg->rentValid($reg->getAllShards()[0]));
    }

    // Освобождённый шард → false.
    public function testReleasedShardReturnsFalse(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        self::assertFalse($reg->rentValid($shard));
    }

    // Неактивный шард с updated_at в будущем → false.
    public function testInactiveShardWithFutureTimestampReturnsFalse(): void
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

        self::assertFalse($reg->rentValid($shard));
    }

    // Свежий активный с updated_at == now() → true.
    public function testActiveWithCurrentTimestampReturnsTrue(): void
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

        self::assertTrue($reg->rentValid($shard));
    }

    // Активный с updated_at ровно time()-rent_timeout → false (граница >).
    public function testActiveOnTimeoutBoundaryReturnsFalse(): void
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

        self::assertFalse($reg->rentValid($shard));
    }

    // Активный со старым updated_at (ago(100)) → false.
    public function testExpiredActiveReturnsFalse(): void
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

        self::assertFalse($reg->rentValid($shard));
    }

    // Несуществующий шард → false БЕЗ исключения (в отличие от isFree,
    // leftTimeUntilRelease, heartbeat, release).
    public function testNonexistentShardReturnsFalseWithoutThrowing(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertFalse($reg->rentValid(new Shard('no-such', $reg)));
    }

    // Отсутствие файла шарда не влияет.
    public function testMissingFileDoesNotAffectResult(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertTrue($reg->rentValid($shard));
    }

    // После успешного heartbeat → true.
    public function testHeartbeatKeepsRentValid(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $reg->heartbeat($shard);

        self::assertTrue($reg->rentValid($shard));
    }

    // Истечение аренды: при rent_timeout=1 и ago(2) → false.
    public function testLeaseExpirationMakesRentInvalid(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 2, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertFalse($reg->rentValid($shard));
    }

    // Увеличение таймаута делает просроченный активный шард снова валидным.
    public function testIncreasingRentTimeoutMakesRentValid(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 35, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        self::assertFalse($reg->rentValid($shard));

        $reg->setRentTimeout(60);

        self::assertTrue($reg->rentValid($shard));
    }

    // Уменьшение таймаута делает свежий активный шард невалидным.
    public function testDecreasingRentTimeoutMakesRentInvalid(): void
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
        self::assertTrue($reg->rentValid($shard));

        $reg->setRentTimeout(10);

        self::assertFalse($reg->rentValid($shard));
    }

    // Метод не меняет состояние шарда.
    public function testDoesNotChangeState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $leftBefore = $reg->leftTimeUntilRelease($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertSame($leftBefore, $reg->leftTimeUntilRelease($shard));
        self::assertFalse($reg->isFree($shard));
    }

    // Второй экземпляр видит актуальное состояние: regA арендует — у объекта
    // regB токена нет, поэтому rentValid даёт false (это не его аренда), но
    // занятость видна через isFree; после release через regA шард свободен.
    public function testSecondInstanceSeesUpdatedState(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $regA->getAllShards()[0];
        $regA->rent($shard);

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $bShard = null;
        foreach ($regB->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $bShard = $s;
                break;
            }
        }
        self::assertNotNull($bShard);
        self::assertFalse($regB->rentValid($bShard));
        self::assertFalse($regB->isFree($bShard));

        $regA->release($shard);
        self::assertTrue($regB->isFree($bShard));
        self::assertFalse($regB->rentValid($bShard));
    }
}
