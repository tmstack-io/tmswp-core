<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use Attribute;
use LogicException;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Kernel\KernelContext;

/**
 * コンポーネントの属性(AsPostType 等)を包み、その初期化を RecordingInitializer に記録させる属性
 * (fixture のテーマ・プラグインが使う)。
 *
 * フックと priority は包んだ属性のものを使う。初期化は RecordingInitializer が記録してから、包んだ属性の
 * Initializer に委ねるため、包んだ属性を直接付けた場合と同じく登録される。
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RecordedComponent implements ComponentAttributeInterface
{
    /**
     * @param string $attribute 包む属性のクラス名。引数を取らない ComponentAttributeInterface の実装
     */
    public function __construct(
        public string $attribute,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return $this->inner()->hookName($context);
    }

    public function hookPriority(): int
    {
        return $this->inner()->hookPriority();
    }

    public function initializerClass(): string
    {
        return RecordingInitializer::class;
    }

    /**
     * 包んだ属性を生成して返す。
     *
     * @throws LogicException 包む属性が ComponentAttributeInterface の実装でない場合
     */
    public function inner(): ComponentAttributeInterface
    {
        if (!is_a($this->attribute, ComponentAttributeInterface::class, true)) {
            throw new LogicException("{$this->attribute} must implement " . ComponentAttributeInterface::class);
        }

        return new ($this->attribute)();
    }
}
