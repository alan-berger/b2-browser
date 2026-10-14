<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Renders PHP templates from views/. Templates receive their variables plus
 * $e() for HTML escaping and $url() for building query strings. Every page is
 * wrapped in views/layout.php; a template can set $bodyClass, $pageTitle and
 * $refreshTo for the layout to use.
 */
final class View
{
    private const LAYOUT_VARS = ['bodyClass', 'pageTitle', 'refreshTo'];

    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        [$content, $locals] = $this->capture($template, $vars);
        foreach (self::LAYOUT_VARS as $name) {
            if (array_key_exists($name, $locals)) {
                $vars[$name] = $locals[$name];
            }
        }
        $vars['content'] = $content;
        return $this->capture('layout', $vars)[0];
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        return $this->capture($template, $vars)[0];
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function bytes(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 2) . ' ' . $unit;
            }
        }
        return $bytes . ' B';
    }

    /**
     * @param array<string, mixed> $vars
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function capture(string $template, array $vars): array
    {
        $file = $this->directory . '/' . $template . '.php';
        if (!preg_match('/^[a-z_]+$/', $template) || !is_file($file)) {
            throw new \InvalidArgumentException("Unknown template {$template}");
        }
        $vars['e'] = static fn(mixed $s): string => self::escape($s);
        $vars['url'] = static fn(array $query): string => '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $vars['view'] = $this;

        return (static function (string $__file, array $__vars): array {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $t) {
                ob_end_clean();
                throw $t;
            }
            $__out = (string) ob_get_clean();
            return [$__out, get_defined_vars()];
        })($file, $vars);
    }
}
