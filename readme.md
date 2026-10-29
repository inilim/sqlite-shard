# Inilim SQLite Shard

Библиотека для работы с пулом независимых SQLite-баз данных (шардов) под общим именем.

Позволяет создать набор шардов с общей схемой, брать их в аренду, продлевать аренду на время длительных операций и освобождать обратно, чтобы другие процессы могли их переиспользовать.

## Требования

- PHP с поддержкой используемых в библиотеке возможностей языка (например, PHP 8.4+).
- Расширение `pdo_sqlite`.

## Установка

```bash
composer require inilim/sqlite-shard:0.0.*
```

## Быстрый старт

```php
<?php

declare(strict_types=1);

use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;

$baseDir = __DIR__ . '/storage/shards';

if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$config = new CreateConfig(
    baseDir: $baseDir,
    schemaSql: [
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )',
    ],
    name: 'main',
    minShards: 5,
    rentTimeout: 30,
);

$registry = Registry::createOrDiscover($config);

// Применяем актуальные настройки и доращиваем пул при необходимости
$registry->applyConfig($config)->ensureMinShards();

$shard = $registry->rentFree();

$ipdo = $shard->getIPDO();

try {
    $ipdo->exec(
        'INSERT INTO users (name) VALUES ({name})',
        ['name' => 'Alice']
    );
} finally {
    $ipdo->close();
    $shard->release();
}
```

## Основные понятия

### Пул шардов

Пул — это набор SQLite-баз с одинаковой схемой, объединённых под общим именем. Пул создаётся в указанном каталоге и в дальнейшем может быть обнаружен по имени.

### Шард

Шард — это отдельная SQLite-база из пула. У шарда есть уникальный `id`, состояние аренды и методы для получения соединения и работы с арендой.

### Аренда

Шард можно взять в аренду, чтобы другие процессы не использовали его одновременно. Аренда действует ограниченное время (`rentTimeout`). Если воркер не продлевает аренду, шард со временем снова становится свободным и доступен для переиспользования.

## Сценарии использования

### Создание или обнаружение пула

```php
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;

$config = new CreateConfig(
    baseDir: __DIR__ . '/storage/shards',
    schemaSql: 'CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, title TEXT NOT NULL)',
    name: 'items',
    minShards: 3,
    rentTimeout: 60,
);

// Вернёт существующий пул или создаст новый
$registry = Registry::createOrDiscover($config);
```

Строгое создание нового пула (если пул с таким именем уже существует — будет исключение):

```php
$registry = Registry::create($config);
```

Обнаружение без создания:

```php
$registry = Registry::discover(__DIR__ . '/storage/shards', 'items');

if (null === $registry) {
    // Пул не найден
}
```

### Аренда свободного шарда

```php
use Inilim\SqliteShard\ShardNotFreeException;

try {
    $shard = $registry->rentFree();

    try {
        $ipdo = $shard->getIPDO();

        $ipdo->exec('INSERT INTO items (title) VALUES ({title})', [
            'title' => 'Пример',
        ]);
    } finally {
        if (isset($ipdo)) {
            $ipdo->close();
        }

        $shard->release();
    }
} catch (ShardNotFreeException $e) {
    // Свободных шардов нет
}
```

Без выброса исключения при отсутствии свободных шардов:

```php
$shard = $registry->tryRentFree();

if (null === $shard) {
    // Свободных шардов нет
}
```

### Продление аренды

Для длительных операций аренду нужно продлевать.

```php
use Inilim\SqliteShard\ShardRentLostException;

$registry->setMinSecHeartbeat(5);

$shard = $registry->rentFree();

try {
    while ($hasWork) {
        // Экономное продление аренды
        $shard->heartbeat_m2();

        // Длительная работа с шардом
    }
} catch (ShardRentLostException $e) {
    // Аренда потеряна
} finally {
    $shard->release();
}
```

Два варианта продления:

- `heartbeat()` — продлевает аренду при каждом вызове;
- `heartbeat_m2()` — более экономный вариант, продлевает аренду только когда до её истечения осталось немного времени (порог задаётся через `setMinSecHeartbeat()`).

### Освобождение шарда

```php
$shard->release();
```

Освобождение безопасно вызывать повторно. Если аренда уже истекла или была перехвачена другим воркером, чужую аренду освободить не получится.

### Проверка состояния аренды

```php
if ($shard->rentValid()) {
    // Аренда актуальна
}

$left = $shard->leftTimeUntilRelease();

if ($left === -1) {
    // Шард неактивен
} elseif ($left === 0) {
    // Время аренды истекло
} else {
    // Осталось $left секунд
}
```

### Создание дополнительных шардов

```php
use Inilim\SqliteShard\ShardsCreationLockedException;

try {
    $newShards = $registry->createNewShards(3);
} catch (ShardsCreationLockedException $e) {
    // Другой процесс уже создаёт шарды
}
```

