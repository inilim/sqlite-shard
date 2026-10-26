## `Inilim\SqliteShard\Registry`

### Публичные свойства `baseDir` и `minSecHeartbeat`

- [x] После `create()` свойство `baseDir` равно `config->baseDir`: абсолютному нормализованному пути без завершающего разделителя. — покрыто: `PropertiesTest::testBaseDirAfterCreateIsNormalizedConfigBaseDir`, `PropertiesTest::testBaseDirAfterCreateWithTrailingSeparatorIsTrimmed`
- [x] После `discover()` свойство `baseDir` равно директории найденного файла реестра в нормализованном виде. — покрыто: `PropertiesTest::testBaseDirAfterDiscoverIsDirectoryOfFoundRegistryFile`, `DiscoverTest::testBaseDirWithTrailingSlashNormalized`
- [x] `baseDir` имеет тип `string`; попытка присвоить ему значение извне класса выбрасывает `Error` (свойство доступно только для чтения). — покрыто: `PropertiesTest::testBaseDirIsReadOnly`, `PropertiesTest::testBaseDirAfterCreateIsNormalizedConfigBaseDir`
- [x] `minSecHeartbeat` после создания или обнаружения реестра равно `10`. — покрыто: `PropertiesTest::testMinSecHeartbeatDefaultAfterCreateIsTen`, `PropertiesTest::testMinSecHeartbeatDefaultAfterDiscoverIsTen`, `SetMinSecHeartbeatTest::testDefaultValueIsTen`
- [x] `minSecHeartbeat` можно читать извне; попытка присвоить ему значение извне класса выбрасывает `Error` (запись разрешена только внутри класса и через `setMinSecHeartbeat`). — покрыто: `PropertiesTest::testMinSecHeartbeatIsReadableFromOutside`, `PropertiesTest::testMinSecHeartbeatIsReadOnlyFromOutside`
- [x] `VERSION` — публичная константа со значением `1`. — покрыто: `PropertiesTest::testVersionConstantIsOne`

### `setMinSecHeartbeat`

- [x] Значение по умолчанию: у только что созданного и у заново обнаруженного реестра `minSecHeartbeat` равно `10`. — покрыто: `SetMinSecHeartbeatTest::testDefaultValueIsTen`
- [x] Успешная установка `1`: метод возвращает тот же объект `Registry`, а `minSecHeartbeat` становится `1`. — покрыто: `SetMinSecHeartbeatTest::testSetToOneReturnsSameInstance`
- [x] Успешная установка `120`: метод возвращает тот же объект `Registry`, а `minSecHeartbeat` становится `120`. — покрыто: `SetMinSecHeartbeatTest::testSetToOneHundredTwentyReturnsSameInstance`
- [x] Установка `0`: выбрасывается `InvalidArgumentException`; `minSecHeartbeat` остаётся прежним (например, если до этого было установлено `20`, остаётся `20`). — покрыто: `SetMinSecHeartbeatTest::testSetZeroThrowsAndKeepsPreviousValue`
- [x] Установка отрицательного значения (например, `-1`): выбрасывается `InvalidArgumentException`; `minSecHeartbeat` остаётся прежним. — покрыто: `SetMinSecHeartbeatTest::testSetNegativeThrowsAndKeepsPreviousValue`
- [x] Передача значения не типа `int` (`1.0`, `'10'`, `true`, `null`) при вызове из файла со `strict_types=1`: выбрасывается `TypeError`; `minSecHeartbeat` не меняется. — покрыто: `SetMinSecHeartbeatTest::testNonIntValuesThrowTypeErrorAndKeepValue`
- [x] Значение `minSecHeartbeat` не сохраняется в реестр: после `setMinSecHeartbeat(3)` новый экземпляр, полученный через `discover()` для того же реестра, имеет `minSecHeartbeat = 10`. — покрыто: `SetMinSecHeartbeatTest::testValueIsNotPersistedToRegistry`
- [x] Значение принадлежит конкретному экземпляру: если экземпляр A установил `minSecHeartbeat = 2`, уже открытый экземпляр B того же реестра продолжает показывать `10`; B изменится только после собственного вызова сеттера. — покрыто: `SetMinSecHeartbeatTest::testValueBelongsToEachInstance`
- [x] Цепочка вызовов `setMinSecHeartbeat(5)`, `setRentTimeout(60)`, `setMinShards(2)` возвращает тот же объект `Registry`, и все три значения применяются. — покрыто: `SetMinSecHeartbeatTest::testChainingReturnsSameInstanceAndAppliesAllValues`
- [x] Метод влияет на порог `heartbeat_m2`, но не на `heartbeat`: при `rent_timeout = 30` и остатке аренды `5` вызов `heartbeat` всё равно продлевает аренду. — покрыто: `SetMinSecHeartbeatTest::testHeartbeatIsNotAffectedByMinSecHeartbeat`
- [x] `heartbeat_m2` использует установленное значение: при остатке аренды `8` и `minSecHeartbeat = 10` аренда продлевается; после `setMinSecHeartbeat(5)` при том же остатке `8` аренда не продлевается. — покрыто: `SetMinSecHeartbeatTest::testHeartbeatM2UsesConfiguredThreshold`
- [x] Очень большое положительное значение (например, `PHP_INT_MAX`) принимается; при `heartbeat_m2` для свежего активного шарда выбирается ветка продления, а для свободного шарда по-прежнему выбрасывается `ShardRentLostException`. — покрыто: `SetMinSecHeartbeatTest::testPhpIntMaxAccepted`
- [x] Установка не меняет `name`, `rent_timeout`, `min_shards`, количество шардов и их состояние аренды. — покрыто: `SetMinSecHeartbeatTest::testSettingDoesNotChangeOtherState`

### `setRentTimeout`

- [x] Значение по умолчанию для `CreateConfig` — `30`; после `create()` с настройками по умолчанию только что взятая аренда имеет остаток примерно `30` секунд. — покрыто: `SetRentTimeoutTest::testDefaultRentTimeoutIsThirtySeconds`, `CreateTest::testRentTimeoutFromConfigApplied`
- [x] Установка `60` возвращает тот же объект `Registry`; активный шард, у которого `updated_at` был `45` секунд назад, после этого считается занятым (`isFree` возвращает `false`). — покрыто: `SetRentTimeoutTest::testSetSixtyReturnsSameInstanceAndKeepsShardBusy`
- [x] Установка `1` возвращает тот же объект `Registry`; активный шард, у которого `updated_at` был `2` секунды назад, считается свободным (`isFree` возвращает `true`, `rentValid` — `false`). — покрыто: `SetRentTimeoutTest::testSetOneReturnsSameInstanceAndFreesShard`
- [x] Значение сохраняется в реестре: после `setRentTimeout(60)` новый экземпляр, полученный через `discover()`, использует таймаут `60`. — покрыто: `SetRentTimeoutTest::testValueIsPersistedAndUsedByNewInstance`
- [x] Установка `0` выбрасывает `InvalidArgumentException`; сохранённое значение не меняется (следующий `discover()` продолжает использовать прежний таймаут). — покрыто: `SetRentTimeoutTest::testSetZeroThrowsAndKeepsSavedValue`
- [x] Установка отрицательного значения выбрасывает `InvalidArgumentException`; сохранённое значение не меняется. — покрыто: `SetRentTimeoutTest::testSetNegativeThrowsAndKeepsSavedValue`
- [x] Передача не-`int` при вызове из файла со `strict_types=1` выбрасывает `TypeError`; сохранённое значение не меняется. — покрыто: `SetRentTimeoutTest::testNonIntValuesThrowTypeErrorAndKeepSavedValue`
- [x] Уменьшение таймаута немедленно освобождает просроченные шарды: активный шард с `updated_at` `20` секунд назад при таймауте `30` занят; после `setRentTimeout(10)` он свободен; после возврата `setRentTimeout(30)` снова занят. — покрыто: `SetRentTimeoutTest::testDecreasingTimeoutFreesShardImmediately`
- [x] Увеличение таймаута возвращает «свежесть» шарду: активный шард с `updated_at` `35` секунд назад при таймауте `30` свободен; после `setRentTimeout(60)` он занят, и `rentValid` возвращает `true`. — покрыто: `SetRentTimeoutTest::testIncreasingTimeoutRestoresFreshness`
- [x] Изменение таймаута сразу отражается на `leftTimeUntilRelease`: у активного шарда с `updated_at` `10` секунд назад при таймауте `30` остаток около `20`; после `setRentTimeout(10)` остаток `0`; после `setRentTimeout(300)` остаток около `290`. — покрыто: `SetRentTimeoutTest::testLeftTimeUntilReleaseFollowsTimeout`
- [x] Изменение таймаута сразу отражается на `heartbeat`: активный шард с `updated_at` `35` секунд назад при таймауте `30` даёт `ShardRentLostException`; после `setRentTimeout(60)` тот же `heartbeat` успешно продлевает аренду. — покрыто: `SetRentTimeoutTest::testHeartbeatDependsOnTimeout`
- [x] Изменение таймаута сразу отражается на `heartbeat_m2`: при таймауте `30` и `updated_at` `24` секунды назад остаток `6` меньше `minSecHeartbeat = 10`, поэтому аренда продлевается; после `setRentTimeout(60)` остаток `36` больше `10`, поэтому `heartbeat_m2` не выполняет запись. — покрыто: `SetRentTimeoutTest::testHeartbeatM2DependsOnTimeout`
- [x] Изменение таймаута сразу отражается на `getFreeShards`, `rentFree`, `tryRentFree`, `isFree`, `rent` и `tryRent`. — покрыто: `SetRentTimeoutTest::testFreeRentApiFollowsTimeout`
- [x] Новое значение видно уже открытому второму экземпляру `Registry` того же файла; переоткрывать реестр не нужно. — покрыто: `SetRentTimeoutTest::testAlreadyOpenSecondInstanceSeesNewTimeout`
- [x] Повторная установка того же значения возвращает тот же объект `Registry`; наблюдаемое поведение не меняется. — покрыто: `SetRentTimeoutTest::testRepeatedSameValueKeepsBehavior`
- [x] Очень большое положительное значение (`PHP_INT_MAX`) принимается; активные шарды не становятся свободными, а только что взятая аренда имеет остаток около `PHP_INT_MAX`. — покрыто: `SetRentTimeoutTest::testPhpIntMaxAccepted`
- [x] Метод возвращает тот же объект `Registry`; поддерживается цепочка с `setMinSecHeartbeat` и `setMinShards`. — покрыто: `SetRentTimeoutTest::testChainingReturnsSameInstance`
- [x] Неудачная установка не меняет ни таймаут, ни состояние шардов: после исключения `discover()` показывает прежний таймаут. — покрыто: `SetRentTimeoutTest::testFailedSetKeepsTimeoutAndShardState`

### `setMinShards`

