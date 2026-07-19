<?php declare(strict_types=1);

namespace AutoDoc;

use Exception;
use Throwable;

/**
 * @phpstan-import-type UiConfig from Config
 * @phpstan-import-type WikiPageConfig from Config
 *
 * @phpstan-type WikiPage array{id: string, title: string, url: string}
 * @phpstan-type RouteGroup array{title: string, routes?: list<string>, exact_routes?: list<string>, collapsed?: bool}
 * @phpstan-type SidebarConfig array{
 *     routes?: array{
 *         show_path?: bool,
 *         show_title?: bool,
 *         show_method?: bool,
 *         show_path_above_title?: bool,
 *     },
 * }
 */
class DocViewer
{
    private const array ASSETS = [
        'autodoc-viewer.js' => 'text/javascript; charset=utf-8',
        'autodoc-viewer.css' => 'text/css; charset=utf-8',
    ];

    private const string OPENAPI_PATH = 'openapi.json';

    private const string ASSETS_PATH = 'assets/';

    private const string WIKI_PATH = 'wiki/';

    public readonly string $title;

    public string $openApiUrl;

    public readonly string $assetsBaseUrl;

    /** @var 'system'|'light'|'dark' */
    public readonly string $theme;

    public readonly string $logo;

    /** @var list<WikiPage> */
    public array $wikiPages;

    /** @var list<RouteGroup> */
    public readonly array $routeGroups;

    /** @var SidebarConfig */
    public readonly array $sidebar;

    public readonly bool $tryItEnabled;

    public readonly string $tryItProxyUrl;

    public function __construct(
        public readonly Config $config,
        string $baseUrl,
        public readonly int|string|null $workspaceKey = null,
    ) {
        $baseUrl = rtrim($baseUrl, '/');
        $ui = self::resolveUi($config, $workspaceKey);

        $this->title = $config->data['api']['title'] ?? '';
        $this->openApiUrl = $baseUrl . '/' . self::OPENAPI_PATH;
        $this->assetsBaseUrl = $baseUrl . '/' . rtrim(self::ASSETS_PATH, '/');
        $this->theme = $ui['theme'] ?? 'system';
        $this->logo = $ui['logo'] ?? '';
        $this->wikiPages = self::buildWikiPages($ui['wiki_pages'] ?? [], $baseUrl);
        $this->routeGroups = $ui['route_groups'] ?? [];
        $this->sidebar = $ui['sidebar'] ?? [];
        $this->tryItEnabled = $ui['try_it']['enabled'] ?? true;
        $this->tryItProxyUrl = $ui['try_it']['proxy_url'] ?? '';
    }

    /**
     * @return UiConfig
     */
    private static function resolveUi(Config $config, int|string|null $workspaceKey): array
    {
        $ui = $config->data['ui'] ?? [];

        if ($workspaceKey !== null) {
            $workspace = $config->getWorkspace($workspaceKey);

            if ($workspace !== null) {
                $ui = array_merge($ui, $workspace['ui'] ?? []);
            }
        }

        return $ui;
    }

    /**
     * @param list<WikiPageConfig> $pages
     * @return list<WikiPage>
     */
    private static function buildWikiPages(array $pages, string $baseUrl): array
    {
        $wikiPages = [];

        foreach ($pages as $page) {
            if (isset($page['path'])) {
                $url = $baseUrl . '/' . self::WIKI_PATH . $page['id'];

            } else if (isset($page['url'])) {
                $url = $page['url'];

            } else {
                throw new Exception("Autodoc wiki page '{$page['id']}' must define either `path` or `url`.");
            }

            $wikiPages[] = ['id' => $page['id'], 'title' => $page['title'], 'url' => $url];
        }

        return $wikiPages;
    }

