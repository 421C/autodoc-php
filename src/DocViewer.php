<?php declare(strict_types=1);

namespace AutoDoc;

class DocViewer
{
    public function __construct(
        public string $title,
        public string $openApiUrl,
        public string $theme,
        public string $logo,
        public bool $hideTryIt,
    ) {}

    public function renderPage(): void
    {
        include dirname(__DIR__) . '/resources/views/docs.php';
    }
}
