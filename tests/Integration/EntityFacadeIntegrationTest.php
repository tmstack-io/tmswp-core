<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use GdImage;
use stdClass;
use TMSWP\Core\Exception\EntityDataException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Post\Thumbnail;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\User\UserRole;
use TMSWP\Testing\IntegrationTestCase;
use WP_Error;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * エンティティのファサード(取得系・URL・アイキャッチ・親・書き込み)の振る舞いを、実物のWordPressで確かめる
 * (docs/03 §16の「取得系」「書き込み」)。fixtureのエンティティはWordPressの読み込みでinitより前にbootした
 * Kernelが登録している。
 */
final class EntityFacadeIntegrationTest extends IntegrationTestCase
{
    /** get()は、タイプの違う投稿・0・負のIDにnullを返す。 */
    public function testGetReturnsNullForMismatchedTypeAndNonPositiveIds(): void
    {
        $productPost = self::productPost();
        $productId = self::createPost($productPost::slug());
        $pagePost = get_post(self::createPost('page'));
        self::assertInstanceOf(WP_Post::class, $pagePost);

        self::assertNull($productPost::get($pagePost));
        self::assertNull($productPost::get($pagePost->ID));
        self::assertNull($productPost::get(0));
        self::assertNull($productPost::get(-1));
        // 比べ方の確認:同じタイプの投稿は返す
        self::assertSame($productId, $productPost::get($productId)?->ID);
    }

    /**
     * URLの取得は、WordPressの関数の「該当なし」(false・空文字)と、解決できない対象をnullにする。
     */
    public function testUrlGettersNormalizeNotFoundToNull(): void
    {
        $productPost = self::productPost();
        $metaPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $category = self::productCategory();
        $member = self::memberRole();
        $postId = self::createPost($productPost::slug());
        $userId = self::factory()->user->create(['role' => $member::role()]);
        self::assertIsInt($userId);

        // 比べ方の確認:該当があれば文字列を返す
        self::assertIsString($productPost::permalink($postId));
        self::assertIsString($productPost::archiveUrl());
        self::assertIsString($member::authorUrl($userId));

        // アーカイブを持たない投稿タイプはget_post_type_archive_link()がfalseを返す
        self::assertNull($metaPost::archiveUrl());
        // アイキャッチもフォールバックも無い
        self::assertNull($productPost::thumbnailUrl($postId));
        // 無い対象・スコープ外の対象
        self::assertNull($productPost::permalink(PHP_INT_MAX));
        self::assertNull($productPost::permalink(self::createPost('page')));
        self::assertNull($category::url(PHP_INT_MAX));
        self::assertNull($category::url('tmswp-no-such-term'));
        self::assertNull($member::authorUrl(PHP_INT_MAX));
        self::assertNull($productPost::query()->idIn(PHP_INT_MAX)->url());

        // WordPressの関数がfalse・空文字を返す場合
        add_filter('post_type_link', '__return_false');
        add_filter('author_link', '__return_empty_string');
        self::assertNull($productPost::permalink($postId));
        self::assertNull($productPost::query()->idIn($postId)->url());
        self::assertNull($member::authorUrl($userId));
    }

    /**
     * thumbnail()は、アイキャッチが無くフォールバックも無ければnullを返す。tmswp/post/thumbnail_fallbackのフィルタが
     * 返したThumbnailはそのまま返し、Thumbnail以外の値は無視する。
     */
    public function testThumbnailFallsBackToTheFilterAndIgnoresNonThumbnailValues(): void
    {
        $productPost = self::productPost();
        $postId = self::createPost($productPost::slug());

        self::assertNull($productPost::thumbnail($postId));

        $fallback = new Thumbnail('https://example.com/fallback.jpg', 640, 480, 'Fallback');
        $received = [];
        add_filter('tmswp/post/thumbnail_fallback', static function (mixed $value, WP_Post $post, string|array $size) use ($fallback, &$received): Thumbnail {
            $received[] = [$value, $post->ID, $size];

            return $fallback;
        }, 10, 3);

        self::assertSame($fallback, $productPost::thumbnail($postId, 'medium'));
        self::assertSame([[null, $postId, 'medium']], $received);

        add_filter('tmswp/post/thumbnail_fallback', static fn(): string => 'https://example.com/not-a-thumbnail.jpg', 20);

        self::assertNull($productPost::thumbnail($postId));
        self::assertNull($productPost::thumbnailUrl($postId));
    }

