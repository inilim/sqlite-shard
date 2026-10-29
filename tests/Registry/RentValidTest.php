<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
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

    // Несуществующий шард: rentValid бросает ShardNotFoundException, как и
    // isFree, leftTimeUntilRelease, heartbeat, release — все они проходят
    // через checkShard. Сообщение содержит id отсутствующего шарда.
    public function testNonexistentShardThrowsShardNotFoundException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $id = 'no-such';

        $this->expectException(ShardNotFoundException::class);
        $this->expectExceptionMessage($id);
        $reg->rentValid(new Shard($id, $reg));
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

    // Увеличение таймаута делает активный шард с updated_at 35 секунд назад
    // снова валидным: без вызова rentValid до setRentTimeout(60) проверяем
    // rentValid после — при таймауте 60 (35 < 60) шард валиден.
    //
    // Важно: rentValid при неудаче очищает ownerToken шарда (см.
    // Registry::rentValid → setOwnerTokenToShard(null)), поэтому порядок
    // «сначала false, потом setRentTimeout, потом true» невозможен — второй
    // вызов увидел бы ownerToken = null и вернул false без обращения к БД.
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

        // повышаем таймаут ДО первой проверки, чтобы не сжечь ownerToken
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

    // Метод не меняет состояние шарда. Проверяется по снимку записи в БД:
    // до и после вызова колонки (updated_at, active, owner_token) идентичны.
    // Это надёжнее, чем сравнивать leftTimeUntilRelease — тот вычисляется от
    // текущего time() и естественно «плывёт» на секунду в процессе теста,
    // хотя сама запись в БД не изменилась.
    public function testDoesNotChangeState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $snapshot = static function () use ($cfg, $shard): array {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $row = $ipdo->exec(
                    'SELECT updated_at, active, owner_token FROM shards WHERE id = {id}',
                    ['id' => $shard->id],
                    IPDO::FETCH_ONCE_NUM,
                );
                return $row;
            } finally {
                $ipdo->close();
            }
        };

        $before = $snapshot();

        self::assertTrue($reg->rentValid($shard));

        $after = $snapshot();
        self::assertSame($before, $after);

        // аренда всё ещё за текущим объектом
        self::assertTrue($reg->rentValid($shard));
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

    // Чужой токен после перехвата аренды: старый владелец (regA) ранее
    // арендовал шард; запись принудительно состарена; новый экземпляр (regB)
    // переарендовал шард и получил новый owner_token. rentValid от старого
    // владельца возвращает false (owner_token не совпадает с БД), повторный
    // вызов — тоже false (после первой неудачи локальный токен очищен). При
    // этом rentValid нового владельца — true, isFree — false: чужая аренда
    // не затронута.
    public function testRentValidByStaleOwnerAfterHijackReturnsFalse(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $shardA = $regA->getAllShards()[0];
        $regA->rent($shardA);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $shardA->id]);
        } finally {
            $ipdo->close();
        }

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $shardB = null;
        foreach ($regB->getAllShards() as $s) {
            if ($s->id === $shardA->id) {
                $shardB = $s;
                break;
            }
        }
        self::assertNotNull($shardB);
        $regB->rent($shardB);

        // у старого владельца owner_token не совпадает с БД → false
        self::assertFalse($regA->rentValid($shardA));
        // повторный вызов тоже false (токен уже очищен после первого провала)
        self::assertFalse($regA->rentValid($shardA));
        // аренда нового владельца действительна
        self::assertTrue($regB->rentValid($shardB));
        self::assertFalse($regB->isFree($shardB));
    }
}