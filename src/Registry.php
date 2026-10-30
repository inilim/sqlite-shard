<?php

declare(strict_types=1);

namespace Inilim\SqliteShard;

use Inilim\IPDO\Exception\IPDOException;
use Inilim\IPDO\IPDO;
use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\HelperService;
use Inilim\SqliteShard\Kv;
use Inilim\SqliteShard\RegistryInvalidException;
use Inilim\SqliteShard\RegistryNameExistsException;
use Inilim\SqliteShard\RegistryVersionMismatchException;
use Inilim\SqliteShard\Shard;
use Inilim\SqliteShard\ShardNotFoundException;
use Inilim\SqliteShard\ShardNotFreeException;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\SqliteShard\ShardsCreationLockedException;
use Inilim\Tool\Assert;
use Inilim\Tool\File;
use Inilim\Tool\FS;
use Inilim\Tool\ID;
use Inilim\Tool\Obj;
use Inilim\Tool\Path;

class Registry
{
    const VERSION = 1;

    protected readonly Kv $kv;
    public readonly string $baseDir;
    protected(set) int $minSecHeartbeat = 10;

    protected static \Closure $setOwnerTokenToShard;

    protected function __construct(
        protected IPDOSQLite $ipdo,
        ?Kv $kv = null,
    ) {
        $this->baseDir = Path::normalize(\dirname($ipdo->getMainFile(), 1));
        $this->kv = null === $kv
            ? \DI(Kv::class, [$ipdo])
            : $kv;
    }

    function setMinSecHeartbeat(int $seconds): self
    {
        Assert::positiveInteger($seconds);
        $this->minSecHeartbeat = $seconds;
        return $this;
    }

    function setRentTimeout(int $rentTimeout): self
    {
        Assert::positiveInteger($rentTimeout);

        $this->kv->setIfChangeValue('rent_timeout', $rentTimeout);
        return $this;
    }

    function setMinShards(int $minShards): self
    {
        Assert::positiveInteger($minShards);

        $this->kv->setIfChangeValue('min_shards', $minShards);
        return $this;
    }

    function getRentTimeout(): int
    {
        return $this->kv->getInt('rent_timeout');
    }

    function getName(): string
    {
        return $this->kv->get('name');
    }

    // GET SET END

    function closeConnect(): self
    {
        $this->ipdo->close();
        return $this;
    }

    /**
     * @return Shard[]
     * 
     * @throws ShardsCreationLockedException
     */
    function createNewShards(int $count = 1): array
    {
        Assert::positiveInteger($count);

        if ($this->isShardsCreationLock()) {
            throw new ShardsCreationLockedException;
        }

        $time = \time();
        $this->kv->set('shards_creation_lock', $time);
        $shards = [];
        $files = [];
        $sql_shard = 'INSERT INTO shards (id, created_at, updated_at, schema_version, active) VALUES ({id}, {time}, {time}, 1, 0)';

        try {
            $sqls = $this->kv->get('schema_sql');
            /** @var string[] $sqls */
            foreach (Obj::rangeInt(1, $count) as $_) {
                $shardId = ID::uuidv4();
                $files[$shardId] = $ptf = $this->createShardPtf($shardId);
                File::put($ptf, '');
                $ipdoShard = \DI(IPDOSQLite::class, [$ptf]);
                try {
                    foreach ($sqls as $sql) {
                        $ipdoShard->exec($sql);
                    }
                } finally {
                    $ipdoShard->close();
                }

                $this->ipdo->exec($sql_shard, ['id' => $shardId, 'time' => $time]);
                // INFO успешные созданные шарды оставляем
                unset($files[$shardId]);
                $shards[] = new Shard($shardId, $this);
            }
        } catch (IPDOException $e) {
            if ([] !== $files) {
                FS::unlink_m2($files);
            }
            throw $e;
        } finally {
            $this->kv->set('shards_creation_lock', null);
        }
        return $shards;
    }

