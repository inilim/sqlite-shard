<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\RegistryInvalidException;
use Inilim\SqliteShard\RegistryVersionMismatchException;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;

/**
 * {@see Registry::createOrDiscover()}
 *
 *  «createOrDiscover»
 */
final class CreateOrDiscoverTest extends TestCase
{
    // Для нового имени создаёт реестр как create(): файл, minShards свободных
    // шардов со схемой, корректные name и baseDir.
    public function testCreatesNewRegistryWhenAbsent(): void
    {
        $reg = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'new',
        )));

        self::assertInstanceOf(Registry::class, $reg);
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'new',
        );
        self::assertFileExists($cfg->ptfRegistry);
        self::assertSame('new', $reg->getName());
        self::assertSame(5, $reg->getCountShards());
        foreach ($reg->getAllShards() as $shard) {
            self::assertTrue($reg->isFree($shard));
            $ipdo = $reg->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // Для существующего реестра возвращает его; RegistryNameExistsException
    // никогда не бросается.
    public function testReturnsExistingRegistryWithoutThrowing(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'exist',
        )));

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'exist',
        )));

        self::assertInstanceOf(Registry::class, $result);
        self::assertSame('exist', $result->getName());
    }

    // config->minShards игнорируется для существующего реестра.
    public function testConfigMinShardsIgnoredForExistingRegistry(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'm',
            minShards: 2,
        )));

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'm',
            minShards: 9,
        )));

        self::assertSame(2, $result->getCountShards());

        $plain = Registry::discover($this->tmpDir, 'm');
        self::assertNotNull($plain);
        $this->registerRegistry($plain);
        self::assertSame(2, $plain->getCountShards());
    }

    // Записей меньше сохранённого min_shards — до-создаётся до 5 (встроенный
    // discover), конфигурация не влияет.
    public function testFillsMissingShardsUpToSavedMinimum(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'f',
        );
        $this->registerRegistry(Registry::create($cfg));
        $all = Registry::discover($this->tmpDir, 'f');
        self::assertNotNull($all);
        $this->registerRegistry($all);
        $ids = \array_map(static fn(Shard $s): string => $s->id, $all->getAllShards());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $ids[0]]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $ids[1]]);
        } finally {
            $ipdo->close();
        }

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'f',
            minShards: 2,
        )));

        self::assertSame(5, $result->getCountShards());
    }

    // config->minShards меньше текущего количества — не удаляет шарды.
    public function testNeverDeletesShards(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'd',
            minShards: 8,
        )));

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'd',
            minShards: 1,
        )));

        self::assertSame(8, $result->getCountShards());
    }

    // config->rentTimeout не применяется к существующему: шард с ago(45) занят
    // только по сохранённому таймауту 60, а 30 — свободен.
    public function testConfigRentTimeoutIgnoredForExistingRegistry(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'rt',
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
        self::assertTrue($reg->isFree($shard)); // таймаут 30: свободен

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'rt',
            rentTimeout: 60,
        )));

        $reopened = null;
        foreach ($result->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $reopened = $s;
                break;
            }
        }
        self::assertNotNull($reopened);
        self::assertTrue($result->isFree($reopened)); // config 60 НЕ применён
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
    }

    // config->schemaSql не меняет схему существующих; новые шарды при нехватке
    // используют сохранённую схему (нет таблицы extra).
    public function testConfigSchemaSqlIgnoredForExistingRegistry(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 's',
        )));
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 's',
        );

        $result = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [
                'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
                'CREATE TABLE extra (x INTEGER)',
            ],
            name: 's',
        )));

        foreach ($result->getAllShards() as $shard) {
            $ipdo = $result->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
                self::assertFalse($ipdo->existsTable('extra'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // Другое имя в той же директории — отдельный реестр; существующий не
    // затрагивается.
    public function testCreatesSeparateRegistryForAnotherName(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        )));
        $idsA = \array_map(static fn(Shard $s): string => $s->id, $regA->getAllShards());

        $regB = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'B',
        )));

        self::assertSame('B', $regB->getName());
        self::assertSame(5, $regB->getCountShards());
        $reopenedA = Registry::discover($this->tmpDir, 'A');
        self::assertNotNull($reopenedA);
        $this->registerRegistry($reopenedA);
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $reopenedA->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $idsA);
        \sort($expected);
        self::assertSame($expected, $actual);
    }

    // Пустой файл реестра → RegistryInvalidException; файл не перезаписывается.
    public function testEmptyFileThrowsRegistryInvalid(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'e',
        );
        \file_put_contents($cfg->ptfRegistry, '');

        try {
            $this->registerRegistry(Registry::createOrDiscover($cfg));
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException) {
            self::assertSame(0, \filesize($cfg->ptfRegistry));
        }
    }

    // version=2 → RegistryVersionMismatchException; файл не перезаписывается.
    public function testWrongVersionThrowsMismatch(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v',
        );
        $this->registerRegistry(Registry::create($cfg));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => 2])]);
        } finally {
            $ipdo->close();
        }

        try {
            $this->registerRegistry(Registry::createOrDiscover($cfg));
            self::fail('RegistryVersionMismatchException expected');
        } catch (RegistryVersionMismatchException) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }
    }

    // minSecHeartbeat === 10 и для созданного, и для обнаруженного.
    public function testMinSecHeartbeatIsAlwaysTen(): void
    {
        $created = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'h',
        )));
        self::assertSame(10, $created->minSecHeartbeat);

        $found = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'h',
        )));
        self::assertSame(10, $found->minSecHeartbeat);
    }

    // Возвращает Registry в обоих случаях.
    public function testReturnsRegistryInBothCases(): void
    {
        self::assertInstanceOf(Registry::class, $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'first',
        ))));
        self::assertInstanceOf(Registry::class, $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'first',
        ))));
    }

    // Сохранённые при создании настройки не перезаписываются конфигурацией
    // при повторных вызовах.
    public function testSavedSettingsNotOverwrittenByLaterCalls(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'p',
            rentTimeout: 30,
        );
        $this->registerRegistry(Registry::create($cfg));

        $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'p',
            rentTimeout: 90,
        )));

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
    }

    // Повторные вызовы стабильны: количество не меняется.
    public function testRepeatedCallsAreStable(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'st',
        )));

        $first = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'st',
        )));
        $second = $this->registerRegistry(Registry::createOrDiscover(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'st',
        )));

        self::assertSame(5, $first->getCountShards());
        self::assertSame(5, $second->getCountShards());
        $plain = Registry::discover($this->tmpDir, 'st');
        self::assertNotNull($plain);
        $this->registerRegistry($plain);
        self::assertSame(5, $plain->getCountShards());
    }
}
