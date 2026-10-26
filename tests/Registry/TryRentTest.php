<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\Parallel\Flow;
use Inilim\Parallel\Task;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::tryRent()}
 *
 *  «tryRent»
 */
final class TryRentTest extends TestCase
{
    // Свободный шард: tryRent возвращает true, и шард реально арендован
    // (isFree=false, rentValid=true).
    public function testTryRentOnFreeShardReturnsTrueAndRents(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        self::assertTrue($reg->tryRent($shard));
        self::assertFalse($reg->isFree($shard));
        self::assertTrue($reg->rentValid($shard));
    }

    // Свежий активный шард: tryRent возвращает false; состояние не меняется
    // (rentValid остаётся true, leftTime прежний).
    public function testTryRentOnFreshActiveShardReturnsFalseAndKeepsState(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $leftBefore = $reg->leftTimeUntilRelease($shard);

        self::assertFalse($reg->tryRent($shard));
        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual($leftBefore - 2, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual($leftBefore, $reg->leftTimeUntilRelease($shard));
    }

    // Просроченный активный шард (updated_at=ago(40) при таймауте 30): tryRent
    // возвращает true и продлевает аренду — leftTime снова ~30.
    public function testTryRentOnExpiredActiveShardReturnsTrueAndRenews(): void
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

        self::assertTrue($reg->tryRent($shard));
        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Освобождённый шард: tryRent возвращает true и арендует его.
    public function testTryRentOnReleasedShardReturnsTrue(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $reg->release($shard);

        self::assertTrue($reg->tryRent($shard));
        self::assertTrue($reg->rentValid($shard));
    }

    // Несуществующий шард: tryRent НЕ превращает отсутствие шарда в false —
    // ShardNotFoundException пробрасывается наружу.
    public function testTryRentOnNonexistentShardThrowsShardNotFoundException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->tryRent(new Shard('no-such-id', $reg));
    }

    // После успешного вызова повторный вызов возвращает false (шард уже занят).
    public function testSecondTryRentAfterSuccessReturnsFalse(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        self::assertTrue($reg->tryRent($shard));
        self::assertFalse($reg->tryRent($shard));
    }

    // Гонка двух воркеров (настоящий параллелизм): два потока одновременно
    // вызывают tryRent одного свободного шарда. Ожидается: один вызов возвращает
    // true, второй — false (внутри проигравшего ShardNotFreeException ловится и
    // превращается в false); состояние шарда соответствует одному арендатору.
    public function testTryRentRaceSecondInstanceReturnsFalse(): void
    {
        if (!\extension_loaded('parallel')) {
            self::markTestSkipped('Для настоящей параллельной гонки нужен ext-parallel (inilim/parallel).');
        }

        $regA = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shardId = $regA->getAllShards()[0]->id;

        // стартовая миллисекунда с запасом в несколько секунд: воркеры успевают
        // прогрузиться (require autoload, подъём потока) до наступления старта
        $startMs = (int)\floor(\microtime(true) * 1000) + 3000;

        $ctx = [
            'autoload' => \dirname(__DIR__, 2) . '/vendor/autoload.php',
            'baseDir'  => $this->tmpDir,
            'shardId'  => $shardId,
        ];

        $worker = static function (array $ctx, int $startMs): array {
            try {
                require $ctx['autoload'];

                // ждём наступления стартовой миллисекунды; итерация ожидания — 1 мс
                while ((int)\floor(\microtime(true) * 1000) < $startMs) {
                    \usleep(1000);
                }
                $reg = Registry::discover($ctx['baseDir'], 'test');
                $ok = $reg->tryRent(new Shard($ctx['shardId'], $reg));
                return ['ok' => $ok, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => \get_class($e)];
            }
        };

        /** @var Flow[] $flows */
        $flows = [];
        /** @var Task[] $tasks */
        $tasks = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $flow = new Flow; // один Runtime = один поток
                $flows[] = $flow;
                $tasks[] = $flow->execAndGetTask($worker, $ctx, $startMs);
            }

            $results = [];
            foreach ($tasks as $task) {
                $task->waitNative();
                $results[] = $task->value();
            }
        } finally {
            // закрытие Runtime: close() ожидает выполнения всех задач
            unset($tasks, $flows);
        }

        $wins = 0;
        $errors = [];
        foreach ($results as $r) {
            if (true === $r['ok']) {
                $wins++;
            } else {
                $errors[] = $r['error'];
            }
        }
        self::assertSame(1, $wins, 'ровно один tryRent должен выиграть: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));
        self::assertSame([null], $errors, 'проигравший возвращает false без исключений: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));
    }

    // В отличие от rent, метод не выбрасывает ShardNotFreeException: для занятого
    // шарда возвращается false, а не исключение; другие исключения (кейс 5 —
    // ShardNotFoundException) не перехватываются.
    public function testTryRentReturnsFalseForBusyShardInsteadOfException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);

        self::assertFalse($reg->tryRent($shard));
    }

    // Возвращаемое значение — именно bool (assertIsBool).
    public function testTryRentReturnsBool(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        self::assertIsBool($reg->tryRent($shard));
    }

    // Отсутствие файла шарда не мешает успешной аренде: unlink файла → tryRent true.
    public function testTryRentSucceedsWhenShardFileIsMissing(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        \unlink(Path::normalize(\sprintf('%s/%s.sqlite', $reg->baseDir, $shard->id)));

        self::assertTrue($reg->tryRent($shard));
        self::assertTrue($reg->rentValid($shard));
    }

    // Изменение rent_timeout меняет результат для того же шарда: активный
    // с ago(20) при таймауте 30 → false; после setRentTimeout(10) → true.
    public function testChangingRentTimeoutChangesTryRentResult(): void
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

        self::assertFalse($reg->tryRent($shard));

        $reg->setRentTimeout(10);

        self::assertTrue($reg->tryRent($shard));
        self::assertTrue($reg->rentValid($shard));
    }
}
