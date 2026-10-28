<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Validator;

Auth::startSession();
if (Auth::user()) {
    header('Location: index.php');
    exit;
}

$allowReg = Env::bool('ALLOW_REGISTRATION', true);
$mode = (($_GET['mode'] ?? '') === 'register' && $allowReg) ? 'register' : 'login';
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Csrf::verify(false);
    $action = (string)($_POST['action'] ?? 'login');
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if ($action === 'register' && $allowReg) {
        $name = trim((string)($_POST['display_name'] ?? ''));
        $res = Auth::register($email, $pass, $name, 'trader');
        if ($res['ok']) {
            Auth::attempt($email, $pass);
            header('Location: index.php');
            exit;
        }
        $error = $res['error'];
        $mode = 'register';
    } else {
        $res = Auth::attempt($email, $pass);
        if ($res['ok']) {
            header('Location: index.php');
            exit;
        }
        $error = $res['error'];
    }
}

$appName = Env::get('APP_NAME', 'GOLD SMC AI PRO');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $mode === 'register' ? 'Create Account' : 'Sign In' ?> — <?= Validator::e($appName) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= Validator::e(APP_VERSION) ?>">
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                <span class="brand-icon">Au</span>
                <div>
                    <div class="brand-title">GOLD SMC AI <span class="brand-pro">PRO</span></div>
                    <div class="brand-sub">XAU/USD Smart Money Concepts Terminal</div>
                </div>
            </div>

            <h2 style="font-size:17px;margin-bottom:14px;">
                <?= $mode === 'register' ? 'Create Trader Account' : 'Sign In to Terminal' ?>
            </h2>

            <?php if ($error): ?>
            <div class="signal-banner sell" style="padding:10px 12px;margin-bottom:14px;font-size:12.5px;">
                <?= Validator::e($error) ?>
            </div>
            <?php endif; ?>

            <form method="post" action="login.php<?= $mode === 'register' ? '?mode=register' : '' ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $mode === 'register' ? 'register' : 'login' ?>">

                <?php if ($mode === 'register'): ?>
                <div class="form-group">
                    <label for="display_name">Display Name</label>
                    <input type="text" id="display_name" name="display_name" required maxlength="100"
                           value="<?= Validator::e($_POST['display_name'] ?? '') ?>" placeholder="Alex Mercer">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required autocomplete="email"
                           value="<?= Validator::e($_POST['email'] ?? '') ?>" placeholder="admin@goldsmc.local">
                </div>

                <div class="form-group">
                    <label for="password">Password <?= $mode === 'register' ? '(min 10 characters)' : '' ?></label>
                    <input type="password" id="password" name="password" required
                           autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"
                           placeholder="••••••••••">
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;">
                    <?= $mode === 'register' ? 'Register & Open Terminal' : 'Sign In' ?>
                </button>
            </form>

            <?php if ($allowReg): ?>
            <div style="margin-top:16px;text-align:center;font-size:12.5px;" class="muted">
                <?php if ($mode === 'register'): ?>
                    Already have an account? <a href="login.php">Sign In</a>
                <?php else: ?>
                    Need a trader account? <a href="login.php?mode=register">Register here</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
