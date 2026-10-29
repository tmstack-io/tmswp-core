<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Testing\MetaStorageContractTestCase;

/**
 * WpMetaStorageがMetaStorageInterfaceの契約を満たすことを、タームのメタで確かめる。
 *
 * docs/04の受け入れ基準の「MetaStorageInterface契約テスト…WpMetaStorageが統合テスト(wp-env)でそれを満たす」を、
 * タームのメタ(ObjectType::Term)で確かめる。契約のテストを通じて、false・空文字の保存とnullの拒否、
 * バックスラッシュを含む値の往復、書き込みの戻り値とデータベースのエラーの例外も、タームのメタで確かめる。
 */
final class WpTermMetaStorageContractTest extends MetaStorageContractTestCase
{
    protected function storage(): MetaStorageInterface
    {
        return new WpMetaStorage(self::themeKernel());
    }

    protected function objectType(): ObjectType
    {
        return ObjectType::Term;
    }

    /** fixtureのテーマの製品カテゴリ(tmswp_product_cat)のタームを作り、そのIDを返す。 */
    protected function createObject(): int
    {
        $term = wp_insert_term('Contract ' . uniqid(), 'tmswp_product_cat');
        self::assertIsArray($term);

        return (int) $term['term_id'];
    }
}
