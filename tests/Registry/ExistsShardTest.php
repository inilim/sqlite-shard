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
 * {@see Registry::existsShard()}
 *
 *  «existsShard»
 */
final class ExistsShardTest extends TestCase
{
    // Существующий свободный шард → true.
    public function testReturnsTrueForExistingFreeShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertTrue($reg->existsShard($reg->getAllShards()[0]));
    }

    // Занятый шард (rent) → true.
    public function testReturnsTrueForBusyShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        self::assertTrue($reg->existsShard($shard));
    }

    // Просроченный и освобождённый шарды → true.
    public function testReturnsTrueForExpiredAndReleasedShards(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shards = $reg->getAllShards();

        $reg->rent($shards[0]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $shards[0]->id]);
        } finally {
            $ipdo->close();
        }

        $reg->rent($shards[1]);
        $reg->release($shards[1]);

        self::assertTrue($reg->existsShard($shards[0]));
        self::assertTrue($reg->existsShard($shards[1]));
    }

    // Shard с несуществующим id → false без исключения.
    public function testReturnsFalseForNonexistentId(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertFalse($reg->existsShard(new Shard('no-such-id', $reg)));
    }

    // Объект Shard с существующим id, но reg другого экземпляра: метод
    // проверяет только id в том реестре, у которого вызван.
    public function testResultDoesNotDependOnShardRegistryReference(): void
    {
        $regA = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $regB = Registry::discover($this->tmpDir, 'test');

        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $id = $regB->getAllShards()[0]->id;

        self::assertTrue($regB->existsShard(new Shard($id, $regA)));
    }

    // Shard с пустым id → false.
    public function testReturnsFalseForEmptyId(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertFalse($reg->existsShard(new Shard('', $reg)));
    }

    // Запись удалена, файл остался → false (проверяется только запись реестра).
    public function testReturnsFalseAfterRowDeletedEvenIfFileRemains(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM shards WHERE id = {id}', ['id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        self::assertFalse($reg->existsShard($shard));
        self::assertFileExists(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));
    }

    // Файл удалён, запись осталась → true.
    public function testReturnsTrueAfterFileDeleted(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertTrue($reg->existsShard($shard));
    }

    // Метод не меняет состояние аренды и не переживает исключений.
    public function testDoesNotChangeShardState(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);
        $leftBefore = $reg->leftTimeUntilRelease($shard);

        self::assertTrue($reg->existsShard($shard));
        self::assertSame($leftBefore, $reg->leftTimeUntilRelease($shard));
        self::assertTrue($reg->rentValid($shard));
    }
}