    /**
     * アイキャッチのある投稿のthumbnail()。旧iwf2bのテストと同じく、GDで作った実際の画像をWordPressのファクトリ
     * (create_upload_object())でアップロードし、URL・幅・高さ・altと、サイズの指定(WordPressが作った中間サイズ)を
     * 確かめる。アイキャッチがあればtmswp/post/thumbnail_fallbackのフィルタは使わない。
     */
    public function testThumbnailResolvesTheFeaturedImageGeneratedWithGd(): void
    {
        $productPost = self::productPost();
        $postId = self::createPost($productPost::slug());
        $image = self::createJpegWithGd(200, 150);
        $attachmentId = null;

        try {
            $attachmentId = self::factory()->attachment->create_upload_object($image, $postId);
            self::assertIsInt($attachmentId);
            update_post_meta($attachmentId, '_wp_attachment_image_alt', 'Image alt text');
            self::assertNotFalse(set_post_thumbnail($postId, $attachmentId));
            $fallbackCalls = 0;
            add_filter('tmswp/post/thumbnail_fallback', static function () use (&$fallbackCalls): Thumbnail {
                ++$fallbackCalls;

                return new Thumbnail('https://example.com/fallback.jpg', 640, 480, 'Fallback');
            });

            $full = $productPost::thumbnail($postId);

            self::assertInstanceOf(Thumbnail::class, $full);
            self::assertSame(wp_get_attachment_url($attachmentId), $full->url);
            self::assertSame([200, 150, 'Image alt text'], [$full->width, $full->height, $full->alt]);

            // 既定のthumbnailサイズ(150x150の切り抜き)
            $small = $productPost::thumbnail($postId, 'thumbnail');

            self::assertInstanceOf(Thumbnail::class, $small);
            self::assertStringEndsWith('-150x150.jpg', $small->url);
            self::assertSame([150, 150], [$small->width, $small->height]);
            self::assertSame($small->url, $productPost::thumbnailUrl($postId, 'thumbnail'));
            self::assertSame(0, $fallbackCalls);
        } finally {
            if (\is_int($attachmentId)) {
                wp_delete_attachment($attachmentId, true);
            }

            unlink($image);
        }
    }

    /**
     * thumbnail()のaltは、添付の_wp_attachment_image_altメタだけから決める(タグを除き前後の空白を取る)。メタが無ければ
     * 空文字で、添付のpost_excerpt(キャプション)・post_titleにはフォールバックしない。
     */
    public function testThumbnailAltIsResolvedOnlyFromTheAttachmentAltMeta(): void
    {
        $productPost = self::productPost();
        $postId = self::createPost($productPost::slug());
        $attachmentId = self::factory()->attachment->create_object([
            'file' => 'tmswp-alt.jpg',
            'post_parent' => $postId,
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Attachment title',
            'post_excerpt' => 'Attachment caption',
        ]);
        self::assertIsInt($attachmentId);
        self::assertNotFalse(set_post_thumbnail($postId, $attachmentId));

        $thumbnail = $productPost::thumbnail($postId);
        self::assertInstanceOf(Thumbnail::class, $thumbnail);
        self::assertStringEndsWith('tmswp-alt.jpg', $thumbnail->url);
        self::assertSame('', $thumbnail->alt);

        update_post_meta($attachmentId, '_wp_attachment_image_alt', '  <b>Alt</b> text ');

        self::assertSame('Alt text', $productPost::thumbnail($postId)?->alt);
    }

