<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
