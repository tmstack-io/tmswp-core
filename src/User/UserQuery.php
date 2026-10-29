<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\MetaClause;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\UserSearchColumn;
use WP_User;
use WP_User_Query;

/**
 * WpUserQueryArgs は php-stubs/wordpress-stubs の WP_User_Query::__construct() の $query(`@phpstan-param`)と同じ配列の形。
 * stubs が要素の型を書いていない array(meta_query・orderby の配列)だけは要素の型を書く
 * (PHPStan level max は要素の型の無い array を許さないため)。
 *
 * @phpstan-type WpUserQueryArgs array{
 *   blog_id?: int,
 *   role?: string|string[],
 *   role__in?: string[],
 *   role__not_in?: string[],
 *   meta_key?: string|string[],
 *   meta_value?: string|string[],
 *   meta_compare?: string,
 *   meta_compare_key?: string,
 *   meta_type?: string,
 *   meta_type_key?: string,
 *   meta_query?: array<int|string, string|array<int|string, mixed>>,
 *   capability?: string|string[],
 *   capability__in?: string[],
 *   capability__not_in?: string[],
 *   include?: int[],
 *   exclude?: int[],
 *   search?: string,
 *   search_columns?: string[],
 *   orderby?: string|array<string, string>,
 *   order?: string,
 *   offset?: int,
 *   number?: int,
 *   paged?: int,
 *   count_total?: bool,
 *   fields?: string|string[],
 *   who?: string,
 *   has_published_posts?: bool|string[],
 *   nicename?: string,
 *   nicename__in?: string[],
 *   nicename__not_in?: string[],
 *   login?: string,
 *   login__in?: string[],
 *   login__not_in?: string[],
 *   cache_results?: bool,
 * }
 */
final class UserQuery
{
    /** @var WpUserQueryArgs */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta() の並び替えだけに使う句のグループ */
    private array $orderMetaGroups = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var WpUserQueryArgs */
    private array $rawArgs = [];

    /**
     * 空の IN 指定を受けた条件(WP_User_Query の引数名)。1つでもあれば結果は0件と確定し、終端メソッドは
     * WP_User_Query を実行しない(WordPress は空の IN 指定を「条件なし」と扱い、全件を返すため)。
     *
     * @var array<string, true>
     */
    private array $emptyIn = [];

    /** @var list<int>|null idIn() の ID。null は idIn() を呼んでいない */
    private ?array $idIn = null;

    /** @var list<int> idNotIn() の ID */
    private array $idNotIn = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private function __construct(
        private readonly string $role,
    ) {}

    /** @param class-string<UserRole> $userRoleClass */
    public static function of(string $userRoleClass): self
    {
        return new self($userRoleClass::role());
    }

    /** 列を渡さなければ、WP_User_Query がキーワードの形から列を選ぶ。 */
    public function search(string $keyword, UserSearchColumn ...$columns): self
    {
        $this->args['search'] = str_contains($keyword, '*') ? $keyword : '*' . $keyword . '*';

        if ($columns !== []) {
            $this->args['search_columns'] = array_map(static fn(UserSearchColumn $column): string => $column->value, array_values($columns));
        }

        return $this;
    }

    /** 空で呼ぶと結果は0件になる。idNotIn() と併用すると、差し引いた ID に絞る(差し引いて空なら0件)。 */
    public function idIn(int ...$ids): self
    {
        $this->idIn = array_values($ids);

        return $this;
    }

    /** 空で呼ぶと除外しない(条件なし)。 */
    public function idNotIn(int ...$ids): self
    {
        $this->idNotIn = array_values($ids);

        return $this;
    }

    /** Compare::In に空の配列を渡すと結果は0件になり、Compare::NotIn に空の配列を渡すと条件を足さない。 */
    public function whereMeta(string $key, mixed $value, Compare $compare = Compare::Eq, CastType $type = CastType::Char): self
    {
        if ($value === [] && $compare === Compare::In) {
            $this->emptyIn['meta_query'] = true;

            return $this;
        }

        if ($value === [] && $compare === Compare::NotIn) {
            return $this;
        }

        $this->metaClauses[] = ['key' => $key, 'value' => $value, 'compare' => $compare->value, 'type' => $type->value];

        return $this;
    }

