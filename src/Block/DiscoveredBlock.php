<?php

declare(strict_types=1);

namespace TMSWP\Core\Block;

/**
 * 探索で見つけた独自ブロック1つ(ビルド出力のblock.json 1つ)。
 */
final readonly class DiscoveredBlock
{
    /**
     * @param non-empty-string $name block.jsonのname(`namespace/name`)
     * @param non-empty-string $path block.jsonのあるディレクトリの、テーマ・プラグインのルートからの相対パス
     *                               (区切りは`/`。例: `build/blocks/card`)
     * @param non-empty-string $metadataHash block.jsonの内容のSHA-256。内容をJSONとして読み直して書き出した
     *                                       文字列から求めるため、空白・改行の違いでは変わらない
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $metadataHash,
    ) {}
}