    /**
     * parents()は、ルートの祖先から直近の親の順に返す。$includeSelfなら自身を末尾に加える(PostType・Taxonomy)。
     */
    public function testParentsAreOrderedFromTheRootToTheNearestParent(): void
    {
        $root = self::createPost('page');
        $child = self::createPage($root);
        $grandchild = self::createPage($child);
        self::assertSame([$root, $child], self::postIds(FacadePage::parents($grandchild)));
        self::assertSame([$root, $child, $grandchild], self::postIds(FacadePage::parents($grandchild, true)));
        self::assertSame([], FacadePage::parents($root));

        $category = self::productCategory();
        $rootTerm = $category::create('Parents root');
        $childTerm = $category::create('Parents child', ['parent' => $rootTerm]);
        $grandchildTerm = $category::create('Parents grandchild', ['parent' => $childTerm]);
        self::assertSame([$rootTerm, $childTerm], array_map(static fn(WP_Term $term): int => $term->term_id, $category::parents($grandchildTerm)));
        self::assertSame([$rootTerm, $childTerm, $grandchildTerm], array_map(static fn(WP_Term $term): int => $term->term_id, $category::parents($grandchildTerm, true)));
        self::assertSame([], $category::parents($rootTerm));
    }

    /**
     * create()は、投稿タイプ・タクソノミー・ロールをエンティティのものにし、IDを無視して新規に作る。WordPressが
     * WP_Errorを返したら、それをwpErrorに持つEntityPersistenceExceptionを投げる。
     */
    public function testCreateForcesTheScopeIgnoresIdAndThrowsOnWpError(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        $existing = self::createPost('page');

        $postId = $productPost::create(['ID' => $existing, 'post_type' => 'page', 'post_title' => 'Created product', 'post_status' => 'publish']);
        self::assertNotSame($existing, $postId);
        self::assertSame($productPost::slug(), get_post_type($postId));
        self::assertSame('Integration page', get_the_title($existing));

        $termId = $category::create('Created term');
        $term = get_term($termId);
        self::assertInstanceOf(WP_Term::class, $term);
        self::assertSame($category::slug(), $term->taxonomy);

        $userId = $member::create('tmswp_created_user', 'password', ['ID' => 1, 'role' => 'administrator', 'user_email' => 'created@example.test']);
        self::assertNotSame(1, $userId);
        $user = get_userdata($userId);
        self::assertInstanceOf(WP_User::class, $user);
        self::assertSame([$member::role()], $user->roles);
        self::assertSame('tmswp_created_user', $user->user_login);

        add_filter('wp_insert_post_empty_content', '__return_true');
        $failures = [
            'post' => [static fn(): int => $productPost::create(['post_title' => 'Rejected']), 'empty_content'],
            'term' => [static fn(): int => $category::create(''), 'empty_term_name'],
            'user' => [static fn(): int => $member::create('tmswp_created_user', 'password'), 'existing_user_login'],
        ];

        foreach ($failures as $name => [$create, $code]) {
            try {
                $create();
                self::fail("EntityPersistenceException was not thrown for {$name}");
            } catch (EntityPersistenceException $exception) {
                self::assertInstanceOf(WP_Error::class, $exception->wpError, $name);
                self::assertSame($code, $exception->wpError->get_error_code(), $name);
                self::assertSame($exception->wpError->get_error_message(), $exception->getMessage(), $name);
            }
        }
    }

    /**
     * parents()は、祖先が深さの上限(100件)ちょうどなら全件を返し、101件目の親があるときだけ深さの超過にする
     * (fixtureのテーマはdebugのため、超過はEntityDataException)。
     */
    public function testParentsReturnsExactlyTheDepthLimitAndExceedsOnlyBeyondIt(): void
    {
        $productPost = self::productPost();
        $category = self::productCategory();
        $postIds = [$productPost::create(['post_title' => 'Depth 0', 'post_status' => 'publish'])];
        $termIds = [$category::create('Depth 0')];

        for ($depth = 1; $depth <= 101; ++$depth) {
            $postIds[] = $productPost::create(['post_title' => "Depth {$depth}", 'post_status' => 'publish', 'post_parent' => $postIds[$depth - 1]]);
            $termIds[] = $category::create("Depth {$depth}", ['parent' => $termIds[$depth - 1]]);
        }

        // 祖先がちょうど100件
        self::assertSame(\array_slice($postIds, 0, 100), self::postIds($productPost::parents($postIds[100])));
        self::assertSame(\array_slice($postIds, 0, 101), self::postIds($productPost::parents($postIds[100], true)));
        self::assertSame(\array_slice($termIds, 0, 100), self::termIds($category::parents($termIds[100])));
        self::assertSame(\array_slice($termIds, 0, 101), self::termIds($category::parents($termIds[100], true)));

        // 祖先が101件
        try {
            $productPost::parents($postIds[101]);
            self::fail('EntityDataException was not thrown for the post');
        } catch (EntityDataException $exception) {
            self::assertSame("post parent depth of post {$postIds[101]} exceeded 100 ({$productPost})", $exception->getMessage());
        }

        try {
            $category::parents($termIds[101]);
            self::fail('EntityDataException was not thrown for the term');
        } catch (EntityDataException $exception) {
            self::assertSame("term parent depth of term {$termIds[101]} exceeded 100 ({$category})", $exception->getMessage());
        }
    }

