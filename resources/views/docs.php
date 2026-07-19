<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
        <title><?= htmlspecialchars($this->title) ?></title>

        <script>
            window.docViewerConfig = <?= json_encode($this->getViewerConfig(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        </script>
        <link rel="stylesheet" href="<?= htmlspecialchars($this->getAssetUrl('autodoc-viewer.css')) ?>">
        <script src="<?= htmlspecialchars($this->getAssetUrl('autodoc-viewer.js')) ?>" defer></script>
    </head>
    <body style="height: 100vh; overflow-y: hidden;">

        <div id="autodoc-viewer"></div>

    </body>
</html>
