<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

/**
 * コンポーネントの初期化のうち、WordPress のフックを待たずに Kernel::boot() の中で行う部分を持つ Initializer。
 *
 * コアのエンティティ(投稿タイプ・タクソノミー・ロール)の Initializer だけが実装し、メタスキーマを MetaSchemaRegistry
 * に登録する。これにより、boot の後なら init の前でも、app の #[Action('init')] の中でも meta() が使える。
 * WordPress への登録(register_post_type() 等)は initialize() が属性のフックで行う。拡張からの実装は想定しない。
 *
 * @internal
 */
interface BootInitializerInterface extends ComponentInitializerInterface
{
    /**
     * コンポーネント1件の boot 時の初期化を行う。Kernel が、拡張の登録とレジストリへの登録の後、本番の遅延 boot の
     * キャッチアップの前に、components の順(FQCN 昇順)に1回ずつ呼ぶ。WordPress の登録 API は呼ばないこと。
     */
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void;
}
