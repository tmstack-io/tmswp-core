<?php

declare(strict_types=1);

// psr/container 1.0 の宣言(WP-CLI の phar が同梱する版)。引数の型が無いため、Container::get(string $id) と合わない
namespace Psr\Container;

interface ContainerInterface
{
    public function get($id);

    public function has($id);
}
