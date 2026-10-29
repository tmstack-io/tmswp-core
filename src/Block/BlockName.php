<?php

declare(strict_types=1);

namespace TMSWP\Core\Block;

/**
 * ブロック名(`namespace/name`)の形式。
 */
final class BlockName
{
    /**
     * WordPressのWP_Block_Type_Registry::register()が受け付けるブロック名の形式(英小文字・数字・`-`の
     * `namespace/name`)。
     */
    private const string PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    /** ブロック名がWordPressに登録できる形式か。 */
    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
