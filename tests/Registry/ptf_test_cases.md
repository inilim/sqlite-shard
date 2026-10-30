# Тест-кейсы Registry::ptf()

- [x] Для существующего шарда возвращает нормализованный путь `<baseDir>/<id>.sqlite`. — покрыто: `PtfTest::testReturnsExpectedPathForExistingShard`
- [x] `Shard::ptf()` является обёрткой над `Registry::ptf()` и возвращает тот же путь. — покрыто: `PtfTest::testShardWrapperReturnsSamePath`
- [x] Путь строится по `id`: новый объект `Shard` с тем же `id` даёт тот же путь. — покрыто: `PtfTest::testNewShardObjectWithSameIdReturnsSamePath`
- [x] Метод не требует наличия файла шарда: путь возвращается и после удаления файла, а сам файл заново не создаётся. — покрыто: `PtfTest::testDoesNotRequireShardFileToExist`
- [x] Для несуществующего шарда выбрасывает `ShardNotFoundException`; сообщение содержит `id` отсутствующего шарда. — покрыто: `PtfTest::testNonexistentShardThrowsShardNotFoundException`
- [x] Если запись шарда удалена из реестра, `ptf()` проверяет существование при каждом вызове и выбрасывает `ShardNotFoundException`. — покрыто: `PtfTest::testThrowsAfterShardRecordRemovedFromRegistry`
- [x] Метод не меняет состояние записи шарда: снимок строки до и после вызовов идентичен, а аренда остаётся действительной. — покрыто: `PtfTest::testDoesNotChangeShardState`
- [x] Для разных шардов возвращаются разные пути, каждый строится по своему `id`. — покрыто: `PtfTest::testDifferentShardsReturnUniquePaths`
- [x] Путь не зависит от состояния аренды: свободный, арендованный и освобождённый шард дают один и тот же путь. — покрыто: `PtfTest::testPathDoesNotDependOnRentState`
- [x] Второй экземпляр `Registry` видит тот же шард и возвращает тот же путь. — покрыто: `PtfTest::testSecondRegistryInstanceReturnsSamePath`
- [x] Объект `Shard`, чей `id` отсутствует в данном реестре, не принимается: проверка идёт по реестру, а не по объекту. — покрыто: `PtfTest::testShardIdFromAnotherRegistryThrows`