<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\WpMetaStorage;
use WP_Error;
use WP_Role;
use WP_User;

abstract class UserRole
{
    /** @var array<class-string<self>, UserRoleDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): UserRoleDefinition;

    final public static function role(): string
    {
        return self::definitionFor(static::class)->role();
    }

    /**
     * ロールを登録する。UserRoleInitializer が、このクラスを探索した Kernel を渡して呼ぶ。
     *
     * WordPress はロールを DB(wp_user_roles オプション)に保存するため、add_role() は初回だけ行い、cap は欠けている
     * ものだけを足す。表示名の変更と宣言から外した cap は DB に反映しない(自動では消さない)ため、宣言と DB の差分を
     * 渡された Kernel のロガーに warning で記録して知らせる。
     */
    final public static function register(Kernel $kernel): void
    {
        $definition = self::definitionFor(static::class);
        $role = get_role($definition->role());

        if ($definition->isBuiltin() && !$role instanceof WP_Role) {
            throw new EntityDefinitionException("builtin role does not exist: {$definition->role()}");
        }

        if (!$definition->isBuiltin() && $role instanceof WP_Role) {
            self::warnDrift($definition, $role, $kernel);
        }

        if (!$definition->isBuiltin() && !$role instanceof WP_Role) {
            $role = add_role($definition->role(), $definition->roleLabel());

            if (!$role instanceof WP_Role) {
                throw new EntityDefinitionException("failed to add user role: {$definition->role()}");
            }
        }

        if (!$role instanceof WP_Role) {
            throw new EntityDefinitionException("user role is unavailable: {$definition->role()}");
        }

        foreach ($definition->capabilityList() as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }

    final public static function get(WP_User|int $user): ?WP_User
    {
        if ($user instanceof WP_User) {
            return \in_array(static::role(), (array) $user->roles, true) ? $user : null;
        }

        if ($user <= 0) {
            return null;
        }

        $resolved = get_user_by('id', $user);

        if (!$resolved instanceof WP_User || !\in_array(static::role(), (array) $resolved->roles, true)) {
            return null;
        }

        return $resolved;
    }

    final public static function findByEmail(string $email): ?WP_User
    {
        $user = get_user_by('email', $email);

        return $user instanceof WP_User ? static::get($user) : null;
    }

    final public static function findByLogin(string $login): ?WP_User
    {
        $user = get_user_by('login', $login);

        return $user instanceof WP_User ? static::get($user) : null;
    }

    final public static function current(): ?WP_User
    {
        if (!is_user_logged_in()) {
            return null;
        }

        return static::get(wp_get_current_user());
    }

    final public static function is(WP_User|int|null $user = null): bool
    {
        if ($user === null) {
            return static::current() instanceof WP_User;
        }

        return static::get($user) instanceof WP_User;
    }

    public static function query(): UserQuery
    {
        return UserQuery::of(static::class);
    }

    /**
     * 対象のユーザーのメタの MetaBag を返す。対象が無い、またはこのクラスのスコープ外の場合は、存在しない対象へのメタの
     * 書き込みを防ぐため、呼び出しの誤りとして例外にする(存在は get()・is() で確かめてから呼ぶ)。
     *
     * @throws InvalidArgumentException 対象が無い、またはこのクラスのスコープ外の場合
     *
     * @return MetaBag<static>
     */
    final public static function meta(WP_User|int $user): MetaBag
    {
        $resolved = static::get($user);

        if (!$resolved instanceof WP_User) {
            throw new InvalidArgumentException('cannot create MetaBag for missing or mismatched user');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function authorUrl(WP_User|int $user): ?string
    {
        $resolved = static::get($user);

        if (!$resolved instanceof WP_User) {
            return null;
        }

        $url = get_author_posts_url((int) $resolved->ID);

        return $url === '' ? null : $url;
    }

    /**
     * ユーザーを作る。user_login・user_pass・role は引数とこのクラスのロールにし、ID は無視する。値は元の(slash して
     * いない)値を渡す。
     *
     * wp_insert_user() は $userdata を slash 済み($_POST と同じ形)として受け取り、文字列を wp_unslash() して保存する
     * (users テーブルの列と、first_name 等・meta_input・連絡先を保存する add_user_meta()・update_user_meta())。
     * 元の値を保存するため、文字列をすべて slash して渡す。meta_input はキーも add_user_meta() が unslash するため、
     * WpMetaStorage::slashMetaInput() でキーと値を slash する(null と object を含む値は InvalidArgumentException)。
     * user_pass は unslash せずにそのままハッシュされる。ログインの画面の wp_signon() は slash 済みの $_POST['pwd'] で
     * 照合するため、slash したパスワードをハッシュさせる(wp-admin でパスワードを設定したユーザーと同じ形)。
     * role は set_role() が unslash せずに使うため、slash の後に設定する。
     *
     * $userdata は php-stubs/wordpress-stubs の wp_insert_user() の $userdata(`@phpstan-param`)のうち、配列の形。
     * stubs が要素の型を書いていない array(meta_input)だけは、WordPress が受け付ける要素の型を書く。
     *
     * @param array{
     *   ID?: int,
     *   user_pass?: string,
     *   user_login?: string,
     *   user_nicename?: string,
     *   user_url?: string,
     *   user_email?: string,
     *   display_name?: string,
     *   nickname?: string,
     *   first_name?: string,
     *   last_name?: string,
     *   description?: string,
     *   rich_editing?: string,
     *   syntax_highlighting?: string,
     *   comment_shortcuts?: string,
     *   admin_color?: string,
     *   use_ssl?: bool,
     *   user_registered?: string,
     *   user_activation_key?: string,
     *   spam?: bool,
     *   show_admin_bar_front?: string,
     *   role?: string,
     *   locale?: string,
     *   meta_input?: array<string, mixed>,
     * } $userdata
     *
     * @throws InvalidArgumentException meta_input に null、または object を含む値がある場合
     * @throws EntityPersistenceException wp_insert_user() が WP_Error を返した場合
     */
    final public static function create(string $login, string $password, array $userdata = []): int
    {
        $userdata['user_login'] = $login;
        $userdata['user_pass'] = $password;
        unset($userdata['ID']);
        $slashed = wp_slash($userdata);

        if (\array_key_exists('meta_input', $userdata)) {
            $slashed['meta_input'] = WpMetaStorage::slashMetaInput($userdata['meta_input']);
        }

        $slashed['role'] = static::role();
        $id = wp_insert_user($slashed);

        if ($id instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($id);
        }

        if (!\is_int($id)) {
            throw new EntityPersistenceException('wp_insert_user did not return an integer ID');
        }

        return $id;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /** @param class-string<self> $class */
    private static function definitionFor(string $class): UserRoleDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }

    /**
     * 既存の(builtin でない)ロールについて、宣言と DB の差分(表示名の違い・宣言に無い cap)を warning で記録する。
     * builtin のロールは表示名を宣言できず、宣言に無い cap を持つのが普通なので対象にしない。
     */
    private static function warnDrift(UserRoleDefinition $definition, WP_Role $role, Kernel $kernel): void
    {
        $storedLabel = wp_roles()->role_names[$definition->role()] ?? null;

        if ($storedLabel !== $definition->roleLabel()) {
            $kernel->logger()->warning('tmswp.user_role: the label of role {role} in the database ({stored}) differs from the declared label ({declared}); add_role() runs only once, so remove the role to apply the declared label', [
                'role' => $definition->role(),
                'stored' => $storedLabel,
                'declared' => $definition->roleLabel(),
            ]);
        }

        $undeclared = array_values(array_diff(array_map(strval(...), array_keys($role->capabilities)), $definition->capabilityList()));

        if ($undeclared !== []) {
            $kernel->logger()->warning('tmswp.user_role: role {role} has capabilities that are not declared: {capabilities}; capabilities removed from the declaration stay in the database until removed explicitly', [
                'role' => $definition->role(),
                'capabilities' => implode(', ', $undeclared),
            ]);
        }
    }
}
