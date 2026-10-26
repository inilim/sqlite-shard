<?php

declare(strict_types=1);

namespace Inilim\SqliteShard;

use Inilim\IPDO\IPDOSQLite;
use Inilim\SqliteShard\Registry;
use Inilim\Tool\Assert;
use Inilim\Tool\FS;
use Inilim\Tool\Path;
use Inilim\Tool\VD;
use Symfony\Component\Finder\Finder;

final class HelperService
{
    protected static \Closure $fnGetRegIPDO;

    /**
     * @return \Generator<int,array{ptf:string,ipdo:IPDOSQLite}>
     */
    function regIterator(string $baseDir): \Generator
    {
        Assert::directory($baseDir);

        foreach (\DI(Finder::class)->in($baseDir)->files()->name('registry-*.sqlite') as $spl) {
            $ptf = Path::normalize((string)$spl->getRealPath());
            $ipdo = \DI(IPDOSQLite::class, [$ptf]);
            yield [
                'ptf' => $ptf,
                'ipdo' => $ipdo,
            ];
        }
    }

    function findRegFileFromCreateConfig(CreateConfig $config): ?string
    {
        return $this->findRegFile($config->baseDir, $config->name);
    }

    function findRegFile(string $baseDir, string $name): ?string
    {
        Assert::directory($baseDir);
        Assert::stringNotEmpty($name);

        $ptf = \sprintf('%s/registry-%s.sqlite', $baseDir, \sha1($name, false));
        $ptf = Path::normalize($ptf);
        // VD::de($ptf);
        return FS::isFile($ptf) ? $ptf : null;
    }

    function getRegIPDO(Registry $registry): IPDOSQLite
    {
        if (!isset(self::$fnGetRegIPDO)) {
            self::$fnGetRegIPDO = (static function (Registry $reg): IPDOSQLite {
                $prop = 'ipdo';
                return $reg->$prop;
            })
                ->bindTo(null, Registry::class);
        }

        /** @var IPDOSQLite $ipdo */
        $ipdo = self::$fnGetRegIPDO->__invoke($registry);
        return $ipdo;
    }

    // TODO добавить метод который будет проверять наличие шардов файлов и записями в регитри
}
