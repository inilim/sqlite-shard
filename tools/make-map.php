<?php

declare(strict_types=1);

/**
 * Формирует map.md — карту публичного API классов пакета (в духе текущего map.md).
 *
 * Классы не перечисляются вручную: они находятся через PSR-4-маппинг из
 * composer.json и раскрываются через PHP Reflection (Composer autoload).
 *
 * Примеры:
 *   php tools/make-map.php                  # прочитать src/ и записать map.md
 *   php tools/make-map.php --out=docs/map.md
 *   php tools/make-map.php --check          # не писать файл, а проверить актуальность (exit 1 при отличии)
 *
 * Правила генерации:
 *  - в карту попадают классы из autoload.psr-4 (не autoload-dev) с публичными методами;
 *  - исключаются классы-исключения (линия Throwable) и классы, помеченные @internal;
 *  - у сигнатур видимость опускается, static сохраняется, promoted-модификаторы
 *    рефлексия не показывает; типы классов выводятся коротким именем;
 *  - порядок методов: сначала static, затем остальные — в порядке объявления в классе;
 *  - классы сортируются по короткому имени.
 */

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

[$outFile, $onlyCheck] = parseArgs($argv);

$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$outFile = resolveAgainst($outFile, $root);

$autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Не найден $autoload — выполните composer install\n");
    exit(2);
}
require $autoload;

// ---------------------------------------------------------------------------
// Поиск классов: PSR-4 из composer.json
// ---------------------------------------------------------------------------

$composer = json_decode((string)file_get_contents($root . DIRECTORY_SEPARATOR . 'composer.json'), true);
$classFiles = []; // fqcn => путь к файлу, где класс объявлен

