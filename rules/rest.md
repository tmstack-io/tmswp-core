# REST APIのルートを追加する

テーマ・プラグインのREST APIのルートは、WordPress標準の`register_rest_route()`で、`app/Hook/`の`#[AsHook]`のクラスの`rest_api_init`のメソッドに書く。TMSWPはRESTのルートを宣言する仕組みを持たない。引数の検証と権限の判定はWordPressの仕組みでできるためで、このレシピはその書き方の手本である。応答の形とエラーコードは、サイトごとに決める。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. `app/Hook/<関心事>ApiHook.php`を作る。`#[AsHook]`を付け、`TMSWP\Core\Hook\Hook`を継承した`final class`にする(rules/hooks.md)。
2. `#[Action('rest_api_init')]`のメソッドで`register_rest_route()`を呼ぶ。ルートの名前空間は`<テキストドメイン>/v1`の形にする(例: `my-theme/v1`)。
3. ルートごとに`permission_callback`を書く(下の「権限の判定」)。
4. 受け取る引数をすべて`args`に宣言し、JSON Schemaの`type`・`required`・`enum`・`maxLength`等と、`'validate_callback' => 'rest_validate_request_arg'`を書く。`sanitize_callback`は書かない(下の「引数の検証」)。
5. 処理のメソッドは、`WP_REST_Request`から`args`に宣言した引数だけを読み、`WP_REST_Response`か`WP_Error`を返す。例外は処理のメソッドの中で応答に変える(下の「応答とエラー」)。
6. `composer discover`で探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
7. `composer qa`を通し、下の「確認方法」で、検証と権限の判定が効くことを確かめる。

## 完全な実装例

投稿をキーワードで探すルート(ログイン不要)と、投稿の抜粋を書き換えるルート(その投稿の編集の権限が要る)。

`app/Hook/PostApiHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use RuntimeException;
use Throwable;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Log\Log;
use UnexpectedValueException;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

#[AsHook]
final class PostApiHook extends Hook
{
    private const string ROUTE_NAMESPACE = 'my-theme/v1';

    #[Action('rest_api_init')]
    public function registerRoutes(): void
    {
        // GET /wp-json/my-theme/v1/posts/search?keyword=<語>&orderby=<date|title>&per_page=<1〜20>
        register_rest_route(self::ROUTE_NAMESPACE, '/posts/search', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            // 公開済みの投稿のタイトルとURLだけを返す。サイトの一覧のページで誰でも読める情報のため、ログインを求めない
            'permission_callback' => '__return_true',
            'args' => [
                'keyword' => [
                    'type' => 'string',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => 50,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                'orderby' => [
                    'type' => 'string',
                    'enum' => ['date', 'title'],
                    'default' => 'date',
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                    'default' => 10,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);

        // POST /wp-json/my-theme/v1/posts/<投稿のID>/excerpt(本文の引数: excerpt)
        register_rest_route(self::ROUTE_NAMESPACE, '/posts/(?P<id>\d+)/excerpt', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'updateExcerpt'],
            'permission_callback' => [$this, 'canEditPost'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'minimum' => 1,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                'excerpt' => [
                    'type' => 'string',
                    'required' => true,
                    'maxLength' => 200,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $orderby = self::stringParam($request, 'orderby');
            $posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                's' => self::stringParam($request, 'keyword'),
                'orderby' => $orderby,
                'order' => $orderby === 'title' ? 'ASC' : 'DESC',
                'posts_per_page' => self::intParam($request, 'per_page'),
            ]);
            $items = [];

            foreach ($posts as $post) {
                if ($post instanceof WP_Post) {
                    $items[] = ['id' => $post->ID, 'title' => get_the_title($post), 'url' => get_permalink($post)];
                }
            }

            return new WP_REST_Response(['items' => $items], 200);
        } catch (Throwable $e) {
            return self::internalError($e);
        }
    }

    /**
     * WordPressは、引数の検証に失敗した要求でも、応答のAllowヘッダを作るためにこのメソッドを呼ぶ。そのときのidは
     * 検証の前の値(URLの文字列)のため、型を確かめてから使い、例外を投げない。
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function canEditPost(WP_REST_Request $request): bool
    {
        $id = $request->get_param('id');

        return is_numeric($id) && current_user_can('edit_post', (int) $id);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function updateExcerpt(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $id = self::intParam($request, 'id');
            $post = get_post($id);

            if (!$post instanceof WP_Post || $post->post_type !== 'post') {
                return new WP_Error('my_theme_post_not_found', __('The post was not found.', 'my-theme'), ['status' => 404]);
            }

            // wp_update_post()は受け取った値のスラッシュを外すため、wp_slash()を通して渡す
            $result = wp_update_post(wp_slash(['ID' => $id, 'post_excerpt' => self::stringParam($request, 'excerpt')]), true);

            if ($result instanceof WP_Error) {
                throw new RuntimeException("the excerpt of post {$id} could not be updated: {$result->get_error_message()}");
            }

            return new WP_REST_Response(['id' => $id, 'excerpt' => get_post_field('post_excerpt', $id, 'raw')], 200);
        } catch (Throwable $e) {
            return self::internalError($e);
        }
    }

    /**
     * argsで検証した文字列の引数を読む。型が違うのは、argsの宣言の誤りである。
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function stringParam(WP_REST_Request $request, string $name): string
    {
        $value = $request->get_param($name);

        if (!\is_string($value)) {
            throw new UnexpectedValueException("the REST parameter {$name} is not a string");
        }

        return $value;
    }

    /**
     * argsで検証した整数の引数を読む。`type`が`integer`の引数は、検証の後に整数に変換されている。
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    private static function intParam(WP_REST_Request $request, string $name): int
    {
        $value = $request->get_param($name);

        if (!\is_int($value)) {
            throw new UnexpectedValueException("the REST parameter {$name} is not an integer");
        }

        return $value;
    }

    /**
     * 例外をKernelのロガーに記録し、500の応答に変える。例外のクラスとメッセージは、Kernelのdebugのときだけ応答に入れる。
     */
    private static function internalError(Throwable $e): WP_Error
    {
        Log::scope('rest')->error('a REST request failed', ['exception' => $e]);
        $data = ['status' => 500];

        if (Kernel::forClass(self::class)->config()->debug) {
            $data['exception'] = $e::class;
            $data['message'] = $e->getMessage();
        }

        return new WP_Error('my_theme_internal_error', __('The request could not be processed.', 'my-theme'), $data);
    }
}
```

