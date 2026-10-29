<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\MetaClause;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\TermField;
use TMSWP\Core\Query\TermOperator;
use WP_Post;
use WP_Query;
use WP_User;

final class PostQuery
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta() の並び替えだけに使う句のグループ */
    private array $orderMetaGroups = [];

    /** @var list<array<string, mixed>> */
    private array $taxClauses = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $rawArgs = [];

    /**
     * 空の IN 指定と author(0) を受けた条件(WP_Query の引数名)。1つでもあれば結果は0件と確定し、終端メソッドは
     * WP_Query を実行しない(WordPress は空の IN 指定と author の 0 を「条件なし」と扱い、全件を返すため)。
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

    private bool $noPaging = false;

    private function __construct(
        private readonly string $postType,
    ) {}

    /** @param class-string<PostType> $postTypeClass */
    public static function of(string $postTypeClass): self
    {
        return new self($postTypeClass::slug());
    }

    /** 空で呼ぶと結果は0件になる。 */
    public function status(string ...$status): self
    {
        $this->in('post_status', array_values($status));

        return $this;
    }

    public function anyStatus(): self
    {
        unset($this->emptyIn['post_status']);
        $this->args['post_status'] = 'any';

        return $this;
    }

    public function search(string $keyword): self
    {
        $this->args['s'] = $keyword;

        return $this;
    }

    /**
     * 投稿者で絞る。WP_Query は author の 0 を「条件なし」、負の値を除外(author__not_in)として扱うため、0(未ログインの
     * wp_get_current_user() 等、ID が 0 の WP_User を含む)は結果を0件にし、負の値は受け付けない。
     *
     * @throws InvalidArgumentException 負の ID の場合
     */
    public function author(int|WP_User $author): self
    {
        $id = $author instanceof WP_User ? (int) $author->ID : $author;

        if ($id < 0) {
            throw new InvalidArgumentException("PostQuery::author() must be 0 or greater, {$id} given");
        }

        if ($id === 0) {
            unset($this->args['author']);
            $this->emptyIn['author'] = true;

            return $this;
        }

        unset($this->emptyIn['author']);
        $this->args['author'] = $id;

        return $this;
    }

    public function parent(int|WP_Post $parent): self
    {
        $this->args['post_parent'] = $parent instanceof WP_Post ? (int) $parent->ID : $parent;

        return $this;
    }

    /** 空で呼ぶと結果は0件になる。 */
    public function slugIn(string ...$slugs): self
    {
        $this->in('post_name__in', array_values($slugs));

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

    /**
     * 空の $terms は、TermOperator::In・And では結果を0件にし、TermOperator::NotIn では条件を足さない。
     *
     * @param string $taxonomy スラッグまたは Taxonomy のサブクラスの FQCN
     * @param int|string|list<int|string> $terms
     */
    public function whereTerm(string $taxonomy, int|string|array $terms, TermField $field = TermField::Slug, TermOperator $operator = TermOperator::In): self
    {
        $taxonomySlug = EntityReference::taxonomySlug($taxonomy);
        $termList = \is_array($terms) ? array_values($terms) : [$terms];

        if ($termList === []) {
            if ($operator !== TermOperator::NotIn) {
                $this->emptyIn['tax_query'] = true;
            }

            return $this;
        }

        $this->taxClauses[] = [
            'taxonomy' => $taxonomySlug,
            'field' => $field->value,
            'terms' => $termList,
            'operator' => $operator->value,
        ];

        return $this;
    }

    public function template(string $templateFile): self
    {
        return $this->whereMeta('_wp_page_template', $templateFile);
    }

    public function hasThumbnail(bool $has = true): self
    {
        $this->metaClauses[] = ['key' => '_thumbnail_id', 'compare' => $has ? 'EXISTS' : 'NOT EXISTS'];

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
     * メタの値で並び替える。絞り込みはせず、メタを持たない投稿も結果に残る(ASC では先頭、DESC では末尾に並ぶ)。
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
     * @throws InvalidQueryException noPaging() と併用した場合
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("PostQuery::limit() must be 1 or greater, {$limit} given");
        }

        if ($this->noPaging) {
            throw new InvalidQueryException('PostQuery::limit() cannot be combined with noPaging()');
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * @throws InvalidArgumentException $offset が負の場合
     * @throws InvalidQueryException noPaging() と併用した場合
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException("PostQuery::offset() must be 0 or greater, {$offset} given");
        }

        if ($this->noPaging) {
            throw new InvalidQueryException('PostQuery::offset() cannot be combined with noPaging()');
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * 全件を取得することを明示する(件数を指定しない場合も全件を返す)。limit()・offset()・paginate() とは併用できない。
     *
     * @throws InvalidQueryException limit()・offset() と併用した場合
     */
    public function noPaging(): self
    {
        if ($this->limit !== null) {
            throw new InvalidQueryException('PostQuery::noPaging() cannot be combined with limit()');
        }

        if ($this->offset !== null) {
            throw new InvalidQueryException('PostQuery::noPaging() cannot be combined with offset()');
        }

        $this->noPaging = true;

        return $this;
    }

    public function ignoreSticky(bool $ignore = true): self
    {
        $this->args['ignore_sticky_posts'] = $ignore;

        return $this;
    }

    /**
     * 専用のメソッドが無い WP_Query の引数を設定する。キーごとに上書きする。post_type は上書きできない。
     *
     * @param array<string, mixed> $args WP_Query::__construct() の $query(stubs の型は string|array)
     */
    public function withArgs(array $args): self
    {
        $this->rawArgs = array_merge($this->rawArgs, $args);

        return $this;
    }

    /** @return list<WP_Post> */
    public function get(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $args['no_found_rows'] = true;
        unset($args['fields']);
        $query = new WP_Query($args);

        return array_values(array_filter($query->posts, static fn(mixed $post): bool => $post instanceof WP_Post));
    }

    /** limit()・noPaging() に関係なく1件だけ取得する。offset() は効く。 */
    public function first(): ?WP_Post
    {
        $args = $this->buildArgs();

        if ($this->matchesNothing()) {
            return null;
        }

        $args['posts_per_page'] = 1;
        $args['no_found_rows'] = true;
        unset($args['nopaging'], $args['fields']);
        $query = new WP_Query($args);
        $post = \array_key_exists(0, $query->posts) ? $query->posts[0] : null;

        return $post instanceof WP_Post ? $post : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $args['fields'] = 'ids';
        $args['no_found_rows'] = true;
        $query = new WP_Query($args);
        $ids = [];

        foreach ($query->posts as $id) {
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

    /** 条件に合う全体の件数。limit()・offset()・noPaging() は無視する。 */
    public function count(): int
    {
        $args = $this->buildArgs();

        if ($this->matchesNothing()) {
            return 0;
        }

        $args['fields'] = 'ids';
        $args['posts_per_page'] = 1;
        $args['paged'] = 1;
        $args['no_found_rows'] = false;
        unset($args['offset'], $args['nopaging']);
        $query = new WP_Query($args);

        return (int) $query->found_posts;
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_Post;
    }

    public function url(): ?string
    {
        $post = $this->first();

        if (!$post instanceof WP_Post) {
            return null;
        }

        $url = get_permalink($post);

        return $url === false ? null : $url;
    }

    /**
     * @throws InvalidArgumentException $perPage・$page が1未満の場合
     * @throws InvalidQueryException noPaging()・offset()・limit() と併用した場合
     *
     * @return PageResult<WP_Post>
     */
    public function paginate(int $perPage, ?int $page = null): PageResult
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("PostQuery::paginate() perPage must be 1 or greater, {$perPage} given");
        }

        if ($page !== null && $page < 1) {
            throw new InvalidArgumentException("PostQuery::paginate() page must be 1 or greater, {$page} given");
        }

        if ($this->noPaging) {
            throw new InvalidQueryException('PostQuery::paginate() cannot be combined with noPaging()');
        }

        if ($this->offset !== null) {
            throw new InvalidQueryException('PostQuery::paginate() cannot be combined with offset()');
        }

        if ($this->limit !== null) {
            throw new InvalidQueryException('PostQuery::paginate() cannot be combined with limit()');
        }

        // 0件と確定している場合は、現在のページを解決せず(get_query_var を呼ばず)、page を省略したら1ページ目にする
        if ($this->matchesNothing()) {
            return new PageResult([], 0, $perPage, $page ?? 1);
        }

        $currentPage = $page ?? self::currentPage();

        $args = $this->buildArgs();
        $args['posts_per_page'] = $perPage;
        $args['paged'] = $currentPage;
        $args['no_found_rows'] = false;
        unset($args['fields']);
        $query = new WP_Query($args);
        $posts = array_values(array_filter($query->posts, static fn(mixed $post): bool => $post instanceof WP_Post));

        return new PageResult($posts, (int) $query->found_posts, $perPage, $currentPage);
    }

    /**
     * WP 標準のループ用の WP_Query を返す。
     *
     * 結果が0件と確定している場合は検索を実行せず、空の結果を持つ WP_Query を返す。引数なしで作った WP_Query は
     * 検索を実行しない。posts・post_count・found_posts・max_num_pages を空の結果の値にし、query・query_vars に
     * toArgs()(`post__in => [0]` を含む)を入れるため、get_posts() で検索をやり直しても0件になる。
     */
    public function wpQuery(): WP_Query
    {
        $args = $this->toArgs();

        if (!$this->matchesNothing()) {
            return new WP_Query($args);
        }

        $query = new WP_Query();
        $query->query = $args;
        $query->query_vars = $args;
        $query->posts = [];
        $query->post_count = 0;
        $query->found_posts = 0;
        $query->max_num_pages = 0;

        return $query;
    }

    /**
     * @throws InvalidQueryException limit() の無い offset() の場合(WP_Query は件数の上限が無いと offset を無視する)
     *
     * @return array<string, mixed> WP_Query::__construct() の $query(stubs の型は string|array で、配列の形を持たない)
     */
    public function toArgs(): array
    {
        if ($this->offset !== null && $this->limit === null) {
            throw new InvalidQueryException('PostQuery::offset() requires limit()');
        }

        return $this->buildArgs();
    }

    /** 結果が0件と確定しているか(空の IN 指定・author(0)、または idIn() から idNotIn() を差し引いて空)。 */
    private function matchesNothing(): bool
    {
        return $this->emptyIn !== [] || $this->includedIds() === [];
    }

    /**
     * idIn() の ID から idNotIn() の ID を差し引いて返す。WP_Query は post__in があると post__not_in を無視するため、
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

    /** @param list<int|string> $values */
    private function in(string $key, array $values): void
    {
        if ($values === []) {
            unset($this->args[$key]);
            $this->emptyIn[$key] = true;

            return;
        }

        unset($this->emptyIn[$key]);
        $this->args[$key] = $values;
    }

    /** @return array<string, mixed> */
    private function buildArgs(): array
    {
        // 件数を指定しない場合は、サイトの設定(posts_per_page)に依存させず全件にする
        $args = ['post_status' => ['publish'], 'ignore_sticky_posts' => true, 'posts_per_page' => $this->limit ?? -1];
        $args = array_merge($args, $this->args);

        if ($this->offset !== null) {
            $args['offset'] = $this->offset;
        }

        if ($this->noPaging) {
            $args['nopaging'] = true;
        }

        $ids = $this->includedIds();

        if ($ids !== null && $ids !== []) {
            $args['post__in'] = $ids;
        } elseif ($ids === null && $this->idNotIn !== []) {
            $args['post__not_in'] = $this->idNotIn;
        }

        $metaQuery = [...$this->metaClauses, ...$this->orderMetaGroups];

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($this->taxClauses !== []) {
            $args['tax_query'] = ['relation' => 'AND', ...$this->taxClauses];
        }

        if ($this->orderBy !== []) {
            $args['orderby'] = $this->orderBy;
            unset($args['order']);
        }

        $args = array_merge($args, $this->rawArgs);
        $args['post_type'] = $this->postType;

        // 結果が0件と確定している場合は、WP_Query に渡しても0件になる引数にする(存在しない ID 0 に絞る。先頭固定表示の
        // 投稿が足されないよう ignore_sticky_posts も強制する)
        if ($this->matchesNothing()) {
            $args['post__in'] = [0];
            $args['ignore_sticky_posts'] = true;
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
