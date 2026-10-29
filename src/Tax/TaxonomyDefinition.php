<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Entity\EntityReference;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

/**
 * register_taxonomy の引数を組み立てる。
 *
 * RegisterTaxonomyArgs は php-stubs/wordpress-stubs の register_taxonomy() の $args(`@phpstan-param`)と同じ配列の形。
 * stubs が要素の型を書いていない array(args)だけは、wp_get_object_terms() の引数(get_terms() と同じ形)の型を書く
 * (PHPStan level max は要素の型の無い array を許さないため)。
 *
 * @phpstan-import-type GetTermsArgs from TermQuery
 *
 * @phpstan-type RegisterTaxonomyArgs array{
 *   labels?: string[],
 *   description?: string,
 *   public?: bool,
 *   publicly_queryable?: bool,
 *   hierarchical?: bool,
 *   show_ui?: bool,
 *   show_in_menu?: bool,
 *   show_in_nav_menus?: bool,
 *   show_in_rest?: bool,
 *   rest_base?: string,
 *   rest_namespace?: string,
 *   rest_controller_class?: string,
 *   show_tagcloud?: bool,
 *   show_in_quick_edit?: bool,
 *   show_admin_column?: bool,
 *   meta_box_cb?: bool|callable,
 *   meta_box_sanitize_cb?: callable,
 *   capabilities?: array{
 *     manage_terms?: string,
 *     edit_terms?: string,
 *     delete_terms?: string,
 *     assign_terms?: string,
 *   },
 *   rewrite?: bool|array{
 *     slug?: string,
 *     with_front?: bool,
 *     hierarchical?: bool,
 *     ep_mask?: int,
 *   },
 *   query_var?: string|bool,
 *   update_count_callback?: callable,
 *   default_term?: string|array{
 *     name?: string,
 *     slug?: string,
 *     description?: string,
 *   },
 *   sort?: bool,
 *   args?: GetTermsArgs,
 *   _builtin?: bool,
 * }
 */
final class TaxonomyDefinition
{
    /** null は label() が呼ばれていないこと */
    private ?string $singularLabel = null;

    private ?string $pluralLabel = null;

    /** @var array<string, string> */
    private array $labelOverrides = [];

    /** null は labelLanguage() が呼ばれていないこと(Kernel の既定 KernelConfig::$labelLanguage を使う) */
    private ?LabelLanguage $labelLanguage = null;

    /** @var RegisterTaxonomyArgs */
    private array $args = [];

    /** @var list<string> */
    private array $objectTypes = [];

    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {}

    public static function create(string $slug): self
    {
        self::assertSlug($slug, 32);

        return new self($slug, false);
    }

    public static function builtin(string $slug): self
    {
        self::assertSlug($slug, null);

        return new self($slug, true);
    }

