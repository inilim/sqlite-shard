<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::ptf()}
 *
 * «ptf»
 */
final class PtfTest extends TestCase
{
    private const SCHEMA_SQL = [
        'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
    ];

    private function createConfig(
        ?string $baseDir = null,
        string $name = 'test',
        int $minShards = 1,
        int $rentTimeout = 30,
    ): CreateConfig {
        return new CreateConfig(
            baseDir: $baseDir ?? $this->tmpDir,
            schemaSql: self::SCHEMA_SQL,
            name: $name,
            minShards: $minShards,
            rentTimeout: $rentTimeout,
        );
    }

    private function expectedPath(CreateConfig $cfg, Shard $shard): string
    {
        return Path::normalize(\sprintf('%s/%s.sqlite', $cfg->baseDir, $shard->id));
    }

    // Для существующего шарда возвращает нормализованный путь
    // <baseDir>/<id>.sqlite.
    public function testReturnsExpectedPathForExistingShard(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $path = $reg->ptf($shard);

        self::assertSame($this->expectedPath($cfg, $shard), $path);
        self::assertStringEndsWith($shard->id . '.sqlite', $path);
    }

    // Shard::ptf() — обёртка над Registry::ptf() и возвращает тот же путь.
    public function testShardWrapperReturnsSamePath(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        self::assertSame($reg->ptf($shard), $shard->ptf());
    }

    // Путь строится по id: новый объект Shard с тем же id даёт тот же путь.
    public function testNewShardObjectWithSameIdReturnsSamePath(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $pathByObject = $reg->ptf($shard);
        $pathByNewObject = $reg->ptf(new Shard($shard->id, $reg));

        self::assertSame($pathByObject, $pathByNewObject);
    }

    // Метод не требует наличия файла шарда: путь возвращается и после удаления
    // файла, а сам файл заново не создаётся.
    public function testDoesNotRequireShardFileToExist(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $path = $reg->ptf($shard);
        self::assertTrue(\file_exists($path));
        \unlink($path);
        self::assertFalse(\file_exists($path));

        self::assertSame($path, $reg->ptf($shard));
        self::assertFalse(\file_exists($path));
    }

    // Для несуществующего шарда выбрасывает ShardNotFoundException; сообщение
    // содержит id отсутствующего шарда.
    public function testNonexistentShardThrowsShardNotFoundException(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $id = 'no-such-shard';

        $this->expectException(ShardNotFoundException::class);
        $this->expectExceptionMessage($id);
        $reg->ptf(new Shard($id, $reg));
    }

    // Если запись шарда удалена из реестра, ptf() проверяет существование при
    // каждом вызове и выбрасывает ShardNotFoundException.
    public function testThrowsAfterShardRecordRemovedFromRegistry(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $this->expectException(ShardNotFoundException::class);
        $this->expectExceptionMessage($shard->id);
        $reg->ptf($shard);
    }

    // Метод не меняет состояние записи шарда: снимок строки до и после
    // вызовов идентичен, а аренда остаётся действительной.
    public function testDoesNotChangeShardState(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        $snapshot = static function () use ($cfg, $shard): array {
            $ipdo = new IPDOSQLite($cfg->ptfRegistry);
            try {
                return $ipdo->exec(
                    'SELECT id, schema_version, active, created_at, updated_at, owner_token
                    FROM shards WHERE id = {id}',
                    ['id' => $shard->id],
                    IPDO::FETCH_ONCE_NUM,
                );
            } finally {
                $ipdo->close();
            }
        };

        $before = $snapshot();
        $first = $reg->ptf($shard);
        $second = $reg->ptf($shard);
        $after = $snapshot();

        self::assertSame($before, $after);
        self::assertSame($first, $second);
        self::assertTrue($reg->rentValid($shard));
    }

    // Для разных шардов возвращаются разные пути, каждый строится по своему id.
    public function testDifferentShardsReturnUniquePaths(): void
    {
        $cfg = $this->createConfig(minShards: 3);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shards = $reg->getAllShards();
        self::assertCount(3, $shards);

        $paths = [];
        foreach ($shards as $shard) {
            $path = $reg->ptf($shard);
            $paths[] = $path;
            self::assertSame($this->expectedPath($cfg, $shard), $path);
        }

        self::assertCount(3, \array_unique($paths));
    }

    // Путь не зависит от состояния аренды: свободный, арендованный и
    // освобождённый шард дают один и тот же путь.
    public function testPathDoesNotDependOnRentState(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $freePath = $reg->ptf($shard);
        $reg->rent($shard);
        $rentedPath = $reg->ptf($shard);
        $reg->release($shard);
        $releasedPath = $reg->ptf($shard);

        self::assertSame($freePath, $rentedPath);
        self::assertSame($freePath, $releasedPath);
    }

    // Второй экземпляр Registry видит тот же шард и возвращает тот же путь.
    public function testSecondRegistryInstanceReturnsSamePath(): void
    {
        $cfg = $this->createConfig(minShards: 1);
        $regA = $this->registerRegistry(Registry::create($cfg));
        $shardA = $regA->getAllShards()[0];

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);

        $shardB = null;
        foreach ($regB->getAllShards() as $shard) {
            if ($shard->id === $shardA->id) {
                $shardB = $shard;
                break;
            }
        }
        self::assertNotNull($shardB);
        self::assertSame($regA->ptf($shardA), $regB->ptf($shardB));
    }

    // Объект Shard, чей id отсутствует в данном реестре, не принимается:
    // проверка идёт по реестру, а не по объекту.
    public function testShardIdFromAnotherRegistryThrows(): void
    {
        $dirA = $this->tmpDir . '/a';
        $dirB = $this->tmpDir . '/b';
        \mkdir($dirA, 0777, true);
        \mkdir($dirB, 0777, true);

        $cfgA = $this->createConfig(baseDir: $dirA, name: 'a', minShards: 1);
        $regA = $this->registerRegistry(Registry::create($cfgA));
        $shardA = $regA->getAllShards()[0];

        $cfgB = $this->createConfig(baseDir: $dirB, name: 'b', minShards: 1);
        $regB = $this->registerRegistry(Registry::create($cfgB));

        $this->expectException(ShardNotFoundException::class);
        $this->expectExceptionMessage($shardA->id);
        $regB->ptf(new Shard($shardA->id, $regB));
    }
}