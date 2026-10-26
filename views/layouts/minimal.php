<!doctype html>
<html lang="en" data-bs-theme="light">
<head><?= \Flatmates\Http\View::partial('partials/head', get_defined_vars() + ['noindex' => true]) ?></head>
<body class="auth-body"><main class="auth-wrap text-center"><?= $content ?></main><script src="<?= e(asset('js/app.js')) ?>"></script></body>
</html>