    public function whereMetaExists(string $key): self
    {
        $this->metaClauses[] = ['key' => $key, 'compare' => 'EXISTS'];

        return $this;
    }

    public function whereMetaNotExists(string $key): self
    {
        $this->metaClauses[] = ['key' => $key, 'compare' => 'NOT EXISTS'];

        return $this;
    }

    public function orderBy(string $field, SortOrder $direction = SortOrder::Asc): self
    {
        $this->orderBy[$field] = $direction->value;

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, SortOrder::Desc);
    }

    /**
     * メタの値で並び替える。絞り込みはせず、メタを持たないユーザーも結果に残る(ASC では先頭、DESC では末尾に並ぶ)。
     */
    public function orderByMeta(string $metaKey, SortOrder $direction = SortOrder::Asc, CastType $type = CastType::Char): self
    {
        $name = 'tms_order_' . \count($this->orderMetaGroups);
        $this->orderMetaGroups[] = MetaClause::orderGroup($name, $metaKey, $type);
        $this->orderBy[$name] = $direction->value;

        return $this;
    }

    /**
     * @throws InvalidArgumentException $limit が1未満の場合
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("UserQuery::limit() must be 1 or greater, {$limit} given");
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * @throws InvalidArgumentException $offset が負の場合
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("UserQuery::offset() must be 0 or greater, {$offset} given");
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * 専用のメソッドが無い WP_User_Query の引数を設定する。キーごとに上書きする。role は上書きできない。
     *
     * @param WpUserQueryArgs $args
     */
    public function withArgs(array $args): self
    {
        $this->rawArgs = array_merge($this->rawArgs, $args);

        return $this;
    }

    /** @return list<WP_User> */
    public function get(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $query = new WP_User_Query($args);

        return array_values(array_filter($query->get_results(), static fn(mixed $user): bool => $user instanceof WP_User));
    }

    /** limit() に関係なく1件だけ取得する。offset() は効く。 */
    public function first(): ?WP_User
    {
        $args = $this->buildArgs();

        if ($this->matchesNothing()) {
            return null;
        }

        $args['number'] = 1;
        $query = new WP_User_Query($args);
        $users = $query->get_results();
        $user = \array_key_exists(0, $users) ? $users[0] : null;

        return $user instanceof WP_User ? $user : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $args['fields'] = 'ID';
        $query = new WP_User_Query($args);
        $ids = [];

        foreach ($query->get_results() as $id) {
            if (\is_int($id)) {
                $ids[] = $id;
                continue;
            }

            if (\is_string($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /** 条件に合う全体の件数。limit()・offset() は無視する。 */
    public function count(): int
    {
        $args = $this->buildArgs();

        if ($this->matchesNothing()) {
            return 0;
        }

        $args['fields'] = 'ID';
        $args['number'] = 1;
        $args['count_total'] = true;
        unset($args['offset']);
        $query = new WP_User_Query($args);

        return (int) $query->get_total();
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_User;
    }

    public function url(): ?string
    {
        $user = $this->first();

        if (!$user instanceof WP_User) {
            return null;
        }

        $url = get_author_posts_url((int) $user->ID);

        return $url === '' ? null : $url;
    }

    /**
     * @throws InvalidArgumentException $perPage・$page が1未満の場合
     * @throws InvalidQueryException offset()・limit() と併用した場合
     *
     * @return PageResult<WP_User>
     */
    public function paginate(int $perPage, ?int $page = null): PageResult
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("UserQuery::paginate() perPage must be 1 or greater, {$perPage} given");
        }

        if ($page !== null && $page < 1) {
            throw new InvalidArgumentException("UserQuery::paginate() page must be 1 or greater, {$page} given");
        }

        if ($this->offset !== null) {
            throw new InvalidQueryException('UserQuery::paginate() cannot be combined with offset()');
        }

        if ($this->limit !== null) {
            throw new InvalidQueryException('UserQuery::paginate() cannot be combined with limit()');
        }

        // 0件と確定している場合は、現在のページを解決せず(get_query_var を呼ばず)、page を省略したら1ページ目にする
        if ($this->matchesNothing()) {
            return new PageResult([], 0, $perPage, $page ?? 1);
        }

        $currentPage = $page ?? self::currentPage();

        $args = $this->buildArgs();
        $args['number'] = $perPage;
        $args['offset'] = ($currentPage - 1) * $perPage;
        $args['count_total'] = true;
        $query = new WP_User_Query($args);
        $users = array_values(array_filter($query->get_results(), static fn(mixed $user): bool => $user instanceof WP_User));

        return new PageResult($users, (int) $query->get_total(), $perPage, $currentPage);
    }

    /**
     * WP_User_Query を返す。
     *
     * 結果が0件と確定している場合は検索を実行せず、空の結果を持つ WP_User_Query を返す。引数なしで作った
     * WP_User_Query は検索を実行しない。get_results()・get_total() が返す results・total_users を、WP_User_Query が
     * 互換のために公開している書き込み(__set())で空の結果の値にし、query_vars に toArgs()(`include => [0]` を含む)を
     * 入れる。
     */
    public function wpUserQuery(): WP_User_Query
    {
        $args = $this->toArgs();

        if (!$this->matchesNothing()) {
            return new WP_User_Query($args);
        }

        $query = new WP_User_Query();
        $query->query_vars = $args;
        // results・total_users は private で、WP_User_Query が互換のために __set() での書き込みだけを許す
        $query->__set('results', []);
        $query->__set('total_users', 0);

        return $query;
    }

    /**
     * @throws InvalidQueryException limit() の無い offset() の場合(WP_User_Query は件数の上限が無いと offset を無視する)
     *
     * @return WpUserQueryArgs
     */
    public function toArgs(): array
    {
        if ($this->offset !== null && $this->limit === null) {
            throw new InvalidQueryException('UserQuery::offset() requires limit()');
        }

        return $this->buildArgs();
    }

    /** 結果が0件と確定しているか(空の IN 指定、または idIn() から idNotIn() を差し引いて空)。 */
    private function matchesNothing(): bool
    {
        return $this->emptyIn !== [] || $this->includedIds() === [];
    }

    /**
     * idIn() の ID から idNotIn() の ID を差し引いて返す。WP_User_Query は include があると exclude を無視するため、
     * 差し引きは FW の側で行う。idIn() を呼んでいなければ null。
     *
     * @return list<int>|null
     */
    private function includedIds(): ?array
    {
        if ($this->idIn === null) {
            return null;
        }

        return array_values(array_diff($this->idIn, $this->idNotIn));
    }

    /** @return WpUserQueryArgs */
    private function buildArgs(): array
    {
        $args = ['fields' => 'all', 'count_total' => false];
        $args = array_merge($args, $this->args);

        $ids = $this->includedIds();

        if ($ids !== null && $ids !== []) {
            $args['include'] = $ids;
        } elseif ($ids === null && $this->idNotIn !== []) {
            $args['exclude'] = $this->idNotIn;
        }

        // 件数を指定しない場合、WP_User_Query は全件を返す(number を渡さない)
        if ($this->limit !== null) {
            $args['number'] = $this->limit;
        }

        if ($this->offset !== null) {
            $args['offset'] = $this->offset;
        }

        $metaQuery = [...$this->metaClauses, ...$this->orderMetaGroups];

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($this->orderBy !== []) {
            $args['orderby'] = $this->orderBy;
        }

        $args = array_merge($args, $this->rawArgs);
        $args['role'] = $this->role;

        // 結果が0件と確定している場合は、WP_User_Query に渡しても0件になる引数にする(存在しない ID 0 に絞る)
        if ($this->matchesNothing()) {
            $args['include'] = [0];
        }

        return $args;
    }

    private static function currentPage(): int
    {
        $pagedValue = get_query_var('paged');
        $paged = \is_scalar($pagedValue) ? (int) $pagedValue : 0;

        if ($paged > 0) {
            return $paged;
        }

        $pageValue = get_query_var('page');
        $page = \is_scalar($pageValue) ? (int) $pageValue : 0;

        if ($page > 0) {
            return $page;
        }

        return 1;
    }
}