    /**
     * create()は値をslashしてWordPressに渡し、バックスラッシュ・引用符・JSONのエスケープ(\u003c等)を元のまま保存する。
     * 投稿は列・meta_input(キーを含む)・tax_inputのターム名、タームは名前と説明、ユーザーは列・メタ・meta_inputを
     * 確かめる。ユーザーのパスワードは、ログインの画面と同じくslashした値(wp_signon()が使う$_POST['pwd']の形)で
     * 認証できる。
     */
    public function testCreateKeepsBackslashesQuotesAndJsonAsGiven(): void
    {
        // kses(unfiltered_htmlの無いユーザーの本文の書き換え)の影響を除き、slashだけを確かめる
        $admin = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($admin);
        wp_set_current_user($admin);
        $productPost = self::productPost();
        $category = self::productCategory();
        $member = self::memberRole();
        $path = 'C:\\temp\\new';
        $json = '{"html":"\\u003cb\\u003e","path":"C:\\\\temp","quote":"\\"q\\""}';
        $block = '<!-- wp:paragraph {"placeholder":"\\u003cb\\u003e \\u0022q\\u0022"} --><p>a\\b</p><!-- /wp:paragraph -->';

        $postId = $productPost::create([
            'post_title' => $path,
            'post_content' => $block,
            'post_excerpt' => $json,
            'post_status' => 'publish',
            'meta_input' => ['tmswp_json' => $json, 'tmswp_list' => [$path, ['nested' => $json]], 'tmswp_back\\slash' => $path],
            'tax_input' => ['tmswp_product_tag' => [$path]],
        ]);
        $post = get_post($postId);
        self::assertInstanceOf(WP_Post::class, $post);
        self::assertSame($path, $post->post_title);
        self::assertSame($block, $post->post_content);
        self::assertSame($json, $post->post_excerpt);
        self::assertSame($json, get_post_meta($postId, 'tmswp_json', true));
        self::assertSame([$path, ['nested' => $json]], get_post_meta($postId, 'tmswp_list', true));
        self::assertSame($path, get_post_meta($postId, 'tmswp_back\\slash', true));
        $tags = get_the_terms($postId, 'tmswp_product_tag');
        self::assertIsArray($tags);
        self::assertSame([$path], array_map(static fn(WP_Term $term): string => $term->name, $tags));

        $termId = $category::create($path, ['description' => $json]);
        $term = get_term($termId);
        self::assertInstanceOf(WP_Term::class, $term);
        self::assertSame($path, $term->name);
        self::assertSame($json, $term->description);

        $password = 'pa"ss\'w\\ord';
        $userId = $member::create('tmswp_slash_user', $password, [
            'user_email' => 'slash@example.test',
            'display_name' => $path,
            'description' => $json,
            'meta_input' => ['tmswp_json' => $json, 'tmswp_back\\slash' => $path],
        ]);
        $user = get_userdata($userId);
        self::assertInstanceOf(WP_User::class, $user);
        self::assertSame($path, $user->display_name);
        self::assertSame($json, get_user_meta($userId, 'description', true));
        self::assertSame($json, get_user_meta($userId, 'tmswp_json', true));
        self::assertSame($path, get_user_meta($userId, 'tmswp_back\\slash', true));
        self::assertSame([$member::role()], $user->roles);

        // wp_signon()は$_POST['pwd'](slash済み)をそのままwp_authenticate()に渡す
        $authenticated = wp_authenticate('tmswp_slash_user', wp_slash($password));
        self::assertInstanceOf(WP_User::class, $authenticated);
        self::assertSame($userId, $authenticated->ID);
    }

