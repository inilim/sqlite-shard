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
 * {@see Registry::getFreeShards()}
 *
 *  «getFreeShards»
 */
final class GetFreeShardsTest extends TestCase
{
    // Сразу после create() все шарды свободны и возвращаются все (5).
    public function testAllShardsAreFreeAfterCreate(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));

        self::assertCount(5, $reg->getFreeShards());
    }

    // Если все шарды свежие и активные (rent), возвращается пустой массив.
    public function testEmptyWhenAllShardsFreshAndActive(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
            rentTimeout: 3600,
        )));
        foreach ($reg->getAllShards() as $shard) {
            $reg->rent($shard);
        }

        self::assertSame([], $reg->getFreeShards());
    }

    // Смешанный набор: свежие активные исключены; неактивные и просроченные
    // включены.
    public function testMixedSet(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 300,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $reg->rent($all[0]); // свежий активный — исключается
        $reg->rent($all[1]); // свежий активный — исключается
        $reg->rent($all[3]); // активный, но просрочим — включается

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 400, 'id' => $all[3]->id]);
        } finally {
            $ipdo->close();
        }

        $free = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());

        self::assertCount(3, $free);
        self::assertNotContains($all[0]->id, $free);
        self::assertNotContains($all[1]->id, $free);
        self::assertContains($all[2]->id, $free);
        self::assertContains($all[3]->id, $free);
        self::assertContains($all[4]->id, $free);
    }

    // Активный шард с updated_at == time()-rent_timeout включается (граница <=).
    public function testIncludesActiveShardOnTimeoutBoundary(): void
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

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Активный шард с updated_at == now() исключается.
    public function testExcludesFreshActiveShard(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 3600,
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

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Неактивный шард с updated_at в будущем включается (active=0 важнее времени).
    public function testIncludesInactiveShardWithFutureUpdatedAt(): void
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

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Освобождённый шард включается.
    public function testIncludesReleasedShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);
        $reg->release($shard);

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Порядок — по возрастанию updated_at: из трёх свободных с ago(50), ago(40),
    // ago(30) первый — самый старый (ago(50)); два других шарда арендованы.
    public function testOrderedByUpdatedAtAscending(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 300,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        // два шарда делаем занятыми (свежие)
        $reg->rent($all[3]);
        $reg->rent($all[4]);
        // трём свободным выставляем старое время
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 50, 'id' => $all[0]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 30, 'id' => $all[2]->id]);
        } finally {
            $ipdo->close();
        }

        $ids = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());

        self::assertSame([$all[0]->id, $all[1]->id, $all[2]->id], $ids);
    }

    // Все элементы — Shard; reg === текущий Registry; ключи последовательные.
    public function testElementsAreShardsOfCurrentRegistry(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 5,
        )));
        $free = $reg->getFreeShards();

        foreach ($free as $key => $shard) {
            self::assertInstanceOf(Shard::class, $shard);
            self::assertSame($reg, $shard->reg);
            self::assertSame($key, \array_search($shard, $free, true));
        }
        self::assertSame(\range(0, 4), \array_keys($free));
    }

    // Пустой реестр → пустой массив.
    public function testEmptyRegistryReturnsEmptyArray(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        foreach ($reg->getAllShards() as $shard) {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
            } finally {
                $ipdo->close();
            }
        }

        self::assertSame([], $reg->getFreeShards());
    }

    // Метод не меняет состояние: не арендует, не освобождает и не обновляет
    // записи в таблицах shards и kv.
    public function testDoesNotChangeState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $reg->rent($reg->getAllShards()[0]);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $beforeShards = $ipdo->exec(
                'SELECT id, schema_version, active, created_at, updated_at, owner_token FROM shards ORDER BY id',
                [],
                IPDO::FETCH_ALL_NUM
            );
            $beforeKv = $ipdo->exec('SELECT k, v FROM kv ORDER BY k', [], IPDO::FETCH_ALL_NUM);

            $reg->getFreeShards();

            $afterShards = $ipdo->exec(
                'SELECT id, schema_version, active, created_at, updated_at, owner_token FROM shards ORDER BY id',
                [],
                IPDO::FETCH_ALL_NUM
            );
            $afterKv = $ipdo->exec('SELECT k, v FROM kv ORDER BY k', [], IPDO::FETCH_ALL_NUM);

            self::assertSame($beforeShards, $afterShards);
            self::assertSame($beforeKv, $afterKv);
        } finally {
            $ipdo->close();
        }
    }

    // Изменение rent_timeout сразу меняет состав.
    public function testChangingRentTimeoutChangesResult(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 120,
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

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->setRentTimeout(10);

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->setRentTimeout(120);

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Жизненный цикл: heartbeat/rent исключают шард, release возвращает его.
    public function testHeartbeatRentReleaseCycle(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 3600,
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->heartbeat($shard);
        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->release($shard);
        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Отсутствие файла шарда не влияет на включение в результат.
    public function testMissingFileDoesNotAffectResult(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // При rent_timeout=PHP_INT_MAX активные не попадают, неактивные попадают.
    public function testHugeRentTimeout(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: PHP_INT_MAX,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
        $reg->release($shard);
        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Просроченный активный включается в результат, но до вызова метода он
    // остаётся активным (rentValid false после истечения).
    public function testExpiredActiveShardIsIncludedButStaysActive(): void
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
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertTrue($reg->isFree($shard));
        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $row = $ipdo->exec(
                'SELECT active, owner_token FROM shards WHERE id = {id}',
                ['id' => $shard->id],
                IPDO::FETCH_ONCE_NUM
            );
            self::assertSame(1, (int)$row[0]);
            self::assertNotNull($row[1]);
        } finally {
            $ipdo->close();
        }

        self::assertFalse($reg->rentValid($shard));
    }
}