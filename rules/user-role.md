# ユーザーロールを追加する

ロール1つにつきクラス1つを`app/User/`に置く。`add_role()`・`add_cap()`は直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. `app/User/<Name>Role.php`を作る(例: `MemberRole`)。
2. `#[AsUserRole]`を付け、`TMSWP\Core\User\UserRole`を継承した`final class`にする。
3. `public static function definition(): UserRoleDefinition`で、`UserRoleDefinition::create('<ロール名>')`の定義を返す。
4. WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
5. `composer qa`を通す。

## 完全な実装例

`app/User/MemberRole.php`:

```php
<?php

declare(strict_types=1);

namespace App\User;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;
use WP_User;

#[AsUserRole]
final class MemberRole extends UserRole
{
    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_PREFECTURE = 'prefecture';

    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('member')
            ->label('会員')
            ->capabilities('read', 'read_member_pages');
    }

    public static function prefecture(WP_User|int $user): string
    {
        return self::meta($user)->string(self::META_PREFECTURE);
    }
}
```

- ロール名は英小文字・数字・`_`・`-`にする。違反は`EntityDefinitionException`になる。
- `label()`を省くと、ロール名がそのまま表示名になる。
- `capabilities()`は複数回呼ぶと追記する(重複は除く)。

## 登録のされ方

- Kernelは`init`のpriority 11でロールを登録する(タクソノミーの9、投稿タイプの10の後)。ロールの登録はFQCNの順に関係なく投稿タイプの後になる。
- WordPressはロールをデータベース(`wp_user_roles`オプション)に保存する。FWは、ロールが無いときだけ`add_role()`し、宣言したcapのうち欠けているものだけを`add_cap()`する。2回目以降のリクエストではデータベースに書き込まない。
- 初めて導入したリクエストでは、priority 11より前にロールが無い。ロールのcapを使う処理(`current_user_can()`・`MemberRole::create()`等)は、`init`のpriority 11より後で呼ぶ。

## capの削除と表示名の変更

FWはcapを自動で削除せず、表示名の変更もデータベースに反映しない。宣言とデータベースが違う場合は、登録のたびにKernelのロガーに`warning`で記録する(データベースは書き換えない)。

- 宣言から外したcapは、データベースに残る。WP-CLI(`wp cap remove member edit_posts`)か、app側の一度だけの処理で`get_role('member')`の`remove_cap()`を呼んで、明示的に削除する。
- 表示名は、最初の`add_role()`の時点のまま変わらない。変えるときは、ロールを削除し(`wp role delete member`)、次のリクエストで宣言どおりに作り直させる。
- warningを放置しない。宣言とデータベースを揃えるまで、リクエストごとに記録される。

## 既存のロールにcapを足す(builtin)

`administrator`等の既存のロールは、`UserRoleDefinition::builtin()`のアダプタを定義して使う。

`app/User/AdministratorRole.php`:

```php
<?php

declare(strict_types=1);

namespace App\User;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

#[AsUserRole]
final class AdministratorRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::builtin('administrator')
            ->capabilities('read_member_pages');
    }
}
```

- builtinの定義では`label()`を呼べない(呼ぶと`EntityDefinitionException`)。既存のロールの表示名は変えない。
- 宣言したcapのうち欠けているものだけを足す。既存のロールが持つほかのcapは、宣言に無くてもwarningにしない。
- アダプタ先のロールが登録の時点で無い場合は、`EntityDefinitionException`になる。

## ユーザーのメタ

- ロールのクラスにも`#[MetaSchema]`の定数を宣言できる(rules/meta.md)。ただしユーザーのメタはロールごとに分かれず、全ユーザーのメタとしてWordPressに登録される。同じキーを2つのロールのクラスで宣言すると、boot時に`DuplicateMetaKeyException`になる。
- `MemberRole::meta($user)`は、そのロールを持つユーザーだけに使える。ロールを持たないユーザーや存在しないユーザーは`InvalidArgumentException`になる。

## 認証(ログイン・会員登録)

FWはロールの宣言とユーザーのクエリまでを持ち、ログイン・会員登録の流れはapp側で書く。パスワードの照合やセッションは自作せず、WordPress標準のログイン(`wp-login.php`・`wp_login_form()`)とパスワード設定のメールを使う。

- 権限の判定は、ロールではなくcapで行う(`current_user_can('read_member_pages')`)。ロールの所属の判定(`MemberRole::is()`)は、ログイン後の振り分けや表示の切り替えに使う。
- 会員登録は、フォーム(rules/form.md)の完了処理から次のようなサービスを呼ぶ。パスワードはフォームで受け取らず、WordPressのパスワード設定のメールで利用者に決めさせる。
- メールアドレス・ログイン名の重複等で登録できない場合、`create()`は`EntityPersistenceException`を投げる。フォームでは事前に`email_exists()`等で検証してエラーを表示する。
- `create()`にはunslash済みの元の値を渡す(自分で`wp_slash()`しない)。FWがslashしてWordPressに渡すため、バックスラッシュ・引用符を含む値もそのまま保存され、パスワードはログインの画面で入力した値で照合できる。

