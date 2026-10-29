<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use PhpToken;
use ReflectionClass;
use ReflectionNamedType;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Messages;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Testing\UnitTestCase;

/**
 * Form 層の構造の受け入れ基準(docs/05 の「API/構造」と、セキュリティのうちコードの形で確かめる項目)を、ソースの
 * トークンとリフレクションで確かめる。メソッドのシグネチャ(引数と戻り値の型)は PHPStan level max が確かめる。
 */
final class FormStructureTest extends UnitTestCase
{
    /** 同梱の規則(docs/05 §同梱ルール一覧の13種) */
    private const array RULES = [
        'Required', 'RequiredIf', 'MaxLength', 'MinLength', 'Email', 'Url', 'Regex',
        'Choice', 'Callback', 'Retype', 'Range', 'FileExtension', 'MaxFileSize',
    ];

    /** 動的な呼び出し・プロパティに使うマジックメソッド */
    private const array MAGIC_METHODS = ['__call', '__callstatic', '__get', '__set', '__isset', '__unset'];

    /**
     * src/Form の全ファイルが宣言するクラスは、docs/05 のクラス・enum・interface(と同梱の規則13種)だけで、旧 FW の
     * FormRenderer 系は無い。例外は docs/05 §例外 の3種。
     */
    public function testFormNamespaceDeclaresExactlyTheDocumentedTypes(): void
    {
        $expected = [
            'Composite\\CompositeField', 'ConfirmToken', 'ConfirmTokenResult', 'Field', 'FieldType', 'FieldView', 'Form',
            'FormAction', 'FormDefinition', 'FormHtml', 'FormRequest', 'FormResult', 'FormStep', 'JtiClaim', 'JtiStore',
            'Messages', 'OptionView', 'Rule\\Rule', 'Rule\\RuleInterface', 'SpamGuardInterface', 'StoredFile', 'SubmitToken',
            'SubmitTokenResult', 'TempFileGc', 'TempFileStore', 'TokenSigner', 'TokenStatus', 'UploadedFile',
            'ValidationContext', 'Validator',
            ...array_map(static fn(string $rule): string => "Rule\\{$rule}", self::RULES),
        ];
        sort($expected);

        self::assertSame($expected, array_keys(self::formTypes()));

        foreach ([FormConfigException::class, TempFileException::class, UnknownFieldException::class] as $exception) {
            self::assertTrue(class_exists($exception), $exception);
        }
    }

    /** 同梱の規則は final で RuleInterface を実装し、CompositeField は abstract、FieldType 等は enum */
    public function testKindsOfTheTypesMatchTheDesign(): void
    {
        foreach (self::RULES as $rule) {
            $reflection = new ReflectionClass("TMSWP\\Core\\Form\\Rule\\{$rule}");

            self::assertTrue($reflection->isFinal(), "{$rule} is not final");
            self::assertTrue($reflection->implementsInterface(RuleInterface::class), "{$rule} does not implement RuleInterface");
        }

        self::assertTrue((new ReflectionClass(CompositeField::class))->isAbstract());

        foreach (['FieldType', 'FormAction', 'FormStep', 'JtiClaim', 'TokenStatus'] as $enum) {
            self::assertTrue(enum_exists("TMSWP\\Core\\Form\\{$enum}"), $enum);
        }

        foreach (['SpamGuardInterface', 'Rule\\RuleInterface'] as $interface) {
            self::assertTrue(interface_exists("TMSWP\\Core\\Form\\{$interface}"), $interface);
        }
    }

    /**
     * Form は final の具象クラスで、abstract メソッド・`create(): static`・null センチネルのコンストラクタが無い
     * (コンストラクタの引数は必須の FormDefinition だけ)。
     */
    public function testFormIsAFinalConcreteClassConstructedOnlyFromADefinition(): void
    {
        $form = new ReflectionClass(Form::class);

        self::assertTrue($form->isFinal());
        self::assertFalse($form->isAbstract());
        self::assertFalse($form->hasMethod('create'));
        self::assertSame([], array_filter($form->getMethods(), static fn($method): bool => $method->isAbstract()));

        $constructor = $form->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(1, $constructor->getNumberOfParameters());
        self::assertSame(1, $constructor->getNumberOfRequiredParameters());
        $type = $constructor->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(FormDefinition::class, $type->getName());
        self::assertFalse($type->allowsNull());
    }