    /**
     * Front controller for the single docs route. Dispatches the wildcard path
     * to the HTML page, the OpenAPI JSON, or a viewer asset:
     *
     *   ''                       -> the docs HTML page
     *   'openapi.json'           -> the workspace OpenAPI JSON
     *   'assets/<file>'          -> a vendored viewer asset (JS/CSS)
     *   'wiki/<id>'              -> a `path`-backed wiki page of the selected workspace
     *   anything else            -> HTTP 404
     */
    public function handle(string $path = ''): DocViewerResponse
    {
        $path = trim($path, '/');

        if ($path === '') {
            return $this->renderPage();
        }

        if ($path === self::OPENAPI_PATH) {
            return $this->outputOpenApiJson();
        }

        if (str_starts_with($path, self::ASSETS_PATH)) {
            return self::serveAsset(substr($path, strlen(self::ASSETS_PATH)));
        }

        if (str_starts_with($path, self::WIKI_PATH)) {
            return $this->serveWikiPage(substr($path, strlen(self::WIKI_PATH)));
        }

        return DocViewerResponse::notFound();
    }

    public function serveWikiPage(string $id): DocViewerResponse
    {
        $ui = self::resolveUi($this->config, $this->workspaceKey);

        foreach ($ui['wiki_pages'] ?? [] as $page) {
            if ($page['id'] !== $id || ! isset($page['path'])) {
                continue;
            }

            $markdown = is_file($page['path']) ? file_get_contents($page['path']) : false;

            if ($markdown === false) {
                return DocViewerResponse::notFound();
            }

            return DocViewerResponse::make($markdown, ['Content-Type' => 'text/markdown; charset=utf-8']);
        }

        return DocViewerResponse::notFound();
    }

    public function renderPage(): DocViewerResponse
    {
        ob_start();

        try {
            include dirname(__DIR__) . '/resources/views/docs.php';

        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return DocViewerResponse::make((string) ob_get_clean(), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function outputOpenApiJson(): DocViewerResponse
    {
        $workspace = $this->workspaceKey === null
            ? Workspace::getDefault($this->config)
            : Workspace::findUsingKey((string) $this->workspaceKey, $this->config);

        if (! $workspace instanceof Workspace) {
            return DocViewerResponse::notFound();
        }

        return DocViewerResponse::make($workspace->getJson() ?? '', ['Content-Type' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewerConfig(): array
    {
        $config = [
            'openapi_json_url' => $this->openApiUrl,
            'title' => $this->title,
            'theme' => $this->theme,
        ];

        if ($this->logo !== '') {
            $config['logo'] = $this->logo;
        }

        if ($this->wikiPages !== []) {
            $config['wiki_pages'] = $this->wikiPages;
        }

        if ($this->routeGroups !== []) {
            $config['route_groups'] = $this->routeGroups;
        }

        if ($this->sidebar !== []) {
            $config['sidebar'] = $this->sidebar;
        }

        $tryIt = ['enabled' => $this->tryItEnabled];

        if ($this->tryItProxyUrl !== '') {
            $tryIt['proxy_url'] = $this->tryItProxyUrl;
        }

        $config['try_it'] = $tryIt;

        return $config;
    }

    public function getAssetUrl(string $file): string
    {
        $url = rtrim($this->assetsBaseUrl, '/') . '/' . $file;
        $path = self::getAssetPath($file);

        if ($path !== null) {
            $mtime = filemtime($path);

            if ($mtime !== false) {
                $url .= '?id=' . $mtime;
            }
        }

        return $url;
    }

    public static function getAssetPath(string $file): ?string
    {
        $file = basename($file);

        if (! isset(self::ASSETS[$file])) {
            return null;
        }

        $path = dirname(__DIR__) . '/resources/viewer/' . $file;

        return is_file($path) ? $path : null;
    }

    public static function serveAsset(string $file): DocViewerResponse
    {
        $file = basename($file);

        if (! isset(self::ASSETS[$file])) {
            return DocViewerResponse::notFound();
        }

        $path = dirname(__DIR__) . '/resources/viewer/' . $file;

        if (! is_file($path)) {
            return DocViewerResponse::notFound();
        }

        $headers = [
            'Content-Type' => self::ASSETS[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ];

        $mtime = filemtime($path);

        if ($mtime !== false) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        }

        return DocViewerResponse::file($path, $headers);
    }
}
