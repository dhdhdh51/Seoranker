<?php
/** @var string $title */
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . brand() : brand() . ' — ' . setting('brand.tagline');
$metaDesc = $description ?? (string)setting('seo.home_description');
$primary = (string)setting('brand.primary_color', '#2f6fed');
$accent = (string)setting('brand.accent_color', '#ff7a45');
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(str_limit($metaDesc, 160)) ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if (!empty($canonical)): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<?php if ($gv = setting('seo.google_verification')): ?><meta name="google-site-verification" content="<?= e($gv) ?>"><?php endif; ?>
<meta property="og:site_name" content="<?= e(brand()) ?>">
<meta property="og:title" content="<?= e($title ?? brand()) ?>">
<meta property="og:description" content="<?= e(str_limit($metaDesc, 200)) ?>">
<meta property="og:type" content="website">
<?php if (!empty($canonical)): ?><meta property="og:url" content="<?= e($canonical) ?>"><?php endif; ?>
<?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="<?= e($primary) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(base_path()) ?>">
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<style>:root{--brand:<?= e($primary) ?>;--accent:<?= e($accent) ?>}</style>
