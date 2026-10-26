<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFreeException;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\Test\TestCase;

/**
 * {@see Registry::setRentTimeout()}
 *
 * Сеттер сохраняет значение в kv 'rent_timeout' и влияет на все проверки
 * аренды (isFree / rentValid / leftTimeUntilRelease / heartbeat / heartbeat_m2).
 */
final class SetRentTimeoutTest extends TestCase
{
    // Кейс 1: значение по умолчанию для CreateConfig — 30: только что взятая
    // аренда имеет остаток примерно 30 секунд.
    public function testDefaultRentTimeoutIsThirtySeconds(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 2: установка 60 возвращает тот же объект; активный шард с
    // updated_at ago(45) после этого занят (isFree === false).
    public function testSetSixtyReturnsSameInstanceAndKeepsShardBusy(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertSame($reg, $reg->setRentTimeout(60));
        self::assertFalse($reg->isFree($shard));
    }

    // Кейс 3: установка 1 возвращает тот же объект; активный шард с
    // updated_at ago(2) после этого свободен: isFree === true, rentValid === false.
    public function testSetOneReturnsSameInstanceAndFreesShard(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 2, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertSame($reg, $reg->setRentTimeout(1));
        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));
    }

    // Кейс 4: значение сохраняется в реестр — новый экземпляр через discover
    // использует таймаут 60: шард с ago(45) свободен при 30, занят после
    // setRentTimeout(60), и новый экземпляр тоже считает его занятым.
    public function testValueIsPersistedAndUsedByNewInstance(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // при таймауте 30 шард свободен
        self::assertTrue($reg->isFree($shard));

        // после setRentTimeout(60) — занят в текущем экземпляре
        $reg->setRentTimeout(60);
        self::assertFalse($reg->isFree($shard));

        // и в новом экземпляре через discover
        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $discShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $discShard = $s;
                break;
            }
        }
        self::assertNotNull($discShard);
        self::assertFalse($discovered->isFree($discShard));
    }

    // Кейс 5: установка 0 → InvalidArgumentException; сохранённое значение
    // (30) не меняется — следующий discover() продолжает использовать 30,
    // шард с ago(45) всё ещё свободен.
    public function testSetZeroThrowsAndKeepsSavedValue(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        try {
            $reg->setRentTimeout(0);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            // ожидаемое исключение
        }

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $discShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $discShard = $s;
                break;
            }
        }
        self::assertNotNull($discShard);
        self::assertTrue($discovered->isFree($discShard));
    }

    // Кейс 6: установка отрицательного значения → InvalidArgumentException;
    // сохранённое значение не меняется.
    public function testSetNegativeThrowsAndKeepsSavedValue(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        try {
            $reg->setRentTimeout(-1);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            // ожидаемое исключение
        }

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertTrue($reg->isFree($shard));
    }

    // Кейс 7: передача не-int ('10', 1.0, true, null) при strict_types=1 →
    // TypeError; сохранённое значение (30) не меняется.
    public function testNonIntValuesThrowTypeErrorAndKeepSavedValue(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        foreach (['10', 1.0, true, null] as $value) {
            try {
                $reg->setRentTimeout($value);
                self::fail('TypeError expected for ' . \var_export($value, true));
            } catch (\TypeError $e) {
                // ожидаемое исключение
            }
        }

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $discShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $discShard = $s;
                break;
            }
        }
        self::assertNotNull($discShard);
        self::assertTrue($discovered->isFree($discShard));
    }

    // Кейс 8: уменьшение таймаута немедленно освобождает: active, ago(20)
    // при 30 — занят; после setRentTimeout(10) — свободен; после
    // setRentTimeout(30) — снова занят.
    public function testDecreasingTimeoutFreesShardImmediately(): void
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

        $reg->setRentTimeout(30);
        self::assertFalse($reg->isFree($shard));
    }

    // Кейс 9: увеличение таймаута возвращает свежесть: active, ago(35) при
    // 30 — свободен; после setRentTimeout(60) — занят и rentValid === true.
    public function testIncreasingTimeoutRestoresFreshness(): void
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

        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));

        $reg->setRentTimeout(60);
        self::assertFalse($reg->isFree($shard));
        self::assertTrue($reg->rentValid($shard));
    }

    // Кейс 10: влияние на leftTimeUntilRelease: ago(10) при 30 → ~20;
    // после setRentTimeout(10) → 0; после setRentTimeout(300) → ~290.
    public function testLeftTimeUntilReleaseFollowsTimeout(): void
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
        // сбрасываем состояние, чтобы исключить накопление секунд между вызовами
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 10, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        self::assertGreaterThanOrEqual(288, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(300, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 11: влияние на heartbeat: ago(35) при 30 — heartbeat выбрасывает
    // ShardRentLostException; после setRentTimeout(60) тот же heartbeat
    // успешно продлевает (остаток ~60).
    public function testHeartbeatDependsOnTimeout(): void
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

        try {
            $reg->heartbeat($shard);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException $e) {
            // аренда просрочена — продлевать нечего
        }

        $reg->setRentTimeout(60);
        $reg->heartbeat($shard);
        self::assertGreaterThanOrEqual(58, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(60, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 12: влияние на heartbeat_m2: ago(24) при 30 → остаток ~6
    // <= minSecHeartbeat 10 → продлевает (остаток ~30); после
    // setRentTimeout(60) остаток ~36 > 10 → записи нет, остаток ~36.
    public function testHeartbeatM2DependsOnTimeout(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 24, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // остаток ~6 <= 10 → продление, остаток снова ~30
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));

        // тот же остаток ~6, но таймаут 60 → остаток ~36 > 10 → записи нет
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 24, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        $reg->setRentTimeout(60);
        $reg->heartbeat_m2($shard);
        self::assertGreaterThanOrEqual(34, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(36, $reg->leftTimeUntilRelease($shard));
    }

    // Кейс 13: влияние на getFreeShards, rentFree, tryRentFree, isFree, rent,
    // tryRent: при таймауте 30 шард с ago(20) занят (не входит в getFreeShards,
    // rent бросает ShardNotFreeException, tryRent false); после
    // setRentTimeout(10) — свободен и доступен всем способам аренды.
    public function testFreeRentApiFollowsTimeout(): void
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

        // при таймауте 30 шард занят
        self::assertNotContains($shard->id, \array_map(static fn (Shard $s): string => $s->id, $reg->getFreeShards()));
        try {
            $reg->rent($shard);
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException $e) {
            // ожидаемое исключение
        }
        self::assertFalse($reg->tryRent($shard));
        self::assertFalse($reg->isFree($shard));

        // после setRentTimeout(10) шард свободен
        $reg->setRentTimeout(10);
        self::assertContains($shard->id, \array_map(static fn (Shard $s): string => $s->id, $reg->getFreeShards()));
        self::assertTrue($reg->isFree($shard));

        // tryRentFree/rentFree может взять наш шард
        $picked = $reg->tryRentFree();
        self::assertNotNull($picked);
        if ($picked->id !== $shard->id) {
            // если rentFree взял другой шард — освобождаем его и берём наш
            $reg->release($picked);
            $picked = $reg->tryRentFree();
            self::assertNotNull($picked);
            self::assertSame($shard->id, $picked->id);
        }

        // rent успешен после освобождения, tryRent тоже возвращает true
        $reg->release($shard);
        $reg->rent($shard);
        self::assertTrue($reg->rentValid($shard));

        $reg->release($shard);
        self::assertTrue($reg->tryRent($shard));
    }

    // Кейс 14: новое значение видно уже открытому второму экземпляру без
    // переоткрытия (kv читается заново при каждом вызове): regB, открытый до
    // установки, сразу видит 60.
    public function testAlreadyOpenSecondInstanceSeesNewTimeout(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);

        $shard = $regA->getAllShards()[0];
        $regA->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        // при 30 шард свободен в обоих экземплярах
        self::assertTrue($regA->isFree($shard));
        self::assertTrue($regB->isFree($shard));

        $regA->setRentTimeout(60);

        // regB, открытый до установки, сразу видит 60
        self::assertFalse($regB->isFree($shard));
        self::assertFalse($regA->isFree($shard));
    }

    // Кейс 15: повторная установка того же значения возвращает тот же объект;
    // наблюдаемое поведение не меняется.
    public function testRepeatedSameValueKeepsBehavior(): void
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

        self::assertSame($reg, $reg->setRentTimeout(30));
        self::assertFalse($reg->isFree($shard));

        self::assertSame($reg, $reg->setRentTimeout(30));
        self::assertFalse($reg->isFree($shard));
    }

    // Кейс 16: очень большое значение (PHP_INT_MAX) принимается: активные
    // шарды не становятся свободными, а только что взятая аренда имеет
    // очень большой остаток (больше 1_000_000).
    public function testPhpIntMaxAccepted(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        self::assertSame($reg, $reg->setRentTimeout(PHP_INT_MAX));
        self::assertFalse($reg->isFree($shard));

        // только что взятая аренда имеет огромный остаток
        $rented = $reg->rentFree();
        self::assertGreaterThan(1_000_000, $reg->leftTimeUntilRelease($rented));
    }

    // Кейс 17: цепочка setRentTimeout(60)->setMinSecHeartbeat(5)->setMinShards(2)
    // возвращает тот же объект Registry; значения применены.
    public function testChainingReturnsSameInstance(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $result = $reg->setRentTimeout(60)->setMinSecHeartbeat(5)->setMinShards(2);

        self::assertSame($reg, $result);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(60, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->minSecHeartbeat);
    }

    // Кейс 18: неудачная установка не меняет ни таймаут, ни состояние шардов:
    // при 30 активный шард с ago(45) свободен; попытка setRentTimeout(0) →
    // InvalidArgumentException; новый discover() показывает прежний таймаут 30
    // (шард всё ещё свободен).
    public function testFailedSetKeepsTimeoutAndShardState(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertTrue($reg->isFree($shard));

        try {
            $reg->setRentTimeout(0);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            // ожидаемое исключение
        }

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $discShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $discShard = $s;
                break;
            }
        }
        self::assertNotNull($discShard);
        self::assertTrue($discovered->isFree($discShard));
    }
}
