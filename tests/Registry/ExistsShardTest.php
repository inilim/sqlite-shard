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

    // Метод не меняет состояние аренды и не обращается к файлу шарда.
    public function testDoesNotChangeShardState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->tryRentFree();
        self::assertNotNull($shard);

        // Снимок строки shards: активность / updated_at / owner_token не
        // должны сдвинуться после вызова existsShard.
        $snapshotBefore = $this->readShardRow($cfg->ptfRegistry, $shard->id);
        self::assertNotSame([], $snapshotBefore, 'Шард должен присутствовать в реестре');

        self::assertTrue($reg->existsShard($shard));

        $snapshotAfter = $this->readShardRow($cfg->ptfRegistry, $shard->id);
        self::assertSame($snapshotBefore, $snapshotAfter, 'existsShard не должен менять строку shards');
        self::assertTrue($reg->rentValid($shard));
    }

    // Повторные вызовы идемпотентны: не меняют строку shards и дают тот же
    // результат независимо от количества вызовов.
    public function testRepeatedCallsAreIdempotent(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $snapshotBefore = $this->readShardRow($cfg->ptfRegistry, $shard->id);
        self::assertNotSame([], $snapshotBefore, 'Шард должен присутствовать в реестре');

        self::assertTrue($reg->existsShard($shard));
        self::assertTrue($reg->existsShard($shard));
        self::assertTrue($reg->existsShard($shard));

        self::assertSame($snapshotBefore, $this->readShardRow($cfg->ptfRegistry, $shard->id));
    }

    // id с SQL-инъекцией не даёт ложного совпадения: параметры биндятся,
    // а не подставляются в текст запроса.
    public function testReturnsFalseForSqlInjectionLikeId(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        foreach (
            [
                "' OR '1'='1",
                "1' OR 1=1 --",
                '" OR 1=1 --',
                "' UNION SELECT id FROM shards --",
            ] as $id
        ) {
            self::assertFalse(
                $reg->existsShard(new Shard($id, $reg)),
                \sprintf('id "%s" не должен давать true', $id),
            );
        }
    }

    /**
     * Снимок строки таблицы shards для проверки «состояние не изменилось».
     * Открывает отдельное соединение, чтобы не задеть основное соединение
     * реестра, и закрывает его в finally.
     *
     * @return array<int,mixed>
     */
    private function readShardRow(string $ptfRegistry, string $id): array
    {
        $ipdo = new IPDOSQLite($ptfRegistry);
        try {
            $row = $ipdo->exec(
                'SELECT active, updated_at, owner_token, schema_version FROM shards WHERE id = {id}',
                ['id' => $id],
                IPDO::FETCH_ONCE_NUM,
            );
            return \is_array($row) ? $row : [];
        } finally {
            $ipdo->close();
        }
    }
}