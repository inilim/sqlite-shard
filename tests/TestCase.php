<?php

declare(strict_types=1);

namespace Inilim\Test;

use Inilim\SqliteShard\Registry;
use Symfony\Component\Finder\Finder;

/**
 * Базовая фикстура для тестов {@see \Inilim\SqliteShard\Registry}.
 *
 * Договорённости из tests/cases.md:
 *  1. Каждый кейс выполняется в отдельной пустой временной директории;
 *     после теста все созданные файлы удаляются.
 *  2. Базовая фикстура: CreateConfig с существующей baseDir, непустым
 *     уникальным name и схемой, создающей таблицу items.
 *  3. Для подготовки точного состояния тест может напрямую изменять файл
 *     реестра через публичный путь CreateConfig::ptfRegistry; проверяемое
 *     поведение — только публичное.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** Корень временных каталогов тестов: <проект>/.sandbox/tests */
    protected string $tmpRoot;

    /** Каталог текущего теста (создаётся в setUp, удаляется в tearDown) */
    protected string $tmpDir;

    /**
     * Реестры, созданные тестами, — закрываем их внутренние соединения явно
     * (см. closeRegistryConnection).
     *
     * @var Registry[]
     */
    protected array $trackedRegistries = [];

    /**
     * Коллбек, связанный с областью видимости {@see Registry} через bindTo:
     * получает доступ к protected-свойству Registry::$ipdo и закрывает
     * соединение реестра.
     *
     * @var null|\Closure(Registry):void
     */
    protected static ?\Closure $registryConnectionCloser = null;

    /**
     * Рекурсивное удаление каталога с повторными попытками
     * (файлы, затем подкаталоги, затем корень).
     *
     * @var null|\Closure(string):string[]
     */
    protected static ?\Closure $dirRemover = null;

    /**
     * Все временные каталоги прогона — страховочная зачистка
     * в tearDownAfterClass.
     *
     * @var string[]
     */
    protected static array $allTmpDirs = [];

    protected function setUp(): void
    {
        $this->tmpRoot = \dirname(__DIR__) . '/.sandbox/tests';
        if (!\is_dir($this->tmpRoot)) {
            \mkdir($this->tmpRoot, 0777, true);
        }
        $this->tmpDir = $this->tmpRoot . '/' . \uniqid('reg-test-', true);
        \mkdir($this->tmpDir, 0777, true);
        $this->trackedRegistries = [];
        self::$allTmpDirs[] = $this->tmpDir;

        // ленивая инициализация замыкания рекурсивного удаления каталога
        self::$dirRemover ??= static function (string $dir, int $maxAttempts = 3): array {
            if (!\is_dir($dir) || \is_link($dir)) {
                return [];
            }

            $leftovers = [];

            // удаление одного пути (файла или каталога) с повторными попытками
            $tryDelete = static function (string $ptf) use ($maxAttempts): bool {
                $isDir = \is_dir($ptf) && !\is_link($ptf);
                for ($i = 0; $i < $maxAttempts; $i++) {
                    $deleted = $isDir ? @\rmdir($ptf) : @\unlink($ptf);
                    if ($deleted || !\file_exists($ptf)) {
                        return true;
                    }
                    \usleep(50_000); // даём ОС освободить дескриптор
                }
                return false;
            };

            // все файлы рекурсивно
            foreach (Finder::create()->in($dir)->files()->ignoreDotFiles(false)->ignoreVCS(false) as $file) {
                if (!$tryDelete($file->getPathname())) {
                    $leftovers[] = $file->getPathname();
                }
            }

            // все подкаталоги рекурсивно, самые глубокие — первыми
            $directories = Finder::create()
                ->in($dir)
                ->directories()
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->sort(static function (\SplFileInfo $a, \SplFileInfo $b): int {
                    $depth = static fn(string $ptf): int => \substr_count($ptf, '\\') + \substr_count($ptf, '/');
                    return $depth($b->getPathname()) <=> $depth($a->getPathname());
                });

            foreach ($directories as $subdir) {
                if (!$tryDelete($subdir->getPathname())) {
                    $leftovers[] = $subdir->getPathname();
                }
            }

            // сам корневой каталог
            if (!$tryDelete($dir)) {
                $leftovers[] = $dir;
            }

            return $leftovers;
        };
    }

    protected function tearDown(): void
    {
        // 1) закрываем внутренние соединения отслеживаемых реестров (коллбек bindTo)
        $trackedRegistries = &$this->trackedRegistries;
        foreach ($trackedRegistries as $idx => $reg) {
            self::closeRegistryConnection($reg);
            unset($trackedRegistries[$idx]);
        }
        $this->trackedRegistries = [];
        unset($trackedRegistries);

        // 2) удаляем временный каталог теста; при неудаче — громкая ошибка
        if (isset($this->tmpDir) && \is_dir($this->tmpDir) && !\is_link($this->tmpDir)) {
            // циклические ссылки (Shard -> Registry) держат PDO-соединения
            \gc_collect_cycles();
            $leftovers = (self::$dirRemover)($this->tmpDir);
            if ([] !== $leftovers) {
                @\file_put_contents(
                    $this->tmpRoot . '/cleanup-report.log',
                    '[' . \date('Y-m-d H:i:s') . '] ' . \implode(', ', $leftovers) . \PHP_EOL,
                    \FILE_APPEND,
                );
                self::fail('Временные файлы не удалены: ' . \implode(', ', $leftovers));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        // страховка: что не удалилось в tearDown отдельных тестов, удаляем
        // здесь — объекты тестов уже уничтожены, соединения закрыты
        foreach (self::$allTmpDirs as $dir) {
            if (\is_dir($dir) && !\is_link($dir)) {
                \gc_collect_cycles();
                (self::$dirRemover)($dir);
            }
        }
    }

    /**
     * Регистрирует реестр — его внутреннее соединение будет явно закрыто
     * в tearDown. Каждый созданный/обнаруженный реестр следует передавать
     * сюда: $this->registerRegistry(Registry::create($cfg)).
     */
    protected function registerRegistry(Registry $reg): Registry
    {
        $this->trackedRegistries[] = $reg;
        return $reg;
    }

    /**
     * Закрывает соединение реестра через коллбек, связанный с Registry
     * (bindTo даёт доступ к protected Registry::$ipdo).
     */
    protected static function closeRegistryConnection(Registry $reg): void
    {
        self::$registryConnectionCloser ??= (static function (Registry $reg): void {
            $reg->ipdo->close();
        })->bindTo(null, Registry::class);
        (self::$registryConnectionCloser)($reg);
    }
}
