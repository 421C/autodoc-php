<?php declare(strict_types=1);

namespace AutoDoc;

final readonly class DocViewerResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public int $status,
        public array $headers,
        public ?string $body,
        public ?string $filePath,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function make(string $body, array $headers = [], int $status = 200): self
    {
        return new self($status, $headers, $body, null);
    }

    /**
     * Stream a file from disk (viewer assets) instead of buffering it.
     *
     * @param array<string, string> $headers
     */
    public static function file(string $path, array $headers = []): self
    {
        return new self(200, $headers, null, $path);
    }

    public static function notFound(): self
    {
        return new self(404, [], null, null);
    }

    /**
     * Send this response using raw PHP output, for consumers without a
     * framework response object of their own. Framework integrations should
     * build a native response from the public properties instead.
     */
    public function emit(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);

        } else if ($this->body !== null) {
            echo $this->body;
        }
    }
}
