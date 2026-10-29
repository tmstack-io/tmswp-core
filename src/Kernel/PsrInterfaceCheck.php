<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use TMSWP\Core\Exception\KernelException;

/**
 * 読み込まれている psr/container・psr/log の宣言が、TMSWP のクラスと合うかを検査する。
 *
 * TMSWP の Container は `Psr\Container\ContainerInterface` を、LoggerRegistry・ScopedLog・SimpleLogger は
 * `Psr\Log\LoggerInterface` を実装し、レベル別のメソッドを `Psr\Log\LoggerTrait` から取り込む。PHP は interface・trait
 * を最初に読み込んだ定義で固定するため、他のプラグインや WP-CLI の phar が古い psr/container・psr/log を先に読み込むと、
 * TMSWP のクラスを読み込んだ時点で宣言が合わずに Fatal error になる(例: psr/container 1.0 の `get($id)` は引数の型が
 * 無く、`Container::get(string $id)` は引数の型を狭めることになる)。
 *
 * そこで TMSWP のクラスを読み込む前に、TMSWP が実装・取り込むメソッドの引数の型と戻り値の型が、tmswp/core の
 * composer.json が要求する版(psr/container 2・psr/log 3)の宣言と同じかを調べ、違えば KernelException にする。
 *
 * @internal
 */
final class PsrInterfaceCheck
{
    private const string LOG_MESSAGE = 'Stringable|string';

    /**
     * 検査する interface・trait と、要求する版の宣言。
     *
     * メソッド名 => [引数の型の並び(型の無い引数は null), 戻り値の型(無ければ null)]。型は `|` で区切ってソートした形。
     *
     * @var array<string, array{requires: string, methods: array<string, array{list<string|null>, string|null}>}>
     */
    private const array EXPECTED = [
        'Psr\Container\ContainerInterface' => [
            'requires' => 'psr/container ^2.0',
            'methods' => [
                'get' => [['string'], null],
                'has' => [['string'], 'bool'],
            ],
        ],
        'Psr\Log\LoggerInterface' => [
            'requires' => 'psr/log ^3.0',
            'methods' => [
                'emergency' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'alert' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'critical' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'error' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'warning' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'notice' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'info' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'debug' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'log' => [[null, self::LOG_MESSAGE, 'array'], 'void'],
            ],
        ],
        'Psr\Log\LoggerTrait' => [
            'requires' => 'psr/log ^3.0',
            'methods' => [
                'emergency' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'alert' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'critical' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'error' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'warning' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'notice' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'info' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'debug' => [[self::LOG_MESSAGE, 'array'], 'void'],
                'log' => [[null, self::LOG_MESSAGE, 'array'], 'void'],
            ],
        ],
    ];

    private function __construct() {}

    /**
     * 検査する interface・trait を読み込み(未読み込みならオートローダから)、宣言を照合する。
     *
     * @throws KernelException 宣言が要求する版と合わない場合(設定の誤り。debug に関係なく投げる)
     */
    public static function assertCompatible(): void
    {
        foreach (self::EXPECTED as $name => $expected) {
            $class = new ReflectionClass($name);
            $problems = self::problems($class, $expected['methods']);

            if ($problems !== []) {
                $file = $class->getFileName();

                throw new KernelException(\sprintf(
                    'TMSWP requires %s, but %s loaded from %s is incompatible (%s). Another plugin or the WP-CLI phar loaded an older copy before TMSWP; load TMSWP\'s Composer autoloader first or update the other copy',
                    $expected['requires'],
                    $name,
                    $file === false ? '(internal)' : $file,
                    implode('; ', $problems),
                ));
            }
        }
    }

    /**
     * @param ReflectionClass<object> $class
     * @param array<string, array{list<string|null>, string|null}> $methods
     *
     * @return list<string> 宣言の違い。合っていれば空
     */
    private static function problems(ReflectionClass $class, array $methods): array
    {
        $problems = [];

        foreach ($class->getMethods() as $method) {
            if (!\array_key_exists($method->getName(), $methods) && ($class->isInterface() || $method->isAbstract())) {
                $problems[] = "unexpected method {$method->getName()}()";
            }
        }

        foreach ($methods as $name => [$parameters, $return]) {
            if (!$class->hasMethod($name)) {
                $problems[] = "missing method {$name}()";

                continue;
            }

            $method = $class->getMethod($name);
            $actualParameters = self::parameterTypes($method);
            $actualReturn = self::typeName($method->getReturnType());

            if ($actualParameters !== $parameters || $actualReturn !== $return) {
                $problems[] = $name . self::signature($actualParameters, $actualReturn) . ' must be ' . $name . self::signature($parameters, $return);
            }
        }

        return $problems;
    }

    /** @return list<string|null> */
    private static function parameterTypes(ReflectionMethod $method): array
    {
        $types = [];

        foreach ($method->getParameters() as $parameter) {
            $types[] = self::typeName($parameter->getType());
        }

        return $types;
    }

    /**
     * 型を比較できる文字列にする。union 型はメンバーをソートして `|` で連結する(宣言の書き順に左右されない)。
     */
    private static function typeName(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionUnionType) {
            $names = array_map(static fn(ReflectionType $member): string => (string) self::typeName($member), $type->getTypes());
            sort($names);

            return implode('|', $names);
        }

        $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        return $type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' . $name : $name;
    }

    /** @param list<string|null> $parameters */
    private static function signature(array $parameters, ?string $return): string
    {
        $rendered = array_map(static fn(?string $type): string => $type ?? '(untyped)', $parameters);

        return '(' . implode(', ', $rendered) . ')' . ($return === null ? '' : ': ' . $return);
    }
}