    /**
     * @throws ShardsCreationLockedException
     */
    function createNewShard(): Shard
    {
        return $this->createNewShards(1)[0];
    }

    /**
     * @return Shard[]
     */
    function getAllShards(): array
    {
        $shards = $this->ipdo->exec('SELECT id FROM shards', [], IPDO::FETCH_ALL_NUM);
        $shards = \array_column($shards, 0);
        foreach ($shards as &$shard) {
            $shard = new Shard($shard, $this);
        }
        return $shards;
    }

    function getCountShards(): int
    {
        $count = $this->ipdo->exec('SELECT COUNT(*) FROM shards', [], IPDO::FETCH_ONCE_NUM);
        return (int)$count[0];
    }

    /**
     * @return Shard[]
     */
    function getFreeShards(): array
    {
        $threshold = \time() - $this->kv->getInt('rent_timeout');

        $shards = $this->ipdo->exec(
            'SELECT id FROM shards WHERE active = 0 OR updated_at <= {threshold} ORDER BY updated_at ASC',
            ['threshold' => $threshold],
            IPDO::FETCH_ALL_NUM
        );
        $shards = \array_column($shards, 0);
        foreach ($shards as &$shard) {
            $shard = new Shard($shard, $this);
        }
        return $shards;
    }

    function existsShard(Shard $shard): bool
    {
        return $this->ipdo->exists('SELECT 1 FROM shards WHERE id = {id}', ['id' => $shard->id]);
    }

    /**
     * @throws ShardNotFoundException
     */
    function isFree(Shard $shard): bool
    {
        $this->checkShard($shard);

        $threshold = \time() - $this->kv->getInt('rent_timeout');
        return $this->ipdo->exists(
            'SELECT 1 FROM shards WHERE id = {id} AND (active = 0 OR updated_at <= {threshold})',
            [
                'id' => $shard->id,
                'threshold' => $threshold,
            ],
        );
    }

    /**
     * @throws ShardNotFreeException
     * @throws ShardNotFoundException
     */
    function rent(Shard $shard): self
    {
        if ($this->rentValid($shard)) {
            // повторная аренда, защита от дурака
            return $this;
        }

        $time = \time();
        $threshold = $time - $this->kv->getInt('rent_timeout');
        $ownerToken = ID::uuidv4();

        $record = $this->ipdo->execBusyRetry(
            'UPDATE shards SET active = 1, updated_at = {time}, owner_token = {token} WHERE id IN (
                SELECT id FROM shards WHERE id = {id} AND (active = 0 OR updated_at <= {threshold})
            ) RETURNING id',
            [
                'id' => $shard->id,
                'token' => $ownerToken,
                'time' => $time,
                'threshold' => $threshold,
            ],
            IPDO::FETCH_ONCE_NUM
        );

        if ([] === $record) {
            throw new ShardNotFreeException($shard->id);
        }

        $this->setOwnerTokenToShard($shard, $ownerToken);

