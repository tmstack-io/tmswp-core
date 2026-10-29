<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Html;

final readonly class Thumbnail
{
    /** img() の $attributes で上書きできない属性(小文字で照合する) */
    private const array RESERVED_ATTRIBUTES = ['src', 'alt', 'width', 'height'];

    public function __construct(
        public string $url,
        public ?int $width,
        public ?int $height,
        public string $alt,
    ) {
        if ($url === '') {
            throw new InvalidArgumentException('thumbnail url must not be empty');
        }
    }

    /**
     * img タグを Support\Html の属性レンダラで組み立てる。src は esc_url() を通り、許可されないプロトコルの URL
     * (フォールバックのフィルタが返した Thumbnail を含む)は Html::attributes() が例外にする。
     *
     * @param array<string, mixed> $attributes 追加の属性(Html::attributes() の値の規則に従う)。src・alt・width・height は無視する
     *
     * @throws InvalidArgumentException 属性名が不正、または URL のプロトコルが許可されない場合
     */
    public function img(array $attributes = []): string
    {
        $extra = array_filter(
            $attributes,
            static fn(string $name): bool => !\in_array(strtolower($name), self::RESERVED_ATTRIBUTES, true),
            ARRAY_FILTER_USE_KEY,
        );

        return '<img' . Html::attributes([
            'src' => $this->url,
            'alt' => $this->alt,
            ...$extra,
            'width' => $this->width,
            'height' => $this->height,
        ]) . '>';
    }
}