- 例のテキストドメイン`my-theme`(ルートの名前空間・エラーコードの接頭辞・`__()`の第2引数)は、テーマのstyle.css(プラグインはプラグインヘッダ)の`Text Domain`に置き換える。
- フックのメソッド`registerRoutes()`のほかのpublicメソッド(`search()`等)は、WordPressがRESTの要求のときに呼ぶ。属性は付けない。

## 権限の判定(permission_callback)

- `permission_callback`を省くと、WordPress 5.5以降は`_doing_it_wrong()`の通知(`The REST API route definition for my-theme/v1/... is missing the required permission_callback argument.`)を出し、誰でもそのルートを呼べる。
- ログインの要らないルートは`'__return_true'`を明示し、ログインを求めなくてよい理由をコメントに書く。
- 書き込むルートは、`current_user_can()`で、操作の対象に対する権限(例: `edit_post`と投稿のID)を確かめる。ログインしているかだけ(`is_user_logged_in()`)で判定しない。購読者も通る。
- `permission_callback`が`false`を返すと、WordPressは、ログインしていない要求には401を、ログインしている要求には403を、どちらもコード`rest_forbidden`で返す。
- WordPressは、引数の検証(下の「引数の検証」)を`permission_callback`より先に行う。権限の無い要求でも、引数が不正なら400が返る。
- WordPressは、応答の`Allow`ヘッダを作るために、そのルートのすべての`permission_callback`を呼ぶ(`rest_send_allow_header()`)。検証に成功した要求ではもう一度呼び、検証に失敗した要求でも呼ぶ。検証に失敗した要求では、引数は検証も型の変換もされていない値(URLの文字列等)である。`permission_callback`では引数の型を確かめてから使い、例外を投げない(例の`canEditPost()`)。例外を投げると、PHPの致命的なエラーになる。要求が`Accept`か`Content-Type`でJSONを示す(`wp_is_json_request()`が真。`apiFetch`の要求等)ときは、コード`internal_server_error`のJSONの500が、それ以外のときはHTMLの500の致命的なエラーの画面(「There has been a critical error on this website.」)が返る。どちらの場合も、例外はKernelのロガーに記録されない。

