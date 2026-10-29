<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Block\BlockName;
use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

/**
 * register_post_type の引数を組み立てる。
 *
 * RegisterPostTypeArgs は php-stubs/wordpress-stubs の register_post_type() の $args(`@phpstan-param`)と同じ配列の形。
 * stubs が要素の型を書いていない array(capability_type・supports・template)だけは、要素の型を対応するセッターの型に絞る
 * (PHPStan level max は要素の型の無い array を許さないため)。絞った型は stubs の型に含まれる。
 *
 * @phpstan-type RegisterPostTypeArgs array{
 *   label?: string,
 *   labels?: string[],
 *   description?: string,
 *   public?: bool,
 *   hierarchical?: bool,
 *   exclude_from_search?: bool,
 *   publicly_queryable?: bool,
 *   show_ui?: bool,
 *   show_in_menu?: bool|string,
 *   show_in_nav_menus?: bool,
 *   show_in_admin_bar?: bool,
 *   show_in_rest?: bool,
 *   rest_base?: string,
 *   rest_namespace?: string,
 *   rest_controller_class?: string,
 *   autosave_rest_controller_class?: string|bool,
 *   revisions_rest_controller_class?: string|bool,
 *   late_route_registration?: bool,
 *   menu_position?: int,
 *   menu_icon?: string,
 *   capability_type?: string|array{0: string, 1: string},
 *   capabilities?: string[],
 *   map_meta_cap?: bool,
 *   supports?: list<string>|false,
 *   register_meta_box_cb?: callable,
 *   taxonomies?: string[],
 *   has_archive?: bool|string,
 *   rewrite?: bool|array{
 *     slug?: string,
 *     with_front?: bool,
 *     feeds?: bool,
 *     pages?: bool,
 *     ep_mask?: int,
 *   },
 *   query_var?: string|bool,
 *   can_export?: bool,
 *   delete_with_user?: bool,
 *   template?: array<int, array<int, mixed>>,
 *   template_lock?: string|false,
 *   _builtin?: bool,
 *   _edit_link?: string,
 * }
 */
final class PostTypeDefinition
{
    /** null は label() が呼ばれていないこと */
    private ?string $singularLabel = null;

    private ?string $pluralLabel = null;

    /** @var array<string, string> */
    private array $labelOverrides = [];

    /** null は labelLanguage() が呼ばれていないこと(Kernel の既定 KernelConfig::$labelLanguage を使う) */
    private ?LabelLanguage $labelLanguage = null;

    /** @var RegisterPostTypeArgs */
    private array $args = [];

    /** @var list<string>|null */
    private ?array $supports = null;

    /** @var list<string> */
    private array $taxonomies = [];

    private ?bool $blockEditor = null;

    /** @var list<string>|null nullはallowedBlocks()が呼ばれていないこと(WordPressの既定のまま) */
    private ?array $allowedBlocks = null;

    /** @param lowercase-string&non-empty-string $slug */
    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {}

    public static function create(string $slug): self
    {
        $definition = new self(self::assertSlug($slug, 20), false);
        $definition->args['public'] = true;

        return $definition;
    }

    public static function builtin(string $slug): self
    {
        return new self(self::assertSlug($slug, null), true);
    }

    /**
     * labels を生成する元の表示名を指定する。
     *
     * 英語(LabelLanguage::En)では複数形が必須。日本語(LabelLanguage::Ja)では省略でき、省略時は単数形を複数形にも使う。
     * 言語は labelLanguage() で指定し、指定しなければ Kernel の既定(KernelConfig の labelLanguage)を使う。
     */
    public function label(string $singular, ?string $plural = null): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->singularLabel = $singular;
        $this->pluralLabel = $plural;

