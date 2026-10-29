<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\WpErrorHandler;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\MetaClause;
use TMSWP\Core\Query\SortOrder;
use WP_Error;
use WP_Post;
use WP_Term;
use WP_Term_Query;

/**
 * GetTermsArgs は php-stubs/wordpress-stubs の get_terms() の $args と WP_Term_Query::__construct() の $query
 * (`@phpstan-param`。2つは同じ形)と同じ配列の形。stubs が要素の型を書いていない array(meta_query)だけは要素の型を書く
 * (PHPStan level max は要素の型の無い array を許さないため)。
 *
 * @phpstan-type GetTermsArgs array{
 *   taxonomy?: string|string[],
 *   object_ids?: int|int[],
 *   orderby?: string,
 *   order?: string,
 *   hide_empty?: bool|int,
 *   include?: int[]|string,
 *   exclude?: int[]|string,
 *   exclude_tree?: int[]|string,
 *   number?: int|string,
 *   offset?: int,
 *   fields?: string,
 *   name?: string|string[],
 *   slug?: string|string[],
 *   term_taxonomy_id?: int|int[],
 *   hierarchical?: bool,
 *   search?: string,
 *   name__like?: string,
 *   description__like?: string,
 *   pad_counts?: bool,
 *   get?: string,
 *   child_of?: int,
 *   parent?: int,
 *   childless?: bool,
 *   cache_domain?: string,
 *   cache_results?: bool,
 *   update_term_meta_cache?: bool,
 *   meta_key?: string|string[],
 *   meta_value?: string|string[],
 *   meta_compare?: string,
 *   meta_compare_key?: string,
 *   meta_type?: string,
 *   meta_type_key?: string,
 *   meta_query?: array<int|string, string|array<int|string, mixed>>,
 * }
 */
final class TermQuery
{
    /** @var GetTermsArgs */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta() の並び替えだけに使う句のグループ */
    private array $orderMetaGroups = [];

    /** @var array{0: string, 1: string}|null orderby と order(WP_Term_Query は並び替えを1組しか受け付けない) */
    private ?array $orderBy = null;

    /** @var GetTermsArgs */
    private array $rawArgs = [];

    /**
     * 空の IN 指定を受けた条件(get_terms の引数名)。1つでもあれば結果は0件と確定し、終端メソッドは get_terms を
     * 呼ばない(WordPress は空の IN 指定を「条件なし」と扱い、全件を返すため)。
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

    /**
     * @param class-string<Taxonomy> $taxonomyClass
     */
    private function __construct(
        private readonly string $taxonomyClass,
        private readonly string $taxonomy,
    ) {}

    /** @param class-string<Taxonomy> $taxonomyClass */
    public static function of(string $taxonomyClass): self
    {
        return new self($taxonomyClass, $taxonomyClass::slug());
    }

    public function hideEmpty(bool $hide = true): self
    {
        $this->args['hide_empty'] = $hide;

        return $this;
    }

    public function search(string $keyword): self
    {
        $this->args['search'] = $keyword;

        return $this;
    }

    public function parent(int|WP_Term $parent): self
    {
        $this->args['parent'] = $parent instanceof WP_Term ? (int) $parent->term_id : $parent;

        return $this;
    }

    public function childrenOf(int|WP_Term $ancestor): self
    {
        $this->args['child_of'] = $ancestor instanceof WP_Term ? (int) $ancestor->term_id : $ancestor;

        return $this;
    }

