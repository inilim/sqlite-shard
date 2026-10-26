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
use Inilim\Tool\Path;

/**
 * {@see Registry::discoverFromCreateConfig()}
 *
 *  «discoverFromCreateConfig»
 */
final class DiscoverFromCreateConfigTest extends TestCase
{
    // Реестр не существует → null; файлы не создаются.
    public function testReturnsNullWhenRegistryDoesNotExist(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'absent',
        );

        $result = Registry::discoverFromCreateConfig($cfg);

        self::assertNull($result);
        self::assertFileDoesNotExist($cfg->ptfRegistry);
    }

    // Реестр существует → Registry; RegistryNameExistsException не бросается.
    public function testReturnsExistingRegistry(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'existing',
        )));
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'existing',
        );

        $result = Registry::discoverFromCreateConfig($cfg);

        self::assertInstanceOf(Registry::class, $result);
        $this->registerRegistry($result);
        self::assertSame('existing', $result->getName());
    }

    // config->rentTimeout не применяется к существующему реестру: используется
    // сохранённое значение 30, а не 60.
    public function testConfigRentTimeoutIsNotApplied(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'r',
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
        self::assertTrue($reg->isFree($shard)); // при таймауте 30 свободен

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'r',
            rentTimeout: 60,
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
        $reopened = null;
        foreach ($result->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $reopened = $s;
                break;
            }
        }
        self::assertNotNull($reopened);
        self::assertTrue($result->isFree($reopened));
    }

    // config->minShards не применяется: при созданном minShards=2 последующий
    // discover всё равно видит 2 шарда.
    public function testConfigMinShardsIsNotApplied(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'm',
            minShards: 2,
        )));

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'm',
            minShards: 5,
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
        self::assertSame(2, $result->getCountShards());

        $plain = Registry::discover($this->tmpDir, 'm');
        self::assertNotNull($plain);
        $this->registerRegistry($plain);
        self::assertSame(2, $plain->getCountShards());
    }

    // Записей меньше сохранённого min_shards (сохранено 5, удалено 2) —
    // встроенный discover до-создаёт недостающие до 5; новые свободны, со
    // схемой; старые не изменены.
    public function testFillsUpToSavedMinShards(): void
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
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $all->getAllShards());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $oldIds[0]]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $oldIds[1]]);
        } finally {
            $ipdo->close();
        }

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'f',
        ));
        self::assertNotNull($result);
        $this->registerRegistry($result);
        self::assertSame(5, $result->getCountShards());

        foreach ($result->getAllShards() as $shard) {
            self::assertTrue($result->isFree($shard));
            if (\in_array($shard->id, $oldIds, true)) {
                continue;
            }
            // новые шарды имеют файлы и сохранённую схему
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $result->baseDir, $shard->id)));
            $ipdo = $result->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // config->minShards меньше текущего количества — шарды не удаляются.
    public function testNeverDeletesShards(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'd',
            minShards: 8,
        )));

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'd',
            minShards: 2,
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
        self::assertSame(8, $result->getCountShards());
    }

    // config->schemaSql не применяется: схема существующих и новых шардов —
    // сохранённая (таблицы extra нет).
    public function testConfigSchemaSqlIsNotApplied(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 's',
        );
        $this->registerRegistry(Registry::create($cfg));

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)', 'CREATE TABLE extra (x INTEGER)'],
            name: 's',
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
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

    // Пустой файл реестра (0 байт) → RegistryInvalidException; файл не
    // перезаписывается (остаётся пустым).
    public function testEmptyFileThrowsRegistryInvalid(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'e',
        );
        \file_put_contents($cfg->ptfRegistry, '');

        try {
            Registry::discoverFromCreateConfig($cfg);
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException) {
            self::assertSame(0, \filesize($cfg->ptfRegistry));
        }
    }

    // version=2 → RegistryVersionMismatchException; файл не перезаписывается.
    public function testWrongVersionThrowsRegistryVersionMismatch(): void
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
            Registry::discoverFromCreateConfig($cfg);
            self::fail('RegistryVersionMismatchException expected');
        } catch (RegistryVersionMismatchException) {
            // файл не перезаписан: версия всё ещё 2
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }
    }

    // Несуществующее имя → null, даже если baseDir содержит другие реестры.
    public function testOtherRegistryInDirIsNotUsed(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'known',
        )));

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'unknown',
        ));

        self::assertNull($result);
    }

    // Состояние существующих шардов не меняется: свежий активный остаётся
    // занятым для возвращённого экземпляра.
    public function testExistingShardStatePreserved(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 't',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 't',
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
        $reopened = null;
        foreach ($result->getAllShards() as $s) {
            if ($s->id === $busy->id) {
                $reopened = $s;
                break;
            }
        }
        self::assertNotNull($reopened);
        self::assertTrue($result->rentValid($reopened));
        self::assertFalse($result->isFree($reopened));
    }

    // minSecHeartbeat === 10 у возвращённого экземпляра.
    public function testMinSecHeartbeatIsTen(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'h',
        )));

        $result = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'h',
        ));

        self::assertNotNull($result);
        $this->registerRegistry($result);
        self::assertSame(10, $result->minSecHeartbeat);
    }

    // Повторные вызовы стабильны: количество шардов не меняется.
    public function testRepeatedCallsAreStable(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'p',
        )));

        $first = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'p',
        ));
        $second = Registry::discoverFromCreateConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'p',
        ));

        self::assertNotNull($first);
        $this->registerRegistry($first);
        self::assertNotNull($second);
        $this->registerRegistry($second);
        self::assertSame(5, $first->getCountShards());
        self::assertSame(5, $second->getCountShards());
    }

    // baseDir возвращаемого экземпляра === baseDir реестра.
    public function testBaseDirMatchesConfig(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'b',
        );
        $this->registerRegistry(Registry::create($cfg));

        $result = Registry::discoverFromCreateConfig($cfg);

        self::assertNotNull($result);
        $this->registerRegistry($result);
        self::assertSame($cfg->baseDir, $result->baseDir);
    }
}