        return $this;
    }

    function tryRent(Shard $shard): bool
    {
        try {
            $this->rent($shard);
            return true;
        } catch (ShardNotFreeException) {
            return false;
        }
    }

    /**
     * @throws ShardNotFreeException
     */
    function rentFree(): Shard
    {
        $shards = $this->getFreeShards();
        if ([] !== $shards) {
            // \shuffle($shards);
            foreach ($shards as $shard) {
                if ($this->tryRent($shard)) {
                    return $shard;
                }
            }
        }

        throw new ShardNotFreeException('There are no free shards');
    }

    function tryRentFree(): ?Shard
    {
        try {
            return $this->rentFree();
        } catch (ShardNotFreeException) {
            return null;
        }
    }

    function getIPDOByShard(Shard $shard): IPDOSQLite
    {
        $this->checkShard($shard);
        return \DI(IPDOSQLite::class, [$this->createShardPtf($shard)]);
    }

    /**
     * @throws ShardNotFoundException
     */
    function release(Shard $shard): self
    {
        $this->checkShard($shard);

        $ownerToken = $shard->ownerToken;
        if (null === $ownerToken) {
            // Объект не держит аренду — освобождать нечего, БД не трогаем.
            return $this;
        }

        // Токен сбрасываем в любом случае: либо аренда освобождена нами, либо
        // она уже не наша (перехвачена после просрочки) — исключение лишнее.
        $this->setOwnerTokenToShard($shard, null);

        $this->ipdo->execBusyRetry(
            'UPDATE shards SET active = 0, updated_at = {time}, owner_token = NULL WHERE id IN (
                SELECT id FROM shards WHERE id = {id} AND active = 1 AND owner_token = {token}
            )',
            ['id' => $shard->id, 'token' => $ownerToken, 'time' => \time()],
        );

        return $this;
    }

    /**
     * @throws ShardNotFoundException
     */
    function ptf(Shard $shard): string
    {
        $this->checkShard($shard);
        return $this->createShardPtf($shard);
    }

    /**
     * Сколько времени осталось до просрочки аренды
     * 
     * | -1 - не активный шард | 0 - время вышло |
     * @return int<-1,max>
     * 
     * @throws ShardNotFoundException
     */
    function leftTimeUntilRelease(Shard $shard): int
    {
        $this->checkShard($shard);

        $record = $this->ipdo->exec(
            'SELECT updated_at FROM shards WHERE id = {id} AND active = 1',
            ['id' => $shard->id],
            IPDO::FETCH_ONCE_NUM
        );

        $updated_at = $record[0] ?? null;
        if (null === $updated_at) {
            return -1;
        }
        $updated_at = (int)$updated_at;
        $threshold = \time() - $this->kv->getInt('rent_timeout');
        $time = $updated_at - $threshold;
        return $time < 0 ? 0 : $time;
    }

    /**
     * @throws ShardNotFoundException
     */
    function rentValid(Shard $shard): bool
    {
        $this->checkShard($shard);
        $ownerToken = $shard->ownerToken;
        if (null === $ownerToken) {
            return false;
        }

        $threshold = \time() - $this->kv->getInt('rent_timeout');

        $valid = $this->ipdo->exists(
            'SELECT id FROM shards
            WHERE id = {id}
                AND active = 1
                AND owner_token = {token}
                AND updated_at > {threshold}',
            ['id' => $shard->id, 'token' => $ownerToken, 'threshold' => $threshold],
        );

        if (false === $valid) {
            $this->setOwnerTokenToShard($shard, null);
        }
        return $valid;
    }

    /**
     * Продлеваем аренду
     * @throws ShardRentLostException
     * @throws ShardNotRentException
     */
    function heartbeat(Shard $shard): self
    {
        $this->checkShard($shard);
        if (null === $shard->ownerToken) {
            throw new ShardNotRentException($shard->id);
        }
        if (!$this->_heartbeat($shard, $this->ipdo)) {
            throw new ShardRentLostException($shard->id);
        }
        return $this;
    }

    /**
     * более экономичная стратегия продления аренды
     * 
     * @throws ShardRentLostException
     * @throws ShardNotRentException
     */
    function heartbeat_m2(Shard $shard): self
    {
        $this->checkShard($shard);
        if (null === $shard->ownerToken) {
            throw new ShardNotRentException($shard->id);
        }

        $left = $this->leftTimeUntilRelease($shard);

        if ($left <= 0) {
            // -1 — не активен, 0 — уже просрочен; в обоих случаях продлевать нечего
            throw new ShardRentLostException($shard->id);
        }

        if ($left <= $this->minSecHeartbeat && !$this->_heartbeat($shard, $this->ipdo)) {
            throw new ShardRentLostException($shard->id);
        }

        // Нет необходимости проверять бд, метод задуман экономить на обновлении записи бд

        return $this;
    }

    /**
     * @return \Generator<string,IPDOSQLite>
     */
    function getAllShardAsIPDO(): \Generator
    {
        foreach ($this->getAllShardAsPtf() as $shardId => $ptf) {
            yield $shardId => \DI(IPDOSQLite::class, [$ptf]);
        }
    }

    /**
     * @return \Generator<string,string>
     */
    function getAllShardAsPtf(): \Generator
    {
        foreach ($this->getAllShards() as $shard) {
            yield $shard->id => $this->createShardPtf($shard);
        }
    }

    /**
     * На данные момент вносятся изменения таких свойств как minShards / rentTimeout
     */
    function applyConfig(CreateConfig $config): self
    {
        $this
            ->setMinShards($config->minShards)
            ->setRentTimeout($config->rentTimeout)
            // 
        ;

        // INFO в будущем планируем внедрять версионирование схемы шардов

        return $this;
    }

    /**
     * @throws ShardsCreationLockedException
     */
    function ensureMinShards(): self
    {
        $curCount = $this->getCountShards();
        $minShards = $this->kv->getInt('min_shards');

        if ($curCount >= $minShards) {
            return $this;
        }

        $reqCount = $minShards - $curCount;
        $this->createNewShards($reqCount);
        return $this;
    }

    // STATIC

    static function discover(string $baseDir, string $name): ?Registry
    {
        $ptf = \DI(HelperService::class)->findRegFile($baseDir, $name);
        if (null === $ptf) {
            return null;
        }
        $ipdo = \DI(IPDOSQLite::class, [$ptf]);

        // INFO проверка таблиц
        $missingTables = [];
        foreach (['kv', 'shards'] as $tbl) {
            if (!$ipdo->existsTable($tbl)) {
                $missingTables[] = $tbl;
            }
        }
        if ([] !== $missingTables) {
            $ipdo->close();
            throw new RegistryInvalidException(\sprintf(
                'Invalid registry "%s" at "%s": required table(s) missing [%s]; the file is not a valid %s registry (corrupted, or another SQLite file placed at this path); remove the file and recreate the registry via %s::create().',
                $name,
                $ptf,
                \implode(', ', $missingTables),
                __NAMESPACE__,
                self::class,
            ));
        }

        $kv = \DI(Kv::class, [$ipdo]);

        // INFO Проверка версии регистра
        $regVer = $kv->getInt('version');
        if (self::VERSION !== $regVer) {
            $ipdo->close();
            throw new RegistryVersionMismatchException(\sprintf(
                'Unsupported registry version for "%s" (%s): expected %s, got %s. %s',
                $name,
                $ptf,
                self::VERSION,
                $regVer,
                (
                    $regVer < self::VERSION
                    ? \sprintf('The registry file is outdated and is not supported by the current class. Migrate it to version %s or recreate the registry.', self::VERSION)
                    : \sprintf('The registry file was created by a newer version and is not supported by the current class. Upgrade %s to a version that supports format %s.', __NAMESPACE__, $regVer)
                ),
            ));
        }

        return new self(ipdo: $ipdo, kv: $kv);
    }

    static function discoverFromCreateConfig(CreateConfig $config): ?Registry
    {
        $reg = self::discover($config->baseDir, $config->name);
        return null === $reg ? null : $reg;
    }

    /**
     * @throws RegistryNameExistsException
     */
    static function create(CreateConfig $config): Registry
    {
        $reg = self::discoverFromCreateConfig($config);
        if (null !== $reg) {
            throw new RegistryNameExistsException($reg->getName());
        }
        return self::_create($config);
    }

    static protected function _create(CreateConfig $config): Registry
    {
        $files = [];
        try {

            // INFO callback для очищения переменных, они не очищаются если выбросится исключение, тем самым файлы не удалятся
            [$ipdoReg, $kv] = (static function (CreateConfig $config, int $version, array &$files): array {
                $ptf = $config->ptfRegistry;
                $time = \time();
                File::put($ptf, '');
                $files[] = $ptf;
                $ipdoReg = \DI(IPDOSQLite::class, [$ptf]);
                foreach (
                    [
                        'CREATE TABLE kv (k TEXT PRIMARY KEY NOT NULL, v TEXT NOT NULL)',
                        'CREATE TABLE shards (id TEXT PRIMARY KEY NOT NULL, schema_version INTEGER NOT NULL, active INTEGER NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, owner_token TEXT)',
                    ] as $sql
                ) {
                    $ipdoReg->exec($sql);
                }

                $kv = \DI(Kv::class, [$ipdoReg]);
                // TODO registry_creation_completed - для подтверждения что реестр создан полноценно | ???
                foreach (
                    [
                        'version'      => $version,
                        'shards_creation_lock' => null,
                        'name'         => $config->name,
                        'schema_sql'   => $config->schemaSql,
                        'rent_timeout' => $config->rentTimeout,
                        'min_shards'   => $config->minShards,
                        'created_at'   => $time,
                    ] as $key => $value
                ) {
                    $kv->set($key, $value);
                }

                // INFO При больших количествах воркеров, производительность в реестр может упиратся.
                // TODO стоит сделать этот режим опциональным?
                $ipdoReg->setJournalMode('wal');

                return [$ipdoReg, $kv];
            })($config, self::VERSION, $files);

            /** @var IPDOSQLite $ipdoReg */
            /** @var Kv $kv */
            $files = [];

            $registry = new self(ipdo: $ipdoReg, kv: $kv);
            $registry->createNewShards($config->minShards);

            return $registry;
        } catch (\Throwable $e) {
            if ([] !== $files) {
                FS::unlink_m2($files);
            }
            throw $e;
        }
    }

    static function createOrDiscover(CreateConfig $config): Registry
    {
        $reg = self::discoverFromCreateConfig($config);
        return null === $reg ? self::_create($config) : $reg;
    }

    // PROTECTED

    protected function isShardsCreationLock(): bool
    {
        // Защита в случаи если несколько воркеров начнут создавать шарды
        $lock = $this->kv->get('shards_creation_lock');
        if (\is_int($lock) && \abs(\time() - $lock) < 3) {
            return true;
        }
        return false;
    }

    protected function _heartbeat(Shard $shard, IPDOSQLite $ipdoReg): bool
    {
        $ownerToken = $shard->ownerToken;
        if (null === $ownerToken) {
            return false;
        }

        $time = \time();
        $threshold = $time - $this->kv->getInt('rent_timeout');

        $record = $ipdoReg->execBusyRetry(
            'UPDATE shards SET updated_at = {time}
                WHERE id IN (
                    SELECT id FROM shards
                        WHERE id = {id} AND active = 1 AND owner_token = {token} AND updated_at > {threshold}
            ) AND active = 1
            RETURNING id',
            ['id' => $shard->id, 'token' => $ownerToken, 'time' => $time, 'threshold' => $threshold],
            IPDO::FETCH_ONCE_NUM,
        );

        if ([] !== $record) {
            return true;
        }
        $this->setOwnerTokenToShard($shard, null);
        return false;
    }

    protected function setOwnerTokenToShard(Shard $shard, ?string $ownerToken = null): void
    {
        if (!isset(self::$setOwnerTokenToShard)) {
            self::$setOwnerTokenToShard = (static function (Shard $shard, ?string $ownerToken): void {
                // IDE ругается
                $prop = 'ownerToken';
                $shard->$prop = $ownerToken;
            })
                ->bindTo(null, Shard::class);
        }

        (self::$setOwnerTokenToShard)($shard, $ownerToken);
    }

    /**
     * @throws ShardNotFoundException
     */
    protected function checkShard(Shard $shard): void
    {
        if (!$this->existsShard($shard)) {
            throw new ShardNotFoundException($shard->id);
        }
    }

    protected function createShardPtf(string|Shard $shardOrId): string
    {
        \is_object($shardOrId) && $shardOrId = $shardOrId->id;
        return Path::normalize(\sprintf('%s/%s.sqlite', $this->baseDir, $shardOrId));
    }
}