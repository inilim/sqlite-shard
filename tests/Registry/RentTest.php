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
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * {@see Registry::rent()}
 *
 *  «rent»
 *
 * Важно про семантику «повторного rent»:
 *
 *   Registry::rent() в самом начале вызывает rentValid():
 *     если текущий объект Shard уже держит действующую аренду (owner_token
 *     совпадает с БД), метод возвращает $this БЕЗ обращения к БД и БЕЗ
 *     исключения — это «защита от дурака» / идемпотентность.
 *   Исключение ShardNotFreeException возникает только когда шард занят
 *   для ДРУГОГО владельца — то есть когда UPDATE ... WHERE owner_token
 *   (в рамках rent) не находит строк. Это моделируется либо свежим
 *   объектом new Shard($id, $reg) (без локального токена), либо вторым
 *   Registry::discover(...).
 *
 * Поэтому тесты «занятого шарда» используют второй объект Shard, а
 * идемпотентность того же объекта проверяется отдельным тестом.
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

    // Свежий активный шард занят для другого владельца: rent, вызванный на
    // СВЕЖЕМ объекте Shard (без локального owner_token), выбрасывает
    // ShardNotFreeException; состояние исходной аренды не меняется —
    // rentValid исходного объекта остаётся true, leftTime прежний.
    public function testRentOnFreshActiveShardByAnotherOwnerThrowsShardNotFreeException(): void
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

        // «другой владелец» — свежий объект Shard без токена
        $other = new Shard($shard->id, $reg);
        try {
            $reg->rent($other);
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

    // Повторный rent ТОГО ЖЕ объекта Shard с действующей арендой идемпотентен:
    // возвращает $this без исключения (защита от дурака), аренда остаётся
    // валидной.
    public function testRepeatedRentOfSameObjectIsIdempotent(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);
        $result = $reg->rent($shard);

        self::assertSame($reg, $result);
        self::assertTrue($reg->rentValid($shard));
        self::assertFalse($reg->isFree($shard));
    }

    // Аренда занятого шарда ДРУГИМ объектом Shard выбрасывает
    // ShardNotFreeException (у свежего объекта нет локального токена, а
    // UPDATE в БД не находит свободных строк).
    public function testRentOfBusyShardByAnotherShardObjectThrowsShardNotFreeException(): void
    {
        $reg = $this->registerRegistry(Registry::create(new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        )));
        $shard = $reg->getAllShards()[0];

        $reg->rent($shard);

        $this->expectException(ShardNotFreeException::class);
        $reg->rent(new Shard($shard->id, $reg));
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
    //
    // ВАЖНО: тест запускается в ОТДЕЛЬНОМ процессе (@runInSeparateProcess).
    //
    // Причина: при прогоне в одном процессе с предыдущими тестами класса
    // (11 тестов × несколько открытых/закрытых PDO-соединений к SQLite,
    // накопленный static state \DI()/Finder, error/exception-handler
    // PHPUnit, возможные opcache/xdebug) создание потока через
    // inilim/parallel / ext-parallel приводило к ACCESS_VIOLATION
    // (exit code -1073741819 / 0xC0000005) — жёсткому падению процесса
    // PHP, а не исключению. В отдельном процессе (standalone script или
    // --process-isolation) тест проходит штатно, что указывает на
    // наследование C-level состояния от предыдущих тестов, а не на баг
    // логики rent().
    //
    // @runInSeparateProcess делает то же самое в рамках PHPUnit: тест идёт
    // в форк-процессе с чистым состоянием — и перестаёт ронять весь набор.
    // Это workaround; если хочется докопаться до корня, см. диагностику в
    // model_message последнего изменения.
    //
    // @preserveGlobalState enabled — чтобы автолоадер и константы были
    // доступны в дочернем процессе.
    //
    // Пропуск на Windows оставлен как отдельный guard: даже в изолированном
    // процессе ext-parallel на Windows нестабилен при teardown Runtime.
    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    public function testRentRaceSecondRegistryInstanceLoses(): void
    {
        if (!\extension_loaded('parallel')) {
            self::markTestSkipped('Для настоящей параллельной гонки нужен ext-parallel (inilim/parallel).');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('ext-parallel на Windows нестабилен (access violation при teardown Flow) — тест пропущен, чтобы не ронять весь набор.');
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

    // Сообщение ShardNotFreeException содержит id арендуемого шарда — бросает
    // rent, вызванный на другом объекте Shard (без локального токена).
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
        $reg->rent(new Shard($shard->id, $reg));
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
    // с ago(20) при таймауте 30 занят для другого владельца (ShardNotFreeException),
    // после setRentTimeout(10) тот же шард становится доступным и арендуется
    // другим объектом Shard.
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

        // «другой владелец» — свежий объект Shard без токена
        $other = new Shard($shard->id, $reg);
        try {
            $reg->rent($other);
            self::fail('ShardNotFreeException expected');
        } catch (ShardNotFreeException) {
        }

        $reg->setRentTimeout(10);

        $reg->rent($other);
        self::assertTrue($reg->rentValid($other));
        self::assertGreaterThanOrEqual(8, $reg->leftTimeUntilRelease($other));
        self::assertLessThanOrEqual(10, $reg->leftTimeUntilRelease($other));
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

    // Чужой/просроченный токен: после перехвата аренды другим экземпляром
    // (старый владелец ранее арендовал шард, БД принудительно состарена, новый
    // экземпляр переарендовал) release от старого владельца не освобождает шард.
    // Новый владелец сохраняет аренду (rentValid true, isFree false).
    public function testReleaseByStaleOwnerDoesNotReleaseHijackedShard(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $shardA = $regA->getAllShards()[0];
        $regA->rent($shardA);

        // принудительно состарим запись, чтобы аренду можно было перехватить
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $shardA->id]);
        } finally {
            $ipdo->close();
        }

        // второй экземпляр перехватывает аренду
        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $shardB = null;
        foreach ($regB->getAllShards() as $s) {
            if ($s->id === $shardA->id) {
                $shardB = $s;
                break;
            }
        }
        self::assertNotNull($shardB);
        $regB->rent($shardB);
        self::assertTrue($regB->rentValid($shardB));

        // старый владелец пытается освободить — owner_token в БД уже другой,
        // UPDATE не должен сработать
        $regA->release($shardA);

        // аренда нового владельца не затронута
        self::assertTrue($regB->rentValid($shardB));
        self::assertFalse($regB->isFree($shardB));
        self::assertGreaterThan(0, $regB->leftTimeUntilRelease($shardB));
    }

    // Чужой токен: после перехвата аренды другим экземпляром heartbeat от
    // старого владельца выбрасывает ShardRentLostException (owner_token не
    // совпадает), а аренда нового владельца остаётся действительной.
    public function testHeartbeatByStaleOwnerAfterHijackThrowsShardRentLostException(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
            rentTimeout: 30,
        );
        $regA = $this->registerRegistry(Registry::create($cfg));
        $shardA = $regA->getAllShards()[0];
        $regA->rent($shardA);

        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('UPDATE shards SET updated_at = {t} WHERE id = {id}', ['t' => \time() - 100, 'id' => $shardA->id]);
        } finally {
            $ipdo->close();
        }

        $regB = Registry::discover($this->tmpDir, 'test');
        self::assertNotNull($regB);
        $this->registerRegistry($regB);
        $shardB = null;
        foreach ($regB->getAllShards() as $s) {
            if ($s->id === $shardA->id) {
                $shardB = $s;
                break;
            }
        }
        self::assertNotNull($shardB);
        $regB->rent($shardB);

        try {
            $regA->heartbeat($shardA);
            self::fail('ShardRentLostException expected');
        } catch (ShardRentLostException) {
        }

        // аренда нового владельца не затронута
        self::assertTrue($regB->rentValid($shardB));
        self::assertFalse($regB->isFree($shardB));
    }
}