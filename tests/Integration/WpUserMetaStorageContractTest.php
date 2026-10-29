<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Testing\MetaStorageContractTestCase;

/**
 * WpMetaStorageがMetaStorageInterfaceの契約を満たすことを、ユーザーのメタで確かめる。
 *
 * docs/04の受け入れ基準の「MetaStorageInterface契約テスト…WpMetaStorageが統合テスト(wp-env)でそれを満たす」を、
 * ユーザーのメタ(ObjectType::User)で確かめる。契約のテストを通じて、false・空文字の保存とnullの拒否、
 * バックスラッシュを含む値・配列・single=falseの行の往復、書き込みの戻り値とデータベースのエラーの例外も、
 * ユーザーのメタで確かめる。
 */
final class WpUserMetaStorageContractTest extends MetaStorageContractTestCase
{
    protected function storage(): MetaStorageInterface
    {
        return new WpMetaStorage(self::themeKernel());
    }

    protected function objectType(): ObjectType
    {
        return ObjectType::User;
    }

    /** ユーザーを作り、そのIDを返す。 */
    protected function createObject(): int
    {
        $userId = self::factory()->user->create();
        self::assertIsInt($userId);

        return $userId;
    }
}