- [x] После `create()` количество шардов равно значению `minShards` из `CreateConfig` (по умолчанию `5`). — покрыто: `SetMinShardsTest::testAfterCreateCountEqualsMinShardsFromConfig`, `CreateTest::testDefaultCreatesFiveFreeShardsWithSchema`
- [x] Установка `8` возвращает тот же объект `Registry`; текущее количество шардов не меняется, новые файлы не создаются. — покрыто: `SetMinShardsTest::testSettingEightReturnsSameObjectAndDoesNotCreateShards`
- [x] Следующий `discover()` для того же имени создаёт недостающие шарды и возвращает реестр, в котором `getCountShards()` равно `8`; новые шарды свободны, имеют файлы и схему, существующие шарды не изменены. — покрыто: `SetMinShardsTest::testNextDiscoverCreatesMissingShardsOnly`
- [x] Значение сохраняется в реестре: повторный `discover()` в новом экземпляре не создаёт лишних шардов. — покрыто: `SetMinShardsTest::testValuePersistedAndRepeatedDiscoverDoesNotDuplicateShards`
- [x] Установка значения меньше текущего количества (например, `3` при `8` шардах) не удаляет шарды: `discover()` возвращает все `8` шардов, их строки и файлы остаются. — покрыто: `SetMinShardsTest::testSettingBelowCurrentCountDoesNotDeleteShards`
- [x] Установка значения, равного текущему количеству, не меняет ни количество, ни файлы. — покрыто: `SetMinShardsTest::testSettingEqualToCurrentCountChangesNothing`
- [x] Установка `0` выбрасывает `InvalidArgumentException`; сохранённое значение не меняется (следующий `discover()` не создаёт шардов по ошибочному значению). — покрыто: `SetMinShardsTest::testSettingZeroThrowsInvalidArgumentExceptionAndKeepsSavedValue`
- [x] Установка отрицательного значения выбрасывает `InvalidArgumentException`; сохранённое значение не меняется. — покрыто: `SetMinShardsTest::testSettingNegativeThrowsInvalidArgumentExceptionAndKeepsSavedValue`
- [x] Передача не-`int` при вызове из файла со `strict_types=1` выбрасывает `TypeError`; сохранённое значение не меняется. — покрыто: `SetMinShardsTest::testNonIntArgumentThrowsTypeErrorAndKeepsSavedValue`
- [x] Установка `100` сама по себе не создаёт шардов; только последующий `discover()` создаёт недостающие `100 - текущее_количество` шардов. — покрыто: `SetMinShardsTest::testSettingOneHundredCreatesShardsOnlyOnDiscover`
- [x] Метод не меняет `name`, `rent_timeout`, состояние аренды существующих шардов и не удаляет файлы. — покрыто: `SetMinShardsTest::testMethodDoesNotChangeNameRentTimeoutOrShardState`
- [x] `createNewShards()` не зависит от `min_shards`: создаёт ровно запрошенное количество шардов, даже если оно больше или меньше сохранённого минимума. — покрыто: `SetMinShardsTest::testCreateNewShardsDoesNotDependOnMinShards`
- [x] Цепочка с `setRentTimeout` и `setMinSecHeartbeat` возвращает тот же объект `Registry`. — покрыто: `SetMinShardsTest::testChainWithOtherSettersReturnsSameObject`

### `applyConfig`

- [x] Возвращает тот же объект `Registry` (`===`) и поддерживает цепочку вызовов с другими сеттерами. — покрыто: `ApplyConfigTest::testReturnsSameObjectAndSupportsChaining`, `ApplyConfigTest::testApplyConfigWithNewRentTimeoutSupportsChaining`
- [x] Применяет `minShards` из конфигурации: после `applyConfig()` с `minShards = 8` у реестра с 5 шардами `getCountShards()` по-прежнему равно `5`; следующий `discover()` создаёт 3 недостающих шарда и возвращает реестр с 8 шардами; новые шарды свободны, имеют файлы и сохранённую схему. — покрыто: `ApplyConfigTest::testAppliesMinShardsOnNextDiscover`
- [x] Применяет `rentTimeout` из конфигурации немедленно: активный шард с `updated_at` `45` секунд назад при старом таймауте `30` свободен; после `applyConfig()` с `rentTimeout = 60` он занят (`isFree` — `false`, `rentValid` — `true`); после `applyConfig()` с `rentTimeout = 10` снова свободен, `leftTimeUntilRelease` равно `0`. — покрыто: `ApplyConfigTest::testAppliesRentTimeoutImmediately`
- [x] Применяет оба значения за один вызов: конфигурация с `minShards = 8` и `rentTimeout = 60` не меняет текущее количество шардов, сразу делает активный шард с `updated_at` `45` секунд назад занятым, а следующий `discover()` доводит количество шардов до `8`. — покрыто: `ApplyConfigTest::testAppliesBothMinShardsAndRentTimeoutInOneCall`
- [x] Не меняет `minSecHeartbeat`: после `setMinSecHeartbeat(3)` вызов `applyConfig()` оставляет значение `3`; у экземпляра со значением по умолчанию остаётся `10`. — покрыто: `ApplyConfigTest::testApplyConfigKeepsCustomMinSecHeartbeat`, `ApplyConfigTest::testApplyConfigKeepsDefaultMinSecHeartbeat`
- [x] Не меняет `name` и `baseDir` текущего реестра. — покрыто: `ApplyConfigTest::testDoesNotChangeNameAndBaseDir`
- [x] Берёт значения из переданного `CreateConfig`, даже если его `name` и `baseDir` указывают на другой реестр: реестр A с именем `A`, 5 шардами и таймаутом `30`; конфигурация B с именем `B`, `minShards = 2`, `rentTimeout = 60` и другой директорией; после `applyConfig(B)` у A остаются `getName() = 'A'`, прежний `baseDir` и 5 шардов, но таймаут становится `60`; реестр B не создаётся, `discover()` для B возвращает `null`. — покрыто: `ApplyConfigTest::testTakesValuesFromPassedConfigEvenIfPointingToAnotherRegistry`
- [x] Не применяет `schemaSql` из конфигурации: схема существующих шардов не меняется; если конфигурация добавляет таблицу `extra`, то после `applyConfig()` с увеличением `minShards` и последующего `discover()` новые шарды используют сохранённую схему и не содержат таблицу `extra`. — покрыто: `ApplyConfigTest::testDoesNotApplySchemaSqlFromConfig`
- [x] Не создаёт и не удаляет шарды сам: при 5 шардах `applyConfig()` с `minShards = 2` оставляет 5 шардов, а с `minShards = 8` — те же 5 шардов и файлы; количество меняется только при последующем `discover()`. — покрыто: `ApplyConfigTest::testApplyConfigDoesNotCreateOrDeleteShardsItself`
- [x] Применение `minShards` меньше текущего количества не удаляет шарды: последующий `discover()` возвращает все существующие шарды. — покрыто: `ApplyConfigTest::testApplyConfigWithLowerMinShardsDoesNotDeleteShards`
- [x] Сохраняет таймаут в реестре: после `applyConfig()` с `rentTimeout = 60` новый экземпляр, полученный через `discover()`, считает активный шард с `updated_at` `45` секунд назад занятым. — покрыто: `ApplyConfigTest::testSavesRentTimeoutInRegistry`
- [x] Сохраняет минимум в реестре: после `applyConfig()` с `minShards = 8` новый экземпляр, полученный через `discover()`, возвращает 8 шардов. — покрыто: `ApplyConfigTest::testSavesMinShardsInRegistry`
- [x] Повторный `applyConfig()` с теми же значениями не меняет количество шардов, их `id`, `updated_at` и файлы; метод возвращает тот же объект. — покрыто: `ApplyConfigTest::testRepeatedApplyConfigWithSameValuesChangesNothing`
- [x] Применение конфигурации не меняет `active` и `updated_at` существующих шардов: меняется только интерпретация `updated_at` через новый `rent_timeout`; количество записей и файлы остаются прежними. — покрыто: `ApplyConfigTest::testApplyConfigDoesNotChangeShardActiveAndUpdatedAt`
- [x] Уже открытый второй экземпляр `Registry` того же файла сразу видит новый таймаут; для нового минимума ему достаточно вызвать `discover()`. — покрыто: `ApplyConfigTest::testSecondOpenInstanceSeesNewTimeoutAndMinShardsAfterDiscover`
- [x] Передача не-`CreateConfig` (`null`, массив, `stdClass`) при вызове из файла со `strict_types=1` выбрасывает `TypeError`; состояние реестра не меняется. — покрыто: `ApplyConfigTest::testPassingNonCreateConfigThrowsTypeErrorAndKeepsState`

### `getName`

- [x] После `create()` метод возвращает точное значение `name`, переданное в `CreateConfig`. — покрыто: `GetNameTest::testReturnsNameFromConfigAfterCreate`, `CreateTest::testCreatesRegistryFileAndReturnsRegistry`
- [x] После `discover()` метод возвращает то же имя, которое было использовано при создании. — покрыто: `GetNameTest::testReturnsSameNameAfterDiscover`
- [x] Имя с пробелами, кириллицей, регистром и специальными символами возвращается без изменений, обрезки пробелов и смены регистра. — покрыто: `GetNameTest::testComplexNameReturnedVerbatim`
- [x] Два реестра в одной директории с именами, различающимися только регистром, независимы; каждый `getName()` возвращает своё имя. — покрыто: `GetNameTest::testRegistriesDifferingInCaseAreIndependent`
- [x] `createOrDiscover()` возвращает реестр, у которого `getName()` равно имени конфигурации. — покрыто: `GetNameTest::testCreateOrDiscoverKeepsName`
- [x] Если в повреждённом реестре удалён ключ `name`, вызов `getName()` выбрасывает `KeyNotFoundKvException`. — покрыто: `GetNameTest::testMissingNameKeyThrowsKeyNotFound`
- [x] Для валидного реестра (созданного `create()` либо открытого `discover()`) возвращает `string` с точным именем; для повреждённого реестра без ключа `name` — `KeyNotFoundKvException` (кейс выше). — покрыто: `GetNameTest::testReturnsNameFromConfigAfterCreate`

### `createNewShards`