    /**
     * create()はpage_templateをslashしない。WordPressはpage_templateをunslashせずにテーマのテンプレートの配列のキーと
     * して検証するため、引用符を含む登録済みのテンプレートもそのまま指定でき、テンプレートのメタに保存される。
     */
    public function testCreateKeepsPageTemplateWithQuotesAsRegistered(): void
    {
        $template = "templates/t6-quote's.php";
        $register = static fn(array $templates): array => [...$templates, $template => 'Quote'];
        add_filter('theme_page_templates', $register);

        try {
            $postId = FacadePage::create(['post_title' => 'Quote template', 'post_status' => 'publish', 'page_template' => $template]);
            self::assertSame($template, get_post_meta($postId, '_wp_page_template', true));
            self::assertSame($template, get_page_template_slug($postId));
        } finally {
            remove_filter('theme_page_templates', $register);
        }
    }

    /**
     * create()のmeta_inputは、WpMetaStorageと同じくnullとobjectを含む値を受け付けない(objectはslashできず、
     * nullは空文字で保存され、元の値として往復しないため)。WordPressを呼ぶ前に例外にし、何も作らない。
     */
    public function testCreateRejectsNullAndObjectsInMetaInput(): void
    {
        $productPost = self::productPost();
        $member = self::memberRole();
        $cases = [
            'post object' => [static fn(): int => $productPost::create(['post_title' => 'Rejected', 'post_status' => 'publish', 'meta_input' => ['tmswp_object' => ['nested' => new stdClass()]]]), "meta 'tmswp_object' cannot store an object (stdClass)"],
            'post null' => [static fn(): int => $productPost::create(['post_title' => 'Rejected', 'post_status' => 'publish', 'meta_input' => ['tmswp_null' => null]]), "meta 'tmswp_null' cannot store null; use delete() to remove it"],
            'user object' => [static fn(): int => $member::create('tmswp_rejected_user', 'password', ['meta_input' => ['tmswp_object' => new stdClass()]]), "meta 'tmswp_object' cannot store an object (stdClass)"],
        ];

        foreach ($cases as $name => [$create, $message]) {
            try {
                $create();
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }

        self::assertSame([], get_posts(['post_type' => $productPost::slug(), 'title' => 'Rejected', 'post_status' => 'any', 'fields' => 'ids']));
        self::assertFalse(get_user_by('login', 'tmswp_rejected_user'));
    }

    /**
     * GDで幅×高さのJPEG画像を一時ディレクトリに作り、そのパスを返す(呼び出し側が消す)。
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function createJpegWithGd(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(GdImage::class, $image);
        $color = imagecolorallocate($image, 32, 96, 160);
        self::assertIsInt($color);
        self::assertTrue(imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color));
        $path = get_temp_dir() . 'tmswp-thumbnail-' . uniqid() . '.jpg';
        self::assertTrue(imagejpeg($image, $path));

        return $path;
    }

    /**
     * @param list<WP_Post> $posts
     *
     * @return list<int>
     */
    private static function postIds(array $posts): array
    {
        return array_map(static fn(WP_Post $post): int => $post->ID, $posts);
    }

    /**
     * @param list<WP_Term> $terms
     *
     * @return list<int>
     */
    private static function termIds(array $terms): array
    {
        return array_map(static fn(WP_Term $term): int => $term->term_id, $terms);
    }

    private static function createPage(int $parent): int
    {
        $postId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Child page', 'post_parent' => $parent]);
        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);

        return $postId;
    }

    /** @return class-string<PostType> */
    private static function productPost(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
    }

    /** @return class-string<Taxonomy> */
    private static function productCategory(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
    }

    /** @return class-string<UserRole> */
    private static function memberRole(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
    }
}

/** 固定ページのアダプタ(Kernelに登録しない。parents()の順の確認に使う)。 */
final class FacadePage extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page');
    }
}