foreach (($composer['autoload']['psr-4'] ?? []) as $prefix => $dir) {
    $prefix = rtrim($prefix, '\\') . '\\';
    $absDir = realpath(resolveAgainst((string)$dir, $root));
    if ($absDir === false || !is_dir($absDir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $real = realpath($file->getPathname());
        if ($real === false || !str_starts_with($real, $absDir . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $relative = substr($real, strlen($absDir . DIRECTORY_SEPARATOR));
        $relative = substr($relative, 0, -4); // снимаем .php
        $fqcn = $prefix . str_replace(['/', '\\'], '\\', $relative);
        $classFiles[$fqcn] = $real;
    }
}

if ($classFiles === []) {
    fwrite(STDERR, "По autoload.psr-4 из composer.json классы не найдены\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Рефлексия и фильтрация
// ---------------------------------------------------------------------------

$classes = []; // fqcn => ['short' => ..., 'fqcn' => ..., 'lines' => [...]]

foreach ($classFiles as $fqcn => $path) {
    try {
        $rc = new ReflectionClass($fqcn);
    } catch (ReflectionException $e) {
        fwrite(STDERR, "Предупреждение: не удалось загрузить $fqcn: {$e->getMessage()}\n");
        continue;
    }

    if ($rc->isSubclassOf(\Throwable::class)) {
        continue; // классы исключений в карту не входят
    }
    $doc = $rc->getDocComment();
    if ($doc !== false && preg_match('/@internal\b/', $doc) === 1) {
        continue; // @internal
    }

    $methods = array_values(array_filter(
        $rc->getMethods(),
        static fn(ReflectionMethod $m): bool => $m->isPublic()
            && $m->getDeclaringClass()->getName() === $fqcn, // только свои, не унаследованные
    ));
    if ($methods === []) {
        continue; // без публичных методов карте нечего показать
    }

    // статические первыми, затем остальные; порядок объявления сохранён
    $static   = array_values(array_filter($methods, static fn(ReflectionMethod $m): bool => $m->isStatic()));
    $instance = array_values(array_filter($methods, static fn(ReflectionMethod $m): bool => !$m->isStatic()));
    $ordered  = array_merge($static, $instance);

    $lines = [];
    foreach ($ordered as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $params[] = renderParam($p);
        }
        $return = $m->hasReturnType() ? ': ' . renderType($m->getReturnType()) : '';
        $lines[] = ($m->isStatic() ? 'static ' : '') . $m->getName() . '(' . implode(', ', $params) . ')' . $return;
    }

    $classes[$fqcn] = [
        'short' => $rc->getShortName(),
        'fqcn'  => $fqcn,
        'lines' => $lines,
    ];
}

// ---------------------------------------------------------------------------
// Сборка map.md
// ---------------------------------------------------------------------------

usort($classes, static fn(array $a, array $b): int => strcmp($a['short'], $b['short']) ?: strcmp($a['fqcn'], $b['fqcn']));

$out = '';
foreach ($classes as $info) {
    $out .= '## \\' . $info['fqcn'] . "\n\n```php\n";
    foreach ($info['lines'] as $line) {
        $out .= $line . "\n";
    }
    $out .= "```\n\n";
}
$out = rtrim($out, "\n") . "\n";

$current = is_file($outFile) ? file_get_contents($outFile) : null;

if ($onlyCheck) {
    if ($current === $out) {
        echo "map.md актуален\n";
        exit(0);
    }
    fwrite(STDERR, "map.md устарел — запустите без --check, чтобы обновить\n");
    exit(1);
}

if (file_put_contents($outFile, $out) === false) {
    fwrite(STDERR, "Не удалось записать: $outFile\n");
    exit(1);
}

echo "Записано: $outFile (" . count($classes) . " классов)\n";

// ---------------------------------------------------------------------------
// Помощники
// ---------------------------------------------------------------------------

function parseArgs(array $argv): array
{
    $out = 'map.md';
    $check = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $check = true;
            continue;
        }
        if (str_starts_with($arg, '--out=')) {
            $out = substr($arg, 6);
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }
        fwrite(STDERR, "Неизвестный аргумент: $arg (--help)\n");
        exit(2);
    }

    return [$out, $check];
}

function usage(): void
{
    echo "Использование: php tools/make-map.php [--out=<file>] [--check] [--help]\n";
}

function resolveAgainst(string $path, string $base): string
{
    if ($path === '') {
        return $base;
    }
    if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path; // абсолютный путь
    }
    return $base . DIRECTORY_SEPARATOR . $path;
}

/**
 * Тип в текстовом виде: union через "|", intersection через "&", классы —
 * коротким именем (последний сегмент namespace), nullable — через "?".
 */
function renderType(ReflectionType $type): string
{
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(static fn(ReflectionNamedType $t): string => renderNamed($t), $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(static fn(ReflectionNamedType $t): string => renderNamed($t), $type->getTypes()));
    }
    return renderNamed($type);
}

function renderNamed(ReflectionNamedType $type): string
{
    $name = $type->getName();

    // 'null', 'mixed', 'void', 'never' и т.п. — без префикса и без сокращений
    if ($type->isBuiltin()) {
        if (in_array($name, ['null', 'mixed', 'void', 'never'], true)) {
            return $name;
        }
        return ($type->allowsNull() ? '?' : '') . $name;
    }

    // рефлексия отдаёт классы с полным namespace — берём последний сегмент
    $pos = strrpos($name, '\\');
    if ($pos !== false) {
        $name = substr($name, $pos + 1);
    }

    return ($type->allowsNull() ? '?' : '') . $name;
}

function renderParam(ReflectionParameter $p): string
{
    $s = '';
    $type = $p->getType();
    if ($type !== null) {
        $s .= renderType($type) . ' ';
    }
    if ($p->isPassedByReference()) {
        $s .= '&';
    }
    if ($p->isVariadic()) {
        $s .= '...';
    }
    $s .= '$' . $p->getName();

    if ($p->isDefaultValueAvailable() && !$p->isVariadic()) {
        $s .= ' = ' . renderDefault($p);
    }
    return $s;
}

function renderDefault(ReflectionParameter $p): string
{
    if ($p->isDefaultValueConstant()) {
        return $p->getDefaultValueConstantName(); // например Foo::BAR — имя константы
    }
    $value = $p->getDefaultValue();
    return $value === null ? 'null' : var_export($value, true);
}