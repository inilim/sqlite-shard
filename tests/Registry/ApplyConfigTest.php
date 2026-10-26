<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::applyConfig()}
 *
 *  «applyConfig»
 */
final class ApplyConfigTest extends TestCase
{
    /**
     * Чтение фактических значений updated_at из файла реестра (id => updated_at).
     *
     * @return array<string,int>
     */
    private function updatedAtMap(CreateConfig $cfg): array
    {
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $rows = $ipdo->exec('SELECT id, updated_at FROM shards', [], IPDO::FETCH_ALL_NUM);
        } finally {
            $ipdo->close();
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row[0]] = (int)$row[1];
        }
        return $map;
    }

    /**
     * Файлы шардов ({id}.sqlite) без файла реестра.
     *
     * @return string[]
     */
    private function shardFiles(): array
    {
        return \array_values(\array_filter(
            \glob($this->tmpDir . '/*.sqlite') ?: [],
            static fn(string $ptf): bool => !\str_starts_with(\basename($ptf), 'registry-'),
        ));
    }

    /**
     * Возвращает тот же объект Registry (assertSame) и поддерживает цепочку:
     * applyConfig(...) -> setMinSecHeartbeat(5) — тот же объект.
     */
    public function testReturnsSameObjectAndSupportsChaining(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $result = $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        ))->setMinSecHeartbeat(5);

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->minSecHeartbeat);
    }

    /**
     * Применяет minShards из конфигурации: у реестра с 5 шардами после applyConfig
     * (minShards=8) getCountShards() по-прежнему 5; следующий discover() создаёт
     * 3 недостающих шарда и возвращает реестр с 8; новые шарды свободны, имеют
     * файлы и сохранённую схему, существующие не изменены.
     */
    public function testAppliesMinShardsOnNextDiscover(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $updatedBefore = $this->updatedAtMap($cfg);

        $result = $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
        ));

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->getCountShards());

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());

        // новые шарды: свободны, имеют файл и сохранённую схему items
        $newShards = [];
        foreach ($discovered->getAllShards() as $shard) {
            if (!\in_array($shard->id, $oldIds, true)) {
                $newShards[] = $shard;
            }
        }
        self::assertCount(3, $newShards);
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

        // существующие не изменены: те же id и те же updated_at
        $updatedAfter = $this->updatedAtMap($cfg);
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        foreach ($oldIds as $id) {
            self::assertContains($id, $allIds);
            self::assertSame($updatedBefore[$id], $updatedAfter[$id]);
        }
    }

    /**
     * Применяет rentTimeout из конфигурации немедленно: активный шард с updated_at
     * ago(45) при старом таймауте 30 свободен; после applyConfig (rentTimeout=60)
     * занят (isFree false, rentValid true); после applyConfig (rentTimeout=10)
     * снова свободен, leftTimeUntilRelease() === 0.
     */
    public function testAppliesRentTimeoutImmediately(): void
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

        // старый таймаут 30: updated_at 45 сек назад -> свободен
        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 60,
        ));

        self::assertFalse($reg->isFree($shard));
        self::assertTrue($reg->rentValid($shard));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 10,
        ));

        self::assertTrue($reg->isFree($shard));
        self::assertSame(0, $reg->leftTimeUntilRelease($shard));
    }

    /**
     * Применяет оба значения за один вызов: конфиг minShards=8, rentTimeout=60 —
     * количество шардов не меняется сразу (5), активный шард с ago(45) сразу
     * занят; следующий discover() доводит количество до 8.
     */
    public function testAppliesBothMinShardsAndRentTimeoutInOneCall(): void
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

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
            rentTimeout: 60,
        ));

        self::assertSame(5, $reg->getCountShards());
        self::assertFalse($reg->isFree($shard));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(8, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(60, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());
    }

    /**
     * Не меняет minSecHeartbeat: после setMinSecHeartbeat(3) вызов applyConfig()
     * оставляет значение 3.
     */
    public function testApplyConfigKeepsCustomMinSecHeartbeat(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $reg->setMinSecHeartbeat(3);

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        ));

        self::assertSame(3, $reg->minSecHeartbeat);
    }

    /**
     * Не меняет minSecHeartbeat: у экземпляра по умолчанию после applyConfig()
     * остаётся значение 10.
     */
    public function testApplyConfigKeepsDefaultMinSecHeartbeat(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        ));

        self::assertSame(10, $reg->minSecHeartbeat);
    }

    /**
     * Не меняет name и baseDir текущего реестра.
     */
    public function testDoesNotChangeNameAndBaseDir(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'B',
            minShards: 2,
            rentTimeout: 60,
        ));

        self::assertSame('A', $reg->getName());
        self::assertSame($cfg->baseDir, $reg->baseDir);
        self::assertSame(5, $reg->getCountShards());
    }

    /**
     * Берёт значения из переданного CreateConfig, даже если его name и baseDir
     * указывают на другой реестр: у A остаются имя 'A', прежний baseDir и 5 шардов,
     * но таймаут становится 60; реестр B не создан, discover для B возвращает null.
     */
    public function testTakesValuesFromPassedConfigEvenIfPointingToAnotherRegistry(): void
    {
        $cfgA = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'A',
            minShards: 5,
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfgA));
        $shard = $regA->getAllShards()[0];
        $regA->rent($shard);
        $ipdo = new IPDOSQLite($cfgA->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }
        // таймаут 30: шард свободен
        self::assertTrue($regA->isFree($shard));

        $dirB = $this->tmpDir . '/b';
        \mkdir($dirB, 0777, true);
        $cfgB = new CreateConfig(
            baseDir: $dirB,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'B',
            minShards: 2,
            rentTimeout: 60,
        );

        $regA->applyConfig($cfgB);

        self::assertSame('A', $regA->getName());
        self::assertSame($cfgA->baseDir, $regA->baseDir);
        self::assertSame(5, $regA->getCountShards());
        // интерпретация теперь по таймауту 60: шард занят
        self::assertFalse($regA->isFree($shard));
        $ipdo = new IPDOSQLite($cfgA->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(60, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfgA->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        // реестр B не создан
        self::assertFileDoesNotExist($cfgB->ptfRegistry);
        self::assertNull(Registry::discover($dirB, 'B'));
    }

    /**
     * Не применяет schemaSql: схема существующих шардов не меняется; после
     * applyConfig (minShards=8) и последующего discover() новые шарды используют
     * сохранённую схему и НЕ содержат таблицу extra.
     */
    public function testDoesNotApplySchemaSqlFromConfig(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: [
                'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
                'CREATE TABLE extra (x INTEGER)',
            ],
            name: 'test',
            minShards: 8,
        ));

        // сохранённая схема в реестре не изменилась
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'schema_sql'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'], \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());

        foreach ($discovered->getAllShards() as $shard) {
            $ipdo = $discovered->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
                self::assertFalse($ipdo->existsTable('extra'));
            } finally {
                $ipdo->close();
            }
        }
    }

    /**
     * Не создаёт и не удаляет шарды сам: при 5 шардах applyConfig (minShards=2)
     * оставляет 5; applyConfig (minShards=8) — те же 5 шардов и файлы; количество
     * меняется только при последующем discover().
     */
    public function testApplyConfigDoesNotCreateOrDeleteShardsItself(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $idsBefore = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 2,
        ));

        self::assertSame(5, $reg->getCountShards());
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
        ));

        self::assertSame(5, $reg->getCountShards());
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $reg->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $idsBefore);
        \sort($expected);
        self::assertSame($expected, $actual);

        // количество меняется только при последующем discover()
        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());
    }

    /**
     * Применение minShards меньше текущего количества не удаляет шарды:
     * последующий discover() возвращает все существующие шарды.
     */
    public function testApplyConfigWithLowerMinShardsDoesNotDeleteShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 3,
        ));

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $discovered->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $allIds);
        \sort($expected);
        self::assertSame($expected, $actual);
        self::assertCount(8, $this->shardFiles());
    }

    /**
     * Сохраняет таймаут в реестре: после applyConfig (rentTimeout=60) новый
     * экземпляр через discover() считает активный шард с ago(45) занятым.
     */
    public function testSavesRentTimeoutInRegistry(): void
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

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 60,
        ));

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        $reopenedShard = null;
        foreach ($discovered->getAllShards() as $s) {
            if ($s->id === $shard->id) {
                $reopenedShard = $s;
                break;
            }
        }
        self::assertNotNull($reopenedShard);
        self::assertFalse($discovered->isFree($reopenedShard));
        self::assertTrue($discovered->rentValid($reopenedShard));
    }

    /**
     * Сохраняет минимум в реестре: после applyConfig (minShards=8) новый экземпляр
     * через discover() возвращает 8 шардов; повторный discover не создаёт лишних.
     */
    public function testSavesMinShardsInRegistry(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
        ));

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());

        $again = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($again);
        $this->registerRegistry($again);
        self::assertSame(8, $again->getCountShards());
    }

    /**
     * Повторный applyConfig() с теми же значениями не меняет количество шардов,
     * их id, updated_at и файлы; метод возвращает тот же объект.
     */
    public function testRepeatedApplyConfigWithSameValuesChangesNothing(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $idsBefore = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $updatedBefore = $this->updatedAtMap($cfg);
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        $result = $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
            rentTimeout: 30,
        ));

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->getCountShards());
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $reg->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $idsBefore);
        \sort($expected);
        self::assertSame($expected, $actual);
        self::assertSame($updatedBefore, $this->updatedAtMap($cfg));
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    /**
     * Применение конфигурации не меняет active и updated_at существующих шардов:
     * меняется только интерпретация через новый rent_timeout; количество записей
     * и файлы остаются прежними.
     */
    public function testApplyConfigDoesNotChangeShardActiveAndUpdatedAt(): void
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
        $frozenAt = \time() - 45;
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => $frozenAt, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 10,
        ));

        // интерпретация поменялась: шард свободен, остаток аренды 0
        self::assertTrue($reg->isFree($shard));
        self::assertFalse($reg->rentValid($shard));
        self::assertSame(0, $reg->leftTimeUntilRelease($shard));

        // active и updated_at в файле реестра не менялись
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $row = $ipdo->exec(
                'SELECT active, updated_at FROM shards WHERE id = {id}',
                ['id' => $shard->id],
                IPDOSQLite::FETCH_ONCE_NUM
            );
        } finally {
            $ipdo->close();
        }
        self::assertSame([1, $frozenAt], [(int)$row[0], (int)$row[1]]);

        // количество записей и файлы прежние
        self::assertSame(5, $reg->getCountShards());
        self::assertCount(5, $this->shardFiles());
    }

    /**
     * Уже открытый второй экземпляр (discover) сразу видит новый таймаут; для
     * нового минимума ему достаточно заново вызвать discover().
     */
    public function testSecondOpenInstanceSeesNewTimeoutAndMinShardsAfterDiscover(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $shard = $regA->getAllShards()[0];
        $regA->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 45, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        // старый таймаут 30: свободен
        self::assertTrue($regB->isFree(new Shard($shard->id, $regB)));

        $regA->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 8,
            rentTimeout: 60,
        ));

        // новый таймаут сразу виден уже открытому экземпляру
        self::assertFalse($regB->isFree(new Shard($shard->id, $regB)));
        self::assertSame(5, $regB->getCountShards());

        // для нового минимума достаточно вызвать discover() заново
        $regB2 = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB2);
        $this->registerRegistry($regB2);
        self::assertSame(8, $regB2->getCountShards());
    }

    /**
     * Передача не-CreateConfig (null, массив, stdClass) при strict_types=1 →
     * TypeError; состояние реестра не меняется (количество и kv rent_timeout /
     * min_shards прежние).
     */
    public function testPassingNonCreateConfigThrowsTypeErrorAndKeepsState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        foreach ([null, [], (object)[]] as $bad) {
            try {
                $reg->applyConfig($bad);
                self::fail('Expected TypeError for ' . \get_debug_type($bad));
            } catch (\TypeError $e) {
                self::assertSame(5, $reg->getCountShards());
            }
        }

        self::assertSame(5, $reg->getCountShards());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
    }

    /**
     * Дополнительно: applyConfig с конфигом rent_timeout=60 поддерживает цепочку
     * и возвращает тот же объект Registry.
     */
    public function testApplyConfigWithNewRentTimeoutSupportsChaining(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $result = $reg->applyConfig(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 60,
        ))->setMinSecHeartbeat(5);

        self::assertSame($reg, $result);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(60, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->minSecHeartbeat);
    }
}
