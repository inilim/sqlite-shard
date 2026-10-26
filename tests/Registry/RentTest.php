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
use Inilim\SqliteShard\ShardNotFreeException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::rent()}
 *
 *  «rent»
 */
final class RentTest extends TestCase
{
    // Аренда свободного шарда: возвращается тот же объект Registry (assertSame);
    // после вызова isFree===false, rentValid===true, leftTimeUntilRelease ≈
    // rent_timeout (assertBetween(28,30) при 30), шард исчезает из getFreeShards.
    public function testRentOnFreeShardReturnsSameRegistryAndBusiesShard(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $result = $reg->rent($shard);

        self::assertSame($reg, $result);
        self::assertFalse($reg->isFree($shard));
        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
        self::assertNotContains($shard->id, \array_map(static fn(Shard $s): string => $s->id, $reg->getFreeShards()));
    }

    // Неактивный шард (active=0) с updated_at в будущем свободен, поэтому аренда
    // успешна: rent → release → setUpdatedAt(now()+3600) → снова rent без исключения.
    public function testRentOnInactiveShardWithFutureUpdatedAtSucceeds(): void
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

        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Просроченный активный шард (updated_at=ago(40) при таймауте 30) свободен:
    // повторный rent успешен и продлевает аренду — leftTime снова ~30.
    public function testRentOnExpiredActiveShardSucceedsAndRenewsLease(): void
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

        // до повторной аренды шард просрочен: leftTime на зажиме равен 0
        self::assertSame(0, $reg->leftTimeUntilRelease($shard));

        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Активный шард с updated_at ровно time()-rent_timeout свободен (граница <=):
    // rent на такой записи успешен и обновляет таймер аренды до ~30.
    public function testRentOnActiveShardOnTimeoutBoundarySucceeds(): void
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

