<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::getIPDOByShard()}
 *
 *  «getIPDOByShard»
 */
final class GetIPDOByShardTest extends TestCase
{
    // Для существующего шарда возвращает объект IPDOSQLite.
    public function testReturnsIPDOSQLiteInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $ipdo = $reg->getIPDOByShard($reg->getAllShards()[0]);

        self::assertInstanceOf(IPDOSQLite::class, $ipdo);
    }

    // getMainFile() совпадает с путём файла шарда.
    public function testMainFileMatchesShardPath(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $ipdo = $reg->getIPDOByShard($shard);

        self::assertSame(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)), Path::normalize($ipdo->getMainFile()));
    }

    // Ленивое соединение: isConnected() false сразу, true после запроса.
    public function testLazyConnection(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $ipdo = $reg->getIPDOByShard($reg->getAllShards()[0]);

        self::assertFalse($ipdo->isConnected());

        $ipdo->exec('SELECT 1');

        self::assertTrue($ipdo->isConnected());
    }

    // В возвращённом SQLite доступна схема items; INSERT работает.
    public function testSchemaIsUsable(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $ipdo = $reg->getIPDOByShard($reg->getAllShards()[0]);

        try {
            self::assertTrue($ipdo->existsTable('items'));
            $row = $ipdo->exec('SELECT COUNT(*) FROM items', [], IPDOSQLite::FETCH_ONCE_NUM);
            self::assertSame(0, (int)$row[0]);
            $ipdo->exec("INSERT INTO items (name) VALUES ('x')", [], IPDOSQLite::FETCH_VOID);
        } finally {
            $ipdo->close();
        }
    }

    // Запись через один объект видна через второй (тот же файл).
    public function testWritesVisibleAcrossInstances(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $ipdo1 = $reg->getIPDOByShard($shard);
        try {
            $ipdo1->exec("INSERT INTO items (name) VALUES ('x')", [], IPDOSQLite::FETCH_VOID);
        } finally {
            $ipdo1->close();
        }

        $ipdo2 = $reg->getIPDOByShard($shard);
        try {
            $row = $ipdo2->exec('SELECT COUNT(*) FROM items', [], IPDOSQLite::FETCH_ONCE_NUM);
            self::assertSame(1, (int)$row[0]);
        } finally {
            $ipdo2->close();
        }
    }

    // Два вызова возвращают разные объекты, работающие с одним файлом.
    public function testEachCallReturnsNewInstance(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $a = $reg->getIPDOByShard($shard);
        $b = $reg->getIPDOByShard($shard);

        self::assertNotSame($a, $b);
        self::assertSame($a->getMainFile(), $b->getMainFile());
    }

    // Метод не требует аренды: работает для любых состояний шарда.
    public function testWorksInAnyRentState(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $all = $reg->getAllShards();

        $reg->rent($all[0]);                                  // занят
        $reg->rent($all[1]);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry); // просрочен
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $all[1]->id]);
        } finally {
            $ipdo->close();
        }
        $reg->rent($all[2]);
        $reg->release($all[2]);                               // освобождён

        foreach ($all as $shard) {
            $ipdo = $reg->getIPDOByShard($shard);
            try {
                self::assertTrue($ipdo->existsTable('items'));
            } finally {
                $ipdo->close();
            }
        }
    }

    // Несуществующий шард → ShardNotFoundException, даже если файл есть на диске.
    public function testNonexistentShardThrowsEvenIfFileExists(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $id = 'no-such-row';
        \file_put_contents($this->tmpDir . '/' . $id . '.sqlite', '');

        $this->expectException(ShardNotFoundException::class);
        $reg->getIPDOByShard(new \Inilim\SqliteShard\Shard($id, $reg));
    }

    // Запись есть, файл удалён: объект возвращается, первая операция бросает
    // IPDOException; сам вызов файл не пересоздаёт.
    public function testMissingFileThrowsOnFirstOperation(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $ptf = Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id));
        self::assertFileExists($ptf);
        \unlink($ptf);

        $ipdo = $reg->getIPDOByShard($shard);

        try {
            $ipdo->exec('SELECT 1');
            self::fail('IPDOException expected');
        } catch (IPDOException) {
            self::assertFileDoesNotExist($ptf);
        }
    }

    // Файл есть, но не является SQLite: объект возвращается, первый запрос
    // к содержимому бросает IPDOException.
    public function testCorruptFileThrowsOnQuery(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        \file_put_contents(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)), \str_repeat('x', 200));

        $ipdo = $reg->getIPDOByShard($shard);

        $this->expectException(IPDOException::class);
        $ipdo->exec("SELECT name FROM sqlite_master LIMIT 1", [], IPDOSQLite::FETCH_ONCE_NUM);
    }

    // Пустая схема ([]): объект корректен, пользовательских таблиц нет.
    public function testEmptySchemaProducesValidSQLite(): void
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
            $row = $ipdo->exec('SELECT COUNT(*) FROM sqlite_master', [], IPDOSQLite::FETCH_ONCE_NUM);
            self::assertIsArray($row);
        } finally {
            $ipdo->close();
        }
    }

    // close() одного объекта не влияет на другие объекты того же шарда.
    public function testCloseDoesNotAffectOtherInstances(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $a = $reg->getIPDOByShard($shard);
        $b = $reg->getIPDOByShard($shard);
        $a->close();

        $b->exec('SELECT 1');
        self::assertTrue($b->isConnected());
    }

    // Метод не меняет состояние аренды и не создаёт файлы.
    public function testDoesNotChangeStateOrCreateFiles(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $busy = $reg->tryRentFree();
        self::assertNotNull($busy);
        $filesBefore = \glob($this->tmpDir . '/*.sqlite');
        \sort($filesBefore);

        $ipdo = $reg->getIPDOByShard($busy);
        $ipdo->close();

        self::assertTrue($reg->rentValid($busy));
        $filesAfter = \glob($this->tmpDir . '/*.sqlite');
        \sort($filesAfter);
        self::assertSame($filesBefore, $filesAfter);
    }

    // Изменения сохраняются на диске: видны при повторном открытии.
    public function testChangesPersistToDisk(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $ipdo = $reg->getIPDOByShard($shard);
        try {
            $ipdo->exec("INSERT INTO items (name) VALUES ('persist')", [], IPDOSQLite::FETCH_VOID);
        } finally {
            $ipdo->close();
        }

        $again = $reg->getIPDOByShard($shard);
        try {
            $row = $again->exec("SELECT COUNT(*) FROM items WHERE name = 'persist'", [], IPDOSQLite::FETCH_ONCE_NUM);
            self::assertSame(1, (int)$row[0]);
        } finally {
            $again->close();
        }
    }
}