Создание одного шарда:

```php
$shard = $registry->createNewShard();
```

### Доращивание пула до минимума

```php
try {
    $registry->ensureMinShards();
} catch (ShardsCreationLockedException $e) {
    // Создание шардов заблокировано другим процессом
}
```

### Обход всех шардов

```php
foreach ($registry->getAllShardAsPtf() as $shardId => $ptf) {
    // $ptf — путь к файлу шарда
}

foreach ($registry->getAllShardAsIPDO() as $shardId => $ipdo) {
    try {
        // Работа с шардом
    } finally {
        $ipdo->close();
    }
}
```

### Применение настроек пула

```php
$registry->applyConfig($config);
```

Обновляет настройки пула из `CreateConfig`: минимальное количество шардов и таймаут аренды.

## API

### CreateConfig

```php
__construct(
    string $baseDir,
    string|array $schemaSql,
    string $name,
    int $minShards = 5,
    int $rentTimeout = 30,
)

setMinShards(int $minShards): self
setRentTimeout(int $rentTimeout): self
```

- `baseDir` — каталог, где будет создан пул (должен существовать);
- `schemaSql` — один SQL-запрос или массив запросов схемы, применяемой к новым шардам;
- `name` — имя пула;
- `minShards` — минимальное количество шардов;
- `rentTimeout` — время аренды в секундах.

### Registry

Статические методы:

```php
static discover(string $baseDir, string $name): ?Registry
static discoverFromCreateConfig(CreateConfig $config): ?Registry
static create(CreateConfig $config): Registry
static createOrDiscover(CreateConfig $config): Registry
```

Методы экземпляра:

```php
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
ptf(Shard $shard): string
leftTimeUntilRelease(Shard $shard): int
rentValid(Shard $shard): bool

release(Shard $shard): self
heartbeat(Shard $shard): self
heartbeat_m2(Shard $shard): self

getAllShardAsIPDO(): Generator
getAllShardAsPtf(): Generator

applyConfig(CreateConfig $config): self
ensureMinShards(): self
```

### Shard

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

## Исключения

Все исключения библиотеки наследуются от `Inilim\SqliteShard\SqliteShardException`.

- `KeyNotFoundKvException` — не найден служебный ключ настроек.
- `RegistryInvalidException` — файл пула повреждён или не является корректным.
- `RegistryNameExistsException` — пул с таким именем уже существует при вызове `Registry::create()`.
- `RegistryVersionMismatchException` — версия формата пула не поддерживается текущей версией библиотеки.
- `ShardNotFoundException` — шард не найден в пуле.
- `ShardNotFreeException` — шард занят или свободных шардов нет.
- `ShardNotRentException` — попытка продлить аренду шарда, который не арендован текущим объектом.
- `ShardRentLostException` — аренда потеряна или истекла.
- `ShardsCreationLockedException` — создание шардов заблокировано другим процессом.

Пример обработки:

```php
use Inilim\SqliteShard\RegistryNameExistsException;
use Inilim\SqliteShard\ShardNotFreeException;
use Inilim\SqliteShard\ShardRentLostException;
use Inilim\SqliteShard\ShardsCreationLockedException;

try {
    $registry = \Inilim\SqliteShard\Registry::create($config);
} catch (RegistryNameExistsException $e) {
    // Пул уже существует
}

try {
    $registry->ensureMinShards();
} catch (ShardsCreationLockedException $e) {
    // Другой процесс создаёт шарды
}

try {
    $shard = $registry->rentFree();
} catch (ShardNotFreeException $e) {
    // Нет свободных шардов
}

try {
    $shard->heartbeat();
} catch (ShardRentLostException $e) {
    // Аренда потеряна
}
```

## Рекомендации

- Всегда освобождайте шард через `release()` в `finally`, чтобы не держать аренду дольше необходимого.
- Для длительных операций регулярно продлевайте аренду через `heartbeat()` или `heartbeat_m2()`.
- Если свободных шардов может не быть, используйте `tryRentFree()` вместо `rentFree()`.
- Каталог `baseDir` должен существовать и быть доступным для записи.
- Для существующего пула при необходимости обновляйте настройки через `applyConfig($config)`.
- `ensureMinShards()` удобно вызывать при старте воркера, чтобы гарантировать минимальный размер пула.

## Benchmark: parallel SQLite writes

```bash
php ./bench/benchmark_1.php 10 15
```

threads:  10
duration: 15s

```
====================================================================================================
Summary
----------------------------------------------------------------------------------------------------
scenario                              inserts    inserts/sec   growth %   errors
----------------------------------------------------------------------------------------------------
Single SQLite file (no WAL)              2821         179.50     +0.00%        0
Shards via library (no WAL)              7927         521.99   +190.81%        0
Single SQLite file (WAL)                12932         824.96   +359.59%        0
Shards via library (WAL)                32600        2148.55  +1096.97%        0
====================================================================================================
```