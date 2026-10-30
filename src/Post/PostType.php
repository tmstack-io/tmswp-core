<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Entity\WpErrorHandler;
use TMSWP\Core\Exception\EntityDataException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\WpMetaStorage;
use WP_Block_Editor_Context;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Term;

abstract class PostType
{
    /** parents() がたどる親の数の上限。超えたら post_parent の循環等のデータの不整合として扱う */
    private const int MAX_PARENT_DEPTH = 100;

    /**
     * create() が slash して wp_insert_post() に渡すキー。WP 7.0 の wp_insert_post() が wp_unslash() してから保存する
     * 文字列の値だけを並べる。投稿の列は $data = wp_unslash($data) で、tags_input・tax_input のターム名は term_exists()・
     * wp_insert_term() で unslash される(wp-includes/post.php・taxonomy.php)。meta_input は別に
     * WpMetaStorage::slashMetaInput() でキーと値を slash する。
     *
     * 含めないキー: page_template は unslash されずに、テーマのテンプレートの配列のキーとしてそのまま検証される
     * (slash すると引用符を含む正当なテンプレートが Invalid page template になる)。post_type はこのクラスのスラッグを
     * そのまま渡す。ID・post_author・post_parent・menu_order・import_id・post_category は整数で、slash の対象外。
     */
    private const array SLASHED_KEYS = [
        'post_date' => true,
        'post_date_gmt' => true,
        'post_content' => true,
        'post_content_filtered' => true,
        'post_title' => true,
        'post_excerpt' => true,
        'post_status' => true,
        'comment_status' => true,
        'ping_status' => true,
        'post_password' => true,
        'post_name' => true,
        'to_ping' => true,
        'pinged' => true,
        'post_mime_type' => true,
        'guid' => true,
        'tags_input' => true,
        'tax_input' => true,
    ];

    /** @var array<class-string<self>, PostTypeDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): PostTypeDefinition;

    final public static function slug(): string
    {
        return self::definitionFor(static::class)->slug();
    }

    /**
     * 投稿タイプを登録する。PostTypeInitializer が、このクラスを探索した Kernel を渡して呼ぶ。
     */
    final public static function register(Kernel $kernel): void
    {
        $definition = self::definitionFor(static::class);
        $slug = $definition->slug();

        if ($definition->isBuiltin()) {
            // アダプタ先が無いのは設定の誤り(スラッグの誤り・登録が init 10 より後等)。黙って supports を足さず例外にする
            if (!post_type_exists($slug)) {
                throw new EntityDefinitionException("builtin post type does not exist: {$slug}");
            }

            foreach ($definition->supportsList() as $feature) {
                add_post_type_support($slug, $feature);
            }

            self::registerBlockEditorPreference($definition);

            return;
        }

        if (post_type_exists($slug)) {
            throw new EntityDefinitionException("post type is already registered: {$slug}");
        }

        if (\in_array('thumbnail', $definition->supportsList(), true)) {
            self::mergeThumbnailSupport($slug);
        }

        $registered = register_post_type($slug, $definition->toArgs($kernel->config()->labelLanguage));

        if ($registered instanceof WP_Error) {
            throw new EntityDefinitionException($registered->get_error_message());
        }

        self::registerBlockEditorPreference($definition);
        self::registerAllowedBlocks($definition);
    }

    final public static function get(WP_Post|int $post): ?WP_Post
    {
        if ($post instanceof WP_Post) {
            return $post->post_type === static::slug() ? $post : null;
        }

        if ($post <= 0) {
            return null;
        }

        $resolved = get_post($post);

        if (!$resolved instanceof WP_Post || $resolved->post_type !== static::slug()) {
            return null;
        }

        return $resolved;
    }

    final public static function findBySlug(string $slug): ?WP_Post
    {
        $post = get_page_by_path($slug, OBJECT, static::slug());

        if (!$post instanceof WP_Post) {
            return null;
        }

        return static::get($post);
    }

    final public static function is(WP_Post|int|null $post): bool
    {
        if ($post === null) {
            return false;
        }

        return static::get($post) instanceof WP_Post;
    }

    public static function query(): PostQuery
    {
        return PostQuery::of(static::class);
    }

