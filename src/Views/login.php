<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Login — Railway Secrets</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.js" integrity="sha384-1MrOtYSDnlvNAr6rHFMYrjwqLm+8lCPz+suIruDTmum9JoBgagrhFzxveKunHj30" crossorigin="anonymous" defer></script>
    <script src="/js/icons.js" defer></script>
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <img src="/favicon.svg" alt="" class="brand-mark" width="18" height="18">
            Railway Secrets
        </div>

        <h1>Welcome back</h1>
        <p>Enter your administrator key to continue.</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger" style="margin-bottom:16px;">
                <i data-lucide="alert-circle" style="width:13px;height:13px;"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login">
            <div class="form-group">
                <label class="form-label">Admin Key</label>
                <input type="password" name="key" class="form-control"
                       placeholder="••••••••" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-xl" style="margin-top:8px;">
                Continue
                <i data-lucide="arrow-right" style="width:14px;height:14px;"></i>
            </button>
        </form>
    </div>
</body>
</html>
