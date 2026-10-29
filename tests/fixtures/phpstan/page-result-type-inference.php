<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanProductPost;

use function PHPStan\Testing\assertType;

assertType('TMSWP\Core\Query\PageResult<WP_Post>', PHPStanProductPost::query()->paginate(10));
assertType('list<WP_Post>', PHPStanProductPost::query()->paginate(10)->items);
