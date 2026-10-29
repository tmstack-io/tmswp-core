<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

use Psr\Container\ContainerExceptionInterface;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
