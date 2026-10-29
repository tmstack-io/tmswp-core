<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaValueType;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class MetaSchema
{
    /**
     * @param bool|array<string, mixed> $showInRest
     * @param string|array{class-string, string}|null $sanitize
     */
    public function __construct(
        public MetaValueType $type = MetaValueType::String,
        public mixed $default = null,
        public bool $single = true,
        public bool|array $showInRest = false,
        public string $description = '',
        public string|array|null $sanitize = null,
    ) {}
}
