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
 * {@see Registry::setMinShards()}
 *
 *  «setMinShards»
 */
final class SetMinShardsTest extends TestCase
{
    /**
     * Чтение фактических значений updated_at из файла реестра (id => updated_at).
     *
     * @return array<string,int>
     */
    private function updatedAtMap(CreateConfig $cfg): array
    {
        /** @var IPDOSQLite $ipdo */
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
     * После create() количество шардов равно minShards из CreateConfig (по умолчанию 5).
     */
    public function testAfterCreateCountEqualsMinShardsFromConfig(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
                minShards: 5,
            ),
        ));

        self::assertSame(5, $reg->getCountShards());
    }

    /**
     * Установка 8 возвращает тот же объект Registry; текущее количество шардов
     * не меняется (остаётся 5), новые файлы не создаются (5 шард-файлов).
     */
    public function testSettingEightReturnsSameObjectAndDoesNotCreateShards(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        $result = $reg->setMinShards(8);

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->getCountShards());
        self::assertCount(5, $this->shardFiles());
        // реестр + 5 шардов — других sqlite-файлов нет
        self::assertCount(6, \glob($this->tmpDir . '/*.sqlite') ?: []);
    }

    /**
     * Следующий discover() для того же имени создаёт недостающие шарды:
     * getCountShards() === 8; новые шарды свободны, имеют файлы и схему items;
     * существующие шарды не изменены (те же id, те же updated_at, тот же rentValid).
     */
    public function testNextDiscoverCreatesMissingShardsOnly(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $oldIds = \array_map(static fn(Shard $s): string => $s->id, $reg->getAllShards());
        $updatedBefore = $this->updatedAtMap($cfg);

        $reg->setMinShards(8);

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(8, $discovered->getCountShards());

        // новые шарды: свободны, имеют файл и сохранённую схему
        $newShards = [];
        foreach ($discovered->getAllShards() as $shard) {
            if (!\in_array($shard->id, $oldIds, true)) {
                $newShards[] = $shard;
            }
        }
        self::assertCount(3, $newShards);
        foreach ($newShards as $shard) {
            self::assertTrue($discovered->isFree($shard));
            self::assertFalse($discovered->rentValid($shard));
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $discovered->baseDir, $shard->id)));
            $ipdo = $discovered->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
                $ipdo->exec('SELECT * FROM items LIMIT 1');
            } finally {
                $ipdo->close();
            }
        }

        // существующие шарды не изменены
        $updatedAfter = $this->updatedAtMap($cfg);
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $discovered->getAllShards());
        foreach ($oldIds as $id) {
            self::assertContains($id, $allIds);
            self::assertSame($updatedBefore[$id], $updatedAfter[$id]);
            $oldShard = null;
            foreach ($discovered->getAllShards() as $s) {
                if ($s->id === $id) {
                    $oldShard = $s;
                    break;
                }
            }
            self::assertNotNull($oldShard);
            self::assertTrue($discovered->isFree($oldShard));
            self::assertFalse($discovered->rentValid($oldShard));
        }
    }

    /**
     * Значение сохраняется в реестре: после setMinShards(8) повторный discover()
     * в новом экземпляре не создаёт лишних шардов (8, не 16).
     */
    public function testValuePersistedAndRepeatedDiscoverDoesNotDuplicateShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->setMinShards(8);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(8, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $first = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($first);
        $this->registerRegistry($first);
        self::assertSame(8, $first->getCountShards());

        $second = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($second);
        $this->registerRegistry($second);
        self::assertSame(8, $second->getCountShards());
        self::assertCount(8, $this->shardFiles());
    }

    /**
     * Установка значения меньше текущего количества (3 при 8) не удаляет шарды:
     * discover() возвращает все 8 шардов, их строки и файлы остаются.
     */
    public function testSettingBelowCurrentCountDoesNotDeleteShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->setMinShards(8);
        $full = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($full);
        $this->registerRegistry($full);
        $allIds = \array_map(static fn(Shard $s): string => $s->id, $full->getAllShards());
        self::assertCount(8, $allIds);

        $reg->setMinShards(3);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(3, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        $again = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($again);
        $this->registerRegistry($again);
        self::assertSame(8, $again->getCountShards());
        $actual = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $again->getAllShards());
        \sort($actual);
        $expected = \array_map(static fn($v): string => $v instanceof Shard ? $v->id : (string) $v, $allIds);
        \sort($expected);
        self::assertSame($expected, $actual);
        self::assertCount(8, $this->shardFiles());
        foreach ($again->getAllShards() as $shard) {
            self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $again->baseDir, $shard->id)));
        }
    }

    /**
     * Установка значения, равного текущему количеству, не меняет ни количество,
     * ни файлы.
     */
    public function testSettingEqualToCurrentCountChangesNothing(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $result = $reg->setMinShards(5);

        self::assertSame($reg, $result);
        self::assertSame(5, $reg->getCountShards());

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
        self::assertCount(5, $this->shardFiles());
    }

    /**
     * Установка 0 → InvalidArgumentException; сохранённое значение не меняется:
     * следующий discover() не создаёт шардов по ошибочному значению.
     */
    public function testSettingZeroThrowsInvalidArgumentExceptionAndKeepsSavedValue(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        try {
            $reg->setMinShards(0);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
    }

    /**
     * Установка отрицательного значения → InvalidArgumentException;
     * сохранённое значение не меняется.
     */
    public function testSettingNegativeThrowsInvalidArgumentExceptionAndKeepsSavedValue(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        try {
            $reg->setMinShards(-1);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
            $ipdo->close();
            self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        }

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(5, $discovered->getCountShards());
    }

    /**
     * Передача не-int (1.0, '3', true, null) при strict_types=1 → TypeError;
     * сохранённое значение не меняется.
     */
    public function testNonIntArgumentThrowsTypeErrorAndKeepsSavedValue(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        foreach ([1.0, '3', true, null] as $bad) {
            try {
                $reg->setMinShards($bad);
                self::fail('Expected TypeError for ' . \var_export($bad, true));
            } catch (\TypeError $e) {
                $ipdo = new IPDOSQLite($cfg->ptfRegistry);
                $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
                $ipdo->close();
                self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
            }
        }
    }

    /**
     * Установка 100 сама по себе не создаёт шардов; только последующий discover()
     * создаёт недостающие 100 − текущее_количество шардов.
     */
    public function testSettingOneHundredCreatesShardsOnlyOnDiscover(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->setMinShards(100);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(100, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->getCountShards());
        self::assertCount(5, $this->shardFiles());

        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(100, $discovered->getCountShards());
        self::assertCount(100, $this->shardFiles());
    }

    /**
     * Метод не меняет name, rent_timeout, состояние аренды существующих шардов
     * и не удаляет файлы.
     */
    public function testMethodDoesNotChangeNameRentTimeoutOrShardState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);
        $free = $reg->getFreeShards()[0];
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');

        $reg->setMinShards(7);

        self::assertSame('test', $reg->getName());
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(30, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertFalse($reg->isFree($busy));
        self::assertTrue($reg->rentValid($busy));
        self::assertTrue($reg->isFree($free));
        self::assertSame($filesBefore, \glob($this->tmpDir . '/*.sqlite'));
    }

    /**
     * createNewShards() не зависит от min_shards: создаёт ровно запрошенное
     * количество, даже если оно больше или меньше сохранённого минимума
     * (при min_shards=5: createNewShards(2) → 7, createNewShards(10) → 17).
     */
    public function testCreateNewShardsDoesNotDependOnMinShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $reg->createNewShards(2);
        self::assertSame(7, $reg->getCountShards());

        $reg->createNewShards(10);
        self::assertSame(17, $reg->getCountShards());

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(5, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);

        // discover не трогает: 17 уже не меньше минимума
        $discovered = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(17, $discovered->getCountShards());
    }

    /**
     * Цепочка setMinShards(2)->setRentTimeout(60)->setMinSecHeartbeat(5)
     * возвращает тот же объект Registry.
     */
    public function testChainWithOtherSettersReturnsSameObject(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        $result = $reg
            ->setMinShards(2)
            ->setRentTimeout(60)
            ->setMinSecHeartbeat(5);

        self::assertSame($reg, $result);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'min_shards'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(2, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        $row = $ipdo->exec('SELECT v FROM kv WHERE k = {k}', ['k' => 'rent_timeout'], IPDOSQLite::FETCH_ONCE_NUM);
        $ipdo->close();
        self::assertSame(60, \json_decode((string)($row[0] ?? ''), true)['value'] ?? null);
        self::assertSame(5, $reg->minSecHeartbeat);
    }
}
