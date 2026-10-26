<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\RegistryInvalidException;
use Inilim\SqliteShard\RegistryNameExistsException;
use Inilim\SqliteShard\RegistryVersionMismatchException;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::create()}
 *
 *  «create»
 */
final class CreateTest extends TestCase
{
    // Новое имя: создаётся файл реестра; возвращается Registry с name и baseDir
    // из конфигурации.
    public function testCreatesRegistryFileAndReturnsRegistry(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'first',
        );

        $reg = $this->registerRegistry(Registry::create($cfg));

        self::assertInstanceOf(Registry::class, $reg);
        self::assertFileExists($cfg->ptfRegistry);
        self::assertSame('first', $reg->getName());
        self::assertSame($cfg->baseDir, $reg->baseDir);
    }

    // Настройки по умолчанию: 5 свободных шардов с файлами и схемой.
    public function testDefaultCreatesFiveFreeShardsWithSchema(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        self::assertSame(5, $reg->getCountShards());
        foreach ($reg->getAllShards() as $shard) {
            self::assertTrue($reg->isFree($shard));
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
            $ipdo = $reg->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // minShards=1 → ровно один шард.
    public function testMinShardsOne(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
        )));

        self::assertSame(1, $reg->getCountShards());
    }

    // minShards=3 → ровно три шарда.
    public function testMinShardsThree(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 3,
        )));

        self::assertSame(3, $reg->getCountShards());
    }

    // rentTimeout=60 применяется: leftTime сразу после аренды ~60; активный
    // шард с ago(45) занят.
    public function testRentTimeoutFromConfigApplied(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'rt',
            minShards: 1,
            rentTimeout: 60,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        self::assertGreaterThanOrEqual(58, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(60, $reg->leftTimeUntilRelease($shard));

        $reg->release($shard);
        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        self::assertFalse($reg->isFree($shard));
    }

    // schemaSql строкой превращается в массив и применяется.
    public function testSchemaSqlAsStringApplied(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: 'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
            name: 'test',
            minShards: 1,
        )));

        $shard = $reg->getAllShards()[0];
        $ipdo = $reg->getIPDOByShard($shard);
        try {
            self::assertTrue($ipdo->existsTable('items'));
        } finally {
            $ipdo->close();
        }
    }

    // Несколько инструкций schemaSql применяются в порядке.
    public function testSchemaSqlArrayAppliedInOrder(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [
                'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
                'CREATE INDEX idx_name ON items(name)',
            ],
            name: 'test',
            minShards: 1,
        )));

        $ipdo = $reg->getIPDOByShard($reg->getAllShards()[0]);
        try {
            $row = $ipdo->exec(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_name'",
                [],
                \Inilim\IPDO\IPDOSQLite::FETCH_ONCE_NUM,
            );
            self::assertIsArray($row);
            self::assertNotEmpty($row);
        } finally {
            $ipdo->close();
        }
    }

    // schemaSql=[] → корректные пустые SQLite-файлы шардов.
    public function testEmptySchemaSql(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [],
            name: 'test',
            minShards: 1,
        )));

        $ipdo = $reg->getIPDOByShard($reg->getAllShards()[0]);
        try {
            self::assertFalse($ipdo->existsTable('items'));
            $ipdo->exec('SELECT 1');
        } finally {
            $ipdo->close();
        }
    }

    // Имя с кириллицей и пробелами: getName() без изменений; файл называется
    // registry-{sha1(name)}.sqlite (аналог ptfRegistry из конфигурации).
    public function testComplexNameCreatesCorrespondingFile(): void
    {
        $name = 'Реестр "Альфа" №1';
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: $name,
        );

        $reg = $this->registerRegistry(Registry::create($cfg));

        self::assertSame($name, $reg->getName());
        self::assertFileExists($cfg->ptfRegistry);
        self::assertSame('registry-' . \sha1($name) . '.sqlite', \basename($cfg->ptfRegistry));
    }

    // Два реестра с разными именами в одной директории независимы.
    public function testTwoRegistriesInOneDirAreIndependent(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        )));
        $regB = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'B',
        )));

        self::assertSame('A', $regA->getName());
        self::assertSame('B', $regB->getName());
        self::assertSame(5, $regA->getCountShards());
        self::assertSame(5, $regB->getCountShards());
        // первый не меняется
        self::assertSame(5, $regA->getCountShards());
    }

    // Одинаковые имена в разных baseDir — два независимых реестра.
    public function testSameNameInDifferentBaseDirsIsIndependent(): void
    {
        $sub = $this->tmpDir . '/sub';
        $cfgA = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'same',
        );
        \mkdir($sub, 0777, true);
        $cfgB = new CreateConfig(
            baseDir: $sub,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'same',
            minShards: 2,
        );

        $regA = $this->registerRegistry(Registry::create($cfgA));
        $regB = $this->registerRegistry(Registry::create($cfgB));

        self::assertSame(5, $regA->getCountShards());
        self::assertSame(2, $regB->getCountShards());
        self::assertFileExists($cfgA->ptfRegistry);
        self::assertFileExists($cfgB->ptfRegistry);
        self::assertNotEquals($cfgA->ptfRegistry, $cfgB->ptfRegistry);
    }

    // Повторный create() с тем же именем → RegistryNameExistsException,
    // сообщение === name; шарды не меняются.
    public function testRepeatedCreateThrowsNameExists(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'dup',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        try {
            $this->registerRegistry(Registry::create($cfg));
            self::fail('RegistryNameExistsException expected');
        } catch (RegistryNameExistsException $e) {
            self::assertSame('dup', $e->getMessage());
        }

        $discovered = Registry::discover($this->tmpDir, 'dup');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $discovered->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $ids);
        \sort($expected);
        self::assertSame($expected, $actual);
    }

    // Записей меньше сохранённого min_shards: повторный create() сначала
    // до-создаёт до 5 через discover, затем бросает RegistryNameExistsException.
    public function testRepeatedCreateFillsMissingShardsThenThrows(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'fill',
        );
        $this->registerRegistry(Registry::create($cfg));
        $all = Registry::discover($this->tmpDir, 'fill');
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

        try {
            $this->registerRegistry(Registry::create($cfg));
            self::fail('RegistryNameExistsException expected');
        } catch (RegistryNameExistsException) {
        }

        $after = Registry::discover($this->tmpDir, 'fill');
        self::assertNotNull($after);
        $this->registerRegistry($after);
        self::assertSame(5, $after->getCountShards());
    }

    // Повторный create() с другими rentTimeout/minShards: исключение; настройки
    // не меняются.
    public function testRepeatedCreateDoesNotOverwriteConfig(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'cfg',
            minShards: 2,
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
            $this->registerRegistry(Registry::create(new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'cfg',
                minShards: 8,
                rentTimeout: 60,
            )));
            self::fail('RegistryNameExistsException expected');
        } catch (RegistryNameExistsException) {
        }

        // настройки не поменялись
        $after = Registry::discover($this->tmpDir, 'cfg');
        self::assertNotNull($after);
        $this->registerRegistry($after);
        self::assertSame(2, $after->getCountShards());
        $reopened = null;
        foreach ($after->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $reopened = $s;
                break;
            }
        }
        self::assertNotNull($reopened);
        self::assertTrue($after->isFree($reopened)); // таймаут всё ещё 30
    }

    // config->minShards больше сохранённого: повторный create() бросает
    // исключение; количество остаётся прежним.
    public function testRepeatedCreateDoesNotGrowToNewMinShards(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'grow',
            minShards: 2,
        )));

        try {
            $this->registerRegistry(Registry::create(new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'grow',
                minShards: 8,
            )));
            self::fail('RegistryNameExistsException expected');
        } catch (RegistryNameExistsException) {
        }

        $after = Registry::discover($this->tmpDir, 'grow');
        self::assertNotNull($after);
        $this->registerRegistry($after);
        self::assertSame(2, $after->getCountShards());
    }

    // Повторный create() при существующем пустом файле → RegistryInvalidException
    // (не RegistryNameExistsException); файл не перезаписывается.
    public function testRepeatedCreateOnEmptyFileThrowsRegistryInvalid(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'empty',
        );
        \file_put_contents($cfg->ptfRegistry, '');

        try {
            $this->registerRegistry(Registry::create($cfg));
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException) {
            self::assertSame(0, \filesize($cfg->ptfRegistry));
        }
    }

    // Повторный create() при реестре с version=2 → RegistryVersionMismatchException;
    // файл не перезаписывается.
    public function testRepeatedCreateOnWrongVersionThrowsMismatch(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'ver',
        );
        $this->registerRegistry(Registry::create($cfg));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => 2])]);
        } finally {
            $ipdo->close();
        }

        try {
            $this->registerRegistry(Registry::create($cfg));
            self::fail('RegistryVersionMismatchException expected');
        } catch (RegistryVersionMismatchException) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }
    }

    // Созданный реестр открывается через discover(): не null, count === minShards.
    public function testCreatedRegistryIsDiscoverable(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'disc',
            minShards: 3,
        )));

        $discovered = Registry::discover($this->tmpDir, 'disc');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(3, $discovered->getCountShards());
    }

    // create() не трогает реестры с другими именами в той же директории.
    public function testDoesNotTouchOtherRegistries(): void
    {
        $cfgA = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        );
        $regA = $this->registerRegistry(Registry::create($cfgA));
        $idsA = \array_map(static fn(Shard $s): string => $s->id, $regA->getAllShards());

        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'B',
        )));

        $reopened = Registry::discover($this->tmpDir, 'A');
        self::assertNotNull($reopened);
        $this->registerRegistry($reopened);
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $reopened->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $idsA);
        \sort($expected);
        self::assertSame($expected, $actual);
    }
}