    /** Messages は静的な可変の状態を持たず、override()・reset() が無い(サイト全体の上書きはフィルタだけ) */
    public function testMessagesHasNoStaticMutableState(): void
    {
        $messages = new ReflectionClass(Messages::class);

        self::assertSame([], $messages->getProperties());
        self::assertFalse($messages->hasMethod('override'));
        self::assertFalse($messages->hasMethod('reset'));
    }

    /**
     * 全ファイルが declare(strict_types=1) で始まり、全メソッドが引数と戻り値の型を宣言する(コンストラクタの戻り値を
     * 除く)。マジックメソッド(__callStatic 等)を宣言せず、extract() を呼ばない(コールバック合成の旧 API の名残を
     * 持たない)。
     */
    public function testEveryFileIsStrictTypedAndHasNoMagicMethodOrExtract(): void
    {
        $violations = [];

        foreach (self::formTypes() as $name => ['class' => $class, 'file' => $file]) {
            $source = self::read($file);

            if (preg_match('/\A<\?php\s+declare\(strict_types=1\);/', $source) !== 1) {
                $violations[] = "{$file} does not start with declare(strict_types=1)";
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if (!$parameter->hasType()) {
                        $violations[] = "{$name}::{$method->getName()}() \${$parameter->getName()} has no type";
                    }
                }

                if ($method->getName() !== '__construct' && !$method->hasReturnType()) {
                    $violations[] = "{$name}::{$method->getName()}() has no return type";
                }
            }

            $tokens = self::significantTokens($file);

            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;
                $previous = $tokens[$index - 1] ?? null;

                if ($token->is(T_FUNCTION) && $next !== null && \in_array(strtolower($next->text), self::MAGIC_METHODS, true)) {
                    $violations[] = "{$file}:{$next->line} declares {$next->text}()";
                }

                if (
                    $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ltrim(strtolower($token->text), '\\') === 'extract' && $next?->text === '('
                    && !($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]))
                ) {
                    $violations[] = "{$file}:{$token->line} calls extract()";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /** トークンの署名の比較は hash_equals() で行い、署名(\$sig)を == や === で比べない(タイミング攻撃の対策) */
    public function testSignaturesAreComparedWithHashEquals(): void
    {
        $tokens = self::significantTokens(self::formTypes()['TokenSigner']['file']);
        $calls = [];
        $comparedSignatures = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_STRING) && ($tokens[$index + 1] ?? null)?->text === '(') {
                $calls[] = strtolower($token->text);
            }

            if ($token->is([T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL])) {
                foreach ([$tokens[$index - 1] ?? null, $tokens[$index + 1] ?? null] as $operand) {
                    if ($operand?->text === '$sig') {
                        $comparedSignatures[] = "line {$token->line}";
                    }
                }
            }
        }

        self::assertContains('hash_equals', $calls);
        self::assertSame([], $comparedSignatures);
    }

    /** コアの runtime の依存は psr/log と psr/container だけ(DR-14) */
    public function testCoreRuntimeDependsOnlyOnPsrLogAndPsrContainer(): void
    {
        $composer = json_decode(self::read(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);

        $require = array_keys($composer['require']);
        sort($require);

        self::assertSame(['php', 'psr/container', 'psr/log'], $require);
    }

    /**
     * src/Form の PHP ファイルが宣言する型(TMSWP\Core\Form\ からの相対名 => 型とファイル)。名前の順。
     *
     * @return array<string, array{class: class-string, file: string}>
     */
    private static function formTypes(): array
    {
        $root = \dirname(__DIR__, 3) . '/src/Form';
        $paths = glob($root . '/{,*/}*.php', GLOB_BRACE);
        self::assertIsArray($paths);
        $types = [];

        foreach ($paths as $path) {
            $relative = str_replace('/', '\\', substr($path, \strlen($root) + 1, -4));
            $class = 'TMSWP\\Core\\Form\\' . $relative;

            if (!class_exists($class) && !interface_exists($class)) {
                self::fail("{$path} does not declare {$class}");
            }

            $types[$relative] = ['class' => $class, 'file' => $path];
        }

        ksort($types);

        return $types;
    }

    /** @return list<PhpToken> 空白・コメントを除いたトークン */
    private static function significantTokens(string $file): array
    {
        return array_values(array_filter(
            PhpToken::tokenize(self::read($file)),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, $file);

        return $source;
    }
}
