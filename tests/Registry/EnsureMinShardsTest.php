<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardsCreationLockedException;
use Inilim\Test\TestCase;

/**
 * {@see Registry::ensureMinShards()}
 *
 * «ensureMinShards»
 */
final class EnsureMinShardsTest extends TestCase
{
    private function createRegistry(int $minShards = 1): Registry
    {
        return $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: $minShards,
            rentTimeout: 30,
        )));
    }

    /**
     * @param Shard[] $shards
     * @return string[]
     */
    private static function ids(array $shards): array
    {
        return \array_map(static fn(Shard $shard): string => $shard->id, $shards);
    }

    private static function setKv(Registry $reg, string $key, mixed $value): void
    {
        static $setter = null;
        if (null === $setter) {
            $setter = (static function (Registry $reg, string $key, mixed $value): void {
                $reg->kv->set($key, $value);
            })->bindTo(null, Registry::class);
        }
        $setter($reg, $key, $value);
    }

    // Если количество шардов уже не меньше min_shards, метод ничего не
    // создаёт и возвращает тот же экземпляр Registry.
    public function testDoesNothingWhenCountIsAlreadyEnough(): void
    {
        $reg = $this->createRegistry(1);
        $beforeIds = self::ids($reg->getAllShards());

        self::assertSame($reg, $reg->ensureMinShards());
        self::assertSame(1, $reg->getCountShards());
        self::assertSame($beforeIds, self::ids($reg->getAllShards()));
    }

    // Если шардов меньше min_shards, создаётся ровно недостающее количество.
    public function testCreatesExactlyMissingShards(): void
    {
        $reg = $this->createRegistry(1);
        $oldId = $reg->getAllShards()[0]->id;
        $reg->setMinShards(4);

        self::assertSame($reg, $reg->ensureMinShards());

        $ids = self::ids($reg->getAllShards());
        self::assertSame(4, $reg->getCountShards());
        self::assertCount(4, $ids);
        self::assertCount(4, \array_unique($ids));
        self::assertContains($oldId, $ids);
    }

    // Если шардов больше min_shards, метод не удаляет лишние шарды.
    public function testDoesNotDeleteShardsWhenCountExceedsMin(): void
    {
        $reg = $this->createRegistry(2);
        $beforeIds = self::ids($reg->getAllShards());
        $reg->setMinShards(1);

        self::assertSame($reg, $reg->ensureMinShards());

        self::assertSame(2, $reg->getCountShards());
        self::assertSame($beforeIds, self::ids($reg->getAllShards()));
    }

    // Повторный вызов после достижения min_shards ничего не создаёт.
    public function testSecondCallCreatesNothing(): void
    {
        $reg = $this->createRegistry(1);
        $reg->setMinShards(3);
        $reg->ensureMinShards();
        $idsAfterFirst = self::ids($reg->getAllShards());

        self::assertSame($reg, $reg->ensureMinShards());

        self::assertSame(3, $reg->getCountShards());
        self::assertSame($idsAfterFirst, self::ids($reg->getAllShards()));
    }

    // Новые шарды создаются с применённой схемой из registry.
    public function testNewShardsReceiveSchema(): void
    {
        $reg = $this->createRegistry(1);
        $oldId = $reg->getAllShards()[0]->id;
        $reg->setMinShards(2);
        $reg->ensureMinShards();

        $newShard = null;
        foreach ($reg->getAllShards() as $shard) {
            if ($shard->id !== $oldId) {
                $newShard = $shard;
                break;
            }
        }
        self::assertNotNull($newShard);

        $ipdo = $reg->getIPDOByShard($newShard);
        try {
            self::assertTrue($ipdo->existsTable('items'));
        } finally {
            $ipdo->close();
        }
    }

    // При активной блокировке создания шардов метод выбрасывает
    // ShardsCreationLockedException и не создаёт новые шарды.
    public function testThrowsWhenShardsCreationLocked(): void
    {
        $reg = $this->createRegistry(1);
        $reg->setMinShards(2);
        self::setKv($reg, 'shards_creation_lock', \time());

        try {
            $reg->ensureMinShards();
            self::fail('Ожидалось ShardsCreationLockedException');
        } catch (ShardsCreationLockedException) {
        }

        self::assertSame(1, $reg->getCountShards());
    }

    // Устаревшая блокировка создания шардов не мешает созданию.
    public function testIgnoresStaleCreationLock(): void
    {
        $reg = $this->createRegistry(1);
        $reg->setMinShards(2);
        self::setKv($reg, 'shards_creation_lock', \time() - 10);

        self::assertSame($reg, $reg->ensureMinShards());
        self::assertSame(2, $reg->getCountShards());
    }

    // Если создание не требуется, блокировка не проверяется и исключение
    // не выбрасывается.
    public function testDoesNotCheckLockWhenNoCreationNeeded(): void
    {
        $reg = $this->createRegistry(1);
        self::setKv($reg, 'shards_creation_lock', \time());

        self::assertSame($reg, $reg->ensureMinShards());
        self::assertSame(1, $reg->getCountShards());
    }
}