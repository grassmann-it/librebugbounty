<?php // Content-based cache key also picks up CSS fixes within the same release. ?>
<link rel="stylesheet" href="/css/studio.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio.css'), 0, 16) ?>">
<?php $studioShortcuts = isset($request) ? $request->attributes->get('studio_shortcuts', \App\Service\ShortcutSettings::DEFAULTS) : \App\Service\ShortcutSettings::DEFAULTS; ?>
<script type="application/json" id="studio-shortcuts-config"><?= json_encode($studioShortcuts, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<link rel="stylesheet" href="/css/studio-shortcuts.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-shortcuts.css'), 0, 16) ?>">
<script src="/js/studio-shortcuts.js?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/js/studio-shortcuts.js'), 0, 16) ?>" defer></script>