`app/Service/MemberRegistration.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\User\MemberRole;

final class MemberRegistration
{
    /**
     * 会員を登録し、パスワード設定用のメールを送る。
     *
     * @throws \TMSWP\Core\Exception\EntityPersistenceException メールアドレスの重複等で登録できない場合
     */
    public function register(string $email, string $displayName): int
    {
        $userId = MemberRole::create($email, wp_generate_password(24), [
            'user_email' => $email,
            'display_name' => $displayName,
        ]);

        wp_new_user_notification($userId, null, 'user');

        return $userId;
    }
}
```

ログイン後の振り分けと管理バーの非表示は、フッククラスに書く。

`app/Hook/MemberAuthHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\User\MemberRole;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;
use WP_Error;
use WP_User;

#[AsHook]
final class MemberAuthHook extends Hook
{
    /** 会員はログイン後に会員ページへ移す */
    #[Filter('login_redirect')]
    public function redirectMember(string $redirectTo, string $requestedRedirectTo, WP_User|WP_Error $user): string
    {
        if ($user instanceof WP_User && MemberRole::is($user)) {
            return home_url('/members/');
        }

        return $redirectTo;
    }

    /** 会員にはフロントの管理バーを出さない */
    #[Filter('show_admin_bar')]
    public function hideAdminBar(bool $show): bool
    {
        return MemberRole::is() ? false : $show;
    }
}
```

会員向けのページテンプレートでは、capで表示を切り替える:

```php
<?php

declare(strict_types=1);

get_header();

if (current_user_can('read_member_pages')) :
    get_template_part('parts/member-content');
else :
    ?>
    <a href="<?php echo esc_url(wp_login_url((string) get_permalink())); ?>">ログインして閲覧する</a>
    <?php
endif;

get_footer();
```

## テンプレートでの使い方

```php
<?php

use App\User\MemberRole;
use TMSWP\Core\Query\SortOrder;

$page = MemberRole::query()
    ->whereMeta(MemberRole::META_PREFECTURE, '東京都')
    ->orderBy('registered', SortOrder::Desc)
    ->paginate(20);

foreach ($page as $member) {
    echo esc_html($member->display_name);
}
```

ロールのクラスの静的メソッド(`$user`は`WP_User|int`):

| メソッド | 動き |
|---|---|
| `query()` | このロールのユーザーの`UserQuery` |
| `get($user)` | このロールを持つユーザーなら`WP_User`、持たない・無ければnull |
| `findByEmail($email)`・`findByLogin($login)` | メールアドレス・ログイン名で1人。このロールでなければnull |
| `current()` | ログイン中のユーザーがこのロールなら`WP_User`、そうでなければnull |
| `is($user = null)` | このロールを持つか。引数を省くとログイン中のユーザーを判定する |
| `meta($user)` | メタの`MetaBag`(rules/meta.md)。このロールを持たないユーザーは`InvalidArgumentException` |
| `authorUrl($user)` | 投稿者のアーカイブのURL。無ければnull |
| `create($login, $password, $userdata)` | このロールのユーザーを作り、IDを返す。失敗は`EntityPersistenceException` |
| `role()` | ロール名 |

- `query()`が返す`UserQuery`は、比較演算子・キャスト型・並び順を`PostQuery`と同じenum(`Compare`・`CastType`・`SortOrder`)で受け取る。件数を指定しない`get()`は全件を返し、`idIn()`を空で呼ぶと0件になる。`limit()`・`offset()`と`paginate()`は併用できず、`limit()`の無い`offset()`も`InvalidQueryException`になる。`paginate()`が返す`PageResult`の全体の件数は`$page->total`で、`count($page)`はそのページの件数である。
- `search()`はキーワードに`*`が無ければ前後に`*`を付けて部分一致にする。検索する列は`TMSWP\Core\Query\UserSearchColumn`のケース(`Id`・`UserLogin`・`UserEmail`・`UserUrl`・`UserNicename`・`DisplayName`)で渡す(例: `MemberRole::query()->search('tanaka', UserSearchColumn::UserLogin, UserSearchColumn::DisplayName)`)。列を渡さなければ、WordPressがキーワードの形から列を選ぶ。
- `MemberRole::is()`は、引数を省くとログイン中のユーザーを判定する。`MemberRole::current()`はログイン中のユーザーがこのロールなら`WP_User`、そうでなければnullを返す。

## してはいけないこと

- `add_role()`・`add_cap()`を自分で呼んでロールを定義しない。
- `definition()`の中でWP関数や`__()`を呼ばない。`definition()`は純粋な関数にする。結果はクラスごとに使い回される。
- 宣言からcapを外しただけで、データベースから消えたと考えない。外したcapは明示的に削除する。
- 基底クラスに`#[AsUserRole]`を付けて継承させない。PHPの属性は継承されないため、子クラスは探索されない。具象クラスごとに付ける。
- `current_user_can('member')`のように、ロール名をcapとして判定に使わない。所属は`MemberRole::is()`、権限はcapで判定する。
- ログインの照合・セッション・パスワードの再設定を自作しない。

## 確認方法

1. `composer qa`が通ること。
2. 管理画面のユーザーの編集でロールを選べ、`wp cap list member`で宣言したcapが付いていること。
3. 宣言を変えた後に、Kernelのロガーにロールのwarningが記録されていないこと。

## 発展

- ユーザーのメタ: rules/meta.md
- 会員登録のフォーム: rules/form.md
- ロールの定義・ユーザーのクエリの仕様: TMSWPの設計文書 docs/03-entities.md
