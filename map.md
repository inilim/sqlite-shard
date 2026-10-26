## \Inilim\SqliteShard\CreateConfig

```php
__construct(string $baseDir, array|string $schemaSql, string $name, int $minShards = 5, int $rentTimeout = 30)
setMinShards(int $minShards): self
setRentTimeout(int $rentTimeout): self
```

## \Inilim\SqliteShard\HelperService

```php
regIterator(string $baseDir): Generator
findRegFileFromCreateConfig(CreateConfig $config): ?string
findRegFile(string $baseDir, string $name): ?string
getRegIPDO(Registry $registry): IPDOSQLite
```

## \Inilim\SqliteShard\Registry

```php
static discover(string $baseDir, string $name): ?Registry
static discoverFromCreateConfig(CreateConfig $config): ?Registry
static create(CreateConfig $config): Registry
static createOrDiscover(CreateConfig $config): Registry
setMinSecHeartbeat(int $seconds): self
setRentTimeout(int $rentTimeout): self
setMinShards(int $minShards): self
getRentTimeout(): int
getName(): string
closeConnect(): self
createNewShards(int $count = 1): array
createNewShard(): Shard
getAllShards(): array
getCountShards(): int
getFreeShards(): array
existsShard(Shard $shard): bool
isFree(Shard $shard): bool
rent(Shard $shard): self
tryRent(Shard $shard): bool
rentFree(): Shard
tryRentFree(): ?Shard
getIPDOByShard(Shard $shard): IPDOSQLite
release(Shard $shard): self
ptf(Shard $shard): string
leftTimeUntilRelease(Shard $shard): int
rentValid(Shard $shard): bool
heartbeat(Shard $shard): self
heartbeat_m2(Shard $shard): self
getAllShardAsIPDO(): Generator
getAllShardAsPtf(): Generator
applyConfig(CreateConfig $config): self
ensureMinShards(): self
```

## \Inilim\SqliteShard\Shard

```php
__construct(string $id, Registry $reg)
ptf(): string
tryRent(): bool
rent(): self
isFree(): bool
getIPDO(): IPDOSQLite
leftTimeUntilRelease(): int
release(): self
heartbeat(): self
heartbeat_m2(): self
rentValid(): bool
```
