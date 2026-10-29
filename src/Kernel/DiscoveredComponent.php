<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Throwable;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Exception\DiscoveryException;

final readonly class DiscoveredComponent
{
    /**
     * @param non-empty-string $class
     * @param non-empty-string $attributeClass
     * @param array<array-key, mixed> $attributeArguments
     */
    public function __construct(
        public string $class,
        public string $attributeClass,
        public array $attributeArguments,
    ) {}

    /**
     * 探索結果の属性クラスと引数から属性を作り直す。
     *
     * 走査のときに生成できた属性を復元できないのは、コードが探索結果ファイルより新しい(設定の誤り)ことを示すため、
     * 失敗はどれも作り直しの案内を含む DiscoveryException にし、debug に関係なく投げる。
     *
     * @throws DiscoveryException 属性クラスが無い、ComponentAttributeInterface を実装しない、属性クラスの autoload で
     *                            例外(未定義の親クラスによる Error・構文の誤りによる ParseError 等)が起きた、または生成で
     *                            例外(引数の不一致による ArgumentCountError・TypeError 等の Error を含む)が起きた場合。
     *                            autoload・生成で起きた例外は previous に持つ
     */
    public function attribute(): ComponentAttributeInterface
    {
        try {
            // class_exists() は autoload で属性クラスのファイルを読み込むため、そこで起きる失敗も生成の失敗と同じく包む
            if (!class_exists($this->attributeClass)) {
                throw $this->restoreFailure("component attribute class not found: {$this->attributeClass}");
            }

            $attribute = new $this->attributeClass(...$this->attributeArguments);
        } catch (DiscoveryException $exception) {
            // 作り直しの案内を含むはずの DiscoveryException は二重に包まない
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->restoreFailure("failed to restore component attribute {$this->attributeClass} of {$this->class}: {$exception->getMessage()}", $exception);
        }

        if (!$attribute instanceof ComponentAttributeInterface) {
            throw $this->restoreFailure("attribute class '{$this->attributeClass}' does not implement ComponentAttributeInterface");
        }

        return $attribute;
    }

    private function restoreFailure(string $reason, ?Throwable $previous = null): DiscoveryException
    {
        return new DiscoveryException("{$reason}; regenerate the discovery file with 'wp tmswp discover' and deploy it together with the code", previous: $previous);
    }
}
