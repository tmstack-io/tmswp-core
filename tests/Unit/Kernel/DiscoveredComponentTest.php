<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use ArgumentCountError;
use Attribute;
use Closure;
use Error;
use InvalidArgumentException;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Hook\HookInitializer;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Testing\UnitTestCase;
use TypeError;

/**
 * 探索結果の属性クラスと引数から属性を作り直す DiscoveredComponent::attribute()。WordPress の関数を使わない。
 */
final class DiscoveredComponentTest extends UnitTestCase
{
    private const REGENERATE_HINT = "; regenerate the discovery file with 'wp tmswp discover' and deploy it together with the code";

    /** @var list<Closure(string): void> tearDown で外す属性クラスの autoload */
    private array $autoloaders = [];

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->autoloaders = [];
        parent::tearDown();
    }

    public function testItRestoresAttributeFromClassAndArguments(): void
    {
        $component = new DiscoveredComponent(
            'Example\\Component',
            DiscoveredComponentTestAttribute::class,
            ['hook' => 'init', 'priority' => 9, 'type' => MetaValueType::Integer],
        );

        $attribute = $component->attribute();

        self::assertInstanceOf(DiscoveredComponentTestAttribute::class, $attribute);
        self::assertSame('init', $attribute->hookName(KernelContext::Theme));
        self::assertSame(9, $attribute->hookPriority());
        self::assertSame(MetaValueType::Integer, $attribute->type);
        // 呼び出しごとに新しいインスタンスを作る
        self::assertNotSame($attribute, $component->attribute());
    }

    public function testItRejectsMissingAttributeClass(): void
    {
        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage('component attribute class not found: Example\\MissingAttribute' . self::REGENERATE_HINT);

        (new DiscoveredComponent('Example\\Component', 'Example\\MissingAttribute', []))->attribute();
    }

    public function testItRejectsClassNotImplementingComponentAttributeInterface(): void
    {
        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("attribute class '" . DiscoveredComponentTestPlainAttribute::class . "' does not implement ComponentAttributeInterface" . self::REGENERATE_HINT);

        (new DiscoveredComponent('Example\\Component', DiscoveredComponentTestPlainAttribute::class, []))->attribute();
    }

    /**
     * 探索結果ファイルの引数が属性のコンストラクタと合わない(探索結果ファイルが古い)。
     *
     * @return iterable<string, array{array<array-key, mixed>, class-string<Error>, string}>
     */
    public static function mismatchedArguments(): iterable
    {
        yield '必須の引数が無い' => [['hook' => 'init'], ArgumentCountError::class, 'Too few arguments'];
        yield '引数の型が違う' => [['hook' => 'init', 'priority' => 'ten'], TypeError::class, 'must be of type int'];
        yield '存在しない名前の引数' => [['hook' => 'init', 'priority' => 10, 'renamed' => true], Error::class, 'Unknown named parameter $renamed'];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param class-string<Error> $error
     */
    #[DataProvider('mismatchedArguments')]
    public function testItWrapsErrorRaisedByMismatchedArguments(array $arguments, string $error, string $reason): void
    {
        $component = new DiscoveredComponent('Example\\Component', DiscoveredComponentTestAttribute::class, $arguments);

        try {
            $component->attribute();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringStartsWith('failed to restore component attribute ' . DiscoveredComponentTestAttribute::class . ' of Example\\Component: ', $exception->getMessage());
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringEndsWith(self::REGENERATE_HINT, $exception->getMessage());
            self::assertInstanceOf($error, $exception->getPrevious());
        }
    }

    /**
     * 属性のコンストラクタが投げる例外(引数の検証の失敗等)も、Error と同じく作り直しの案内を含む DiscoveryException で包む。
     */
    public function testItWrapsExceptionThrownByAttributeConstructor(): void
    {
        $component = new DiscoveredComponent('Example\\Component', DiscoveredComponentTestValidatingAttribute::class, ['hook' => '']);

        try {
            $component->attribute();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertSame(
                'failed to restore component attribute ' . DiscoveredComponentTestValidatingAttribute::class . ' of Example\\Component: hook name must not be empty' . self::REGENERATE_HINT,
                $exception->getMessage(),
            );
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    /**
     * 属性クラス {NS}\Broken のファイルを autoload で読み込むときの失敗(探索結果ファイルより後に属性クラスが壊れた)。
     * {NS} は一意な名前空間に置き換える。
     *
     * @return iterable<string, array{string, class-string<Error>, string}>
     */
    public static function brokenAttributeClassFiles(): iterable
    {
        yield '未定義の親クラス(Error)' => ["<?php\n\nnamespace {NS};\n\nfinal class Broken extends MissingParent\n{\n}\n", Error::class, 'Class "{NS}\\MissingParent" not found'];
        yield '構文の誤り(ParseError)' => ["<?php\n\nnamespace {NS};\n\nfinal class Broken {\n", ParseError::class, "Unclosed '{' on line 5"];
    }

    /**
     * @param class-string<Error> $error
     */
    #[DataProvider('brokenAttributeClassFiles')]
    public function testItWrapsErrorRaisedByAutoloadOfAttributeClass(string $source, string $error, string $reason): void
    {
        $namespace = 'Example\\Tests\\BrokenAttribute' . uniqid();
        $attributeClass = $namespace . '\\Broken';
        $dir = $this->createTempDirectory('broken-attribute-class');
        $file = $dir . '/Broken.php';

        if (file_put_contents($file, str_replace('{NS}', $namespace, $source)) === false) {
            self::fail("ファイルを書き込めません: {$file}");
        }

        $autoload = static function (string $class) use ($attributeClass, $file): void {
            if ($class === $attributeClass) {
                require $file;
            }
        };
        spl_autoload_register($autoload);
        $this->autoloaders[] = $autoload;

        try {
            (new DiscoveredComponent('Example\\Component', $attributeClass, []))->attribute();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertSame(DiscoveryException::class, $exception::class);
            self::assertStringStartsWith("failed to restore component attribute {$attributeClass} of Example\\Component: ", $exception->getMessage());
            self::assertStringContainsString(str_replace('{NS}', $namespace, $reason), $exception->getMessage());
            self::assertStringEndsWith(self::REGENERATE_HINT, $exception->getMessage());
            self::assertInstanceOf(Error::class, $exception->getPrevious());
            self::assertSame($error, $exception->getPrevious()::class);
        }
    }

    /** コンストラクタが投げた DiscoveryException は二重に包まない */
    public function testItDoesNotWrapDiscoveryExceptionAgain(): void
    {
        $component = new DiscoveredComponent('Example\\Component', DiscoveredComponentTestValidatingAttribute::class, ['hook' => 'discovery']);

        try {
            $component->attribute();
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertSame('thrown by the attribute constructor', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscoveredComponentTestAttribute implements ComponentAttributeInterface
{
    public function __construct(
        private string $hook,
        private int $priority,
        public ?MetaValueType $type = null,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return $this->hook;
    }

    public function hookPriority(): int
    {
        return $this->priority;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}

/** コンストラクタで引数を検証する属性クラス */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscoveredComponentTestValidatingAttribute implements ComponentAttributeInterface
{
    public function __construct(
        private string $hook,
    ) {
        if ($hook === '') {
            throw new InvalidArgumentException('hook name must not be empty');
        }

        if ($hook === 'discovery') {
            throw new DiscoveryException('thrown by the attribute constructor');
        }
    }

    public function hookName(KernelContext $context): string
    {
        return $this->hook;
    }

    public function hookPriority(): int
    {
        return 10;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}

/** ComponentAttributeInterface を実装しない属性クラス */
#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveredComponentTestPlainAttribute {}
