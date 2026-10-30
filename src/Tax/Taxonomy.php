<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\WpErrorHandler;
use TMSWP\Core\Exception\EntityDataException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_Error;
use WP_Taxonomy;
use WP_Term;

abstract class Taxonomy
{
    /** parents() がたどる親の数の上限。超えたら parent の循環等のデータの不整合として扱う */
    private const int MAX_PARENT_DEPTH = 100;

    /** @var array<class-string<self>, TaxonomyDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): TaxonomyDefinition;

    final public static function slug(): string
    {
        return self::definitionFor(static::class)->slug();
    }

    /**
     * タクソノミーを登録する。TaxonomyInitializer が、このクラスを探索した Kernel を渡して呼ぶ。
     */
    final public static function register(Kernel $kernel): void
    {
        $definition = self::definitionFor(static::class);
        $slug = $definition->slug();

        if ($definition->isBuiltin()) {
            // アダプタ先が無いのは設定の誤り(スラッグの誤り・登録が init 9 より後等)。objectType() の有無に関係なく例外にする
            if (!taxonomy_exists($slug)) {
                throw new EntityDefinitionException("builtin taxonomy does not exist: {$slug}");
            }

            self::registerBuiltinObjectTypes($definition, $kernel);

            return;
        }

        if (taxonomy_exists($slug)) {
            throw new EntityDefinitionException("taxonomy is already registered: {$slug}");
        }

        $registered = register_taxonomy($slug, $definition->resolvedObjectTypes(), $definition->toArgs($kernel->config()->labelLanguage));

        if ($registered instanceof WP_Error) {
            throw new EntityDefinitionException($registered->get_error_message());
        }
    }

    final public static function get(WP_Term|int $term): ?WP_Term
    {
        if ($term instanceof WP_Term) {
            return $term->taxonomy === static::slug() ? $term : null;
        }

        if ($term <= 0) {
            return null;
        }

        $resolved = get_term($term, static::slug());

        if ($resolved instanceof WP_Error) {
            return WpErrorHandler::handle($resolved, static::class, 'get_term', null);
        }

        if (!$resolved instanceof WP_Term || $resolved->taxonomy !== static::slug()) {
            return null;
        }

        return $resolved;
    }

    final public static function findBySlug(string $slug): ?WP_Term
    {
        $term = get_term_by('slug', $slug, static::slug());

        return $term instanceof WP_Term ? $term : null;
    }

    final public static function findByName(string $name): ?WP_Term
    {
        $term = get_term_by('name', $name, static::slug());

        return $term instanceof WP_Term ? $term : null;
    }

    final public static function is(WP_Term|int|null $term): bool
    {
        if ($term === null) {
            return false;
        }

        return static::get($term) instanceof WP_Term;
    }

    public static function query(): TermQuery
    {
        return TermQuery::of(static::class);
    }

    /** @return list<WP_Term> */
    final public static function all(bool $hideEmpty = false): array
    {
        return static::query()->hideEmpty($hideEmpty)->get();
    }

