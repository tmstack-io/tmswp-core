<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveredHookMethod
{
    /**
     * @param non-empty-string $class
     * @param int $acceptedArgs メソッドの宣言引数数
     * @param HookMethodKind $kind 宣言した属性の種類(#[Action] / #[Filter])
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $hook,
        public int $priority,
        public int $acceptedArgs,
        public HookMethodKind $kind,
    ) {}
}