- [x] Вызов без аргумента создаёт ровно один шард, увеличивает количество шардов на `1` и возвращает массив с одним элементом. — покрыто: `CreateNewShardsTest::testDefaultCountIsOne`
- [x] Вызов с `count = 3` возвращает массив из трёх элементов с ключами `0`, `1`, `2`, увеличивает количество шардов на `3`. — покрыто: `CreateNewShardsTest::testCountThreeReturnsThreeShards`
- [x] Все возвращённые шарды имеют уникальные непустые `id`; `id` пригодны как имена файлов (не содержат `/`, `\` и не равны `..`). Текущая реализация генерирует UUID v4. — покрыто: `CreateNewShardsTest::testUniqueUuidIdsAndSameRegistryReference`
- [x] У каждого возвращённого шарда свойство `reg` указывает на тот же объект `Registry`, у которого вызван метод. — покрыто: `CreateNewShardsTest::testUniqueUuidIdsAndSameRegistryReference`
- [x] Каждый новый шард свободен и неактивен: `isFree($shard)` возвращает `true`, `rentValid($shard)` — `false`, `leftTimeUntilRelease($shard)` — `-1`; шард входит в `getFreeShards()`. — покрыто: `CreateNewShardsTest::testNewShardsAreFree`
- [x] Для каждого нового шарда существует файл `baseDir/{id}.sqlite`; `getIPDOByShard()` для него открывает SQLite, в котором доступна таблица из `schemaSql`. — покрыто: `CreateNewShardsTest::testEachNewShardHasFileWithSchema`
- [x] Если `schemaSql` содержит несколько SQL-инструкций, они применяются к каждому новому шарду в заданном порядке (например, сначала таблица, затем индекс). — покрыто: `CreateNewShardsTest::testMultipleSchemaStatementsAppliedInOrder`
- [x] Схема применяется к каждому шарду отдельно: запись, вставленная в шард №1, отсутствует в шарде №2. — покрыто: `CreateNewShardsTest::testSchemaIsAppliedPerShardIsolated`
- [x] Если `schemaSql` — пустой массив, создаются корректные пустые SQLite-файлы: `getIPDOByShard()` работает, запрос к `sqlite_master` не выбрасывает исключение, пользовательских таблиц нет. — покрыто: `CreateNewShardsTest::testEmptySchemaProducesValidEmptyFiles`
- [x] Уже существующие шарды не меняются: их `id`, состояние аренды, файлы и количество остаются прежними; количество увеличивается ровно на `count`. — покрыто: `CreateNewShardsTest::testExistingShardsUntouched`
- [x] Возвращённый массив можно перебирать повторно, и его значения не меняются после последующих вызовов `createNewShards()`. — покрыто: `CreateNewShardsTest::testResultOfFirstCallNotAffectedBySecond`
- [x] `count = 0` выбрасывает `InvalidArgumentException`; количество шардов и файлы не меняются. — покрыто: `CreateNewShardsTest::testZeroThrowsInvalidArgumentException`
- [x] `count = -1` выбрасывает `InvalidArgumentException`; количество шардов и файлы не меняются. — покрыто: `CreateNewShardsTest::testNegativeThrowsInvalidArgumentException`
- [x] Передача не-`int` при вызове из файла со `strict_types=1` выбрасывает `TypeError`; количество шардов и файлы не меняются. — покрыто: `CreateNewShardsTest::testNonIntThrowsTypeError`
- [x] Если в повреждённом реестре `schema_sql` содержит заведомо некорректный SQL, вызов выбрасывает `IPDOException`; файл текущего неудавшегося шарда удаляется, запись о нём не добавляется, количество шардов не меняется. — покрыто: `CreateNewShardsTest::testInvalidSchemaSqlThrowsAndCleansUp`
- [x] Если ошибка возникает на втором шарде (например, тестовый триггер в таблице `shards` запрещает вставку второй записи), выбрасывается `IPDOException`; первый уже созданный шард и его запись остаются, файл второго удаляется, количество шардов увеличивается только на `1`. — покрыто: `CreateNewShardsTest::testMultiShardFailureKeepsSuccessfulAndCleansFailed`
- [x] Если в повреждённом реестре отсутствует ключ `schema_sql`, вызов выбрасывает `KeyNotFoundKvException` до создания файлов; количество шардов и файлы не меняются. — покрыто: `CreateNewShardsTest::testMissingSchemaSqlKeyThrowsKeyNotFound`
- [x] Вызов с большим, но разумным `count` (например, `20`) создаёт 20 уникальных шардов, каждый со своим файлом. — покрыто: `CreateNewShardsTest::testManyShards`
- [x] После успешного вызова в `baseDir` не остаётся временных файлов, кроме файлов с `id` шардов и файла реестра. — покрыто: `CreateNewShardsTest::testNoStrayFilesAfterSuccess`

### `createNewShard`

- [x] Возвращает один объект `Shard`, увеличивает количество шардов ровно на `1`. — покрыто: `CreateNewShardTest::testReturnsOneShardAndIncrementsCount`
- [x] Новый шард свободен, имеет файл `baseDir/{id}.sqlite` и схему из `schemaSql`. — покрыто: `CreateNewShardTest::testNewShardIsFreeWithFileAndSchema`
- [x] Три последовательных вызова создают три разных шарда с уникальными `id`. — покрыто: `CreateNewShardTest::testThreeCallsCreateThreeDistinctShards`
- [x] Существующие шарды и их состояние не меняются. — покрыто: `CreateNewShardTest::testExistingShardsUntouched`
- [x] Ошибка при создании (некорректная схема в повреждённом реестре) выбрасывается наружу; частично созданный файл удаляется, запись не добавляется. — покрыто: `CreateNewShardTest::testSchemaFailureThrowsAndCleansUp`
- [x] Метод не меняет `min_shards` и не запускает дополнительное создание шардов. — покрыто: `CreateNewShardTest::testDoesNotChangeMinShardsAndCreatesExactlyOne`

### `getAllShards`

- [x] После `create()` с `minShards = 5` метод возвращает 5 шардов. — покрыто: `GetAllShardsTest::testReturnsAllShardsAfterCreate`
- [x] После `createNewShards(2)` метод возвращает 7 шардов; два новых присутствуют в результате. — покрыто: `GetAllShardsTest::testIncludesNewlyCreatedShards`
- [x] Все элементы — объекты `Shard`; их `id` соответствуют созданным, а `reg` указывает на текущий `Registry`. — покрыто: `GetAllShardsTest::testEachElementIsShardReferencingCurrentRegistry`
- [x] Метод возвращает шарды в любом состоянии: свободные, занятые, просроченные и освобождённые. — покрыто: `GetAllShardsTest::testReturnsShardsInAnyState`
- [x] Ключи возвращаемого массива — последовательные целые числа от `0` до `N - 1`. — покрыто: `GetAllShardsTest::testKeysAreSequentialIntegers`
- [x] Если в таблице нет записей (подготовка повреждённого/пустого реестра), метод возвращает пустой массив. — покрыто: `GetAllShardsTest::testEmptyRegistryReturnsEmptyArray`
- [x] Порядок элементов не гарантирован; тесты должны сравнивать наборы `id`, а не последовательность. — покрыто: `GetAllShardsTest::testOrderIsNotGuaranteed`
- [x] Два последовательных вызова возвращают разные объекты `Shard` с одинаковыми наборами `id`. — покрыто: `GetAllShardsTest::testEachCallReturnsNewShardObjects`
- [x] Шард, у которого удалён файл, но осталась запись, всё равно присутствует в результате. — покрыто: `GetAllShardsTest::testShardWithMissingFileIsStillReturned`
- [x] Метод не меняет состояние аренды, количество шардов и файлы. — покрыто: `GetAllShardsTest::testDoesNotChangeState`

### `getCountShards`

- [x] После `create()` с `minShards = 5` возвращается `5`. — покрыто: `GetCountShardsTest::testAfterCreateWithMinShardsFiveReturnsFive`
- [x] После `createNewShards(3)` возвращается `8`. — покрыто: `GetCountShardsTest::testAfterCreateNewShardsReturnsIncreasedCount`
- [x] Взятие аренды, продление и освобождение не меняют значение. — покрыто: `GetCountShardsTest::testRentHeartbeatReleaseDoNotChangeCount`
- [x] После удаления одной записи шарда напрямую из реестра возвращается на `1` меньше; при удалении всех записей — `0`. — покрыто: `GetCountShardsTest::testDeletingRowsChangesCount`
- [x] Возвращаемое значение имеет тип `int` (например, строго `5`, а не `'5'`). — покрыто: `GetCountShardsTest::testReturnValueIsInt`
- [x] Значение совпадает с количеством элементов `getAllShards()`. — покрыто: `GetCountShardsTest::testValueEqualsNumberOfAllShards`
- [x] Метод не создаёт шарды и не пытается довести их количество до `min_shards`. — покрыто: `GetCountShardsTest::testMethodDoesNotCreateShardsUpToMinShards`
- [x] Шард с отсутствующим файлом продолжает учитываться, пока существует его запись. — покрыто: `GetCountShardsTest::testShardWithMissingFileIsStillCounted`

### `getFreeShards`

- [x] Сразу после `create()` все шарды свободны и возвращаются все. — покрыто: `GetFreeShardsTest::testAllShardsAreFreeAfterCreate`
- [x] Если все шарды свежие и активные, возвращается пустой массив. — покрыто: `GetFreeShardsTest::testEmptyWhenAllShardsFreshAndActive`
- [x] В смешанном наборе свежий активный шард исключается, а неактивные и просроченные включаются. — покрыто: `GetFreeShardsTest::testMixedSet`
- [x] Активный шард, у которого `updated_at` равен `time() - rent_timeout`, включается (граница `<=`). — покрыто: `GetFreeShardsTest::testIncludesActiveShardOnTimeoutBoundary`
- [x] Активный шард с `updated_at` заметно новее границы (например, `time()`) исключается. — покрыто: `GetFreeShardsTest::testExcludesFreshActiveShard`
- [x] Неактивный шард с `updated_at` в будущем включается, потому что `active = 0` важнее времени. — покрыто: `GetFreeShardsTest::testIncludesInactiveShardWithFutureUpdatedAt`
- [x] Освобождённый шард включается. — покрыто: `GetFreeShardsTest::testIncludesReleasedShard`
- [x] Порядок результата — по возрастанию `updated_at`: при трёх свободных шардах с `updated_at` `50`, `40` и `30` секунд назад порядок именно такой. При одинаковых `updated_at` порядок не гарантирован. — покрыто: `GetFreeShardsTest::testOrderedByUpdatedAtAscending`
- [x] Все элементы — `Shard`; `reg` указывает на текущий `Registry`; ключи последовательные. — покрыто: `GetFreeShardsTest::testElementsAreShardsOfCurrentRegistry`
- [x] Для пустого реестра возвращается пустой массив. — покрыто: `GetFreeShardsTest::testEmptyRegistryReturnsEmptyArray`
- [x] Метод не меняет состояние: не арендует, не освобождает и не обновляет `updated_at`. — покрыто: `GetFreeShardsTest::testDoesNotChangeState`
- [x] Изменение `rent_timeout` сразу меняет состав результата: активный шард с `updated_at` `20` секунд назад при таймауте `30` отсутствует, после `setRentTimeout(10)` присутствует, после возврата `30` снова отсутствует. — покрыто: `GetFreeShardsTest::testChangingRentTimeoutChangesResult`
- [x] После `heartbeat` свежий шард остаётся исключённым; после `rent` исключён; после `release` снова включён. — покрыто: `GetFreeShardsTest::testHeartbeatRentReleaseCycle`
- [x] Отсутствие файла шарда не влияет на результат: учитывается только запись реестра. — покрыто: `GetFreeShardsTest::testMissingFileDoesNotAffectResult`
- [x] При очень большом `rent_timeout` (например, `PHP_INT_MAX`) активные шарды не попадают в результат, а неактивные попадают. — покрыто: `GetFreeShardsTest::testHugeRentTimeout`
- [x] Просроченный активный шард включается в результат, но остаётся активным до явного `release` или нового `rent`. — покрыто: `GetFreeShardsTest::testExpiredActiveShardIsIncludedButStaysActive`

### `existsShard`

- [x] Для существующего свободного шарда возвращается `true`. — покрыто: `ExistsShardTest::testReturnsTrueForExistingFreeShard`
- [x] Для существующего занятого шарда возвращается `true`. — покрыто: `ExistsShardTest::testReturnsTrueForBusyShard`
- [x] Для существующего просроченного и для освобождённого шарда возвращается `true`. — покрыто: `ExistsShardTest::testReturnsTrueForExpiredAndReleasedShards`
- [x] Для `Shard` с `id`, которого нет в реестре, возвращается `false` без исключения. — покрыто: `ExistsShardTest::testReturnsFalseForNonexistentId`
- [x] Для объекта `Shard` с `id` существующего шарда, но с `reg`, указывающим на другой реестр, возвращается `true`: метод проверяет только `id` в текущем реестре (текущее поведение). — покрыто: `ExistsShardTest::testResultDoesNotDependOnShardRegistryReference`
- [x] Для `Shard` с пустым `id` возвращается `false`. — покрыто: `ExistsShardTest::testReturnsFalseForEmptyId`
- [x] Если запись шарда удалена, но файл остался, возвращается `false`. — покрыто: `ExistsShardTest::testReturnsFalseAfterRowDeletedEvenIfFileRemains`
- [x] Если файл шарда удалён, но запись осталась, возвращается `true`. — покрыто: `ExistsShardTest::testReturnsTrueAfterFileDeleted`
- [x] Метод не меняет состояние аренды и не обращается к файлу шарда. — покрыто: `ExistsShardTest::testDoesNotChangeShardState`

### `isFree`

- [x] Для только что созданного шарда возвращается `true`. — покрыто: `IsFreeTest::testFreshlyCreatedShardIsFree`
- [x] Для свежего активного шарда возвращается `false`. — покрыто: `IsFreeTest::testFreshActiveShardIsNotFree`
- [x] Для активного шарда с `updated_at` равным `time() - rent_timeout` возвращается `true` (граница `<=`). — покрыто: `IsFreeTest::testActiveShardWithUpdatedAtOnBoundaryIsFree`
- [x] Для активного шарда с заметно более новым `updated_at` возвращается `false`. — покрыто: `IsFreeTest::testActiveShardWithFreshUpdatedAtIsNotFree`
- [x] Для освобождённого шарда возвращается `true` независимо от `updated_at`. — покрыто: `IsFreeTest::testReleasedShardIsFreeRegardlessOfUpdatedAt`
- [x] Для неактивного шарда с `updated_at` в будущем возвращается `true`. — покрыто: `IsFreeTest::testInactiveShardWithFutureUpdatedAtIsFree`
- [x] Для несуществующего шарда выбрасывается `ShardNotFoundException`; возврат `false` в этом случае недопустим. — покрыто: `IsFreeTest::testNonexistentShardThrowsShardNotFoundException`
- [x] Изменение `rent_timeout` сразу меняет результат согласно новому порогу. — покрыто: `IsFreeTest::testChangingRentTimeoutChangesResult`
- [x] После успешного `heartbeat` свежий активный шард остаётся занятым (`false`); после `release` становится свободным (`true`). — покрыто: `IsFreeTest::testHeartbeatKeepsShardBusyAndReleaseMakesItFree`
- [x] Отсутствие файла шарда не влияет на результат; проверяется только запись реестра. — покрыто: `IsFreeTest::testMissingShardFileDoesNotAffectResult`
- [x] Метод не меняет состояние шарда (не арендует и не освобождает). — покрыто: `IsFreeTest::testMethodDoesNotChangeShardState`
- [x] Для шарда, чей `id` существует в текущем реестре, результат определяется этим реестром, даже если объект `Shard` ссылается на другой `Registry`. — покрыто: `IsFreeTest::testResultDefinedByRegistryWhoseMethodWasCalled`

### `rent`

- [x] Для свободного шарда метод возвращает тот же объект `Registry`; после вызова `isFree` даёт `false`, `rentValid` — `true`, `leftTimeUntilRelease` — примерно `rent_timeout`, а `getFreeShards` больше не содержит шард. — покрыто: `RentTest::testRentOnFreeShardReturnsSameRegistryAndBusiesShard`
- [x] Для неактивного шарда с `updated_at` в будущем аренда успешна, потому что `active = 0` делает шард свободным. — покрыто: `RentTest::testRentOnInactiveShardWithFutureUpdatedAtSucceeds`
- [x] Для просроченного активного шарда аренда успешна; `updated_at` обновляется, остаток аренды снова примерно равен `rent_timeout`. — покрыто: `RentTest::testRentOnExpiredActiveShardSucceedsAndRenewsLease`
- [x] Для активного шарда с `updated_at` ровно `time() - rent_timeout` аренда успешна: `isFree` возвращает `true`, `rent` переводит шард в занятое состояние и обновляет `updated_at`. — покрыто: `RentTest::testRentOnActiveShardOnTimeoutBoundarySucceeds`
- [x] Для свежего активного шарда выбрасывается `ShardNotFreeException`; активность и `updated_at` не меняются. — покрыто: `RentTest::testRentOnFreshActiveShardThrowsShardNotFreeException`
- [x] Для несуществующего шарда выбрасывается `ShardNotFoundException`, а не `ShardNotFreeException`. — покрыто: `RentTest::testRentOnNonexistentShardThrowsShardNotFoundException`
- [x] После `release` тот же шард можно снова арендовать; аренда успешна и возвращает тот же объект `Registry`. — покрыто: `RentTest::testRentAfterReleaseSucceeds`, `ReleaseTest::testReleasedShardCanBeRentedAgain`
- [x] Повторный `rent` уже арендованного шарда выбрасывает `ShardNotFreeException`. — покрыто: `RentTest::testRepeatedRentOfBusyShardThrowsShardNotFreeException`
- [x] Два разных свободных шарда арендуются независимо; аренда одного не делает занятым другой. — покрыто: `RentTest::testRentOfOneFreeShardDoesNotBusyAnother`
- [x] После истечения аренды (например, `rent_timeout = 1` и пауза 2 секунды) повторный `rent` успешен и обновляет аренду. — покрыто: `RentTest::testRentAfterLeaseExpirationSucceedsAndRefreshesTimestamp`
- [x] При гонке двух экземпляров `Registry` проверяется инвариант «ровно один `rent` успешен, второй выбрасывает `ShardNotFreeException`, итоговое состояние — шард занят». Аренда «в одни руки» здесь не гарантируется, а тестируется: если атомарность `rent` нарушена, оба вызова могут пройти, и тест упадёт. — покрыто: `RentTest::testRentRaceSecondRegistryInstanceLoses` (параллельно: два потока inilim/parallel, старт выравнен по миллисекунде)
- [x] Отсутствие файла шарда не мешает аренде: метод работает только с записью реестра и не создаёт файл заново. — покрыто: `RentTest::testRentSucceedsWhenShardFileIsMissing`
- [x] Количество шардов не меняется. — покрыто: `RentTest::testRentDoesNotChangeShardCount`
- [x] Сообщение `ShardNotFreeException` содержит `id` арендуемого шарда; сообщение `ShardNotFoundException` — `id` отсутствующего. — покрыто: `RentTest::testShardNotFreeExceptionMessageContainsShardId`, `RentTest::testShardNotFoundExceptionMessageContainsShardId`
- [x] Изменение `rent_timeout` меняет, какие шарды можно арендовать. — покрыто: `RentTest::testChangingRentTimeoutChangesRentability`
- [x] Аренда одного шарда не меняет состояние и `updated_at` других шардов. — покрыто: `RentTest::testRentOfOneShardLeavesOtherShardsUntouched`
- [x] Метод поддерживает цепочку: возвращаемое значение можно использовать как `Registry`. — покрыто: `RentTest::testRentReturnsRegistryForMethodChaining`

### `tryRent`

- [x] Для свободного шарда возвращает `true`, и шард действительно становится арендованным (`isFree` — `false`, `rentValid` — `true`). — покрыто: `TryRentTest::testTryRentOnFreeShardReturnsTrueAndRents`
- [x] Для свежего активного шарда возвращает `false`; состояние шарда не меняется. — покрыто: `TryRentTest::testTryRentOnFreshActiveShardReturnsFalseAndKeepsState`
- [x] Для просроченного активного шарда возвращает `true` и продлевает аренду. — покрыто: `TryRentTest::testTryRentOnExpiredActiveShardReturnsTrueAndRenews`
- [x] Для освобождённого шарда возвращает `true` и арендует его. — покрыто: `TryRentTest::testTryRentOnReleasedShardReturnsTrue`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`: метод не превращает ошибку отсутствия в `false`. — покрыто: `TryRentTest::testTryRentOnNonexistentShardThrowsShardNotFoundException`
- [x] После успешного вызова повторный вызов возвращает `false`. — покрыто: `TryRentTest::testSecondTryRentAfterSuccessReturnsFalse`
- [x] При гонке двух экземпляров проверяется: ровно один `tryRent` возвращает `true`, второй — `false` (внутри проигравшего `ShardNotFreeException` превращается в `false`). Это не гарантия, а детектор: если `rent` не атомарен, оба вызова вернут `true`, и тест упадёт. — покрыто: `TryRentTest::testTryRentRaceSecondInstanceReturnsFalse` (параллельно: два потока inilim/parallel)
- [x] В отличие от `rent`, не выбрасывает `ShardNotFreeException`; другие исключения не перехватывает. — покрыто: `TryRentTest::testTryRentReturnsFalseForBusyShardInsteadOfException`, `TryRentTest::testTryRentOnNonexistentShardThrowsShardNotFoundException`
- [x] Возвращает именно `bool`: `true` или `false`. — покрыто: `TryRentTest::testTryRentReturnsBool`
- [x] Отсутствие файла шарда не мешает успешной аренде. — покрыто: `TryRentTest::testTryRentSucceedsWhenShardFileIsMissing`
- [x] Изменение `rent_timeout` меняет результат для того же шарда. — покрыто: `TryRentTest::testChangingRentTimeoutChangesTryRentResult`

### `rentFree`

- [x] При нескольких свободных шардах возвращает один из них, арендует ровно один шард, остальные остаются свободными; возвращённый шард имеет `reg`, указывающий на текущий `Registry`. — покрыто: `RentFreeTest::testRentFreeRentsExactlyOneOfFreeShards`, `RentFreeTest::testRentFreeReturnsShardInstance`
- [x] При одном свободном шарде среди занятых возвращает именно этот шард и арендует его. — покрыто: `RentFreeTest::testRentFreeReturnsTheOnlyFreeShard`
- [x] Если свободных шардов нет, выбрасывает `ShardNotFreeException` с сообщением `There are no free shards`; количество и состояние шардов не меняются. — покрыто: `RentFreeTest::testRentFreeWithNoFreeShardsThrowsAndKeepsState`
- [x] Для пустого реестра (ни одной записи) также выбрасывает `ShardNotFreeException` с тем же сообщением. — покрыто: `RentFreeTest::testRentFreeOnEmptyRegistryThrows`
- [x] Если есть только просроченные активные шарды, возвращает один из них и успешно арендует. — покрыто: `RentFreeTest::testRentFreeRentsExpiredActiveShard`
- [x] Если есть освобождённый шард, возвращает его. — покрыто: `RentFreeTest::testRentFreeReturnsReleasedShard`
- [x] При нескольких последовательных вызовах арендует разные свободные шарды; когда свободные заканчиваются, следующий вызов выбрасывает `ShardNotFreeException`. — покрыто: `RentFreeTest::testConsecutiveRentFreeCallsRentDifferentShardsUntilExhausted`
- [x] После успешного вызова `getFreeShards` не содержит возвращённый шард, а количество свободных уменьшается на один. — покрыто: `RentFreeTest::testFreeShardsShrinkByOneAfterRentFree`
- [x] Без гонки выбирает первый элемент `getFreeShards`, то есть шард с наименьшим `updated_at`; при одинаковых `updated_at` конкретный выбор не гарантирован. — покрыто: `RentFreeTest::testRentFreePicksOldestFreeShard`
- [x] Если у кандидата отсутствует файл, аренда всё равно успешна, потому что проверяется только запись реестра. — покрыто: `RentFreeTest::testRentFreeSucceedsWhenCandidateShardFileMissing`
- [x] Изменение `rent_timeout` меняет набор кандидатов и результат. — покрыто: `RentFreeTest::testChangingRentTimeoutChangesRentFreeCandidate`
- [x] При гонке, когда свободен ровно один шард, проверяется: ровно один вызов возвращает `Shard`, второй выбрасывает `ShardNotFreeException` с сообщением `There are no free shards` (когда все попытки кандидатов проиграны). Это не гарантия: если один шард ушёл нескольким арендаторам, победителей станет больше и тест упадёт. — покрыто: `RentFreeTest::testRentFreeRaceAllCandidatesRentedByAnotherInstance` (параллельно: два потока inilim/parallel)
- [x] Метод не создаёт новые шарды. — покрыто: `RentFreeTest::testRentFreeDoesNotCreateShards`

### `tryRentFree`

- [x] При наличии свободного шарда возвращает этот `Shard`, и шард становится арендованным. — покрыто: `TryRentFreeTest::testTryRentFreeReturnsFreeShardAndRentsIt`
- [x] Если свободных шардов нет, возвращает `null` без исключения; состояние шардов не меняется. — покрыто: `TryRentFreeTest::testTryRentFreeWithNoFreeShardsReturnsNullAndKeepsState`
- [x] Если все шарды заняты, возвращает `null`. — покрыто: `TryRentFreeTest::testTryRentFreeWhenAllShardsAreBusyReturnsNull`
- [x] Для пустого реестра возвращает `null`. — покрыто: `TryRentFreeTest::testTryRentFreeOnEmptyRegistryReturnsNull`
- [x] Если освободить арендованный шард, следующий вызов возвращает именно его (или другой свободный, если их несколько). — покрыто: `TryRentFreeTest::testTryRentFreeReturnsReleasedShard`
- [x] При последовательных вызовах возвращает шарды, пока они есть; после исчерпания возвращает `null`. — покрыто: `TryRentFreeTest::testConsecutiveCallsRentDifferentShardsUntilExhausted`
- [x] При `null` не меняет количество, аренду и `updated_at` шардов. — покрыто: `TryRentFreeTest::testNullResultDoesNotChangeCountRentAndUpdatedAt`
- [x] Перехватывает только `ShardNotFreeException`; другие исключения (например, `ShardNotFoundException` при удалении записи между получением списка и арендой) не превращает в `null`. — покрыто: `TryRentFreeTest::testTryRentFreeDoesNotSwallowOtherExceptions` (проверено на примере `IPDOException` при удалении таблицы `shards`)
- [x] При гонке, когда свободен ровно один шард, проверяется: один вызов возвращает `Shard`, второй — `null`. Это не гарантия: один и тот же шард может попасть в несколько рук, и тогда оба вызова вернут один `Shard` — тест упадёт по числу победителей. — покрыто: `TryRentFreeTest::testTryRentFreeRaceAllCandidatesRentedByAnotherInstance` (параллельно: два потока inilim/parallel)
- [x] Возвращаемый тип — `?Shard`: либо объект, либо `null`. — покрыто: `TryRentFreeTest::testReturnTypeIsNullableShard`
- [x] Метод не создаёт шарды. — покрыто: `TryRentFreeTest::testTryRentFreeDoesNotCreateShards`

### `getIPDOByShard`

- [x] Для существующего шарда возвращает объект `IPDOSQLite`. — покрыто: `GetIPDOByShardTest::testReturnsIPDOSQLiteInstance`
- [x] `getMainFile()` возвращает путь `baseDir/{id}.sqlite` в нормализованном виде. — покрыто: `GetIPDOByShardTest::testMainFileMatchesShardPath`
- [x] Соединение создаётся лениво: сразу после возврата `isConnected()` даёт `false`; после любого запроса, включая `getMainFile()`, — `true`. — покрыто: `GetIPDOByShardTest::testLazyConnection` (ленивость проверена через `isConnected()` до/после первого запроса)
- [x] В возвращённом SQLite доступна схема из `schemaSql`: таблица `items` существует, по ней выполняются `SELECT` и `INSERT`. — покрыто: `GetIPDOByShardTest::testSchemaIsUsable`
- [x] Запись, сделанная через один полученный объект, видна через второй объект, полученный повторным вызовом для того же шарда. — покрыто: `GetIPDOByShardTest::testWritesVisibleAcrossInstances`
- [x] Два вызова возвращают разные объекты `IPDOSQLite`, работающие с одним и тем же файлом. — покрыто: `GetIPDOByShardTest::testEachCallReturnsNewInstance`
- [x] Метод не требует аренды: работает для свободного, занятого, просроченного и освобождённого шарда. — покрыто: `GetIPDOByShardTest::testWorksInAnyRentState`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException` даже при наличии файла с таким `id` на диске. — покрыто: `GetIPDOByShardTest::testNonexistentShardThrowsEvenIfFileExists`
- [x] Если запись есть, а файл удалён, метод всё равно возвращает объект `IPDOSQLite`; первая операция с этим объектом выбрасывает `IPDOException` с сообщением о том, что файл не найден. Сам вызов `getIPDOByShard` файл не пересоздаёт. — покрыто: `GetIPDOByShardTest::testMissingFileThrowsOnFirstOperation`
- [x] Если файл существует, но не является SQLite-базой (например, случайные байты), метод возвращает объект, а первый запрос, читающий содержимое базы (например, `SELECT` из `items` или `sqlite_master`), выбрасывает `IPDOException`. — покрыто: `GetIPDOByShardTest::testCorruptFileThrowsOnQuery`
- [x] Для шарда с пустой схемой возвращённый SQLite корректен: запрос к `sqlite_master` успешен, пользовательских таблиц нет. — покрыто: `GetIPDOByShardTest::testEmptySchemaProducesValidSQLite`
- [x] Закрытие возвращённого объекта (`close`) не влияет на реестр и другие соединения. — покрыто: `GetIPDOByShardTest::testCloseDoesNotAffectOtherInstances`
- [x] Метод не меняет состояние аренды шарда. — покрыто: `GetIPDOByShardTest::testDoesNotChangeStateOrCreateFiles`
- [x] Изменения, внесённые через возвращённый объект, сохраняются на диске и видны после повторного получения объекта. — покрыто: `GetIPDOByShardTest::testChangesPersistToDisk`

### `release`

- [x] Для арендованного шарда возвращает тот же объект `Registry`; после вызова `isFree` — `true`, `rentValid` — `false`, `leftTimeUntilRelease` — `-1`, а `updated_at` становится текущим временем. — покрыто: `ReleaseTest::testReleasedBusyShardBecomesFree`
- [x] `updated_at` обновляется при освобождении активного шарда: шард A активен с `updated_at` `100` секунд назад, шард B неактивен с `updated_at` `50` секунд назад; после `release(A)` в `getFreeShards` B идёт раньше A, потому что `updated_at` A стал текущим временем. — покрыто: `ReleaseTest::testActiveShardGetsFreshTimestampOnRelease`
- [x] Для уже свободного шарда метод не выбрасывает исключение, возвращает тот же объект `Registry` и не меняет `updated_at`: если A неактивен с `updated_at` `100` секунд назад, а B неактивен с `updated_at` `50` секунд назад, после `release(A)` A по-прежнему идёт раньше B. — покрыто: `ReleaseTest::testReleaseOfFreeShardDoesNotTouchTimestamp`
- [x] Для просроченного активного шарда переводит его в неактивное состояние и обновляет `updated_at` текущим временем; `leftTimeUntilRelease` становится `-1`. — покрыто: `ReleaseTest::testReleaseOfExpiredActiveShard`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`. — покрыто: `ReleaseTest::testNonexistentShardThrows`
- [x] Количество шардов не меняется; `existsShard` продолжает возвращать `true`; файл шарда остаётся. — покрыто: `ReleaseTest::testDoesNotRemoveShard`
- [x] Освобождённый шард можно снова арендовать через `rent` или `rentFree`. — покрыто: `ReleaseTest::testReleasedShardCanBeRentedAgain` (через `rent`), `RentFreeTest::testRentFreeReturnsReleasedShard` (через `rentFree`)
- [x] Повторный `release` того же шарда не выбрасывает исключение; второй вызов не меняет `updated_at`, потому что шард уже неактивен. — покрыто: `ReleaseTest::testRepeatedReleaseIsNoOp`
- [x] После `release` вызов `heartbeat` и `heartbeat_m2` для этого шарда выбрасывает `ShardRentLostException`. — покрыто: `ReleaseTest::testHeartbeatAfterReleaseThrowsRentLost`
- [x] Освобождение одного шарда не меняет состояние и `updated_at` других шардов. — покрыто: `ReleaseTest::testOtherActiveShardsUntouched`
- [x] Отсутствие файла шарда не мешает освобождению: метод работает с записью реестра. — покрыто: `ReleaseTest::testMissingFileDoesNotPreventRelease`
- [x] Если шард неактивен, но `updated_at` в будущем, повторный `release` оставляет его неактивным и не меняет `updated_at`. — покрыто: `ReleaseTest::testReleaseOfInactiveFutureShardKeepsItInactive`
- [x] Метод поддерживает цепочку и возвращает тот же объект `Registry`. — покрыто: `ReleaseTest::testChainingIsSupported`

### `leftTimeUntilRelease`

- [x] Для только что созданного свободного шарда возвращает `-1`. — покрыто: `LeftTimeUntilReleaseTest::testFreeShardReturnsMinusOne`
- [x] Для освобождённого шарда возвращает `-1`. — покрыто: `LeftTimeUntilReleaseTest::testReleasedShardReturnsMinusOne`
- [x] Для неактивного шарда с `updated_at` в будущем возвращает `-1`. — покрыто: `LeftTimeUntilReleaseTest::testInactiveShardWithFutureTimestampReturnsMinusOne`
- [x] Для только что арендованного шарда возвращает значение от `rent_timeout - 1` до `rent_timeout` включительно. — покрыто: `LeftTimeUntilReleaseTest::testFreshlyRentedReturnsAboutRentTimeout`
- [x] Для активного шарда с `updated_at` `10` секунд назад и таймаутом `30` возвращает примерно `20` (допуск 1–2 секунды). — покрыто: `LeftTimeUntilReleaseTest::testActiveTenSecondsAgoReturnsTwenty`
- [x] Для активного шарда с `updated_at` ровно `time() - rent_timeout` возвращает `0`. — покрыто: `LeftTimeUntilReleaseTest::testBoundaryReturnsZero`
- [x] Для активного шарда с `updated_at` раньше границы возвращает `0`, а не отрицательное число. — покрыто: `LeftTimeUntilReleaseTest::testExpiredActiveReturnsZero`
- [x] Для активного шарда с `updated_at` в будущем возвращает значение больше `rent_timeout`; верхней границы у результата нет. — покрыто: `LeftTimeUntilReleaseTest::testActiveWithFutureTimestampReturnsMoreThanTimeout`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`. — покрыто: `LeftTimeUntilReleaseTest::testNonexistentShardThrows`
- [x] Изменение `rent_timeout` сразу меняет результат: при `updated_at` `10` секунд назад и таймауте `30` — около `20`; после `setRentTimeout(10)` — `0`; после `setRentTimeout(300)` — около `290`. — покрыто: `LeftTimeUntilReleaseTest::testChangingRentTimeoutChangesResult`
- [x] После успешного `heartbeat` возвращает примерно `rent_timeout`; после успешного `heartbeat_m2` с продлением — также примерно `rent_timeout`. — покрыто: `LeftTimeUntilReleaseTest::testHeartbeatResetsLeftTime`
- [x] Если `heartbeat_m2` не продлевал аренду, остаток не сбрасывается, а уменьшается на прошедшее время. — покрыто: `LeftTimeUntilReleaseTest::testHeartbeatM2WithoutRenewalKeepsLeftTime`
- [x] После повторного `rent` остаток снова примерно равен `rent_timeout`. — покрыто: `LeftTimeUntilReleaseTest::testRentAgainResetsLeftTime`
- [x] Отсутствие файла шарда не влияет на результат. — покрыто: `LeftTimeUntilReleaseTest::testMissingFileDoesNotAffectResult`
- [x] `minSecHeartbeat` не влияет на результат. — покрыто: `LeftTimeUntilReleaseTest::testMinSecHeartbeatDoesNotAffectLeftTime`
- [x] Всегда возвращает `int`; допустимые значения — `-1` либо число не меньше `0`. — покрыто: `LeftTimeUntilReleaseTest::testAlwaysReturnsInt`

### `rentValid`

- [x] Для только что арендованного шарда возвращает `true`. — покрыто: `RentValidTest::testFreshlyRentedReturnsTrue`
- [x] Для только что созданного свободного шарда возвращает `false`. — покрыто: `RentValidTest::testFreeShardReturnsFalse`
- [x] Для освобождённого шарда возвращает `false`. — покрыто: `RentValidTest::testReleasedShardReturnsFalse`
- [x] Для неактивного шарда с `updated_at` в будущем возвращает `false` (`active = 0` важнее времени). — покрыто: `RentValidTest::testInactiveShardWithFutureTimestampReturnsFalse`
- [x] Для свежего активного шарда возвращает `true`. — покрыто: `RentValidTest::testActiveWithCurrentTimestampReturnsTrue`
- [x] Для активного шарда с `updated_at` ровно `time() - rent_timeout` возвращает `false` (граница `>`). — покрыто: `RentValidTest::testActiveOnTimeoutBoundaryReturnsFalse`
- [x] Для активного шарда с заметно более старым `updated_at` возвращает `false`. — покрыто: `RentValidTest::testExpiredActiveReturnsFalse`
- [x] Для несуществующего шарда возвращает `false` и не выбрасывает исключение; это отличие от `isFree`, `leftTimeUntilRelease`, `heartbeat` и `release`. — покрыто: `RentValidTest::testNonexistentShardReturnsFalseWithoutThrowing`
- [x] Отсутствие файла шарда не влияет на результат. — покрыто: `RentValidTest::testMissingFileDoesNotAffectResult`
- [x] После успешного `heartbeat` возвращает `true`. — покрыто: `RentValidTest::testHeartbeatKeepsRentValid`
- [x] После истечения аренды (например, `rent_timeout = 1` и пауза 2 секунды) возвращает `false`. — покрыто: `RentValidTest::testLeaseExpirationMakesRentInvalid`
- [x] Увеличение таймаута может вернуть `true` для активного шарда, который был просрочен: при `updated_at` `35` секунд назад `rentValid` сначала `false`, после `setRentTimeout(60)` — `true`. — покрыто: `RentValidTest::testIncreasingRentTimeoutMakesRentValid`
- [x] Уменьшение таймаута может вернуть `false`: при `updated_at` `20` секунд назад и таймауте `30` — `true`, после `setRentTimeout(10)` — `false`. — покрыто: `RentValidTest::testDecreasingRentTimeoutMakesRentInvalid`
- [x] Метод не меняет состояние шарда. — покрыто: `RentValidTest::testDoesNotChangeState`
- [x] Второй экземпляр `Registry` видит актуальное состояние для аренды, сделанной первым: у его объекта шарда токена нет, поэтому `rentValid` возвращает `false` (аренда не его), а занятость видна через `isFree` — `false` во время аренды и `true` после `release`. — покрыто: `RentValidTest::testSecondInstanceSeesUpdatedState`

### `heartbeat`

- [x] Для свежего активного шарда возвращает тот же объект `Registry`, обновляет `updated_at`, после чего `leftTimeUntilRelease` примерно равно `rent_timeout`, а `rentValid` — `true`. — покрыто: `HeartbeatTest::testHeartbeatRenewsFreshLease`
- [x] Для активного шарда с `updated_at` `20` секунд назад и таймаутом `30` (остаток около `10`) успешно продлевает аренду: остаток возвращается к примерно `30`. — покрыто: `HeartbeatTest::testHeartbeatRenewsAlmostExpiredLease`
- [x] Повторный вызов через секунду снова успешен; `updated_at` продвигается вперёд. — покрыто: `HeartbeatTest::testRepeatedHeartbeatAdvancesTimestamp`
- [x] Для свободного шарда выбрасывает `ShardRentLostException`; активность и `updated_at` не меняются. — покрыто: `HeartbeatTest::testFreeShardThrowsRentLost`
- [x] Для просроченного активного шарда выбрасывает `ShardRentLostException`; шард остаётся активным и просроченным, `updated_at` не меняется. — покрыто: `HeartbeatTest::testExpiredActiveThrowsRentLost`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`, а не `ShardRentLostException`. — покрыто: `HeartbeatTest::testNonexistentShardThrowsNotFound`
- [x] Для освобождённого шарда выбрасывает `ShardRentLostException`. — покрыто: `HeartbeatTest::testReleasedShardThrowsRentLost`
- [x] При `rent_timeout = 1` сразу после аренды вызов успешен; после паузы 2 секунды — `ShardRentLostException`. — покрыто: `HeartbeatTest::testTinyTimeout`
- [x] Для активного шарда с `updated_at` ровно `time() - rent_timeout` выбрасывает `ShardRentLostException` (граница `>`). — покрыто: `HeartbeatTest::testBoundaryThrowsRentLost`
- [x] При очень большом `rent_timeout` (например, `PHP_INT_MAX`) продление успешно. — покрыто: `HeartbeatTest::testHugeRentTimeoutSucceeds`
- [x] Отсутствие файла шарда не мешает продлению: метод работает с записью реестра. — покрыто: `HeartbeatTest::testMissingShardFileDoesNotPreventRenewal`
- [x] При неудаче состояние шарда и других шардов не меняется; шард не переводится в неактивное состояние. — покрыто: `HeartbeatTest::testFailedHeartbeatLeavesEverythingUnchanged`
- [x] Сообщение `ShardRentLostException` содержит `id` шарда. — покрыто: `HeartbeatTest::testExceptionMessageContainsShardId`
- [x] Метод возвращает тот же объект `Registry`; поддерживает цепочку. — покрыто: `HeartbeatTest::testChainingSucceedsForActiveShard`

### `heartbeat_m2`

- [x] При остатке аренды заметно больше `minSecHeartbeat` (например, `20` при `minSecHeartbeat = 10`) возвращает тот же объект `Registry` и не обновляет `updated_at`: остаток после вызова остаётся примерно прежним, а не сбрасывается к `rent_timeout`. — покрыто: `HeartbeatM2Test::testDoesNotRenewWhenLeftTimeAboveThreshold`
- [x] При остатке меньше `minSecHeartbeat` (например, `5` при `10`) продлевает аренду: остаток становится примерно `rent_timeout`. — покрыто: `HeartbeatM2Test::testRenewsWhenLeftTimeBelowThreshold`
- [x] При остатке ровно `minSecHeartbeat` (`10` при `10`) продлевает аренду (граница `<=`). — покрыто: `HeartbeatM2Test::testRenewsAtExactThreshold`
- [x] При остатке `11` при `minSecHeartbeat = 10` не продлевает аренду; кейс чувствителен к пересечению секунды и должен выполняться сразу либо с контролем времени. — покрыто: `HeartbeatM2Test::testDoesNotRenewJustAboveThreshold` (остаток ~12)
- [x] Для свободного шарда (остаток `-1`) выбрасывает `ShardRentLostException`. — покрыто: `HeartbeatM2Test::testFreeShardThrowsRentLost`
- [x] Для просроченного активного шарда (остаток `0`) выбрасывает `ShardRentLostException`; `updated_at` не меняется. — покрыто: `HeartbeatM2Test::testExpiredActiveThrowsRentLost`
- [x] Для освобождённого шарда выбрасывает `ShardRentLostException`. — покрыто: `HeartbeatM2Test::testReleasedShardThrowsRentLost`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`. — покрыто: `HeartbeatM2Test::testNonexistentShardThrowsNotFound`
- [x] После `setMinSecHeartbeat(5)`: при остатке `20` продления нет; при остатке `3` продление есть; при остатке ровно `5` продление есть. — покрыто: `HeartbeatM2Test::testCustomThreshold`
- [] После `setMinSecHeartbeat(1)`: при остатке `5` продления нет; при остатке ровно `1` продление есть. — НЕ покрыто: нет теста с порогом `1`. Ближайший аналог — `HeartbeatM2Test::testSmallThreshold` с порогом `3` (остаток `5` без продления, остаток `2` с продлением), но кейс с порогом `1` не реализован.
- [x] Значение по умолчанию — `10`: при остатке `20` продления нет, при остатке `5` продление есть. — покрыто: `HeartbeatM2Test::testDefaultThresholdIsTen` + `HeartbeatM2Test::testDoesNotRenewWhenLeftTimeAboveThreshold` + `HeartbeatM2Test::testRenewsWhenLeftTimeBelowThreshold`
- [x] Если несколько раз вызывать метод при остатке больше порога, аренда не продлевается и через время истекает; затем вызов выбрасывает `ShardRentLostException`. — покрыто: `HeartbeatM2Test::testEventuallyExpiresWithoutRenewal`
- [x] После успешного продления немедленный повторный вызов не продлевает аренду, потому что при `minSecHeartbeat < rent_timeout` остаток снова больше порога. — покрыто: `HeartbeatM2Test::testNoRenewalAccumulation`
- [x] Изменение `rent_timeout` меняет решение: при `updated_at` `24` секунды назад и таймауте `30` остаток `6` (продление), после `setRentTimeout(60)` остаток `36` (без продления). — покрыто: `HeartbeatM2Test::testChangingRentTimeoutChangesThresholdReachability`
- [x] Если `minSecHeartbeat` больше либо равен `rent_timeout`, то у только что арендованного или продлённого шарда (`updated_at` примерно равен текущему времени) остаток всегда меньше либо равен порогу, и каждое успешное обращение продлевает аренду. — покрыто: `HeartbeatM2Test::testThresholdLargerThanTimeout`
- [x] При неудаче шард не переводится в неактивное состояние и `updated_at` не меняется. — покрыто: `HeartbeatM2Test::testFailureDoesNotDeactivateShard`
- [x] Метод возвращает тот же объект `Registry`; поддерживает цепочку. — покрыто: `HeartbeatM2Test::testChainingSucceedsForActiveShard`

### `getAllShardAsPtf`

- [x] Возвращает `\Generator`. — покрыто: `GetAllShardAsPtfTest::testReturnsGenerator`
- [x] Для пустого реестра генератор не выдаёт ни одной пары. — покрыто: `GetAllShardAsPtfTest::testEmptyRegistryYieldsNothing`
- [x] Для `N` шардов выдаёт ровно `N` пар; ключи — `id` шардов, значения — пути к файлам. — покрыто: `GetAllShardAsPtfTest::testYieldsIdToPathPairs`
- [x] Каждый путь соответствует `baseDir/{id}.sqlite` в нормализованном виде, и файл по этому пути существует. — покрыто: `GetAllShardAsPtfTest::testEachPathIsAnExistingFile`
- [x] В результат входят шарды в любом состоянии аренды. — покрыто: `GetAllShardAsPtfTest::testIncludesShardsInAnyRentState`
- [x] Генератор ленивый: при создании объекта запрос к реестру не выполняется; шард, добавленный до первого обращения к генератору, попадает в результат. — покрыто: `GetAllShardAsPtfTest::testShardAddedBeforeIterationIsIncluded`
- [x] Шард, добавленный после начала итерации, уже не попадает в текущую итерацию, потому что список шардов был получен в начале. — покрыто: `GetAllShardAsPtfTest::testShardAddedAfterIterationStartedIsNotIncluded`
- [x] Если файл шарда отсутствует, пара всё равно выдаётся: метод не проверяет наличие файла. — покрыто: `GetAllShardAsPtfTest::testMissingFileStillYieldsPair`
- [x] Повторная итерация того же генератора невозможна (поведение `\Generator`). — покрыто: `GetAllShardAsPtfTest::testGeneratorCannotBeReiterated`
- [x] Порядок пар соответствует `getAllShards` и не гарантирован. — покрыто: `GetAllShardAsPtfTest::testKeySetMatchesAllShardIds`
- [x] Метод не открывает соединения и не меняет состояние реестра. — покрыто: `GetAllShardAsPtfTest::testDoesNotChangeState`

### `getAllShardAsIPDO`

- [x] Возвращает `\Generator`. — покрыто: `GetAllShardAsIPDOTest::testReturnsGenerator`
- [x] Для пустого реестра генератор не выдаёт ни одной пары. — покрыто: `GetAllShardAsIPDOTest::testEmptyRegistryYieldsNothing`
- [x] Для `N` шардов выдаёт ровно `N` пар; ключи — `id` шардов, значения — объекты `IPDOSQLite`. — покрыто: `GetAllShardAsIPDOTest::testYieldsIdToIPDOPairs`
- [x] Для каждого значения `getMainFile()` указывает на `baseDir/{id}.sqlite`; схема из `schemaSql` доступна. — покрыто: `GetAllShardAsIPDOTest::testEachIPDOPointsToShardFileWithSchema`
- [x] Записи в разных шардах изолированы: вставка через первый `IPDOSQLite` не появляется во втором. — покрыто: `GetAllShardAsIPDOTest::testShardsAreIsolated`
- [x] В результат входят шарды в любом состоянии аренды. — покрыто: `GetAllShardAsIPDOTest::testIncludesShardsInAnyRentState`
- [x] Генератор ленивый: шард, добавленный до первого обращения, попадает в результат; добавленный после начала итерации — нет. — покрыто: `GetAllShardAsIPDOTest::testLaziness`
- [x] Если файл шарда удалён, пара всё равно выдаётся; первая операция с полученным `IPDOSQLite` выбрасывает `IPDOException`. — покрыто: `GetAllShardAsIPDOTest::testDeletedShardFileThrowsOnOperation`
- [x] Каждый вызов создаёт новый объект `IPDOSQLite`; соединение не открывается до первого запроса. — покрыто: `GetAllShardAsIPDOTest::testEachCallReturnsFreshLazyInstances`
- [x] Закрытие одного полученного объекта не влияет на другие. — покрыто: `GetAllShardAsIPDOTest::testCloseDoesNotAffectOthers`
- [x] Порядок пар соответствует `getAllShards` и не гарантирован. — покрыто: `GetAllShardAsIPDOTest::testKeySetMatchesAllShardIds`
- [x] Метод не меняет состояние аренды и не создаёт файлы. — покрыто: `GetAllShardAsIPDOTest::testDoesNotChangeStateOrCreateFiles`

### `discover`

- [x] Для существующего реестра, созданного через `create()`, возвращает объект `Registry` с тем же `name`, тем же `baseDir` и тем же набором `id` шардов, что и при создании. — покрыто: `DiscoverTest::testReturnsExistingRegistry`
- [x] Если шард арендован через первый экземпляр, второй экземпляр, полученный через `discover()`, видит его занятым: `isFree` — `false`, `leftTimeUntilRelease` больше `0`. — покрыто: `DiscoverTest::testRentStateVisibleToSecondInstance`
- [x] Сохранённый `rent_timeout` используется: если реестр создан с таймаутом `60` и у активного шарда `updated_at` `45` секунд назад, `discover()` возвращает реестр, в котором шард занят. — покрыто: `DiscoverTest::testSavedRentTimeoutUsedByNewInstance`
- [x] `minSecHeartbeat` у нового экземпляра равно `10`, даже если на предыдущем экземпляре было установлено другое значение. — покрыто: `DiscoverTest::testMinSecHeartbeatIsTenForNewInstance`
- [x] Если количество шардов равно сохранённому `min_shards`, `discover()` не создаёт новых шардов: набор `id` не меняется. — покрыто: `DiscoverTest::testNoNewShardsWhenCountIsSufficient`
- [x] Если количество шардов больше сохранённого `min_shards`, `discover()` не удаляет шарды и не меняет файлы. — покрыто: `DiscoverTest::testMoreShardsThanMinimumAreKept`
- [x] Если количество шардов меньше сохранённого `min_shards`, `discover()` создаёт недостающие шарды; возвращаемый реестр имеет ровно `min_shards` шардов, новые шарды свободны, имеют файлы и схему, а старые шарды не изменены. — покрыто: `DiscoverTest::testFillsMissingShardsUpToSavedMinimum`
- [x] Повторный `discover()` после заполнения не создаёт дополнительных шардов (идемпотентность при неизменном `min_shards`). — покрыто: `DiscoverTest::testRepeatedDiscoverIsStable`
- [x] Для неизвестного имени возвращает `null`; файл реестра и файлы шардов не создаются. — покрыто: `DiscoverTest::testUnknownNameReturnsNullWithoutCreatingFiles`
- [x] Для имени, у которого нет своего реестра, но в той же директории есть реестр с другим именем (созданный через `create()`), возвращает `null`; чужой реестр не изменяется. — покрыто: `DiscoverTest::testOtherRegistryInDirNotUsed`
- [x] Для несуществующей `baseDir` выбрасывает `InvalidArgumentException` (путь не является директорией); ничего не создаётся. — покрыто: `DiscoverTest::testNonexistentBaseDirThrows`
- [x] Для `baseDir`, указывающей на файл, выбрасывает `InvalidArgumentException`. — покрыто: `DiscoverTest::testBaseDirIsFileThrows`
- [x] Для пустого `name` выбрасывает `InvalidArgumentException`. — покрыто: `DiscoverTest::testEmptyNameThrows`
- [x] Для пустого файла с ожидаемым именем `registry-{sha1(name)}.sqlite` выбрасывает `RegistryInvalidException`; сообщение содержит имя, путь, список `kv, shards`, пространство имён `Inilim\SqliteShard` и подсказку про `Inilim\SqliteShard\Registry::create()`. Файл не изменяется. — покрыто: `DiscoverTest::testEmptyFileThrowsRegistryInvalidWithDetailedMessage`
- [x] Если в файле есть только таблица `shards` (нет `kv`), выбрасывает `RegistryInvalidException`; в сообщении отсутствующая таблица `kv`. — покрыто: `DiscoverTest::testMissingKvTableThrows`
- [x] Если в файле есть только `kv` (нет `shards`), выбрасывает `RegistryInvalidException`; в сообщении отсутствующая таблица `shards`. — покрыто: `DiscoverTest::testMissingShardsTableThrows`
- [x] Если в файле нет обеих таблиц, выбрасывает `RegistryInvalidException`; в сообщении перечислены обе таблицы. — покрыто: `DiscoverTest::testBothTablesMissingMentionedInMessage`
- [x] Если файл не является SQLite-базой (случайные байты), выбрасывает `IPDOException`, а не `RegistryInvalidException`; файл не изменяется. — покрыто: `DiscoverTest::testNonSQLiteFileThrowsIPDOException`
- [x] Если таблицы `kv` и `shards` есть, но ключ `version` отсутствует, выбрасывает `KeyNotFoundKvException`. — покрыто: `DiscoverTest::testMissingVersionThrowsKeyNotFound`
- [x] Если `version = 0` или `version = -1`, выбрасывает `RegistryVersionMismatchException`; сообщение говорит, что реестр устарел, ожидается версия `1`, и предлагает миграцию либо пересоздание; файл не изменяется. — покрыто: `DiscoverTest::testVersionZeroThrowsOutdatedMismatch`, `DiscoverTest::testVersionMinusOneThrowsMismatch`
- [x] Если `version = 2`, выбрасывает `RegistryVersionMismatchException`; сообщение говорит, что файл создан более новой версией, и предлагает обновить `Inilim\SqliteShard`; файл не изменяется. — покрыто: `DiscoverTest::testVersionTwoThrowsNewerVersionMismatch`
- [x] Если `version = 1`, реестр успешно открывается (без ложного `RegistryVersionMismatchException`). — покрыто: `DiscoverTest::testVersionOneOpensFine`
- [x] Если `version = 1`, но ключ `min_shards` отсутствует, выбрасывает `KeyNotFoundKvException`. — покрыто: `DiscoverTest::testMissingMinShardsThrowsKeyNotFound`
- [x] Если `version = 1` и `min_shards` есть, но отсутствует `rent_timeout`, `discover()` успешно возвращает реестр; первый вызов `isFree`, `rent` или `leftTimeUntilRelease` выбрасывает `KeyNotFoundKvException` (текущее поведение: не все ключи проверяются при открытии). — покрыто: `DiscoverTest::testMissingRentTimeoutThrowsOnFirstUse`
- [x] Если удалена запись шарда, а его файл остался, `discover()` при нехватке до `min_shards` создаёт новый шард с новым `id`; файл старого шарда остаётся в директории как «сирота» и не удаляется. — покрыто: `DiscoverTest::testOrphanShardFileRemainsAfterRowDeleted`
- [x] Если удалён файл шарда, а запись осталась и количество строк достаточно, `discover()` не восстанавливает файл; шард остаётся в `getAllShards`, а запрос через `getIPDOByShard` выбрасывает `IPDOException`. — покрыто: `DiscoverTest::testMissingShardFileNotRestoredAndFailsOnUse`
- [x] Имя с кириллицей, пробелами и специальными символами находится; путь файла строится по `sha1(name)`. — покрыто: `DiscoverTest::testComplexNameIsFound`
- [x] `discover()` с завершающим разделителем в `baseDir` находит тот же реестр; возвращаемый `baseDir` нормализован. — покрыто: `DiscoverTest::testBaseDirWithTrailingSlashNormalized`
- [x] Каждый вызов возвращает новый объект `Registry` (повторный вызов не обязан возвращать тот же экземпляр). — покрыто: `DiscoverTest::testEachCallReturnsNewInstance`
- [x] `discover()` никогда не выбрасывает `RegistryNameExistsException`. — покрыто: `DiscoverTest::testDiscoverDoesNotThrowNameExists`
- [x] Состояние реестра при успешном `discover()` не меняется, кроме случая нехватки шардов до `min_shards`. — покрыто: `DiscoverTest::testRegistryContentUnchangedByDiscover`

### `discoverFromCreateConfig`

- [x] Если реестр с именем из конфигурации не существует, возвращает `null`; файлы реестра и шардов не создаются. — покрыто: `DiscoverFromCreateConfigTest::testReturnsNullWhenRegistryDoesNotExist`
- [x] Если реестр существует, возвращает объект `Registry` и не выбрасывает `RegistryNameExistsException`. — покрыто: `DiscoverFromCreateConfigTest::testReturnsExistingRegistry`
- [x] `rentTimeout` из конфигурации не применяется к существующему реестру: если реестр создан с таймаутом `30`, а в конфигурации указано `60`, после вызова активный шард с `updated_at` `45` секунд назад остаётся свободным; новый экземпляр, полученный через `discover()`, также использует сохранённый таймаут `30`. — покрыто: `DiscoverFromCreateConfigTest::testConfigRentTimeoutIsNotApplied`
- [x] `minShards` из конфигурации не применяется к существующему реестру: если реестр создан с `minShards = 2` и в нём 2 шарда, вызов с `config->minShards = 5` возвращает реестр с 2 шардами; повторный вызов также не создаёт шарды, а последующий `discover()` не увеличивает количество до `5`. — покрыто: `DiscoverFromCreateConfigTest::testConfigMinShardsIsNotApplied`
- [x] Если записей в реестре меньше сохранённого `min_shards` (например, сохранено `5`, а записей `3`), встроенный `discover()` создаёт недостающие шарды до сохранённого минимума; `config->minShards` на это количество не влияет. Возвращается реестр с `5` шардами; новые шарды свободны, имеют файлы и сохранённую схему; старые шарды не изменены. — покрыто: `DiscoverFromCreateConfigTest::testFillsUpToSavedMinShards`
- [x] Если `config->minShards` меньше текущего количества шардов, шарды не удаляются. — покрыто: `DiscoverFromCreateConfigTest::testNeverDeletesShards`
- [x] `schemaSql` из конфигурации не применяется к существующему реестру: схема существующих шардов не меняется; новые шарды, созданные из-за нехватки до сохранённого минимума, используют сохранённую в реестре схему, а не схему из конфигурации. — покрыто: `DiscoverFromCreateConfigTest::testConfigSchemaSqlIsNotApplied`
- [x] Если файл реестра повреждён (нет обязательных таблиц), выбрасывает `RegistryInvalidException`; файл не перезаписывается. — покрыто: `DiscoverFromCreateConfigTest::testEmptyFileThrowsRegistryInvalid`
- [x] Если версия реестра не поддерживается, выбрасывает `RegistryVersionMismatchException`; файл не перезаписывается. — покрыто: `DiscoverFromCreateConfigTest::testWrongVersionThrowsRegistryVersionMismatch`
- [x] Если реестров с именем из конфигурации нет, возвращает `null` (наличие других реестров и существование `baseDir` значения не имеют). — покрыто: `DiscoverFromCreateConfigTest::testOtherRegistryInDirIsNotUsed`
- [x] Если в `baseDir` есть реестр с другим именем, он не используется; возвращается `null`. — покрыто: `DiscoverFromCreateConfigTest::testOtherRegistryInDirIsNotUsed`
- [x] Структура шардов и `updated_at` существующих шардов не меняются; конфигурационный `rent_timeout` не может сделать активный шард свободным. — покрыто: `DiscoverFromCreateConfigTest::testExistingShardStatePreserved`
- [x] Новый экземпляр имеет `minSecHeartbeat = 10`. — покрыто: `DiscoverFromCreateConfigTest::testMinSecHeartbeatIsTen`
- [x] Повторные вызовы с той же конфигурацией стабильны: количество шардов равно `max(текущее_количество, сохранённый_min_shards)` и не меняется от вызова к вызову. — покрыто: `DiscoverFromCreateConfigTest::testRepeatedCallsAreStable`
- [x] Возвращаемый `baseDir` совпадает с директорией найденного реестра. — покрыто: `DiscoverFromCreateConfigTest::testBaseDirMatchesConfig`
- [x] Метод возвращает `?Registry`: `null` только при отсутствии реестра; при ошибках выбрасывает исключения. — покрыто: `DiscoverFromCreateConfigTest::testReturnsNullWhenRegistryDoesNotExist` (null), `DiscoverFromCreateConfigTest::testReturnsExistingRegistry` (Registry), `DiscoverFromCreateConfigTest::testEmptyFileThrowsRegistryInvalid` и `testWrongVersionThrowsRegistryVersionMismatch` (исключения)

### `create`

- [x] Для нового имени создаёт файл `config->ptfRegistry`, возвращает объект `Registry` с точным `name` и `baseDir`, равным `config->baseDir`. — покрыто: `CreateTest::testCreatesRegistryFileAndReturnsRegistry`
- [x] С настройками по умолчанию (`minShards = 5`, `rentTimeout = 30`) создаёт 5 шардов; все они свободны, имеют файлы и схему из `schemaSql`. — покрыто: `CreateTest::testDefaultCreatesFiveFreeShardsWithSchema`
- [x] С `minShards = 1` создаёт ровно один шард. — покрыто: `CreateTest::testMinShardsOne`
- [x] С `minShards = 3` создаёт ровно три шарда. — покрыто: `CreateTest::testMinShardsThree`
- [x] С `rentTimeout = 60` только что взятая аренда имеет остаток около `60`, а активный шард с `updated_at` `45` секунд назад считается занятым. — покрыто: `CreateTest::testRentTimeoutFromConfigApplied`
- [x] Если `schemaSql` передан строкой, `CreateConfig` превращает его в массив, и схема применяется к каждому шарду. — покрыто: `CreateTest::testSchemaSqlAsStringApplied`
- [x] Если `schemaSql` содержит несколько инструкций, они применяются к каждому шарду в заданном порядке. — покрыто: `CreateTest::testSchemaSqlArrayAppliedInOrder`
- [x] Если `schemaSql` — пустой массив, создаются пустые SQLite-файлы шардов: `getIPDOByShard()` открывает каждый без исключения, запрос к `sqlite_master` успешен, пользовательских таблиц нет. — покрыто: `CreateTest::testEmptySchemaSql`
- [x] Имя с кириллицей, пробелами и специальными символами сохраняется в `getName()` без изменений; файл реестра называется `registry-{sha1(name)}.sqlite`. — покрыто: `CreateTest::testComplexNameCreatesCorrespondingFile`
- [x] Два реестра с разными именами в одной директории независимы: у каждого свои файл реестра, шарды и настройки; создание второго не меняет первый. — покрыто: `CreateTest::testTwoRegistriesInOneDirAreIndependent`
- [x] Одинаковое имя в разных `baseDir` создаёт два независимых реестра; `discover()` в каждой директории находит свой файл, а шарды и настройки одного реестра не видны в другом. — покрыто: `CreateTest::testSameNameInDifferentBaseDirsIsIndependent`
- [x] Повторный `create()` с тем же именем и теми же настройками для нормального реестра (количество шардов не меньше сохранённого `min_shards`) выбрасывает `RegistryNameExistsException`; сообщение содержит существующее имя; количество шардов и их `id` не меняются. — покрыто: `CreateTest::testRepeatedCreateThrowsNameExists`
- [x] Если существующий реестр содержит меньше шардов, чем сохранённый `min_shards`, повторный `create()` сначала заполняет реестр через `discover()` до старого минимума, а затем выбрасывает `RegistryNameExistsException`; количество шардов увеличивается до старого `min_shards`. — покрыто: `CreateTest::testRepeatedCreateFillsMissingShardsThenThrows`
- [x] Повторный `create()` с тем же именем, но другим `rentTimeout`/`minShards`, выбрасывает `RegistryNameExistsException`; настройки существующего реестра не меняются. Проверка: `discover()` после исключения использует старый таймаут, а количество шардов не увеличивается до значения `config->minShards`. — покрыто: `CreateTest::testRepeatedCreateDoesNotOverwriteConfig`
- [x] Повторный `create()` с `config->minShards` больше сохранённого `min_shards` не создаёт шарды сверх сохранённого минимума: конфигурационный минимум не применяется к существующему реестру. — покрыто: `CreateTest::testRepeatedCreateDoesNotGrowToNewMinShards`
- [x] Повторный `create()` при существующем повреждённом файле (нет обязательных таблиц) выбрасывает `RegistryInvalidException`, а не `RegistryNameExistsException`, и не перезаписывает файл. — покрыто: `CreateTest::testRepeatedCreateOnEmptyFileThrowsRegistryInvalid`
- [x] Повторный `create()` при существующей неподдерживаемой версии выбрасывает `RegistryVersionMismatchException` и не перезаписывает файл. — покрыто: `CreateTest::testRepeatedCreateOnWrongVersionThrowsMismatch`
- [x] Созданный реестр успешно открывается через `discover()`; количество шардов остаётся равным `config->minShards`, повторного создания нет. — покрыто: `CreateTest::testCreatedRegistryIsDiscoverable`
- [x] `create()` не использует и не изменяет реестры с другими именами в той же директории. — покрыто: `CreateTest::testDoesNotTouchOtherRegistries`
- [x] Метод возвращает `Registry` (не `null`); при ошибке создания исключение выбрасывается наружу. — покрыто: всеми тестами `CreateTest` (успешные ветки возвращают `Registry`; ошибочные ветки проверяют исключения)

### `createOrDiscover`

- [x] Для нового имени создаёт реестр так же, как `create()`: файл реестра, `config->minShards` свободных шардов со схемой, `getName()` и `baseDir`, равные значениям конфигурации. — покрыто: `CreateOrDiscoverTest::testCreatesNewRegistryWhenAbsent`
- [x] Для существующего реестра (созданного через `create()`) возвращает его и не выбрасывает `RegistryNameExistsException`. — покрыто: `CreateOrDiscoverTest::testReturnsExistingRegistryWithoutThrowing`
- [x] Для существующего реестра `config->minShards` игнорируется: если реестр создан с `minShards = 2` и в нём 2 шарда, вызов с `config->minShards = 5` возвращает 2 шарда; повторный вызов и последующий `discover()` оставляют 2 шарда. — покрыто: `CreateOrDiscoverTest::testConfigMinShardsIgnoredForExistingRegistry`
- [x] Если текущее количество записей меньше сохранённого `min_shards`, встроенный `discover()` создаёт недостающие шарды до сохранённого минимума; `config->minShards` на количество не влияет. — покрыто: `CreateOrDiscoverTest::testFillsMissingShardsUpToSavedMinimum`
- [x] Если `config->minShards` меньше текущего количества, шарды не удаляются. — покрыто: `CreateOrDiscoverTest::testNeverDeletesShards`
- [x] `rentTimeout` из конфигурации не применяется к существующему реестру: используется сохранённый таймаут; конфигурационное значение не влияет на `isFree`, `leftTimeUntilRelease`, `rent` и `heartbeat`. — покрыто: `CreateOrDiscoverTest::testConfigRentTimeoutIgnoredForExistingRegistry`
- [x] `schemaSql` из конфигурации не меняет схему существующих шардов; новые шарды, созданные при нехватке до сохранённого минимума, используют сохранённую схему. — покрыто: `CreateOrDiscoverTest::testConfigSchemaSqlIgnoredForExistingRegistry`
- [x] Для другого имени в той же директории создаёт отдельный реестр, не затрагивая существующий. — покрыто: `CreateOrDiscoverTest::testCreatesSeparateRegistryForAnotherName`
- [x] Для повреждённого существующего файла выбрасывает `RegistryInvalidException` и не перезаписывает файл. — покрыто: `CreateOrDiscoverTest::testEmptyFileThrowsRegistryInvalid`
- [x] Для неподдерживаемой версии выбрасывает `RegistryVersionMismatchException` и не перезаписывает файл. — покрыто: `CreateOrDiscoverTest::testWrongVersionThrowsMismatch`
- [x] Новый экземпляр имеет `minSecHeartbeat = 10`. — покрыто: `CreateOrDiscoverTest::testMinSecHeartbeatIsAlwaysTen`
- [x] `createOrDiscover()` для существующего реестра (созданного через `create()`) не выбрасывает `RegistryNameExistsException`. — покрыто: `CreateOrDiscoverTest::testReturnsExistingRegistryWithoutThrowing`
- [x] Возвращает `Registry` (не `null`), кроме случаев, когда выбрасывается исключение. — покрыто: `CreateOrDiscoverTest::testReturnsRegistryInBothCases`
- [x] Настройки, сохранённые при создании реестра, не перезаписываются конфигурацией при последующих вызовах и видны последующим `discover()`. — покрыто: `CreateOrDiscoverTest::testSavedSettingsNotOverwrittenByLaterCalls`
- [x] Повторные вызовы стабильны: количество шардов равно `max(текущее_количество, сохранённый_min_shards)` и не меняется от вызова к вызову. — покрыто: `CreateOrDiscoverTest::testRepeatedCallsAreStable`

## Состязательные сценарии (интеграционные)

- [x] Два экземпляра `Registry`, открытые на один файл, одновременно вызывают `rent` для одного свободного шарда. Проверяется (параллельно): ровно один вызов успешен, второй выбрасывает `ShardNotFreeException`; итоговое состояние — шард занят (`isFree` — `false`), `leftTimeUntilRelease` больше `0`, `rentValid` для объекта-наблюдателя без токена — `false` (аренду держит объект-победитель в своём потоке). Аренда «в одни руки» здесь не гарантируется, а тестируется: если оба вызова пройдут (неатомарный `rent`), тест упадёт. — покрыто: `RentTest::testRentRaceSecondRegistryInstanceLoses` (параллельно: два потока inilim/parallel, старт выравнен по стартовой миллисекунде)
- [x] Два экземпляра `Registry` одновременно вызывают `rentFree`, когда свободен ровно один шард. Проверяется (параллельно): ровно один вызов возвращает `Shard`, второй выбрасывает `ShardNotFreeException` (либо, если он получил устаревший список и все попытки проиграны, то же исключение с сообщением `There are no free shards`). Это детектор двойной выдачи, а не гарантия: если шард ушёл обоим, оба вызова вернут `Shard` и тест упадёт по числу победителей. — покрыто: `RentFreeTest::testRentFreeRaceAllCandidatesRentedByAnotherInstance` (параллельно: два потока inilim/parallel, реестр с одним свободным шардом)
- [x] Два экземпляра одновременно вызывают `tryRentFree` при одном свободном шарде. Проверяется (параллельно): один вызов возвращает `Shard`, второй — `null`. Утверждение «один и тот же шард не может быть арендован обоими вызовами» — проверяемый инвариант, а не гарантия: при баге оба вызова могут вернуть один `Shard`, и тест упадёт по числу победителей. — покрыто: `TryRentFreeTest::testTryRentFreeRaceAllCandidatesRentedByAnotherInstance` (параллельно: два потока inilim/parallel, реестр с одним свободным шардом)
- [x] Два экземпляра одновременно вызывают `tryRent` для одного свободного шарда. Проверяется (параллельно): один возвращает `true`, второй — `false`; состояние шарда соответствует одному успешному арендатору. Это также детектор неатомарности `rent`: два `true` означают двойную выдачу и роняют тест. — покрыто: `TryRentTest::testTryRentRaceSecondInstanceReturnsFalse` (параллельно: два потока inilim/parallel, старт выравнен по стартовой миллисекунде)
- [] Конкурентный `discover()` при нехватке шардов до `min_shards` не имеет гарантированного итогового количества: два процесса могут оба увидеть нехватку и создать больше `min_shards`, либо один процесс может получить `IPDOException` из-за блокировки. Этот сценарий не следует фиксировать как контракт без отдельного решения по блокировкам. — НЕ покрыто: сценарий намеренно не фиксируется как контракт (см. сам текст кейса), отдельного теста нет.
- [x] Настоящая гонка аренды — многораундовый стресс-детектор двойной выдачи (сверх кейсов выше): несколько воркеров — отдельные потоки через `inilim/parallel` (`parallel\Runtime`), у каждого собственное соединение SQLite — одновременно вызывают `tryRentFree` на одном реестре; старты выравниваются по стартовой миллисекунде (цикл ожидания с шагом 1 мс), чтобы несколько воркеров успевали прочитать один и тот же список свободных шардов до записи аренды. Проверяемый инвариант (не гарантия): один и тот же шард не может быть выдан двум воркерам. При одном свободном шарде его получает ровно один воркер, остальные — `null`; при нескольких свободных шардах набор выданных `id` не содержит повторов, каждый победитель держит валидную аренду, а после раунда количество свободных шардов согласовано. При срабатывании бага ассерт падает с сообщением `shard rented by several workers: {"<id>":2}` — это и есть цель теста. Прогон состоит из многих раундов, чтобы маленький шанс окна гонки стал статистически заметен; зелёный прогон не доказывает отсутствие бага. Требует расширения `ext-parallel`; без него тест пропускается (`markTestSkipped`). — покрыто: `RaceParallelTest::testOneFreeShardIsGrantedToExactlyOneWorker`, `RaceParallelTest::testNoShardIsRentedByTwoWorkersSimultaneously`

## Задуманное поведение и границы текущих тестов

- [x] `heartbeat_m2()` при остатке больше `minSecHeartbeat` намеренно не обращается к БД и не выполняет запись. Это экономия операций; тест не должен требовать запрос к БД в этой ветке. — отражено в проверках `HeartbeatM2Test::testDoesNotRenewWhenLeftTimeAboveThreshold` и аналогичных (остаток не сбрасывается, запись не выполняется)
- [x] `discover()` не восстанавливает отсутствующие файлы шардов. Он только доводит количество записей до сохранённого `min_shards`, создавая новые шарды; существующие записи и файлы не пересоздаются. — покрыто: `DiscoverTest::testMissingShardFileNotRestoredAndFailsOnUse`, `DiscoverTest::testOrphanShardFileRemainsAfterRowDeleted`, `DiscoverTest::testMoreShardsThanMinimumAreKept`
- [x] `createNewShards()` при ошибке на одном из шардов оставляет уже успешно созданные шарды и их записи; удаляется только файл текущего неудавшегося шарда. Это соответствует комментарию в реализации. — покрыто: `CreateNewShardsTest::testMultiShardFailureKeepsSuccessfulAndCleansFailed`, `CreateNewShardsTest::testInvalidSchemaSqlThrowsAndCleansUp`
- [] `owner token` будет реализован позже. Тесты текущей версии не проверяют принадлежность аренды; после реализации появятся отдельные кейсы на чужой токен. — принято как граница тестов; отдельных тестов нет (и не требуется до реализации)
