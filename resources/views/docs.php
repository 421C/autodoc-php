<!doctype html>
<html lang="en" data-theme="<?= $this->theme ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= $this->title ?></title>

        <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">
    </head>
    <body style="height: 100vh; overflow-y: hidden;">

        <elements-api
            apiDescriptionUrl="<?= htmlspecialchars($this->openApiUrl) ?>"
            router="hash"
            layout="responsive"
            <?= ($this->hideTryIt ? 'hideTryIt="true"' : '') ?>
            <?= ($this->logo ? 'logo="' . htmlspecialchars($this->logo) . '"' : '') ?>
        />

    </body>
</html>