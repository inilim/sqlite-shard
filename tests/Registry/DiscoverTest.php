<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\KeyNotFoundKvException;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\RegistryInvalidException;
use Inilim\SqliteShard\RegistryVersionMismatchException;
use Inilim\SqliteShard\Shard;
use Inilim\Tool\Path;
use Inilim\Test\TestCase;

/**
 * {@see Registry::discover()}
 *
 *  «discover»
 */
final class DiscoverTest extends TestCase
{
    // Создаёт «сырой» файл реестра с таблицами kv и shards (пустыми).
    private function rawRegistry(CreateConfig $cfg): void
    {
        \file_put_contents($cfg->ptfRegistry, '');
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('CREATE TABLE kv (k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)');
            $ipdo->exec(
                'CREATE TABLE shards (id TEXT PRIMARY KEY NOT NULL, schema_version INTEGER NOT NULL, '
                    . 'active INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)'
            );
        } finally {
            $ipdo->close();
        }
    }

    // Создаёт «сырой» корректный реестр: таблицы + обязательные ключи kv.
    private function fullRawRegistry(CreateConfig $cfg): void
    {
        $this->rawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'name', 'v' => \json_encode(['value' => $cfg->name])]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => Registry::VERSION])]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'schema_sql', 'v' => \json_encode(['value' => ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)']])]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'created_at', 'v' => \json_encode(['value' => \time()])]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'rent_timeout', 'v' => \json_encode(['value' => 30])]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'min_shards', 'v' => \json_encode(['value' => 5])]);
        } finally {
            $ipdo->close();
        }
    }

    // Для существующего корректного реестра возвращает Registry: тот же name,
    // тот же baseDir, тот же набор id шардов.
    public function testReturnsExistingRegistry(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'dis',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $discovered = Registry::discover($this->tmpDir, 'dis');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertInstanceOf(Registry::class, $discovered);
        self::assertSame('dis', $discovered->getName());
        self::assertSame($cfg->baseDir, $discovered->baseDir);
        $shardIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        \sort($shardIds);
        \sort($ids);
        self::assertSame($ids, $shardIds);
    }

    // Шард, арендованный первым экземпляром, виден второму: isFree false,
    // leftTime > 0.
    public function testRentStateVisibleToSecondInstance(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'st',
        )));
        $busy = $regA->tryRentFree();
        self::assertNotNull($busy);

        $regB = Registry::discover($this->tmpDir, 'st');

        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $bShard = null;
        foreach ($regB->getAllShards() as $s) {
            if ($s->id === $busy->id) {
                $bShard = $s;
                break;
            }
        }
        self::assertNotNull($bShard);
        self::assertFalse($regB->isFree($bShard));
        self::assertGreaterThan(0, $regB->leftTimeUntilRelease($bShard));
    }

    // Сохранённый rent_timeout используется новым экземпляром: реестр создан с
    // таймаутом 60, активный шард с ago(45) занят для discover-экземпляра.
    public function testSavedRentTimeoutUsedByNewInstance(): void
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
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $discovered = Registry::discover($this->tmpDir, 'rt');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $dShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $dShard = $s;
                break;
            }
        }
        self::assertNotNull($dShard);
        self::assertFalse($discovered->isFree($dShard));
    }

    // minSecHeartbeat у нового экземпляра 10, даже если предыдущему установлено 3.
    public function testMinSecHeartbeatIsTenForNewInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'hb',
        )));
        $reg->setMinSecHeartbeat(3);

        $discovered = Registry::discover($this->tmpDir, 'hb');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(10, $discovered->minSecHeartbeat);
    }

    // Количество равно сохранённому min_shards: новых шардов не создаётся.
    public function testNoNewShardsWhenCountIsSufficient(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'ok',
            minShards: 5,
        )));
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $discovered = Registry::discover($this->tmpDir, 'ok');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
        $shardIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        \sort($shardIds);
        \sort($ids);
        self::assertSame($ids, $shardIds);
    }

    // Количество БОЛЬШЕ сохранённого (5 + createNewShards(3) = 8): discover
    // возвращает те же 8 id, не удаляет, файлы на месте.
    public function testMoreShardsThanMinimumAreKept(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'more',
            minShards: 5,
        )));
        $reg->createNewShards(3);
        self::assertSame(8, $reg->getCountShards());
        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $discovered = Registry::discover($this->tmpDir, 'more');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());
        $shardIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        \sort($shardIds);
        \sort($ids);
        self::assertSame($ids, $shardIds);
        foreach ($discovered->getAllShards() as $shard) {
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $discovered->baseDir, $shard->id)));
        }
    }

    // Количество МЕНЬШЕ сохранённого (удалены 2 записи из 5): discover
    // до-создаёт недостающие; новые свободны, со схемой; старые не изменены.
    public function testFillsMissingShardsUpToSavedMinimum(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'fill',
            minShards: 5,
        );
        $this->registerRegistry(Registry::create($cfg));
        $old = Registry::discover($this->tmpDir, 'fill');
        self::assertNotNull($old);
        $this->registerRegistry($old);
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $old->getAllShards());
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

        $discovered = Registry::discover($this->tmpDir, 'fill');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());

        $newShards = [];
        foreach ($discovered->getAllShards() as $shard) {
            if (!\in_array($shard->id, $oldIds, true)) {
                $newShards[] = $shard;
            }
        }
        self::assertCount(2, $newShards);
        foreach ($newShards as $shard) {
            self::assertTrue($discovered->isFree($shard));
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $discovered->baseDir, $shard->id)));
            $ipdo = $discovered->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
        // старые (неудалённые) на месте
        foreach ($oldIds as $i => $id) {
            if ($i < 2) {
                continue; // эти две записи удалены выше
            }
            self::assertContains($id, \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards()));
        }
    }

    // Идемпотентность: два discover подряд дают одинаковый набор id.
    public function testRepeatedDiscoverIsStable(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'rep',
            minShards: 3,
        )));

        $a = Registry::discover($this->tmpDir, 'rep');
        $b = Registry::discover($this->tmpDir, 'rep');

        self::assertNotNull($a);
        self::assertNotNull($b);
        $this->registerRegistry($a);
        $this->registerRegistry($b);
        $idsA = \array_map(static fn(Shard $s): string => $s->id, $a->getAllShards());
        $idsB = \array_map(static fn(Shard $s): string => $s->id, $b->getAllShards());
        \sort($idsA);
        \sort($idsB);
        self::assertSame($idsB, $idsA);
        self::assertSame(3, $a->getCountShards());
    }

    // Неизвестное имя → null; файлы не создаются.
    public function testUnknownNameReturnsNullWithoutCreatingFiles(): void
    {
        self::assertNull(Registry::discover($this->tmpDir, 'unknown'));

        self::assertSame([], \glob($this->tmpDir . '/*.sqlite'));
        self::assertSame([], \glob($this->tmpDir . '/registry-*.sqlite'));
    }

    // В директории есть реестр с другим именем, нужного нет → null; чужой
    // реестр не изменяется.
    public function testOtherRegistryInDirNotUsed(): void
    {
        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        )));
        $idsA = \array_map(static fn(Shard $s): string => $s->id, $regA->getAllShards());

        self::assertNull(Registry::discover($this->tmpDir, 'B'));

        $reopened = Registry::discover($this->tmpDir, 'A');
        self::assertNotNull($reopened);
        $this->registerRegistry($reopened);
        $reopenedIds = \array_map(static fn(Shard $s): string => $s->id, $reopened->getAllShards());
        \sort($reopenedIds);
        \sort($idsA);
        self::assertSame($idsA, $reopenedIds);
    }

    // Несуществующая baseDir → InvalidArgumentException; ничего не создаётся.
    public function testNonexistentBaseDirThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::discover($this->tmpDir . '/nope', 'x');
    }

    // baseDir — это файл → InvalidArgumentException.
    public function testBaseDirIsFileThrows(): void
    {
        $file = $this->tmpDir . '/file.txt';
        \file_put_contents($file, '');

        $this->expectException(\InvalidArgumentException::class);
        Registry::discover($file, 'x');
    }

    // Пустое имя → InvalidArgumentException.
    public function testEmptyNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::discover($this->tmpDir, '');
    }

    // Пустой файл реестра (0 байт) → RegistryInvalidException; сообщение
    // содержит имя, путь, перечень таблиц, namespace и вызов create();
    // файл не перезаписывается.
    public function testEmptyFileThrowsRegistryInvalidWithDetailedMessage(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'dmg',
        );
        \file_put_contents($cfg->ptfRegistry, '');

        try {
            Registry::discover($this->tmpDir, 'dmg');
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('dmg', $msg);
            self::assertStringContainsString($cfg->ptfRegistry, $msg);
            self::assertStringContainsString('kv, shards', $msg);
            self::assertStringContainsString('Inilim\SqliteShard', $msg);
            self::assertStringContainsString('Registry::create()', $msg);
            self::assertSame(0, \filesize($cfg->ptfRegistry));
        }
    }

    // Только таблица shards → в сообщении об отсутствующих таблицах есть 'kv'.
    public function testMissingKvTableThrows(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'no-kv',
        );
        \file_put_contents($cfg->ptfRegistry, '');
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec(
                'CREATE TABLE shards (id TEXT PRIMARY KEY NOT NULL, schema_version INTEGER NOT NULL, '
                    . 'active INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)'
            );
        } finally {
            $ipdo->close();
        }

        try {
            Registry::discover($this->tmpDir, 'no-kv');
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException $e) {
            self::assertStringContainsString('kv', $e->getMessage());
        }
    }

    // Только таблица kv → в сообщении есть 'shards'.
    public function testMissingShardsTableThrows(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'no-shards',
        );
        \file_put_contents($cfg->ptfRegistry, '');
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('CREATE TABLE kv (k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)');
        } finally {
            $ipdo->close();
        }

        try {
            Registry::discover($this->tmpDir, 'no-shards');
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException $e) {
            self::assertStringContainsString('shards', $e->getMessage());
        }
    }

    // Ни одной из таблиц (пустой файл) → в сообщении обе.
    public function testBothTablesMissingMentionedInMessage(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'both',
        );
        \file_put_contents($cfg->ptfRegistry, '');

        try {
            Registry::discover($this->tmpDir, 'both');
            self::fail('RegistryInvalidException expected');
        } catch (RegistryInvalidException $e) {
            self::assertStringContainsString('kv', $e->getMessage());
            self::assertStringContainsString('shards', $e->getMessage());
        }
    }

    // Файл — не SQLite (случайные байты) → IPDOException, а не
    // RegistryInvalidException.
    public function testNonSQLiteFileThrowsIPDOException(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'bin',
        );
        \file_put_contents($cfg->ptfRegistry, \str_repeat('not a database ', 20));

        $this->expectException(IPDOException::class);
        Registry::discover($this->tmpDir, 'bin');
    }

    // Таблицы есть, но нет version → KeyNotFoundKvException.
    public function testMissingVersionThrowsKeyNotFound(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'no-ver',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM kv WHERE k = {k}', ['k' => 'version']);
        } finally {
            $ipdo->close();
        }

        $this->expectException(KeyNotFoundKvException::class);
        Registry::discover($this->tmpDir, 'no-ver');
    }

    // version=0 → RegistryVersionMismatchException; сообщение про устаревший
    // реестр и миграцию к версии 1; файл не изменяется.
    public function testVersionZeroThrowsOutdatedMismatch(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v0',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => 0])]);
        } finally {
            $ipdo->close();
        }

        try {
            Registry::discover($this->tmpDir, 'v0');
            self::fail('RegistryVersionMismatchException expected');
        } catch (RegistryVersionMismatchException $e) {
            self::assertStringContainsString('outdated', $e->getMessage());
            self::assertStringContainsString('Migrate', $e->getMessage());
            self::assertStringContainsString('1', $e->getMessage());
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(0, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }
    }

    // version=-1 → RegistryVersionMismatchException (ветка устаревшего).
    public function testVersionMinusOneThrowsMismatch(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v-1',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => -1])]);
        } finally {
            $ipdo->close();
        }

        $this->expectException(RegistryVersionMismatchException::class);
        Registry::discover($this->tmpDir, 'v-1');
    }

    // version=2 → RegistryVersionMismatchException; сообщение про более новую
    // версию и Upgrade; файл не изменяется.
    public function testVersionTwoThrowsNewerVersionMismatch(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v2',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'version', 'v' => \json_encode(['value' => 2])]);
        } finally {
            $ipdo->close();
        }

        try {
            Registry::discover($this->tmpDir, 'v2');
            self::fail('RegistryVersionMismatchException expected');
        } catch (RegistryVersionMismatchException $e) {
            self::assertStringContainsString('newer version', $e->getMessage());
            self::assertStringContainsString('Upgrade', $e->getMessage());
            self::assertStringContainsString('format 2', $e->getMessage());
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }
    }

    // version=1 — реестр открывается без RegistryVersionMismatchException.
    public function testVersionOneOpensFine(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v1',
        )));

        $discovered = Registry::discover($this->tmpDir, 'v1');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $cfgX = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'v1',
        );
        $ipdo = new IPDOSQLite($cfgX->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(1, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
    }

    // version=1, нет min_shards → KeyNotFoundKvException.
    public function testMissingMinShardsThrowsKeyNotFound(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'no-min',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM kv WHERE k = {k}', ['k' => 'min_shards']);
        } finally {
            $ipdo->close();
        }

        $this->expectException(KeyNotFoundKvException::class);
        Registry::discover($this->tmpDir, 'no-min');
    }

    // version=1, min_shards есть, нет rent_timeout: discover успешен, но
    // первый вызов isFree() бросает KeyNotFoundKvException.
    public function testMissingRentTimeoutThrowsOnFirstUse(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'no-rt',
        );
        $this->fullRawRegistry($cfg);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM kv WHERE k = {k}', ['k' => 'rent_timeout']);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('INSERT INTO kv (k, v) VALUES ({k}, {v}) ON CONFLICT(k) DO UPDATE SET v = {v}', ['k' => 'min_shards', 'v' => \json_encode(['value' => 5])]);
        } finally {
            $ipdo->close();
        }
        // 5 записей шардов, чтобы не было до-создания
        for ($i = 1; $i <= 5; $i++) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('INSERT OR REPLACE INTO shards (id, schema_version, active, created_at, updated_at) VALUES ({id}, {sv}, {a}, {c}, {t})', ['id' => 'shard-' . $i, 'sv' => Registry::VERSION, 'a' => 0, 'c' => \time(), 't' => \time()]);
            } finally {
                $ipdo->close();
            }
        }

        $discovered = Registry::discover($this->tmpDir, 'no-rt');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());

        $this->expectException(KeyNotFoundKvException::class);
        $tmp = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === 'shard-1') {
                $tmp = $s;
                break;
            }
        }
        self::assertNotNull($tmp);
        $discovered->isFree($tmp);
    }

    // Удалена запись шарда, файлы остались, записей меньше min_shards: discover
    // создаёт шард с НОВЫМ id; файл старого id остаётся «сиротой».
    public function testOrphanShardFileRemainsAfterRowDeleted(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'orphan',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $deletedId = $allIds[0];
        $tmp = null;
        foreach ($reg->getAllShards() as $s) {
            if ($s->id === $deletedId) {
                $tmp = $s;
                break;
            }
        }
        self::assertNotNull($tmp);
        $orphanPtf = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $tmp->id));

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $deletedId]);
        } finally {
            $ipdo->close();
        }

        $discovered = Registry::discover($this->tmpDir, 'orphan');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
        $newIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        self::assertNotContains($deletedId, $newIds);
        self::assertFileExists($orphanPtf); // сирота остаётся
        foreach ($newIds as $id) {
            $found = null;
            foreach ($discovered->getAllShards() as $s) {
                if ($s->id === $id) {
                    $found = $s;
                    break;
                }
            }
            self::assertNotNull($found);
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $discovered->baseDir, $found->id)));
        }
    }

    // Удалён файл шарда, запись осталась, записей достаточно: discover не
    // восстанавливает файл; первая операция с шардом бросает IPDOException.
    public function testMissingShardFileNotRestoredAndFailsOnUse(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'gone',
            minShards: 5,
        )));
        $shard = $reg->getAllShards()[0];
        $ptf = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id));
        self::assertFileExists($ptf);
        \unlink($ptf);

        $discovered = Registry::discover($this->tmpDir, 'gone');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
        $dShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $dShard = $s;
                break;
            }
        }
        self::assertNotNull($dShard);
        self::assertFileDoesNotExist($ptf);

        $ipdo = $discovered->getIPDOByShard($dShard);
        $this->expectException(IPDOException::class);
        $ipdo->exec('SELECT 1');
    }

    // Имя с кириллицей, пробелами и спецсимволами корректно находится:
    // файл registry-{sha1(name)}.sqlite.
    public function testComplexNameIsFound(): void
    {
        $name = 'Реестр №1 тест';
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: $name,
        )));
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: $name,
        );

        $discovered = Registry::discover($this->tmpDir, $name);

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame($name, $discovered->getName());
        self::assertFileExists($cfg->ptfRegistry);
    }

    // discover() с завершающим разделителем в baseDir находит реестр;
    // возвращаемый baseDir нормализован.
    public function testBaseDirWithTrailingSlashNormalized(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 's',
        )));

        $discovered = Registry::discover($reg->baseDir . '/', 's');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(Path::normalize($this->tmpDir), $discovered->baseDir);
    }

    // Каждый вызов discover() — новый объект Registry.
    public function testEachCallReturnsNewInstance(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'new',
        )));

        $a = Registry::discover($this->tmpDir, 'new');
        $b = Registry::discover($this->tmpDir, 'new');

        self::assertNotNull($a);
        self::assertNotNull($b);
        $this->registerRegistry($a);
        $this->registerRegistry($b);
        self::assertNotSame($a, $b);
    }

    // discover() не бросает RegistryNameExistsException на существующем
    // корректном реестре.
    public function testDiscoverDoesNotThrowNameExists(): void
    {
        $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'ne',
        )));

        try {
            $discovered = Registry::discover($this->tmpDir, 'ne');
            self::assertNotNull($discovered);
            $this->registerRegistry($discovered);
        } catch (RegistryInvalidException | RegistryVersionMismatchException | KeyNotFoundKvException $e) {
            self::fail('Неожиданное исключение: ' . $e::class);
        }
    }

    // Корректный реестр с достаточным числом шардов: kv и shards не меняются,
    // состояние аренды занятого шарда не меняется.
    public function testRegistryContentUnchangedByDiscover(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'unch',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);
        $leftBefore = $reg->leftTimeUntilRelease($busy);

        $discovered = Registry::discover($this->tmpDir, 'unch');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'version'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(1, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $discovered->getCountShards());
        $dBusy = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $busy->id) {
                $dBusy = $s;
                break;
            }
        }
        self::assertNotNull($dBusy);
        self::assertGreaterThanOrEqual($leftBefore - 2, $discovered->leftTimeUntilRelease($dBusy));
        self::assertLessThanOrEqual($leftBefore, $discovered->leftTimeUntilRelease($dBusy));
    }
}
