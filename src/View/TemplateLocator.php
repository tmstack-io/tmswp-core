<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidTemplateSlugException;

final class TemplateLocator
{
    /** @var list<string> */
    private array $fallbackDirs = [];

    /** @var array<string, string|null> */
    private array $resolved = [];

    public function addFallbackDir(string $dir): void
    {
        $trimmed = rtrim($dir, '/\\');

        if ($trimmed === '') {
            throw new InvalidArgumentException("template fallback directory must not be empty: '{$dir}'");
        }

        if (\in_array($trimmed, $this->fallbackDirs, true)) {
            return;
        }

        $this->fallbackDirs[] = $trimmed;
        $this->flush();
    }

    /** @return list<string> */
    public function fallbackDirs(): array
    {
        return $this->fallbackDirs;
    }

    public function locate(string $slug): ?string
    {
        $this->assertSlug($slug);

        if (\array_key_exists($slug, $this->resolved)) {
            return $this->resolved[$slug];
        }

        foreach ($this->candidatePaths($slug) as $path) {
            if (is_file($path)) {
                $this->resolved[$slug] = $path;

                return $path;
            }
        }

        $this->resolved[$slug] = null;

        return null;
    }

    public function exists(string $slug): bool
    {
        return $this->locate($slug) !== null;
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    /** @return list<string> */
    public function candidatePaths(string $slug): array
    {
        $this->assertSlug($slug);

        $paths = [];
        $stylesheet = rtrim(get_stylesheet_directory(), '/\\');
        $template = rtrim(get_template_directory(), '/\\');

        foreach (array_values(array_unique([$stylesheet, $template])) as $root) {
            if ($root !== '') {
                $paths[] = $root . DIRECTORY_SEPARATOR . $slug . '.php';
            }
        }

        foreach ($this->fallbackDirs as $dir) {
            $paths[] = $dir . DIRECTORY_SEPARATOR . $slug . '.php';
        }

        return $paths;
    }

    /**
     * slug が形式の規則に合うかを確かめる。
     *
     * @throws InvalidTemplateSlugException slug が形式の規則に合わないとき
     */
    public function assertSlug(string $slug): void
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/', $slug) === 1) {
            return;
        }

        throw new InvalidTemplateSlugException("invalid template slug: {$slug}");
    }
}
