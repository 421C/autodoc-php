# PHP autodoc

PHP autodoc generates OpenAPI 3.1.0 documentation and TypeScript types directly from your PHP code. It reads native type declarations, performs static analysis, and uses PHPDoc when available. As your code changes, the generated documentation and types stay aligned with it.

For Laravel projects there is [autodoc-laravel](https://github.com/421C/autodoc-laravel), which plugs into routing, request validation, Eloquent models and query builders, API resources, paginated responses and the authenticated user.

This package includes a built-in interactive viewer for exploring the generated OpenAPI documentation.

**[Read the documentation](https://phpautodoc.com) or [explore the demo](https://phpautodoc.com/demo).**

Upgrading from v1? See the [upgrade guide](https://phpautodoc.com/v2-upgrade).


### OpenAPI

Registered routes become OpenAPI operations with parameters, request bodies, responses and schemas. Serve the generated OpenAPI 3.1.0 document through the bundled viewer or export it as JSON:

```bash
vendor/bin/autodoc openapi --config=config/autodoc.php
```

When caching is disabled, the OpenAPI document is regenerated each time the viewer is loaded.


### TypeScript

`autodoc ts` keeps frontend declarations synchronized with PHP classes, enums, PHPStan type aliases, PHPDoc expressions and API routes:

```bash
vendor/bin/autodoc ts --config=config/autodoc.php
```

See the [TypeScript documentation](https://phpautodoc.com/docs/typescript).


## Installation

Requires PHP 8.5 or newer.

For Laravel:

```bash
composer require 421c/autodoc-laravel
```

For other frameworks or standalone use:

```bash
composer require 421c/autodoc-php
```

Continue with the [installation and configuration documentation](https://phpautodoc.com).