## 引数の検証(args)

- `args`の各引数のJSON Schema(`type`・`enum`・`minLength`・`maxLength`・`minimum`・`maximum`等)は、`validate_callback`に書いた`rest_validate_request_arg`が検証する。違反すると処理のメソッドは呼ばれず、400(`rest_invalid_param`)が返る。`required`の引数が無いときも400(`rest_missing_callback_param`)が返る。
- `validate_callback`を省いても、`type`を書いて`sanitize_callback`を書かない引数は、WordPressが`sanitize_callback`の既定(`rest_parse_request_arg`)の中で検証する。しかし、`sanitize_callback`(例: `sanitize_text_field`)を足すとこの既定が外れ、`type`・`enum`・`maxLength`等は検証されなくなる。`type`を書かない引数は、`validate_callback`を書いても、型と、型ごとの制約(`maxLength`・`minimum`等)が検証されない。このとき`rest_validate_request_arg`が確かめるのは`enum`・`format`だけ(`anyOf`・`oneOf`を書かない場合)で、WordPressは`_doing_it_wrong()`の通知(`The "type" schema keyword for ... is required.`)を出す。書き方によって検証が外れないよう、すべての引数に`type`と`'validate_callback' => 'rest_validate_request_arg'`を書く。
- `sanitize_callback`は書かない。検証の後の`type`への変換は、既定の`rest_parse_request_arg`に任せる。`sanitize_callback`を書くと既定が外れ、`integer`の引数が整数に変換されないまま処理のメソッドに渡る(例では`intParam()`が例外を投げ、500になる)。
- 検証の後、`sanitize_callback`の既定が値を`type`に変換する(`integer`の引数は、URLの文字列から整数になる)。処理のメソッドでは、`get_param()`の戻り値(`mixed`)を`is_int()`・`is_string()`で絞り込んでから使う(例の`intParam()`・`stringParam()`)。
- `args`に宣言していない引数は検証されない。処理のメソッドで読まない。

## 応答とエラー

応答の形とエラーコードは、サイトで決める。例では、成功は`WP_REST_Response`に配列を入れ、失敗は`WP_Error`で返す。`WP_Error`は、WordPressが`{"code": "...", "message": "...", "data": {"status": 404}}`の形のJSONにする。

| コード | ステータス | 出すもの | 場面 |
|---|---|---|---|
| `rest_missing_callback_param` | 400 | WordPress | `required`の引数が無い |
| `rest_invalid_param` | 400 | WordPress | 引数がJSON Schemaに違反する |
| `rest_forbidden` | 401・403 | WordPress | `permission_callback`が`false`を返した |
| `rest_cookie_invalid_nonce` | 403 | WordPress | `X-WP-Nonce`の値が正しくない |
| `my_theme_post_not_found` | 404 | アプリ | IDの対象が投稿タイプ`post`でない(例のルート。存在しないIDは、`edit_post`の権限の判定で403になる) |
| `my_theme_internal_error` | 500 | アプリ | 処理のメソッドで例外が起きた(例のルート) |

- アプリのエラーコードはテキストドメインを接頭辞にし、WordPressのコード(`rest_`で始まる)と区別する。
- 処理のメソッドから例外が出ると、WordPressのRESTは例外を応答に変えず、PHPの致命的なエラーになる。要求が`Accept`か`Content-Type`でJSONを示す(`wp_is_json_request()`が真。`apiFetch`の要求等)ときは、コード`internal_server_error`のJSONの500が、それ以外のときはHTMLの500の致命的なエラーの画面(「There has been a critical error on this website.」)が返る。どちらの場合も、例外はKernelのロガーに記録されない。例のように、処理のメソッドで`Throwable`を受け止め、Kernelのロガーに記録してから500の`WP_Error`を返す。
- 例外のメッセージ・スタックトレース・SQLを応答に入れてよいのは、Kernelのdebugのときだけである。debugは`Kernel::forClass(self::class)->config()->debug`で読む(省略すると`WP_DEBUG`の値)。`WP_DEBUG`を直接読まない。

