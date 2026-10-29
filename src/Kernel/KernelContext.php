<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

enum KernelContext: string
{
    case Theme = 'theme';
    case Plugin = 'plugin';

    public function bootHook(): string
    {
        return match ($this) {
            self::Theme => 'after_setup_theme',
            self::Plugin => 'plugins_loaded',
        };
    }
}