    /**
     * 対象の投稿のメタの MetaBag を返す。対象が無い、またはこのクラスのスコープ外の場合は、存在しない対象へのメタの
     * 書き込みを防ぐため、呼び出しの誤りとして例外にする(存在は get()・is() で確かめてから呼ぶ)。
     *
     * @throws InvalidArgumentException 対象が無い、またはこのクラスのスコープ外の場合
     *
     * @return MetaBag<static>
     */
    final public static function meta(WP_Post|int $post): MetaBag
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            throw new InvalidArgumentException('cannot create MetaBag for missing or mismatched post');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function permalink(WP_Post|int $post): ?string
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return null;
        }

        $url = get_permalink($resolved);

        return $url === false ? null : $url;
    }

    final public static function archiveUrl(): ?string
    {
        $url = get_post_type_archive_link(static::slug());

        return $url === false ? null : $url;
    }

    final public static function title(WP_Post|int $post): string
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return '';
        }

        return get_the_title($resolved);
    }

    /** @param string|array<int> $size */
    final public static function thumbnail(WP_Post|int $post, string|array $size = 'full'): ?Thumbnail
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return null;
        }

        if (has_post_thumbnail($resolved)) {
            $thumbnailId = get_post_thumbnail_id($resolved);

            if (!\is_int($thumbnailId)) {
                $thumbnailId = 0;
            }

            $src = wp_get_attachment_image_src($thumbnailId, $size);

            if (\is_array($src) && \array_key_exists(0, $src) && \is_string($src[0])) {
                $altValue = get_post_meta($thumbnailId, '_wp_attachment_image_alt', true);
                $alt = trim(wp_strip_all_tags(\is_scalar($altValue) ? (string) $altValue : '', true));

                return new Thumbnail($src[0], \is_int($src[1]) ? $src[1] : null, \is_int($src[2]) ? $src[2] : null, $alt);
            }
        }

        $fallback = apply_filters('tmswp/post/thumbnail_fallback', null, $resolved, $size);

        return $fallback instanceof Thumbnail ? $fallback : null;
    }

    /** @param string|array<int> $size */
    final public static function thumbnailUrl(WP_Post|int $post, string|array $size = 'full'): ?string
    {
        $thumbnail = static::thumbnail($post, $size);

        return $thumbnail instanceof Thumbnail ? $thumbnail->url : null;
    }

    /** @return list<WP_Term> */
    final public static function terms(WP_Post|int $post, string $taxonomy): array
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return [];
        }

        $terms = get_the_terms($resolved, EntityReference::taxonomySlug($taxonomy));

        if ($terms instanceof WP_Error) {
            return WpErrorHandler::handle($terms, static::class, 'get_the_terms', []);
        }

        // false はタームが付いていない(該当なし)
        if ($terms === false) {
            return [];
        }

        return array_values($terms);
    }

    final public static function firstTerm(WP_Post|int $post, string $taxonomy): ?WP_Term
    {
        $terms = static::terms($post, $taxonomy);

        if ($terms === []) {
            return null;
        }

        return $terms[0];
    }

    /** @return list<WP_Post> */
    final public static function parents(WP_Post|int $post, bool $includeSelf = false): array
    {
        $resolved = static::get($post);

        if (!$resolved instanceof WP_Post) {
            return [];
        }

        $parents = [];
        $current = $resolved;

        while ((int) $current->post_parent > 0) {
            $parent = static::get((int) $current->post_parent);

            if (!$parent instanceof WP_Post) {
                break;
            }

            // 上限ちょうどの数の祖先は正常に返し、その先にさらに親があるときだけ超過にする
            if (\count($parents) === self::MAX_PARENT_DEPTH) {
                return self::parentDepthExceeded($resolved->ID);
            }

            $parents[] = $parent;
            $current = $parent;
        }

        $ancestors = array_reverse($parents);

        return $includeSelf ? [...$ancestors, $resolved] : $ancestors;
    }

    /**
     * 投稿を作る。post_type はこのクラスのスラッグにし、ID は無視する。値は元の(slash していない)値を渡す。
     *
     * wp_insert_post() は $postarr を slash 済み($_POST と同じ形)として受け取り、多くの値を wp_unslash() して保存する。
     * 元の値を保存するため、unslash されるキー(SLASHED_KEYS)の値だけを slash して渡す。meta_input はキーも
     * update_post_meta() が unslash するため、WpMetaStorage::slashMetaInput() でキーと値を slash する(null と object を
     * 含む値は InvalidArgumentException)。page_template は unslash されずに検証されるため、元の値のまま渡す。
     *
     * $postarr は php-stubs/wordpress-stubs の wp_insert_post() の $postarr(`@phpstan-param`)と同じ配列の形。
     * stubs が要素の型を書いていない array(tags_input・tax_input・meta_input)だけは、WordPress が受け付ける要素の型を書く。
     *
     * @param array{
     *   ID?: int,
     *   post_author?: int,
     *   post_date?: string,
     *   post_date_gmt?: string,
     *   post_content?: string,
     *   post_content_filtered?: string,
     *   post_title?: string,
     *   post_excerpt?: string,
     *   post_status?: string,
     *   post_type?: string,
     *   comment_status?: string,
     *   ping_status?: string,
     *   post_password?: string,
     *   post_name?: string,
     *   to_ping?: string,
     *   pinged?: string,
     *   post_parent?: int,
     *   menu_order?: int,
     *   post_mime_type?: string,
     *   guid?: string,
     *   import_id?: int,
     *   post_category?: int[],
     *   tags_input?: array<int|string>,
     *   tax_input?: array<string, array<int|string>|string>,
     *   meta_input?: array<string, mixed>,
     *   page_template?: string,
     * } $postarr
     *
     * @throws InvalidArgumentException meta_input に null、または object を含む値がある場合
     * @throws EntityPersistenceException wp_insert_post() が WP_Error を返した場合
     */
    final public static function create(array $postarr): int
    {
        $postarr['post_type'] = static::slug();
        unset($postarr['ID']);
        $slashed = array_replace($postarr, wp_slash(array_intersect_key($postarr, self::SLASHED_KEYS)));

        if (\array_key_exists('meta_input', $postarr)) {
            $slashed['meta_input'] = WpMetaStorage::slashMetaInput($postarr['meta_input']);
        }

        $id = wp_insert_post($slashed, true);

        if ($id instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($id);
        }

        if (!\is_int($id)) {
            throw new EntityPersistenceException('wp_insert_post did not return an integer ID');
        }

        return $id;
    }

    final public static function postTypeObject(): ?WP_Post_Type
    {
        $object = get_post_type_object(static::slug());

        return $object instanceof WP_Post_Type ? $object : null;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /**
     * @param class-string<self> $class
     */
    private static function definitionFor(string $class): PostTypeDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }

    private static function mergeThumbnailSupport(string $slug): void
    {
        $support = get_theme_support('post-thumbnails');

        if ($support === true) {
            return;
        }

        if (\is_array($support) && \array_key_exists(0, $support) && \is_array($support[0])) {
            $types = [];

            foreach ($support[0] as $postType) {
                if (\is_string($postType)) {
                    $types[] = $postType;
                }
            }

            add_theme_support('post-thumbnails', array_values(array_unique([...$types, $slug])));

            return;
        }

        add_theme_support('post-thumbnails', [$slug]);
    }

    /**
     * allowedBlocks()で限ったブロックを、この投稿タイプの編集画面にだけ反映する。ほかの投稿タイプ(別のKernelが
     * 登録したものを含む)の値は受け取ったまま返すため、Kernelどうしで互いの指定を上書きしない。限っていなければ
     * フィルタを登録せず、WordPressの既定のままにする。
     *
     * この投稿タイプでも、先に動いたフィルタの制限は広げない。受け取った値がtrue(すべて許可)なら指定を返し、配列なら
     * 指定との共通部分を受け取った配列の順で返し、それ以外(false等)はそのまま返す。
     */
    private static function registerAllowedBlocks(PostTypeDefinition $definition): void
    {
        $allowedBlocks = $definition->allowedBlockNames();

        if ($allowedBlocks === null) {
            return;
        }

        // 他のプラグインのフィルタがbool・null等を返すこともあるため、値は型で縛らない
        add_filter(
            'allowed_block_types_all',
            static function (mixed $allowedBlockTypes, mixed $context) use ($definition, $allowedBlocks): mixed {
                if (!$context instanceof WP_Block_Editor_Context || !$context->post instanceof WP_Post || $context->post->post_type !== $definition->slug()) {
                    return $allowedBlockTypes;
                }

                if ($allowedBlockTypes === true) {
                    return $allowedBlocks;
                }

                if (\is_array($allowedBlockTypes)) {
                    return array_values(array_filter($allowedBlockTypes, static fn(mixed $name): bool => \in_array($name, $allowedBlocks, true)));
                }

                return $allowedBlockTypes;
            },
            10,
            2,
        );
    }

    private static function registerBlockEditorPreference(PostTypeDefinition $definition): void
    {
        $preference = $definition->blockEditorPreference();

        if ($preference === null) {
            return;
        }

        // 他のプラグインのフィルタが bool 以外(null 等)を返すこともあるため、引数は型で縛らない。対象外の投稿タイプの
        // 値は受け取ったまま返し、扱いは WordPress(戻り値の真偽で判定する)に任せる
        add_filter(
            'use_block_editor_for_post_type',
            static fn(mixed $useBlockEditor, mixed $postType): mixed => $postType === $definition->slug() ? $preference : $useBlockEditor,
            10,
            2,
        );
    }

    /**
     * 親の深さの超過(post_parent の循環等のデータの不整合)を、実行時のデータ取得の
     * 失敗として扱う。debug では例外を投げ、本番ではこのクラスの Kernel のロガーに error で記録して空配列を返す。
     *
     * @throws EntityDataException debug の場合
     *
     * @return list<WP_Post>
     */
    private static function parentDepthExceeded(int $postId): array
    {
        $kernel = Kernel::forClass(static::class);

        if ($kernel->config()->debug) {
            throw new EntityDataException("post parent depth of post {$postId} exceeded " . self::MAX_PARENT_DEPTH . ' (' . static::class . ')');
        }

        $kernel->logger()->error('tmswp.post: post parent depth of post {post} exceeded {limit} ({class})', [
            'post' => $postId,
            'limit' => self::MAX_PARENT_DEPTH,
            'class' => static::class,
        ]);

        return [];
    }
}
