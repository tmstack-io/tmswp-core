<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Meta;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaSchemaDefinition;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Testing\UnitTestCase;

final class MetaSchemaDefinitionTest extends UnitTestCase
{
    #[DataProvider('invalidSchemaProvider')]
    public function testItRejectsInvalidDeclarations(string $key, MetaSchema $schema): void
    {
        $this->expectException(InvalidSchemaException::class);

        MetaSchemaDefinition::fromAttribute(MetaSchemaDefinitionFixture::class, 'META_KEY', $key, $schema);
    }

    /**
     * WP の register_meta() が拒否する(array)、または REST の値が常に空になる(object)組み合わせ。
     *
     * @param bool|array<string, mixed> $showInRest
     */
    #[DataProvider('restSchemaMissingProvider')]
    public function testItRejectsArrayAndObjectMetaShownInRestWithoutItemSchema(MetaValueType $type, bool|array $showInRest, string $message): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage($message . ': ' . MetaSchemaDefinitionFixture::class . '::META_KEY');

        MetaSchemaDefinition::fromAttribute(MetaSchemaDefinitionFixture::class, 'META_KEY', 'rest_key', self::schema(type: $type, showInRest: $showInRest));
    }

    /** @return iterable<string, array{MetaValueType, bool|array<string, mixed>, string}> */
    public static function restSchemaMissingProvider(): iterable
    {
        $arrayMessage = "array meta shown in REST requires showInRest['schema'] with items";
        $objectMessage = "object meta shown in REST requires showInRest['schema'] with properties or patternProperties or additionalProperties";

        yield 'array with true' => [MetaValueType::Array, true, $arrayMessage];
        yield 'array without schema' => [MetaValueType::Array, ['prepare_callback' => 'strval'], $arrayMessage];
        yield 'array without items' => [MetaValueType::Array, ['schema' => ['type' => 'array']], $arrayMessage];
        yield 'object with true' => [MetaValueType::Object, true, $objectMessage];
        yield 'object without properties' => [MetaValueType::Object, ['schema' => ['type' => 'object']], $objectMessage];
    }

    /** @param bool|array<string, mixed> $showInRest */
    #[DataProvider('restSchemaProvidedProvider')]
    public function testItAcceptsArrayAndObjectMetaWithItemSchemaOrHiddenFromRest(MetaValueType $type, bool|array $showInRest): void
    {
        $definition = MetaSchemaDefinition::fromAttribute(MetaSchemaDefinitionFixture::class, 'META_KEY', 'rest_key', self::schema(type: $type, showInRest: $showInRest));

        self::assertSame($showInRest, $definition->showInRest);
    }

    /** @return iterable<string, array{MetaValueType, bool|array<string, mixed>}> */
    public static function restSchemaProvidedProvider(): iterable
    {
        yield 'array hidden from REST' => [MetaValueType::Array, false];
        yield 'array with items' => [MetaValueType::Array, ['schema' => ['items' => ['type' => 'string']]]];
        yield 'object hidden from REST' => [MetaValueType::Object, false];
        yield 'object with properties' => [MetaValueType::Object, ['schema' => ['properties' => ['name' => ['type' => 'string']]]]];
        yield 'object with additionalProperties' => [MetaValueType::Object, ['schema' => ['additionalProperties' => ['type' => 'integer']]]];
        yield 'string with true' => [MetaValueType::String, true];
    }

    /** @return iterable<string, array{string, MetaSchema}> */
    public static function invalidSchemaProvider(): iterable
    {
        yield 'empty key' => ['', self::schema()];
        yield 'default type mismatch' => ['price', self::schema(type: MetaValueType::Integer, default: '1200')];
        yield 'single false with default' => ['related_id', self::schema(default: [], single: false)];
        yield 'empty sanitize string' => ['code', self::schema(sanitize: '')];
        yield 'invalid sanitize tuple' => ['code', self::schema(sanitize: [MetaSchemaDefinitionFixture::class])];
        yield 'missing sanitize class' => ['code', self::schema(sanitize: ['Missing\\MetaSchemaSanitizer', 'sanitize'])];
    }

    /**
     * @param bool|array<string, mixed> $showInRest
     */
    private static function schema(
        MetaValueType $type = MetaValueType::String,
        mixed $default = null,
        bool $single = true,
        bool|array $showInRest = false,
        string $description = '',
        mixed $sanitize = null,
    ): MetaSchema {
        $schema = (new ReflectionClass(MetaSchema::class))->newInstanceArgs([
            $type,
            $default,
            $single,
            $showInRest,
            $description,
            $sanitize,
        ]);

        if (!$schema instanceof MetaSchema) {
            throw new LogicException('MetaSchema fixture を生成できません');
        }

        return $schema;
    }
}

final class MetaSchemaDefinitionFixture {}