        return $this;
    }

    /** @param array<string, string> $labels */
    public function labels(array $labels): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->labelOverrides = $labels;

        return $this;
    }

    /**
     * ラベルの言語を指定する。指定しなければ Kernel の既定(KernelConfig の labelLanguage)を使う。
     */
    public function labelLanguage(LabelLanguage $language): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->labelLanguage = $language;

        return $this;
    }

    public function description(string $description): self
    {
        return $this->argsForNew(['description' => $description], __FUNCTION__);
    }

    public function public(bool $public = true): self
    {
        return $this->argsForNew(['public' => $public], __FUNCTION__);
    }

    public function hierarchical(bool $hierarchical = true): self
    {
        return $this->argsForNew(['hierarchical' => $hierarchical], __FUNCTION__);
    }

    public function excludeFromSearch(bool $exclude = true): self
    {
        return $this->argsForNew(['exclude_from_search' => $exclude], __FUNCTION__);
    }

    public function publiclyQueryable(bool $queryable = true): self
    {
        return $this->argsForNew(['publicly_queryable' => $queryable], __FUNCTION__);
    }

    public function showUi(bool $show = true): self
    {
        return $this->argsForNew(['show_ui' => $show], __FUNCTION__);
    }

    public function showInMenu(bool|string $show = true): self
    {
        return $this->argsForNew(['show_in_menu' => $show], __FUNCTION__);
    }

    public function showInNavMenus(bool $show = true): self
    {
        return $this->argsForNew(['show_in_nav_menus' => $show], __FUNCTION__);
    }

    public function showInAdminBar(bool $show = true): self
    {
        return $this->argsForNew(['show_in_admin_bar' => $show], __FUNCTION__);
    }

    public function menuPosition(int $position): self
    {
        return $this->argsForNew(['menu_position' => $position], __FUNCTION__);
    }

    public function menuIcon(string $icon): self
    {
        return $this->argsForNew(['menu_icon' => $icon], __FUNCTION__);
    }

    public function showInRest(bool $show = true): self
    {
        return $this->argsForNew(['show_in_rest' => $show], __FUNCTION__);
    }

    public function restBase(string $base): self
    {
        return $this->argsForNew(['rest_base' => $base], __FUNCTION__);
    }

    public function blockEditor(bool $enabled): self
    {
        $this->blockEditor = $enabled;

        return $this;
    }

    /**
     * この投稿タイプのブロックエディタで使えるブロックを、ブロック名(`core/paragraph`・`my-theme/card`等)で限る。
     * `allowed_block_types_all`で、この投稿タイプの編集画面にだけ反映する。
     *
     * @throws EntityDefinitionException builtinの定義で呼んだ、ブロック名が無い、または形式の違うブロック名を渡した場合
     */
    public function allowedBlocks(string ...$blockNames): self
    {
        $this->assertNotBuiltin(__FUNCTION__);

        if ($blockNames === []) {
            throw new EntityDefinitionException("post type '{$this->slug}' got no block names in allowedBlocks(); pass at least one block name, or use blockEditor(false) to disable the block editor");
        }

        foreach ($blockNames as $blockName) {
            if (!BlockName::isValid($blockName)) {
                throw new EntityDefinitionException("post type '{$this->slug}' got an invalid block name '{$blockName}' in allowedBlocks(); use the form 'namespace/name'");
            }
        }

        $this->allowedBlocks = array_values(array_unique($blockNames));

        return $this;
    }

    /** @param string|array{0: string, 1: string} $type */
    public function capabilityType(string|array $type, bool $mapMetaCap = true): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->args['capability_type'] = $type;
        $this->args['map_meta_cap'] = $mapMetaCap;

        return $this;
    }

    /** @param array<string, string> $capabilities */
    public function capabilities(array $capabilities): self
    {
        return $this->argsForNew(['capabilities' => $capabilities], __FUNCTION__);
    }

    public function supports(string ...$features): self
    {
        $this->supports = array_values(array_unique($features));

        return $this;
    }

    public function taxonomies(string ...$taxonomies): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->taxonomies = array_values([...$this->taxonomies, ...$taxonomies]);

        return $this;
    }

    public function hasArchive(bool|string $archive = true): self
    {
        return $this->argsForNew(['has_archive' => $archive], __FUNCTION__);
    }

    /** @param bool|array{slug?: string, with_front?: bool, feeds?: bool, pages?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self
    {
        return $this->argsForNew(['rewrite' => $rewrite], __FUNCTION__);
    }

    public function rewriteSlug(string $slug, bool $withFront = true): self
    {
        return $this->argsForNew(['rewrite' => ['slug' => $slug, 'with_front' => $withFront]], __FUNCTION__);
    }

    public function queryVar(bool|string $queryVar): self
    {
        return $this->argsForNew(['query_var' => $queryVar], __FUNCTION__);
    }

    public function canExport(bool $canExport = true): self
    {
        return $this->argsForNew(['can_export' => $canExport], __FUNCTION__);
    }

    public function deleteWithUser(bool $delete = true): self
    {
        return $this->argsForNew(['delete_with_user' => $delete], __FUNCTION__);
    }

    /** @param array<int, array<int, mixed>> $blockTemplate */
    public function template(array $blockTemplate, string|false $templateLock = false): self
    {
        $this->assertNotBuiltin(__FUNCTION__);
        $this->args['template'] = $blockTemplate;
        $this->args['template_lock'] = $templateLock;

        return $this;
    }

    /**
     * 専用のセッターが無い register_post_type の引数を設定する。キーごとに上書きする。
     *
     * @param RegisterPostTypeArgs $args
     */
    public function args(array $args): self
    {
        return $this->argsForNew($args, __FUNCTION__);
    }

    /** @return lowercase-string&non-empty-string */
    public function slug(): string
    {
        return $this->slug;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    public function blockEditorPreference(): ?bool
    {
        return $this->blockEditor;
    }

    /**
     * allowedBlocks()で限ったブロック名。nullは限っていないこと。
     *
     * @return list<string>|null
     */
    public function allowedBlockNames(): ?array
    {
        return $this->allowedBlocks;
    }

    /** @return list<string> */
    public function supportsList(): array
    {
        if ($this->supports === null) {
            return [];
        }

        return $this->supports;
    }

    /**
     * @param LabelLanguage $defaultLabelLanguage labelLanguage() を指定していないときのラベル言語(Kernel の既定)
     *
     * @return RegisterPostTypeArgs
     */
    public function toArgs(LabelLanguage $defaultLabelLanguage): array
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("builtin post type '{$this->slug}' cannot be converted to register_post_type args");
        }

        $args = $this->args;
        $labels = $this->labelsForArgs($this->labelLanguage ?? $defaultLabelLanguage);
        $args['labels'] = $labels;
        $args['label'] = $labels['name'];

        if ($this->supports !== null) {
            $args['supports'] = $this->supports;
        }

        if ($this->taxonomies !== []) {
            $resolved = [];

            foreach ($this->taxonomies as $taxonomy) {
                $resolved[] = EntityReference::taxonomySlug($taxonomy);
            }

            $args['taxonomies'] = array_values(array_unique($resolved));
        }

        return $args;
    }

    /**
     * スラッグを検証し、register_post_type() の $post_type と同じ型(小文字の空でない文字列)で返す。
     *
     * @return lowercase-string&non-empty-string
     */
    private static function assertSlug(string $slug, ?int $maxLength): string
    {
        if (preg_match('/^[a-z0-9_\-]+$/', $slug) !== 1) {
            throw new EntityDefinitionException("invalid post type slug: {$slug}");
        }

        if ($maxLength !== null && \strlen($slug) > $maxLength) {
            throw new EntityDefinitionException("post type slug must be {$maxLength} characters or fewer: {$slug}");
        }

        // 正規表現で小文字・数字・_・- だけと確かめた値なので、strtolower() は値を変えない。型を小文字の文字列にするために通す
        return strtolower($slug);
    }

    private function assertNotBuiltin(string $method): void
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("PostTypeDefinition::{$method}() is not allowed for builtin post type '{$this->slug}'");
        }
    }

    /** @param RegisterPostTypeArgs $args */
    private function argsForNew(array $args, string $method): self
    {
        $this->assertNotBuiltin($method);
        $this->args = array_merge($this->args, $args);

        return $this;
    }

    /** @return array<string, string> */
    private function labelsForArgs(LabelLanguage $language): array
    {
        if ($this->singularLabel === null && !\array_key_exists('name', $this->labelOverrides)) {
            throw new EntityDefinitionException("post type '{$this->slug}' requires label() or labels(['name' => ...])");
        }

        if ($this->singularLabel === null) {
            return $this->labelOverrides;
        }

        // 空文字のラベルは、複数形や labels() の有無・言語に関係なく設定の誤りとして拒否する
        if ($this->singularLabel === '') {
            throw new EntityDefinitionException("post type '{$this->slug}' got an empty singular label: pass a non-empty singular to label()");
        }

        // 複数形の省略は null(第2引数を渡さない)で表すため、空文字の複数形も拒否する
        if ($this->pluralLabel === '') {
            throw new EntityDefinitionException("post type '{$this->slug}' got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");
        }

        // 日本語には複数形が無いため、Ja に限り省略した複数形を単数形で補う。En の省略は設定の誤りとして常に拒否する。
        $plural = $this->pluralLabel ?? match ($language) {
            LabelLanguage::Ja => $this->singularLabel,
            LabelLanguage::En => throw new EntityDefinitionException("post type '{$this->slug}' requires a plural label for LabelLanguage::En: pass it as label('{$this->singularLabel}', '<plural>'), or select LabelLanguage::Ja with labelLanguage() or KernelConfig's labelLanguage"),
        };

        $labels = array_merge(
            LabelGenerator::forPostType($this->singularLabel, $plural, $language, $this->args['hierarchical'] ?? false),
            $this->labelOverrides,
        );

        // LabelGenerator はコアと同じく、階層の有無で既定が null になるキーを null で返す。stubs の labels は string[] のため、
        // null のキーは除いて渡す。WordPress は無いキーを、同じ階層の有無に応じたコアの既定(同じ null)で補う(_get_custom_object_labels())
        return array_filter($labels, static fn(?string $label): bool => $label !== null);
    }
}