    /** 空で呼ぶと結果は0件になる。 */
    public function slugIn(string ...$slugs): self
    {
        if ($slugs === []) {
            unset($this->args['slug']);
            $this->emptyIn['slug'] = true;

            return $this;
        }

        unset($this->emptyIn['slug']);
        $this->args['slug'] = array_values($slugs);

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

    /** 指定した投稿に付いたタームに絞る。空で呼ぶと結果は0件になる。 */
    public function forPost(WP_Post|int ...$posts): self
    {
        if ($posts === []) {
            unset($this->args['object_ids']);
            $this->emptyIn['object_ids'] = true;

            return $this;
        }

        unset($this->emptyIn['object_ids']);
        $this->args['object_ids'] = array_values(array_map(static fn(WP_Post|int $post): int => $post instanceof WP_Post ? (int) $post->ID : $post, $posts));

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
     * @throws InvalidQueryException 並び替えを指定済みの場合(orderByMeta() を含む)
     */
    public function orderBy(string $field, SortOrder $direction = SortOrder::Asc): self
    {
        if ($this->orderBy !== null) {
            throw new InvalidQueryException('TermQuery supports only one orderBy clause');
        }

        $this->orderBy = [$field, $direction->value];

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, SortOrder::Desc);
    }

    /**
     * メタの値で並び替える。絞り込みはせず、メタを持たないタームも結果に残る(ASC では先頭、DESC では末尾に並ぶ)。
     *
     * @throws InvalidQueryException 並び替えを指定済みの場合
     */
    public function orderByMeta(string $metaKey, SortOrder $direction = SortOrder::Asc, CastType $type = CastType::Char): self
    {
        $name = 'tms_order_' . \count($this->orderMetaGroups);
        $this->orderBy($name, $direction);
        $this->orderMetaGroups[] = MetaClause::orderGroup($name, $metaKey, $type);

        return $this;
    }

    /**
     * @throws InvalidArgumentException $limit が1未満の場合
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("TermQuery::limit() must be 1 or greater, {$limit} given");
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
            throw new InvalidArgumentException("TermQuery::offset() must be 0 or greater, {$offset} given");
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * 専用のメソッドが無い get_terms の引数を設定する。キーごとに上書きする。taxonomy は上書きできない。
     *
     * @param GetTermsArgs $args
     */
    public function withArgs(array $args): self
    {
        $this->rawArgs = array_merge($this->rawArgs, $args);

        return $this;
    }

    /** @return list<WP_Term> */
    public function get(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $terms = get_terms($args);

        if ($terms instanceof WP_Error) {
            return WpErrorHandler::handle($terms, $this->taxonomyClass, 'get_terms', []);
        }

        if (!\is_array($terms)) {
            return [];
        }

        return array_values(array_filter($terms, static fn(mixed $term): bool => $term instanceof WP_Term));
    }

    /** limit() に関係なく1件だけ取得する。offset() は効く。 */
    public function first(): ?WP_Term
    {
        $args = $this->buildArgs();

        if ($this->matchesNothing()) {
            return null;
        }

        $args['number'] = 1;
        $terms = get_terms($args);

        if ($terms instanceof WP_Error) {
            return WpErrorHandler::handle($terms, $this->taxonomyClass, 'get_terms', null);
        }

        if (!\is_array($terms) || $terms === []) {
            return null;
        }

        return $terms[0] instanceof WP_Term ? $terms[0] : null;
    }

    /** @return list<int> */
    public function ids(): array
    {
        $args = $this->toArgs();

        if ($this->matchesNothing()) {
            return [];
        }

        $args['fields'] = 'ids';
        $ids = get_terms($args);

        if ($ids instanceof WP_Error) {
            return WpErrorHandler::handle($ids, $this->taxonomyClass, 'get_terms', []);
        }

        if (!\is_array($ids)) {
            return [];
        }

        $typedIds = [];

        foreach ($ids as $id) {
            if (\is_int($id)) {
                $typedIds[] = $id;
                continue;
            }

            if (\is_string($id)) {
                $typedIds[] = (int) $id;
            }
        }

        return $typedIds;
    }

    /** 条件に合う全体の件数。limit()・offset() と並び替えは無視する。 */
    public function count(): int
    {
        // 並び替え専用の句のグループを残すと、そのメタが無いタームがほかのメタの行と結び付いて重複して数えられる
        // (fields=count の SELECT DISTINCT COUNT(*) は重複を除かない)ため、並び替えを除いて数える
        $args = $this->buildArgs(false);

        if ($this->matchesNothing()) {
            return 0;
        }

        $args['fields'] = 'count';
        unset($args['number'], $args['offset']);
        $count = get_terms($args);

        if ($count instanceof WP_Error) {
            return WpErrorHandler::handle($count, $this->taxonomyClass, 'get_terms', 0);
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    public function exists(): bool
    {
        return $this->first() instanceof WP_Term;
    }

    public function url(): ?string
    {
        $term = $this->first();

        if (!$term instanceof WP_Term) {
            return null;
        }

        $url = get_term_link($term);

        if ($url instanceof WP_Error) {
            return WpErrorHandler::handle($url, $this->taxonomyClass, 'get_term_link', null);
        }

        return $url;
    }

    /**
     * WP_Term_Query を返す。
     *
     * 結果が0件と確定している場合は検索を実行せず、空の結果を持つ WP_Term_Query を返す。引数なしで作った
     * WP_Term_Query は検索を実行しない。terms を空の配列にし、query_vars に toArgs()(`include => [0]` を含む)を
     * 入れるため、get_terms() で検索をやり直しても0件になる。
     */
    public function wpTermQuery(): WP_Term_Query
    {
        $args = $this->toArgs();

        if (!$this->matchesNothing()) {
            return new WP_Term_Query($args);
        }

        $query = new WP_Term_Query();
        $query->query_vars = $args;
        $query->terms = [];

        return $query;
    }

    /**
     * @throws InvalidQueryException limit() の無い offset() の場合(WP_Term_Query は件数の上限が無いと offset を無視する)
     *
     * @return GetTermsArgs
     */
    public function toArgs(): array
    {
        if ($this->offset !== null && $this->limit === null) {
            throw new InvalidQueryException('TermQuery::offset() requires limit()');
        }

        return $this->buildArgs();
    }

    /** 結果が0件と確定しているか(空の IN 指定、または idIn() から idNotIn() を差し引いて空)。 */
    private function matchesNothing(): bool
    {
        return $this->emptyIn !== [] || $this->includedIds() === [];
    }

    /**
     * idIn() の ID から idNotIn() の ID を差し引いて返す。get_terms は include があると exclude を無視するため、
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

    /**
     * @param bool $withOrder 並び替え(orderBy()・orderByMeta() とその句のグループ)を含めるか
     *
     * @return GetTermsArgs
     */
    private function buildArgs(bool $withOrder = true): array
    {
        $args = ['hide_empty' => false];
        $args = array_merge($args, $this->args);

        $ids = $this->includedIds();

        if ($ids !== null && $ids !== []) {
            $args['include'] = $ids;
        } elseif ($ids === null && $this->idNotIn !== []) {
            $args['exclude'] = $this->idNotIn;
        }

        // 件数を指定しない場合、get_terms は全件を返す(number を渡さない)
        if ($this->limit !== null) {
            $args['number'] = $this->limit;
        }

        if ($this->offset !== null) {
            $args['offset'] = $this->offset;
        }

        $metaQuery = $withOrder ? [...$this->metaClauses, ...$this->orderMetaGroups] : $this->metaClauses;

        if ($metaQuery !== []) {
            $args['meta_query'] = ['relation' => 'AND', ...$metaQuery];
        }

        if ($withOrder && $this->orderBy !== null) {
            [$args['orderby'], $args['order']] = $this->orderBy;
        }

        $args = array_merge($args, $this->rawArgs);
        $args['taxonomy'] = $this->taxonomy;

        // 結果が0件と確定している場合は、get_terms に渡しても0件になる引数にする(存在しない ID 0 に絞る)
        if ($this->matchesNothing()) {
            $args['include'] = [0];
        }

        return $args;
    }
}
