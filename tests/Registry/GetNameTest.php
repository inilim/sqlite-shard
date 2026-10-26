<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\KeyNotFoundKvException;
use Inilim\SqliteShard\Registry;
use Inilim\Test\TestCase;

/**
 * {@see Registry::getName()}
 *
 *  «getName»
 */
final class GetNameTest extends TestCase
{
    // После create() возвращает точное name из конфигурации.
    public function testReturnsNameFromConfigAfterCreate(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'My-Registry',
            ),
        ));

        self::assertSame('My-Registry', $reg->getName());
        self::assertIsString($reg->getName());
    }

    // После discover() возвращает то же имя.
    public function testReturnsSameNameAfterDiscover(): void
    {
        $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'My-Registry',
            ),
        ));

        $discovered = Registry::discover($this->tmpDir, 'My-Registry');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame('My-Registry', $discovered->getName());
    }

    // Имя с пробелами, кириллицей, регистром и спецсимволами возвращается
    // без изменений (точное сравнение).
    public function testComplexNameReturnedVerbatim(): void
    {
        $name = 'Реестр "Альфа" №1 test Name';
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: $name,
            ),
        ));

        self::assertSame($name, $reg->getName());
    }

    // Два реестра с именами, различающимися регистром, независимы; getName()
    // у каждого — своё; создание второго не меняет первый.
    public function testRegistriesDifferingInCaseAreIndependent(): void
    {
        $regA = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'Alpha',
            ),
        ));
        $regB = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'alpha',
            ),
        ));

        self::assertSame('Alpha', $regA->getName());
        self::assertSame('alpha', $regB->getName());
        self::assertSame(5, $regA->getCountShards());
        self::assertSame(5, $regB->getCountShards());
    }

    // createOrDiscover() возвращает реестр с name из конфигурации.
    public function testCreateOrDiscoverKeepsName(): void
    {
        $reg = $this->registerRegistry(Registry::createOrDiscover(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'Some-Name',
            ),
        ));

        self::assertSame('Some-Name', $reg->getName());
    }

    // Повреждённый реестр без ключа name: живые экземпляры, созданные до
    // удаления ключа, при вызове бросают KeyNotFoundKvException.
    public function testMissingNameKeyThrowsKeyNotFound(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir,
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));
        $ipdo = new IPDOSQLite($cfg->ptfRegistry);
        try {
            $ipdo->exec('DELETE FROM kv WHERE k = {k}', ['k' => 'name']);
        } finally {
            $ipdo->close();
        }

        $this->expectException(KeyNotFoundKvException::class);
        $reg->getName();
    }
}
