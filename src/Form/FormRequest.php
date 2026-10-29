<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class FormRequest
{
    /**
     * @param array<string, mixed> $post unslash 済みの POST の値
     * @param array<string, array<array-key, mixed>> $files $_FILES の形。エントリの形はここでは確かめず、Form が
     *                                                      取り込むときに確かめる(不正な形は項目のエラーにする)
     */
    public function __construct(
        public string $method,
        public array $post = [],
        public array $files = [],
    ) {}

    /**
     * スーパーグローバルからリクエストを作る。利用者の入力がどんな形でも例外を投げない。
     *
     * - 文字列でないキー(`$_POST[0]` のような数字のキー)は捨てる。項目の名前は英小文字で始まるため、どの項目にも
     *   当たらず、未定義の POST のキーと同じく使われないから。
     * - REQUEST_METHOD が無い(CLI 等)ときは GET とする。
     */
    public static function fromGlobals(): self
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && \is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $post = [];

        foreach ($_POST as $key => $value) {
            if (\is_string($key)) {
                $post[$key] = wp_unslash($value);
            }
        }

        $files = [];

        foreach ($_FILES as $key => $value) {
            if (\is_string($key) && \is_array($value)) {
                $files[$key] = $value;
            }
        }

        return new self($method, $post, $files);
    }

    public function isPost(): bool
    {
        return strtoupper($this->method) === 'POST';
    }

    public function post(string $key): mixed
    {
        return $this->post[$key] ?? '';
    }

    public function hasPost(string $key): bool
    {
        return \array_key_exists($key, $this->post);
    }
}
