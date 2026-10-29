<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/**
 * TMSWP のすべての例外が実装するマーカー interface。
 *
 * `catch (TmswpExceptionInterface $e)` で TMSWP が投げた例外だけをまとめて捕まえられる。
 */
interface TmswpExceptionInterface extends \Throwable
{
}
