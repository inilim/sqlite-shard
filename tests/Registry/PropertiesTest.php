<?php

declare(strict_types=1);

namespace Inilim\Test\Registry;

use Inilim\SqliteShard\CreateConfig;
use Inilim\SqliteShard\Registry;
use Inilim\Test\TestCase;
use Inilim\Tool\Path;

/**
 * Публичные свойства: baseDir, minSecHeartbeat и константа VERSION.
 *
 *  «Публичные свойства baseDir и minSecHeartbeat»
 */
final class PropertiesTest extends TestCase
{
    public function testBaseDirAfterCreateIsNormalizedConfigBaseDir(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertIsString($reg->baseDir);
        self::assertSame(Path::normalize(\realpath($this->tmpDir)), $reg->baseDir);
        self::assertStringNotContainsString('\\', $reg->baseDir);
        self::assertStringEndsNotWith('/', $reg->baseDir);
    }

    public function testBaseDirAfterCreateWithTrailingSeparatorIsTrimmed(): void
    {
        $cfg = new CreateConfig(
            baseDir: $this->tmpDir . '/',
            schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
            name: 'test',
        );
        $reg = $this->registerRegistry(Registry::create($cfg));

        self::assertSame(Path::normalize(\realpath($this->tmpDir)), $reg->baseDir);
    }

    public function testBaseDirAfterDiscoverIsDirectoryOfFoundRegistryFile(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $discovered = Registry::discover($reg->baseDir, 'test');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame($reg->baseDir, $discovered->baseDir);
        self::assertIsString($discovered->baseDir);
    }

    public function testBaseDirIsReadOnly(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        $this->expectException(\Error::class);
        $reg->baseDir = '/somewhere/else';
    }

    public function testMinSecHeartbeatDefaultAfterCreateIsTen(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertSame(10, $reg->minSecHeartbeat);
    }

    public function testMinSecHeartbeatDefaultAfterDiscoverIsTen(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));
        $discovered = Registry::discover($reg->baseDir, 'test');

        self::assertNotNull($discovered);
        $this->registerRegistry($discovered);
        self::assertSame(10, $discovered->minSecHeartbeat);
    }

    public function testMinSecHeartbeatIsReadableFromOutside(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        self::assertIsInt($reg->minSecHeartbeat);
    }

    public function testMinSecHeartbeatIsReadOnlyFromOutside(): void
    {
        $reg = $this->registerRegistry(Registry::create(
            new CreateConfig(
                baseDir: $this->tmpDir,
                schemaSql: ['CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)'],
                name: 'test',
            ),
        ));

        $this->expectException(\Error::class);
        $reg->minSecHeartbeat = 5;
    }

    public function testVersionConstantIsOne(): void
    {
        self::assertSame(1, Registry::VERSION);
    }
}
