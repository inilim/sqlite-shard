<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\Test\TestCase;

/**
 * {@see Registry::setMinSecHeartbeat()}
 *
 * Сеттер меняет ТОЛЬКО свойство экземпляра {@see Registry::$minSecHeartbeat}
 * и НЕ сохраняется в реестр (в отличие от setRentTimeout/setMinShards).
 */
final class SetMinSecHeartbeatTest extends TestCase
{
    // Кейс 1: значение по умолчанию равно 10 — и у только что созданного,
    // и у заново обнаруженного реестра.
    public function testDefaultValueIsTen(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        self::assertSame(10, $reg->minSecHeartbeat);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(10, $discovered->minSecHeartbeat);
    }

    // Кейс 2: успешная установка 1 — метод возвращает тот же объект Registry,
    // minSecHeartbeat становится 1.
    public function testSetToOneReturnsSameInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertSame($reg, $reg->setMinSecHeartbeat(1));
        self::assertSame(1, $reg->minSecHeartbeat);
    }

    // Кейс 3: успешная установка 120 — тот же объект, minSecHeartbeat == 120.
    public function testSetToOneHundredTwentyReturnsSameInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertSame($reg, $reg->setMinSecHeartbeat(120));
        self::assertSame(120, $reg->minSecHeartbeat);
    }

    // Кейс 4: установка 0 выбрасывает InvalidArgumentException,
    // minSecHeartbeat остаётся прежним (20).
    public function testSetZeroThrowsAndKeepsPreviousValue(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $reg->setMinSecHeartbeat(20);

        try {
            $reg->setMinSecHeartbeat(0);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            // ожидаемое исключение
        }

        self::assertSame(20, $reg->minSecHeartbeat);
    }

    // Кейс 5: установка -1 выбрасывает InvalidArgumentException,
    // minSecHeartbeat остаётся прежним (20).
    public function testSetNegativeThrowsAndKeepsPreviousValue(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $reg->setMinSecHeartbeat(20);

        try {
            $reg->setMinSecHeartbeat(-1);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            // ожидаемое исключение
        }

        self::assertSame(20, $reg->minSecHeartbeat);
    }

    // Кейс 6: передача не-int (1.0, '10', true, null) при strict_types=1
    // даёт TypeError; minSecHeartbeat не меняется.
    public function testNonIntValuesThrowTypeErrorAndKeepValue(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $reg->setMinSecHeartbeat(20);

        foreach ([1.0, '10', true, null] as $value) {
            try {
                $reg->setMinSecHeartbeat($value);
                self::fail('TypeError expected for ' . \var_export($value, true));
            } catch (\TypeError $e) {
                // ожидаемое исключение
            }
        }

        self::assertSame(20, $reg->minSecHeartbeat);
    }

    // Кейс 7: значение НЕ сохраняется в реестр — новый экземпляр через
    // Registry::discover для того же реестра снова видит 10.
    public function testValueIsNotPersistedToRegistry(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $reg->setMinSecHeartbeat(3);
        self::assertSame(3, $reg->minSecHeartbeat);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(10, $discovered->minSecHeartbeat);
    }

    // Кейс 8: значение принадлежит экземпляру: A поставил 2, уже открытый
    // экземпляр B того же реестра показывает 10; после собственной установки
    // 5 у B становится 5, у A по-прежнему 2.
    public function testValueBelongsToEachInstance(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $regA->setMinSecHeartbeat(2);
        self::assertSame(2, $regA->minSecHeartbeat);

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        self::assertSame(10, $regB->minSecHeartbeat);

        $regB->setMinSecHeartbeat(5);
        self::assertSame(5, $regB->minSecHeartbeat);
        self::assertSame(2, $regA->minSecHeartbeat);
    }

    // Кейс 9: цепочка setMinSecHeartbeat(5)->setRentTimeout(60)->setMinShards(2)
    // возвращает тот же объект Registry; все три значения применены:
    // minSecHeartbeat==5, активный шард с ago(45) занят при таймауте 60,
    // min_shards в kv == 2.
    public function testChainingReturnsSameInstanceAndAppliesAllValues(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $result = $reg->setMinSecHeartbeat(5)->setRentTimeout(60)->setMinShards(2);

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->minSecHeartbeat);
        self::assertFalse($reg->isFree($shard));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
    }

    // Кейс 10: minSecHeartbeat влияет только на heartbeat_m2, но не на
    // heartbeat: остаток ~5 (ago(25) при таймауте 30) — heartbeat всё равно
    // продлевает без исключения, остаток снова ~30.
    public function testHeartbeatIsNotAffectedByMinSecHeartbeat(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 25, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // остаток ~5 <= minSecHeartbeat=10, но heartbeat продлевает всегда
        $reg->heartbeat($shard);

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 11: heartbeat_m2 использует установленное значение: при остатке ~8
    // (ago(22), таймаут 30) и minSecHeartbeat=10 аренда продлевается (остаток
    // ~30); после setMinSecHeartbeat(5) при том же остатке ~8 аренда НЕ
    // продлевается (остаток остаётся ~8, не сбрасывается к 30).
    public function testHeartbeatM2UsesConfiguredThreshold(): void
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

        // minSecHeartbeat=10 (по умолчанию): остаток ~8 <= 10 → продление
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 22, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        // minSecHeartbeat=5: тот же остаток ~8 > 5 → записи нет, остаток ~8
        $reg->setMinSecHeartbeat(5);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 22, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(6, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(8, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 12: очень большое значение (PHP_INT_MAX) принимается: для свежего
    // активного шарда heartbeat_m2 выбирает ветку продления (left > 0 и
    // <= PHP_INT_MAX), а для свободного шарда по-прежнему выбрасывается
    // ShardRentLostException.
    public function testPhpIntMaxAccepted(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $reg->setMinSecHeartbeat(PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX, $reg->minSecHeartbeat);

        // свежий активный шард: left ~30 > 0 и <= PHP_INT_MAX → продление
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        // свободный шард (active=0): left == -1 → исключение
        $freeShard = null;
        foreach ($reg->getAllShards() as $s) {
            if ($reg->isFree($s)) {
                $freeShard = $s;
                break;
            }
        }
        self::assertNotNull($freeShard);

        $this->expectException(ShardRentLostException::class);
        $reg->heartbeat_m2($freeShard);
    }

    // Кейс 13: установка minSecHeartbeat не меняет name, rent_timeout,
    // min_shards, количество шардов и состояние аренды.
    public function testSettingDoesNotChangeOtherState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        self::assertSame('test', $reg->getName());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->getCountShards());
        self::assertTrue($reg->rentValid($shard));

        $reg->setMinSecHeartbeat(7);
        self::assertSame(7, $reg->minSecHeartbeat);

        self::assertSame('test', $reg->getName());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->getCountShards());
        self::assertTrue($reg->rentValid($shard));
    }
}
