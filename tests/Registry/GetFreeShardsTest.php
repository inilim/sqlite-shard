<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

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
        )));
        foreach ($reg->getAllShards() as $shard) {
            $reg->rent($shard);
        }

        self::assertSame([], $reg->getFreeShards());
    }

    // Смешанный набор: свежий активный исключён; неактивные и просроченные
    // включены.
    public function testMixedSet(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $reg->rent($all[0]);                       // свежий активный — исключается
        $reg->rent($all[1]);                       // остаётся свободным? нет — rent сразу
        self::assertTrue($reg->isFree($all[2]));    // свободный
        $reg->rent($all[3]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry); // просроченный
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 40, 'id' => $all[3]->id]);
        } finally {
            $ipdo->close();
        }

        $free = \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards());

        self::assertNotContains($all[0]->id, $free);
        self::assertNotContains($all[1]->id, $free);
        self::assertContains($all[2]->id, $free);
        self::assertContains($all[3]->id, $free);
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
            rentTimeout: 30,
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

    // Метод не меняет состояние: leftTimeUntilRelease и rentValid не меняются.
    public function testDoesNotChangeState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $states = [];
        foreach ($reg->getAllShards() as $shard) {
            $states[$shard->id] = [$reg->leftTimeUntilRelease($shard), $reg->rentValid($shard)];
        }

        $reg->getFreeShards();

        foreach ($states as $id => [$left, $valid]) {
            $shard = null;
            foreach ($reg->getAllShards() as $s) {
                if ($s->id === $id) {
                    $shard = $s;
                    break;
                }
            }
            self::assertNotNull($shard);
            self::assertSame($left, $reg->leftTimeUntilRelease($shard));
            self::assertSame($valid, $reg->rentValid($shard));
        }
    }

    // Изменение rent_timeout сразу меняет состав.
    public function testChangingRentTimeoutChangesResult(): void
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

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->setRentTimeout(10);

        self::assertContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));

        $reg->setRentTimeout(30);

        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Жизненный цикл: heartbeat/rent исключают шард, release возвращает его.
    public function testHeartbeatRentReleaseCycle(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
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
        self::assertFalse($reg->rentValid($shard));
    }
}