    public function objectType(string ...$objectTypes): self
    {
        $this->objectTypes = array_values([...$this->objectTypes, ...$objectTypes]);

        return $this;
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

    public function publiclyQueryable(bool $queryable = true): self
    {
        return $this->argsForNew(['publicly_queryable' => $queryable], __FUNCTION__);
    }

    public function showUi(bool $show = true): self
    {
        return $this->argsForNew(['show_ui' => $show], __FUNCTION__);
    }

    public function showInMenu(bool $show = true): self
    {
        return $this->argsForNew(['show_in_menu' => $show], __FUNCTION__);
    }

    public function showInNavMenus(bool $show = true): self
    {
        return $this->argsForNew(['show_in_nav_menus' => $show], __FUNCTION__);
    }

    public function showTagcloud(bool $show = true): self
    {
        return $this->argsForNew(['show_tagcloud' => $show], __FUNCTION__);
    }

    public function showInQuickEdit(bool $show = true): self
    {
        return $this->argsForNew(['show_in_quick_edit' => $show], __FUNCTION__);
    }

    public function showAdminColumn(bool $show = true): self
    {
        return $this->argsForNew(['show_admin_column' => $show], __FUNCTION__);
    }

    public function showInRest(bool $show = true): self
    {
        return $this->argsForNew(['show_in_rest' => $show], __FUNCTION__);
    }

    public function restBase(string $base): self
    {
        return $this->argsForNew(['rest_base' => $base], __FUNCTION__);
    }

    /** @param array{manage_terms?: string, edit_terms?: string, delete_terms?: string, assign_terms?: string} $capabilities */
    public function capabilities(array $capabilities): self
    {
        return $this->argsForNew(['capabilities' => $capabilities], __FUNCTION__);
    }

    /** @param bool|array{slug?: string, with_front?: bool, hierarchical?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self
    {
        return $this->argsForNew(['rewrite' => $rewrite], __FUNCTION__);
    }

    public function rewriteSlug(string $slug, bool $withFront = true, bool $hierarchical = false): self
    {
        return $this->argsForNew(['rewrite' => ['slug' => $slug, 'with_front' => $withFront, 'hierarchical' => $hierarchical]], __FUNCTION__);
    }

    public function queryVar(bool|string $queryVar): self
    {
        return $this->argsForNew(['query_var' => $queryVar], __FUNCTION__);
    }

    /** @param string|array{name: string, slug?: string, description?: string} $term */
    public function defaultTerm(string|array $term): self
    {
        return $this->argsForNew(['default_term' => $term], __FUNCTION__);
    }

    public function sort(bool $sort = true): self
    {
        return $this->argsForNew(['sort' => $sort], __FUNCTION__);
    }

    /**
     * 専用のセッターが無い register_taxonomy の引数を設定する。キーごとに上書きする。
     *
     * @param RegisterTaxonomyArgs $args
     */
    public function args(array $args): self
    {
        return $this->argsForNew($args, __FUNCTION__);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function isBuiltin(): bool
    {
        return $this->builtin;
    }

    /** @return list<string> */
    public function resolvedObjectTypes(): array
    {
        $resolved = [];

        foreach ($this->objectTypes as $objectType) {
            $resolved[] = EntityReference::postTypeSlug($objectType);
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param LabelLanguage $defaultLabelLanguage labelLanguage() を指定していないときのラベル言語(Kernel の既定)
     *
     * @return RegisterTaxonomyArgs
     */
    public function toArgs(LabelLanguage $defaultLabelLanguage): array
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("builtin taxonomy '{$this->slug}' cannot be converted to register_taxonomy args");
        }

        $args = $this->args;
        $args['labels'] = $this->labelsForArgs($this->labelLanguage ?? $defaultLabelLanguage);

        return $args;
    }

    private static function assertSlug(string $slug, ?int $maxLength): void
    {
        if (preg_match('/^[a-z0-9_\-]+$/', $slug) !== 1) {
            throw new EntityDefinitionException("invalid taxonomy slug: {$slug}");
        }

        if ($maxLength !== null && \strlen($slug) > $maxLength) {
            throw new EntityDefinitionException("taxonomy slug must be {$maxLength} characters or fewer: {$slug}");
        }
    }

    private function assertNotBuiltin(string $method): void
    {
        if ($this->builtin) {
            throw new EntityDefinitionException("TaxonomyDefinition::{$method}() is not allowed for builtin taxonomy '{$this->slug}'");
        }
    }

    /** @param RegisterTaxonomyArgs $args */
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
            throw new EntityDefinitionException("taxonomy '{$this->slug}' requires label() or labels(['name' => ...])");
        }

        if ($this->singularLabel === null) {
            return $this->labelOverrides;
        }

        // 空文字のラベルは、複数形や labels() の有無・言語に関係なく設定の誤りとして拒否する
        if ($this->singularLabel === '') {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' got an empty singular label: pass a non-empty singular to label()");
        }

        // 複数形の省略は null(第2引数を渡さない)で表すため、空文字の複数形も拒否する
        if ($this->pluralLabel === '') {
            throw new EntityDefinitionException("taxonomy '{$this->slug}' got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");
        }

        // 日本語には複数形が無いため、Ja に限り省略した複数形を単数形で補う。En の省略は設定の誤りとして常に拒否する。
        $plural = $this->pluralLabel ?? match ($language) {
            LabelLanguage::Ja => $this->singularLabel,
            LabelLanguage::En => throw new EntityDefinitionException("taxonomy '{$this->slug}' requires a plural label for LabelLanguage::En: pass it as label('{$this->singularLabel}', '<plural>'), or select LabelLanguage::Ja with labelLanguage() or KernelConfig's labelLanguage"),
        };

        $labels = array_merge(
            LabelGenerator::forTaxonomy($this->singularLabel, $plural, $language, $this->args['hierarchical'] ?? false),
            $this->labelOverrides,
        );

        // LabelGenerator はコアと同じく、階層の有無で既定が null になるキーを null で返す。stubs の labels は string[] のため、
        // null のキーは除いて渡す。WordPress は無いキーを、同じ階層の有無に応じたコアの既定(同じ null)で補う(_get_custom_object_labels())
        return array_filter($labels, static fn(?string $label): bool => $label !== null);
    }
}