        // на границе шард ещё свободен (updated_at == time() - rent_timeout)
        self::assertTrue($reg->isFree($shard));

        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($shard));
    }

    // Свежий активный шард арендовать нельзя: rent выбрасывает
    // ShardNotFreeException; активность и updated_at не меняются — rentValid
    // остаётся true, leftTime прежний.
    public function testRentOnFreshActiveShardThrowsShardNotFreeException(): void
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
        $leftBefore = $reg->leftTimeUntilRelease($shard);

        try {
            $reg->rent($shard);
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException) {
        }

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual($leftBefore - 2, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual($leftBefore, $reg->leftTimeUntilRelease($shard));
    }

    // Несуществующий шард: rent бросает ShardNotFoundException, а не
    // ShardNotFreeException.
    public function testRentOnNonexistentShardThrowsShardNotFoundException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));

        $this->expectException(ShardNotFoundException::class);
        $reg->rent(new Shard('no-such-id', $reg));
    }

    // После release тот же шард снова арендуется: rent → release → rent успешен,
    // возвращается тот же объект Registry.
    public function testRentAfterReleaseSucceeds(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $reg->release($shard);
        $result = $reg->rent($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->rentValid($shard));
    }

    // Повторный rent уже арендованного (свежего) шарда выбрасывает
    // ShardNotFreeException.
    public function testRepeatedRentOfBusyShardThrowsShardNotFreeException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);

        $this->expectException(ShardNotFreeException::class);
        $reg->rent($shard);
    }

    // Два разных свободных шарда арендуются независимо: аренда одного не делает
    // занятым другой — тот остаётся свободным и его можно арендовать.
    public function testRentOfOneFreeShardDoesNotBusyAnother(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        self::assertGreaterThanOrEqual(2, \count($shards));
        [$a, $b] = $shards;

        $reg->rent($a);

        self::assertFalse($reg->rentValid($b));
        self::assertTrue($reg->isFree($b));

        $reg->rent($b);
        self::assertTrue($reg->rentValid($b));
    }

    // После истечения аренды (rent_timeout=1, updated_at=ago(2)) повторный rent
    // успешен и обновляет updated_at: leftTime снова ≈ 1.
    public function testRentAfterLeaseExpirationSucceedsAndRefreshesTimestamp(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            minShards: 1,
            rentTimeout: 1,
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 2, 'id' => $shard->id]);
        } finally {
            $ipdo->close();
        }

        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(0, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(1, $reg->leftTimeUntilRelease($shard));
    }

    // Гонка двух воркеров (настоящий параллелизм через inilim/parallel, старт
    // выравнен по стартовой миллисекунде): два потока одновременно вызывают
    // rent одного свободного шарда. Ожидается: ровно один вызов успешен, второй
    // выбрасывает ShardNotFreeException; итоговое состояние — шард занят
    // (isFree false, leftTime > 0 через regA; rentValid для объекта без токена
    // даёт false — аренду держит объект-победитель в своём потоке).
    public function testRentRaceSecondRegistryInstanceLoses(): void
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
                $reg->rent(new Shard($ctx['shardId'], $reg));
                return ['ok' => true, 'error' => null];
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

        $winners = 0;
        $errors = [];
        foreach ($results as $r) {
            if (true === $r['ok']) {
                $winners++;
            } else {
                $errors[] = $r['error'];
            }
        }
        self::assertSame(1, $winners, 'ровно один rent должен выиграть: ' . \json_encode($results, \JSON_UNESCAPED_UNICODE));
        self::assertSame([ShardNotFreeException::class], $errors, 'проигравший обязан получить ShardNotFreeException');

        $shard = new Shard($shardId, $regA);
        // у объекта-наблюдателя нет токена: rentValid даёт false, но занятость
        // и остаток аренды видны
        self::assertFalse($regA->rentValid($shard));
        self::assertFalse($regA->isFree($shard));
        self::assertGreaterThan(0, $regA->leftTimeUntilRelease($shard));
    }

    // Отсутствие файла шарда не мешает аренде: после unlink rent свободного
    // шарда успешен, и метод не создаёт файл заново (файла после rent нет).
    public function testRentSucceedsWhenShardFileIsMissing(): void
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

        $reg->rent($shard);

        self::assertTrue($reg->rentValid($shard));
        self::assertFileDoesNotExist($ptf);
    }

    // rent не меняет количество шардов в реестре.
    public function testRentDoesNotChangeShardCount(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $countBefore = $reg->getCountShards();
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);

        self::assertSame($countBefore, $reg->getCountShards());
    }

    // Сообщение ShardNotFreeException содержит id арендуемого шарда
    // (expectExceptionMessage именно на id).
    public function testShardNotFreeExceptionMessageContainsShardId(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];
        $reg->rent($shard);

        $this->expectException(ShardNotFreeException::class);
        $this->expectExceptionMessage($shard->id);
        $reg->rent($shard);
    }

    // Сообщение ShardNotFoundException содержит id отсутствующего шарда.
    public function testShardNotFoundExceptionMessageContainsShardId(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $id = 'no-such-id';

        $this->expectException(ShardNotFoundException::class);
        $this->expectExceptionMessage($id);
        $reg->rent(new Shard($id, $reg));
    }

    // Изменение rent_timeout меняет, какие шарды можно арендовать: активный
    // с ago(20) при таймауте 30 занят (ShardNotFreeException), после
    // setRentTimeout(10) тот же шард становится доступным.
    public function testChangingRentTimeoutChangesRentability(): void
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

        try {
            $reg->rent($shard);
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException) {
        }

        $reg->setRentTimeout(10);

        $reg->rent($shard);
        self::assertTrue($reg->rentValid($shard));
        self::assertGreaterThanOrEqual(8, $reg->leftTimeUntilRelease($shard));
        self::assertLessThanOrEqual(10, $reg->leftTimeUntilRelease($shard));
    }

    // Аренда одного шарда не меняет состояние и updated_at других: у занятого b
    // leftTime прежний (~30), свободный c остаётся свободным.
    public function testRentOfOneShardLeavesOtherShardsUntouched(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shards = $reg->getAllShards();
        self::assertGreaterThanOrEqual(3, \count($shards));
        [$a, $b, $c] = $shards;

        $reg->rent($b);
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($b));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($b));
        self::assertTrue($reg->isFree($c));

        $reg->rent($a);

        // b: аренда и таймер не затронуты
        self::assertGreaterThanOrEqual(28, $reg->leftTimeUntilRelease($b));
        self::assertLessThanOrEqual(30, $reg->leftTimeUntilRelease($b));
        self::assertTrue($reg->rentValid($b));
        self::assertFalse($reg->isFree($b));
        // c: остался свободным
        self::assertTrue($reg->isFree($c));
        self::assertFalse($reg->rentValid($c));
    }

    // Метод возвращает $this, поэтому поддерживает цепочку:
    // $reg->rent($shard)->heartbeat($shard) работает и возвращает Registry.
    public function testRentReturnsRegistryForMethodChaining(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $result = $reg->rent($shard)->heartbeat($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->rentValid($shard));
    }
}
