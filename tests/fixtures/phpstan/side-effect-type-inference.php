<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use TMSWP\Core\Tests\Unit\PHPStanSideEffectOption;
use TMSWP\Core\Tests\Unit\PHPStanSideEffectPost;

use function PHPStan\Testing\assertType;

assertType('int', PHPStanSideEffectPost::meta(1)->get(PHPStanSideEffectPost::META_COUNT));
assertType('list<string>|null', PHPStanSideEffectPost::meta(1)->get(PHPStanSideEffectPost::META_TAGS));
assertType('int|null', PHPStanSideEffectOption::Counter->get());