## Cookie認証(X-WP-Nonce)

ログインした利用者のブラウザから呼ぶときは、WordPress標準のCookie認証をそのまま使う。

- 要求に、`wp_create_nonce('wp_rest')`で作ったnonceを`X-WP-Nonce`ヘッダで付ける。nonceが無い要求は、ログインのCookieがあってもログインしていない利用者(ID 0)として扱われ、権限の要るルートは401になる。nonceが正しくなければ403(`rest_cookie_invalid_nonce`)になる。
- JavaScriptは`@wordpress/api-fetch`(スクリプトのハンドル`wp-api-fetch`)で呼ぶ。WordPressが`wp-api-fetch`にnonceを付ける処理を足しているため、nonceを自分で渡さなくてよい。

```js
import apiFetch from '@wordpress/api-fetch';

const result = await apiFetch({
    path: '/my-theme/v1/posts/12/excerpt',
    method: 'POST',
    data: { excerpt: '抜粋の文' },
});
```

- `@wordpress/scripts`でビルドするスクリプト(rules/blocks.md)は、`import`から`wp-api-fetch`への依存が`*.asset.php`に書かれる。ビルドしないスクリプトは、`wp_enqueue_script()`の依存に`wp-api-fetch`を書き、`wp.apiFetch(...)`で呼ぶ。
- WordPressの外のシステムから呼ぶときは、WordPress標準のアプリケーションパスワード(HTTPSのサイトで使える)のBasic認証を使う。

## してはいけないこと

- `permission_callback`を省かない。書き込むルートに`__return_true`を使わない。
- `type`を省かない。`validate_callback`を省かない。
- `sanitize_callback`を書かない。
- `$_GET`・`$_POST`・`$_REQUEST`・`php://input`を読まない。`WP_REST_Request`から、`args`に宣言した引数だけを読む。
- 処理のメソッドで`echo`・`exit`・`wp_send_json()`を使わない。`WP_REST_Response`か`WP_Error`を返す。
- 例外を記録せずに捨てない。受け止めたら、Kernelのロガーに記録してから応答に変える。
- Kernelのdebugでないときに、例外のメッセージ・スタックトレース・SQLを応答に入れない。
- `functions.php`・プラグインの本体ファイルに`add_action('rest_api_init', ...)`を書かない(rules/hooks.md)。
- 独自の認証(独自のCookie・セッション・クエリに付けたトークン等)を作らない。
- WordPress標準の名前空間(`wp/v2`等)にルートを足さない。

## 確認方法

`curl`のURLの`http://localhost:8888`は、開発環境のサイトのURLの例である。自分の開発環境のサイトのURLに置き換える。

1. `composer qa`が通ること(`wp tmswp discover --check`で、探索結果ファイルにフックのクラスが入っていることも確かめる)。
2. `curl -i 'http://localhost:8888/wp-json/my-theme/v1/posts/search?keyword=hello'`が200と`items`を返すこと。パーマリンクが「基本」のサイトでは、`/wp-json/my-theme/v1/...`の代わりに`/?rest_route=/my-theme/v1/...`を使う。
3. `curl -i 'http://localhost:8888/wp-json/my-theme/v1/posts/search?keyword=hello&orderby=author'`(`enum`に無い値)が400(`rest_invalid_param`)を返し、`keyword`を省くと400(`rest_missing_callback_param`)を返すこと。
4. `curl -i -X POST -d 'excerpt=test' 'http://localhost:8888/wp-json/my-theme/v1/posts/1/excerpt'`(ログインしていない要求)が401(`rest_forbidden`)を返すこと。
5. 管理者でログインしたブラウザから`apiFetch`で抜粋を書き換えられ、購読者では403(`rest_forbidden`)になること。

## 発展

- フックのクラスの書き方: rules/hooks.md
- 投稿タイプ・投稿のクエリ: rules/post-type.md
- 本番へのデプロイ(探索結果ファイルを上げる): rules/deploy.md