    /**
     * 対象のタームのメタの MetaBag を返す。対象が無い、またはこのクラスのスコープ外の場合は、存在しない対象へのメタの
     * 書き込みを防ぐため、呼び出しの誤りとして例外にする(存在は get()・is() で確かめてから呼ぶ)。
     *
     * @throws InvalidArgumentException 対象が無い、またはこのクラスのスコープ外の場合
     *
     * @return MetaBag<static>
     */
    final public static function meta(WP_Term|int $term): MetaBag
    {
        $resolved = static::get($term);

        if (!$resolved instanceof WP_Term) {
            throw new InvalidArgumentException('cannot create MetaBag for missing or mismatched term');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function url(WP_Term|int|string $term): ?string
    {
        $resolved = \is_string($term) ? static::findBySlug($term) : static::get($term);

        if (!$resolved instanceof WP_Term) {
            return null;
        }

        $url = get_term_link($resolved);

        if ($url instanceof WP_Error) {
            return WpErrorHandler::handle($url, static::class, 'get_term_link', null);
        }

        return $url;
    }

    /** @return list<WP_Term> */
    final public static function parents(WP_Term|int $term, bool $includeSelf = false): array
    {
        $resolved = static::get($term);

        if (!$resolved instanceof WP_Term) {
            return [];
        }

        $parents = [];
        $current = $resolved;

        while ((int) $current->parent > 0) {
            $parent = static::get((int) $current->parent);

            if (!$parent instanceof WP_Term) {
                break;
            }

            // 上限ちょうどの数の祖先は正常に返し、その先にさらに親があるときだけ超過にする
            if (\count($parents) === self::MAX_PARENT_DEPTH) {
                return self::parentDepthExceeded($resolved->term_id);
            }

            $parents[] = $parent;
            $current = $parent;
        }

        $ancestors = array_reverse($parents);

        return $includeSelf ? [...$ancestors, $resolved] : $ancestors;
    }

    /**
     * タームを作る。名前と値は元の(slash していない)値を渡す。
     *
     * $args は php-stubs/wordpress-stubs の wp_insert_term() の $args(`@phpstan-param`)と同じ配列の形。
     *
     * wp_insert_term() は名前と description を slash 済み($_POST と同じ形)として受け取り、wp_unslash() して保存する。
     * 元の値を保存するため、この2つを slash して渡す。slug・alias_of は unslash されずにそのまま使われるため、slash しない。
     *
     * @param array{alias_of?: string, description?: string, parent?: int, slug?: string} $args
     *
     * @throws EntityPersistenceException wp_insert_term() が WP_Error を返した場合
     */
    final public static function create(string $name, array $args = []): int
    {
        if (\array_key_exists('description', $args)) {
            $args['description'] = wp_slash($args['description']);
        }

        $result = wp_insert_term(wp_slash($name), static::slug(), $args);

        if ($result instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($result);
        }

        if (!\is_array($result) || !\array_key_exists('term_id', $result) || !\is_int($result['term_id'])) {
            throw new EntityPersistenceException('wp_insert_term did not return an integer term_id');
        }

        return $result['term_id'];
    }

    final public static function taxonomyObject(): ?WP_Taxonomy
    {
        $object = get_taxonomy(static::slug());

        return $object instanceof WP_Taxonomy ? $object : null;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /**
     * @param class-string<self> $class
     */
    private static function definitionFor(string $class): TaxonomyDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }

    /**
     * builtin のタクソノミーを objectType() の投稿タイプに紐付ける。
     *
     * 紐付け先が同じ Kernel の init 10 で登録する投稿タイプなら、init 9 のこの時点ではまだ無く失敗する。失敗した
     * 投稿タイプだけを init 11 で1回だけ再試行し、それでも失敗したら Kernel のロガーに warning で記録する。再試行は
     * Kernel::defer() で予約するため、本番の遅延 boot のキャッチアップでも、投稿タイプの登録の後に実行される。
     */
    private static function registerBuiltinObjectTypes(TaxonomyDefinition $definition, Kernel $kernel): void
    {
        $retry = [];

        foreach ($definition->resolvedObjectTypes() as $objectType) {
            if (!register_taxonomy_for_object_type($definition->slug(), $objectType)) {
                $retry[] = $objectType;
            }
        }

        if ($retry === []) {
            return;
        }

        $logger = $kernel->logger();

        $kernel->defer('init', 11, static function () use ($definition, $retry, $logger): void {
            foreach ($retry as $objectType) {
                if (!register_taxonomy_for_object_type($definition->slug(), $objectType)) {
                    $logger->warning("failed to attach taxonomy '{$definition->slug()}' to post type '{$objectType}'");
                }
            }
        });
    }

    /**
     * 親の深さの超過(parent の循環等のデータの不整合)を、実行時のデータ取得の
     * 失敗として扱う。debug では例外を投げ、本番ではこのクラスの Kernel のロガーに error で記録して空配列を返す。
     *
     * @throws EntityDataException debug の場合
     *
     * @return list<WP_Term>
     */
    private static function parentDepthExceeded(int $termId): array
    {
        $kernel = Kernel::forClass(static::class);

        if ($kernel->config()->debug) {
            throw new EntityDataException("term parent depth of term {$termId} exceeded " . self::MAX_PARENT_DEPTH . ' (' . static::class . ')');
        }

        $kernel->logger()->error('tmswp.taxonomy: term parent depth of term {term} exceeded {limit} ({class})', [
            'term' => $termId,
            'limit' => self::MAX_PARENT_DEPTH,
            'class' => static::class,
        ]);

        return [];
    }
}
