<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pages = ['dashboard', 'transactions', 'budgets', 'savings', 'reports', 'categories', 'profile'];
$page = (string)($_GET['page'] ?? 'dashboard');
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}
$authMode = (($_GET['auth'] ?? '') === 'register') ? 'register' : 'login';
$dbError = null;
$pdo = null;
try {
    $pdo = db();
} catch (Throwable $exception) {
    $dbError = 'The database is not connected yet. Import database.sql in phpMyAdmin, then check the settings in config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        verify_csrf();

        if ($action === 'login' || $action === 'register') {
            if (!$pdo) {
                throw new RuntimeException($dbError ?? 'Database connection is unavailable.');
            }
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }
            if ($action === 'register') {
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new RuntimeException('Enter your name (up to 100 characters).');
                }
                if (strlen($password) < 8) {
                    throw new RuntimeException('Choose a password with at least 8 characters.');
                }
                $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $check->execute([$email]);
                if ($check->fetchColumn()) {
                    throw new RuntimeException('An account with that email already exists.');
                }
                $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'created_at' => date('Y-m-d H:i:s')];
                set_flash('Your account is ready. Let’s set up your finances.');
                go('?page=dashboard');
            }
            $statement = $pdo->prepare('SELECT id, name, email, password_hash, created_at FROM users WHERE email = ?');
            $statement->execute([$email]);
            $account = $statement->fetch();
            if (!$account || !password_verify($password, $account['password_hash'])) {
                throw new RuntimeException('Email or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$account['id'], 'name' => $account['name'], 'email' => $account['email'], 'created_at' => $account['created_at']];
            set_flash('Welcome back, ' . $account['name'] . '.');
            go('?page=dashboard');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            set_flash('You have been signed out.');
            go('?');
        }

        $user = current_user();
        if (!$pdo || !$user) {
            throw new RuntimeException('Please sign in to continue.');
        }
        $userId = (int)$user['id'];
        $returnPage = (string)($_POST['return_page'] ?? 'dashboard');
        if (!in_array($returnPage, $pages, true)) {
            $returnPage = 'dashboard';
        }

        if ($action === 'save_transaction') {
            $kind = (string)($_POST['kind'] ?? '');
            if (!in_array($kind, ['income', 'expense'], true)) {
                throw new RuntimeException('Choose income or expense.');
            }
            $amount = valid_amount($_POST['amount'] ?? null);
            if ($amount === null) {
                throw new RuntimeException('Enter an amount greater than zero.');
            }
            $categoryId = (int)($_POST['category_id'] ?? 0);
            if (!category_is_available($pdo, $categoryId, $kind, $userId)) {
                throw new RuntimeException('Choose a valid category.');
            }
            $date = (string)($_POST['occurred_on'] ?? '');
            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                throw new RuntimeException('Choose a valid transaction date.');
            }
            $description = trim((string)($_POST['description'] ?? ''));
            $description = mb_substr($description, 0, 255);
            $table = $kind === 'income' ? 'incomes' : 'expenses';
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $statement = $pdo->prepare("UPDATE {$table} SET category_id = ?, amount = ?, description = ?, occurred_on = ? WHERE id = ? AND user_id = ?");
                $statement->execute([$categoryId, $amount, $description, $date, $id, $userId]);
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND user_id = ?");
                    $exists->execute([$id, $userId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('That transaction could not be found.');
                    }
                }
                set_flash(ucfirst($kind) . ' updated.');
            } else {
                $statement = $pdo->prepare("INSERT INTO {$table} (user_id, category_id, amount, description, occurred_on) VALUES (?, ?, ?, ?, ?)");
                $statement->execute([$userId, $categoryId, $amount, $description, $date]);
                set_flash(ucfirst($kind) . ' added.');
            }
            go(page_url($returnPage, ['month' => $_POST['filter_month'] ?? date('Y-m')]));
        }

        if ($action === 'delete_transaction') {
            $kind = (string)($_POST['kind'] ?? '');
            if (!in_array($kind, ['income', 'expense'], true)) {
                throw new RuntimeException('Choose a valid transaction type.');
            }
            $table = $kind === 'income' ? 'incomes' : 'expenses';
            $statement = $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND user_id = ?");
            $statement->execute([(int)($_POST['id'] ?? 0), $userId]);
            set_flash($statement->rowCount() ? ucfirst($kind) . ' deleted.' : 'Transaction not found.', $statement->rowCount() ? 'success' : 'error');
            go(page_url($returnPage, ['month' => $_POST['filter_month'] ?? date('Y-m')]));
        }

        if ($action === 'save_budget') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $amount = valid_amount($_POST['amount'] ?? null);
            $month = (string)($_POST['month'] ?? '');
            if (!category_is_available($pdo, $categoryId, 'expense', $userId)) {
                throw new RuntimeException('Choose a valid expense category.');
            }
            if ($amount === null || !valid_month($month)) {
                throw new RuntimeException('Enter a valid monthly amount and month.');
            }
            $statement = $pdo->prepare('INSERT INTO budgets (user_id, category_id, month_start, amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
            $statement->execute([$userId, $categoryId, $month . '-01', $amount]);
            set_flash('Monthly budget saved.');
            go(page_url('budgets', ['month' => $month]));
        }

        if ($action === 'delete_budget') {
            $statement = $pdo->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?');
            $statement->execute([(int)($_POST['id'] ?? 0), $userId]);
            set_flash('Budget removed.');
            go(page_url('budgets', ['month' => $_POST['month'] ?? date('Y-m')]));
        }

        if ($action === 'save_goal') {
            $title = trim((string)($_POST['title'] ?? ''));
            $target = valid_amount($_POST['target_amount'] ?? null);
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            if ($title === '' || mb_strlen($title) > 120 || $target === null) {
                throw new RuntimeException('Enter a goal name and a target amount greater than zero.');
            }
            if ($targetDate !== '') {
                $dateObject = DateTime::createFromFormat('!Y-m-d', $targetDate);
                if (!$dateObject || $dateObject->format('Y-m-d') !== $targetDate) {
                    throw new RuntimeException('Choose a valid target date.');
                }
            } else {
                $targetDate = null;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $statement = $pdo->prepare('UPDATE savings SET title = ?, target_amount = ?, target_date = ? WHERE id = ? AND user_id = ?');
                $statement->execute([$title, $target, $targetDate, $id, $userId]);
                if (!$statement->rowCount()) {
                    $exists = $pdo->prepare('SELECT id FROM savings WHERE id = ? AND user_id = ?');
                    $exists->execute([$id, $userId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('That savings goal could not be found.');
                    }
                }
                set_flash('Savings goal updated.');
            } else {
                $statement = $pdo->prepare('INSERT INTO savings (user_id, title, target_amount, target_date) VALUES (?, ?, ?, ?)');
                $statement->execute([$userId, $title, $target, $targetDate]);
                set_flash('Savings goal created.');
            }
            go('?page=savings');
        }

        if ($action === 'add_savings') {
            $amount = valid_amount($_POST['amount'] ?? null);
            if ($amount === null) {
                throw new RuntimeException('Enter an amount greater than zero.');
            }
            $statement = $pdo->prepare('UPDATE savings SET current_amount = LEAST(target_amount, current_amount + ?) WHERE id = ? AND user_id = ?');
            $statement->execute([$amount, (int)($_POST['id'] ?? 0), $userId]);
            if (!$statement->rowCount()) {
                throw new RuntimeException('Savings goal not found or already at target.');
            }
            set_flash('Savings progress updated.');
            go('?page=savings');
        }

        if ($action === 'delete_goal') {
            $statement = $pdo->prepare('DELETE FROM savings WHERE id = ? AND user_id = ?');
            $statement->execute([(int)($_POST['id'] ?? 0), $userId]);
            set_flash('Savings goal removed.');
            go('?page=savings');
        }

        if ($action === 'add_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            $kind = (string)($_POST['kind'] ?? '');
            $color = (string)($_POST['color'] ?? '#6c63ff');
            if ($name === '' || mb_strlen($name) > 60 || !in_array($kind, ['income', 'expense'], true) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new RuntimeException('Enter a category name, type, and valid color.');
            }
            $statement = $pdo->prepare('INSERT INTO categories (user_id, name, type, color) VALUES (?, ?, ?, ?)');
            $statement->execute([$userId, $name, $kind, $color]);
            set_flash('Category added.');
            go('?page=categories');
        }

        if ($action === 'delete_category') {
            $categoryId = (int)($_POST['id'] ?? 0);
            $check = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ?');
            $check->execute([$categoryId, $userId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Only your custom categories can be removed.');
            }
            $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM incomes WHERE category_id = ?) + (SELECT COUNT(*) FROM expenses WHERE category_id = ?) + (SELECT COUNT(*) FROM budgets WHERE category_id = ?)');
            $used->execute([$categoryId, $categoryId, $categoryId]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('This category is in use. Remove its transactions and budgets first.');
            }
            $statement = $pdo->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
            $statement->execute([$categoryId, $userId]);
            set_flash('Category removed.');
            go('?page=categories');
        }

        if ($action === 'update_profile') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid name and email address.');
            }
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $check->execute([$email, $userId]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('That email address is already connected to another account.');
            }
            $statement = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
            $statement->execute([$name, $email, $userId]);
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            set_flash('Profile updated.');
            go('?page=profile');
        }

        throw new RuntimeException('That action is not available.');
    } catch (Throwable $exception) {
        set_flash($exception instanceof PDOException ? 'That change could not be saved. Check for duplicate names or linked records.' : $exception->getMessage(), 'error');
        $fallback = current_user() ? page_url($page) : '?auth=' . $authMode;
        go($fallback);
    }
}

$user = current_user();
$flash = take_flash();
if (!$user) {
    $authMode = (($_GET['auth'] ?? '') === 'register') ? 'register' : 'login';
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $authMode === 'register' ? 'Create account' : 'Sign in' ?> · Pennywise</title><link rel="stylesheet" href="assets/style.css"></head>
    <body class="auth-body"><main class="auth-shell">
      <section class="auth-intro"><a class="brand brand-light" href="index.php"><span class="brand-mark">p</span><span>Pennywise</span></a><div class="auth-copy"><div class="eyebrow">YOUR MONEY, IN FOCUS</div><h1>A calmer way to manage your money.</h1><p>Keep your everyday finances in one clear place. Track what comes in, plan what goes out, and make progress toward what matters.</p><div class="auth-stat-row"><div><strong>01</strong><span>Know your numbers</span></div><div><strong>02</strong><span>Plan with confidence</span></div></div></div><div class="auth-ornament" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div></section>
      <section class="auth-form-wrap"><div class="auth-form-card"><div class="mobile-brand"><a class="brand" href="index.php"><span class="brand-mark">p</span><span>Pennywise</span></a></div><div class="eyebrow">PERSONAL FINANCE MANAGER</div><h2><?= $authMode === 'register' ? 'Create your account' : 'Welcome back' ?></h2><p class="muted"><?= $authMode === 'register' ? 'Start building a clearer picture of your finances.' : 'Sign in to pick up where you left off.' ?></p>
      <?php if ($dbError): ?><div class="alert alert-error"><?= e($dbError) ?></div><?php endif; ?>
      <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
      <?php if ($dbError): ?><div class="setup-note"><strong>First time here?</strong><br>Start Apache and MySQL in XAMPP, then import the included <code>database.sql</code> file using phpMyAdmin.</div><?php endif; ?>
      <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $authMode === 'register' ? 'register' : 'login' ?>">
        <?php if ($authMode === 'register'): ?><label>Your name<input name="name" autocomplete="name" maxlength="100" required placeholder="e.g. Ananya Sharma"></label><?php endif; ?>
        <label>Email address<input type="email" name="email" autocomplete="email" required placeholder="you@example.com"></label>
        <label>Password<input type="password" name="password" autocomplete="<?= $authMode === 'register' ? 'new-password' : 'current-password' ?>" minlength="<?= $authMode === 'register' ? '8' : '1' ?>" required placeholder="<?= $authMode === 'register' ? 'At least 8 characters' : 'Your password' ?>"></label>
        <button class="button button-primary button-wide" type="submit"><?= $authMode === 'register' ? 'Create account' : 'Sign in' ?><span aria-hidden="true">→</span></button>
      </form><p class="auth-switch"><?= $authMode === 'register' ? 'Already have an account?' : 'New to Pennywise?' ?> <a href="?auth=<?= $authMode === 'register' ? 'login' : 'register' ?>"><?= $authMode === 'register' ? 'Sign in' : 'Create an account' ?></a></p><p class="auth-footnote">Your financial data is private to your account.</p></div></section>
    </main></body></html><?php
    exit;
}

if (!$pdo) {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Database setup · Pennywise</title><link rel="stylesheet" href="assets/style.css"></head><body><main class="setup-page"><div class="brand"><span class="brand-mark">p</span><span>Pennywise</span></div><div class="panel setup-panel"><div class="eyebrow">ONE-TIME SETUP</div><h1>Connect your database</h1><p><?= e($dbError) ?></p><ol><li>Open XAMPP and start Apache and MySQL.</li><li>Visit <code>http://localhost/phpmyadmin</code>.</li><li>Import the <code>database.sql</code> file from this project.</li><li>Refresh this page.</li></ol><p class="muted">If you use a MySQL password, update <code>DB_PASS</code> in <code>config.php</code>.</p><form method="post"><button class="button button-primary" name="action" value="logout">Sign out</button><?= csrf_field() ?></form></div></main></body></html><?php
    exit;
}

$userId = (int)$user['id'];
$today = date('Y-m-d');
$currentMonth = date('Y-m');
$selectedMonth = (string)($_GET['month'] ?? $currentMonth);
if (!valid_month($selectedMonth)) {
    $selectedMonth = $currentMonth;
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));
$incomeCategories = category_options($pdo, 'income');
$expenseCategories = category_options($pdo, 'expense');
$contentTitle = [
    'dashboard' => ['Overview', 'Here’s how your money is moving this month.'],
    'transactions' => ['Transactions', 'Every income and expense, all in one place.'],
    'budgets' => ['Budgets', 'Give every category a thoughtful monthly limit.'],
    'savings' => ['Savings goals', 'Small steps add up to meaningful progress.'],
    'reports' => ['Reports & insights', 'A clearer view of your financial patterns.'],
    'categories' => ['Categories', 'Organize your income and spending your way.'],
    'profile' => ['Your profile', 'Manage the details for your account.'],
][$page];

$incomeTotal = 0.0;
$expenseTotal = 0.0;
$netTotal = 0.0;
$recentTransactions = [];
$categoryBreakdown = [];
$monthlyBudgets = [];
$trendRows = [];
$transactionRows = [];
$editTransaction = null;
$budgetRows = [];
$goalRows = [];
$editGoal = null;
$reportMonths = [];
$reportCategories = [];
$customCategories = [];

if ($page === 'dashboard') {
    $statement = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ?');
    $statement->execute([$userId, date('Y-m-01'), date('Y-m-01', strtotime('+1 month'))]);
    $incomeTotal = (float)$statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ?');
    $statement->execute([$userId, date('Y-m-01'), date('Y-m-01', strtotime('+1 month'))]);
    $expenseTotal = (float)$statement->fetchColumn();
    $netTotal = $incomeTotal - $expenseTotal;
    $statement = $pdo->prepare("SELECT 'income' AS kind, i.id, i.description, i.amount, i.occurred_on, c.name AS category, c.color FROM incomes i JOIN categories c ON c.id = i.category_id WHERE i.user_id = ? UNION ALL SELECT 'expense' AS kind, x.id, x.description, x.amount, x.occurred_on, c.name AS category, c.color FROM expenses x JOIN categories c ON c.id = x.category_id WHERE x.user_id = ? ORDER BY occurred_on DESC, id DESC LIMIT 6");
    $statement->execute([$userId, $userId]);
    $recentTransactions = $statement->fetchAll();
    $statement = $pdo->prepare('SELECT c.name, c.color, SUM(e.amount) AS total FROM expenses e JOIN categories c ON c.id = e.category_id WHERE e.user_id = ? AND e.occurred_on >= ? AND e.occurred_on < ? GROUP BY c.id, c.name, c.color ORDER BY total DESC');
    $statement->execute([$userId, date('Y-m-01'), date('Y-m-01', strtotime('+1 month'))]);
    $categoryBreakdown = $statement->fetchAll();
    $statement = $pdo->prepare('SELECT b.id, b.amount, c.name, c.color, COALESCE(SUM(e.amount),0) AS spent FROM budgets b JOIN categories c ON c.id = b.category_id LEFT JOIN expenses e ON e.user_id = b.user_id AND e.category_id = b.category_id AND e.occurred_on >= b.month_start AND e.occurred_on < DATE_ADD(b.month_start, INTERVAL 1 MONTH) WHERE b.user_id = ? AND b.month_start = ? GROUP BY b.id, b.amount, c.name, c.color ORDER BY c.name LIMIT 4');
    $statement->execute([$userId, date('Y-m-01')]);
    $monthlyBudgets = $statement->fetchAll();
    $statement = $pdo->prepare("SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS month_key, SUM(amount) AS total FROM incomes WHERE user_id = ? AND occurred_on >= ? GROUP BY month_key");
    $statement->execute([$userId, date('Y-m-01', strtotime('-5 months'))]);
    $incomeMap = array_column($statement->fetchAll(), 'total', 'month_key');
    $statement = $pdo->prepare("SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS month_key, SUM(amount) AS total FROM expenses WHERE user_id = ? AND occurred_on >= ? GROUP BY month_key");
    $statement->execute([$userId, date('Y-m-01', strtotime('-5 months'))]);
    $expenseMap = array_column($statement->fetchAll(), 'total', 'month_key');
    for ($offset = 5; $offset >= 0; $offset--) {
        $key = date('Y-m', strtotime("first day of -{$offset} months"));
        $trendRows[] = ['label' => date('M', strtotime($key . '-01')), 'income' => (float)($incomeMap[$key] ?? 0), 'expense' => (float)($expenseMap[$key] ?? 0)];
    }
}

if ($page === 'transactions') {
    $filterType = (string)($_GET['type'] ?? 'all');
    if (!in_array($filterType, ['all', 'income', 'expense'], true)) $filterType = 'all';
    $search = trim((string)($_GET['q'] ?? ''));
    $sources = $filterType === 'income' ? ['income'] : ($filterType === 'expense' ? ['expense'] : ['income', 'expense']);
    foreach ($sources as $source) {
        $table = $source === 'income' ? 'incomes' : 'expenses';
        $sql = "SELECT ? AS kind, t.id, t.amount, t.description, t.occurred_on, c.name AS category, c.color FROM {$table} t JOIN categories c ON c.id = t.category_id WHERE t.user_id = ? AND t.occurred_on >= ? AND t.occurred_on < ?";
        $params = [$source, $userId, $monthStart, $monthEnd];
        if ($search !== '') {
            $sql .= ' AND (t.description LIKE ? OR c.name LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY t.occurred_on DESC, t.id DESC LIMIT 150';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $transactionRows = array_merge($transactionRows, $statement->fetchAll());
    }
    usort($transactionRows, fn($a, $b) => strcmp($b['occurred_on'], $a['occurred_on']) ?: ((int)$b['id'] <=> (int)$a['id']));
    if (isset($_GET['edit_id'], $_GET['edit_type'])) {
        $editType = (string)$_GET['edit_type'];
        if (in_array($editType, ['income', 'expense'], true)) {
            $table = $editType === 'income' ? 'incomes' : 'expenses';
            $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND user_id = ?");
            $statement->execute([(int)$_GET['edit_id'], $userId]);
            $editTransaction = $statement->fetch() ?: null;
            if ($editTransaction) $editTransaction['kind'] = $editType;
        }
    }
}

if ($page === 'budgets') {
    $statement = $pdo->prepare('SELECT b.id, b.category_id, b.amount, c.name, c.color, COALESCE(SUM(e.amount),0) AS spent FROM budgets b JOIN categories c ON c.id = b.category_id LEFT JOIN expenses e ON e.user_id = b.user_id AND e.category_id = b.category_id AND e.occurred_on >= b.month_start AND e.occurred_on < DATE_ADD(b.month_start, INTERVAL 1 MONTH) WHERE b.user_id = ? AND b.month_start = ? GROUP BY b.id, b.category_id, b.amount, c.name, c.color ORDER BY c.name');
    $statement->execute([$userId, $monthStart]);
    $budgetRows = $statement->fetchAll();
}

if ($page === 'savings') {
    $statement = $pdo->prepare('SELECT * FROM savings WHERE user_id = ? ORDER BY created_at DESC');
    $statement->execute([$userId]);
    $goalRows = $statement->fetchAll();
    if (isset($_GET['edit_id'])) {
        $statement = $pdo->prepare('SELECT * FROM savings WHERE id = ? AND user_id = ?');
        $statement->execute([(int)$_GET['edit_id'], $userId]);
        $editGoal = $statement->fetch() ?: null;
    }
}

if ($page === 'reports') {
    $selectedYear = (string)($_GET['year'] ?? date('Y'));
    if (!preg_match('/^\d{4}$/', $selectedYear) || (int)$selectedYear < 2000 || (int)$selectedYear > 2100) $selectedYear = date('Y');
    $statement = $pdo->prepare("SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS month_key, SUM(amount) AS total FROM incomes WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ? GROUP BY month_key");
    $statement->execute([$userId, $selectedYear . '-01-01', ((int)$selectedYear + 1) . '-01-01']);
    $incomeMap = array_column($statement->fetchAll(), 'total', 'month_key');
    $statement = $pdo->prepare("SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS month_key, SUM(amount) AS total FROM expenses WHERE user_id = ? AND occurred_on >= ? AND occurred_on < ? GROUP BY month_key");
    $statement->execute([$userId, $selectedYear . '-01-01', ((int)$selectedYear + 1) . '-01-01']);
    $expenseMap = array_column($statement->fetchAll(), 'total', 'month_key');
    for ($m = 1; $m <= 12; $m++) {
        $key = $selectedYear . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        $reportMonths[] = ['key' => $key, 'label' => date('M', strtotime($key . '-01')), 'income' => (float)($incomeMap[$key] ?? 0), 'expense' => (float)($expenseMap[$key] ?? 0)];
    }
    $statement = $pdo->prepare('SELECT c.name, c.color, SUM(e.amount) AS total FROM expenses e JOIN categories c ON c.id = e.category_id WHERE e.user_id = ? AND e.occurred_on >= ? AND e.occurred_on < ? GROUP BY c.id, c.name, c.color ORDER BY total DESC');
    $statement->execute([$userId, $selectedYear . '-01-01', ((int)$selectedYear + 1) . '-01-01']);
    $reportCategories = $statement->fetchAll();
}

if ($page === 'categories') {
    $statement = $pdo->prepare('SELECT * FROM categories WHERE user_id IS NULL OR user_id = ? ORDER BY type, user_id IS NOT NULL, name');
    $statement->execute([$userId]);
    $customCategories = $statement->fetchAll();
}

$nav = [
    'dashboard' => ['Overview', '◫'],
    'transactions' => ['Transactions', '↕'],
    'budgets' => ['Budgets', '▤'],
    'savings' => ['Savings goals', '◇'],
    'reports' => ['Reports', '▥'],
    'categories' => ['Categories', '◉'],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f6f8"><title><?= e($contentTitle[0]) ?> · Pennywise</title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body class="app-body"><div class="app-shell">
  <aside class="sidebar" id="sidebar"><a class="brand" href="?page=dashboard"><span class="brand-mark">p</span><span>Pennywise</span></a><div class="workspace-label">WORKSPACE</div><nav class="side-nav" aria-label="Main navigation">
    <?php foreach ($nav as $key => [$label, $icon]): ?><a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="?page=<?= e($key) ?>"><span class="nav-icon"><?= e($icon) ?></span><span><?= e($label) ?></span><?php if ($key === 'transactions'): ?><span class="nav-shortcut">↗</span><?php endif; ?></a><?php endforeach; ?>
  </nav><div class="sidebar-bottom"><div class="sidebar-tip"><span class="tip-icon">✦</span><strong>Little by little</strong><p>Good money habits start with knowing where it goes.</p><a href="?page=reports">View your insights <span>→</span></a></div><a class="nav-link <?= $page === 'profile' ? 'active' : '' ?>" href="?page=profile"><span class="nav-icon">⚙</span><span>Settings</span></a><div class="sidebar-user"><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span><div><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small></div><form method="post" class="logout-form"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button type="submit" aria-label="Sign out" title="Sign out">↗</button></form></div></div>
  </aside>
  <div class="main-column"><header class="topbar"><button class="mobile-menu" type="button" aria-label="Toggle navigation" data-menu-toggle>☰</button><div class="breadcrumb"><span>Workspace</span><span class="crumb-separator">/</span><strong><?= e($contentTitle[0]) ?></strong></div><div class="topbar-actions"><span class="today-label"><?= e(date('D, j M Y')) ?></span><a class="avatar top-avatar" href="?page=profile" title="Profile"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></a></div></header>
  <main class="main-content"><div class="page-heading"><div><div class="eyebrow">PERSONAL FINANCE</div><h1><?= e($contentTitle[0]) ?></h1><p><?= e($contentTitle[1]) ?></p></div><?php if ($page === 'dashboard'): ?><a class="button button-primary" href="?page=transactions"><span>＋</span> Add transaction</a><?php endif; ?></div>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" data-alert><?= e($flash['message']) ?><button type="button" aria-label="Dismiss" data-dismiss>×</button></div><?php endif; ?>

  <?php if ($page === 'dashboard'): ?>
    <section class="metric-grid">
      <article class="metric-card"><div class="metric-top"><span class="metric-label">Income this month</span><span class="metric-icon income-icon">↙</span></div><strong class="metric-value"><?= money($incomeTotal) ?></strong><span class="metric-caption">Money received in <?= e(date('F')) ?></span></article>
      <article class="metric-card"><div class="metric-top"><span class="metric-label">Spent this month</span><span class="metric-icon expense-icon">↗</span></div><strong class="metric-value"><?= money($expenseTotal) ?></strong><span class="metric-caption">Expenses recorded so far</span></article>
      <article class="metric-card metric-highlight"><div class="metric-top"><span class="metric-label">Available balance</span><span class="metric-icon balance-icon">◌</span></div><strong class="metric-value"><?= money($netTotal) ?></strong><span class="metric-caption">Income minus expenses this month</span></article>
    </section>
    <section class="dashboard-grid">
      <article class="panel trend-panel"><div class="panel-heading"><div><h2>Cash flow</h2><p>Income and expenses over the last 6 months</p></div><a class="text-link" href="?page=reports">Full report <span>→</span></a></div><div class="bar-chart" aria-label="Income and expense by month">
        <?php $trendMax = max(1, ...array_map(fn($r) => max($r['income'], $r['expense']), $trendRows)); foreach ($trendRows as $row): ?><div class="bar-group"><div class="bar-pair"><span class="bar bar-income" style="height:<?= max(3, (int)round($row['income'] / $trendMax * 100)) ?>%" title="Income <?= money($row['income']) ?>"></span><span class="bar bar-expense" style="height:<?= max(3, (int)round($row['expense'] / $trendMax * 100)) ?>%" title="Expenses <?= money($row['expense']) ?>"></span></div><span class="bar-label"><?= e($row['label']) ?></span></div><?php endforeach; ?>
      </div><div class="chart-legend"><span><i class="legend-dot income-dot"></i>Income</span><span><i class="legend-dot expense-dot"></i>Expenses</span></div></article>
      <article class="panel spending-panel"><div class="panel-heading"><div><h2>Spending breakdown</h2><p>Where your money went this month</p></div><a class="icon-link" href="?page=transactions" aria-label="View transactions">↗</a></div>
        <?php if ($categoryBreakdown): $sumBreakdown = array_sum(array_map(fn($c) => (float)$c['total'], $categoryBreakdown)); $angle = 0; $segments = []; foreach ($categoryBreakdown as $category) { $nextAngle = $angle + ((float)$category['total'] / max(1, $sumBreakdown) * 360); $segments[] = $category['color'] . ' ' . round($angle, 1) . 'deg ' . round($nextAngle, 1) . 'deg'; $angle = $nextAngle; } ?>
          <div class="donut-wrap"><div class="donut-chart" style="background:conic-gradient(<?= e(implode(', ', $segments)) ?>)"><div class="donut-center"><strong><?= money($expenseTotal) ?></strong><span>Total spent</span></div></div></div><div class="category-legend"><?php foreach (array_slice($categoryBreakdown, 0, 5) as $category): ?><div><span class="category-name"><i class="legend-dot" style="background:<?= e($category['color']) ?>"></i><?= e($category['name']) ?></span><strong><?= money($category['total']) ?></strong></div><?php endforeach; ?></div>
        <?php else: ?><div class="empty-state compact"><div class="empty-icon">◉</div><strong>No spending to chart yet</strong><span>Add expenses to see your breakdown.</span></div><?php endif; ?>
      </article>
    </section>
    <section class="dashboard-grid lower-grid"><article class="panel"><div class="panel-heading"><div><h2>Budget check-in</h2><p>Your category limits for <?= e(date('F')) ?></p></div><a class="text-link" href="?page=budgets">Manage budgets <span>→</span></a></div>
      <?php if ($monthlyBudgets): ?><div class="budget-list"><?php foreach ($monthlyBudgets as $budget): $percent = min(100, (int)round((float)$budget['spent'] / max(1, (float)$budget['amount']) * 100)); $over = (float)$budget['spent'] > (float)$budget['amount']; ?><div class="budget-row"><div class="budget-title-row"><span><i class="legend-dot" style="background:<?= e($budget['color']) ?>"></i><?= e($budget['name']) ?></span><small class="<?= $over ? 'text-danger' : '' ?>"><?= money($budget['spent']) ?> <span class="muted">of <?= money($budget['amount']) ?></span></small></div><div class="progress-track"><span class="progress-fill <?= $over ? 'over' : '' ?>" style="width:<?= $percent ?>%"></span></div></div><?php endforeach; ?></div><?php else: ?><div class="empty-state inline"><strong>No budgets set for this month</strong><span>Set a limit to keep your spending on track.</span><a class="button button-secondary button-small" href="?page=budgets">Create a budget</a></div><?php endif; ?>
    </article><article class="panel recent-panel"><div class="panel-heading"><div><h2>Recent activity</h2><p>Your latest recorded transactions</p></div><a class="text-link" href="?page=transactions">See all <span>→</span></a></div>
      <?php if ($recentTransactions): ?><div class="recent-list"><?php foreach ($recentTransactions as $transaction): ?><div class="recent-row"><span class="transaction-badge <?= $transaction['kind'] === 'income' ? 'badge-income' : 'badge-expense' ?>"><?= $transaction['kind'] === 'income' ? '↓' : '↑' ?></span><div class="recent-description"><strong><?= e($transaction['description'] ?: $transaction['category']) ?></strong><small><?= e($transaction['category']) ?> · <?= e(date('M j', strtotime($transaction['occurred_on']))) ?></small></div><strong class="recent-amount <?= $transaction['kind'] === 'income' ? 'amount-income' : '' ?>"><?= $transaction['kind'] === 'income' ? '+' : '−' ?><?= money($transaction['amount']) ?></strong></div><?php endforeach; ?></div><?php else: ?><div class="empty-state inline"><strong>Your activity will show up here</strong><span>Add your first income or expense to get started.</span><a class="button button-secondary button-small" href="?page=transactions">Add transaction</a></div><?php endif; ?>
    </article></section>
  <?php elseif ($page === 'transactions'): ?>
    <div class="content-grid transaction-layout"><section class="panel form-panel"><div class="panel-heading"><div><h2><?= $editTransaction ? 'Edit transaction' : 'Add a transaction' ?></h2><p>Keep a simple record of money in and out.</p></div><?php if ($editTransaction): ?><a class="icon-link" href="?page=transactions" aria-label="Close edit form">×</a><?php endif; ?></div>
      <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="save_transaction"><input type="hidden" name="return_page" value="transactions"><input type="hidden" name="id" value="<?= e($editTransaction['id'] ?? '') ?>"><input type="hidden" name="filter_month" value="<?= e($selectedMonth) ?>">
        <label>Transaction type<select name="kind" required data-kind-select><option value="expense" <?= ($editTransaction['kind'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Expense</option><option value="income" <?= ($editTransaction['kind'] ?? '') === 'income' ? 'selected' : '' ?>>Income</option></select></label>
        <label>Category<select name="category_id" required data-category-select><?php foreach ($expenseCategories as $category): ?><option value="<?= e($category['id']) ?>" data-kind="expense" <?= (int)($editTransaction['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?><?php foreach ($incomeCategories as $category): ?><option value="<?= e($category['id']) ?>" data-kind="income" <?= (int)($editTransaction['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
        <label>Amount<input type="number" name="amount" min="0.01" max="999999999" step="0.01" required value="<?= e($editTransaction['amount'] ?? '') ?>" placeholder="0.00"></label>
        <label>Description <span class="optional">Optional</span><input type="text" name="description" maxlength="255" value="<?= e($editTransaction['description'] ?? '') ?>" placeholder="What was this for?"></label>
        <label>Date<input type="date" name="occurred_on" value="<?= e($editTransaction['occurred_on'] ?? $today) ?>" required></label>
        <button class="button button-primary button-wide" type="submit"><?= $editTransaction ? 'Save changes' : 'Add transaction' ?><span>→</span></button>
      </form><div class="form-footnote">Need another category? <a href="?page=categories">Manage categories</a></div>
    </section><section class="panel transaction-table-panel"><div class="panel-heading"><div><h2>Transaction history</h2><p><?= e(month_label($selectedMonth)) ?></p></div><form class="month-picker" method="get"><input type="hidden" name="page" value="transactions"><input type="month" name="month" value="<?= e($selectedMonth) ?>" aria-label="Filter by month"><button class="button button-secondary button-small">Go</button></form></div>
      <form method="get" class="filter-row"><input type="hidden" name="page" value="transactions"><input type="hidden" name="month" value="<?= e($selectedMonth) ?>"><select name="type" aria-label="Filter transaction type"><option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All types</option><option value="income" <?= $filterType === 'income' ? 'selected' : '' ?>>Income</option><option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>Expenses</option></select><div class="search-input"><span>⌕</span><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search transactions"></div><button class="button button-secondary button-small">Filter</button></form>
      <?php if ($transactionRows): ?><div class="table-scroll"><table><thead><tr><th>Transaction</th><th>Category</th><th>Date</th><th class="align-right">Amount</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody><?php foreach ($transactionRows as $transaction): ?><tr><td><div class="table-main"><?= e($transaction['description'] ?: $transaction['category']) ?></div><div class="table-sub <?= $transaction['kind'] === 'income' ? 'amount-income' : 'amount-expense' ?>"><?= ucfirst($transaction['kind']) ?></div></td><td><span class="category-pill"><i class="legend-dot" style="background:<?= e($transaction['color']) ?>"></i><?= e($transaction['category']) ?></span></td><td><?= e(date('M j, Y', strtotime($transaction['occurred_on']))) ?></td><td class="align-right amount-cell <?= $transaction['kind'] === 'income' ? 'amount-income' : '' ?>"><?= $transaction['kind'] === 'income' ? '+' : '−' ?><?= money($transaction['amount']) ?></td><td><div class="row-actions"><a class="icon-link" href="?page=transactions&edit_type=<?= e($transaction['kind']) ?>&edit_id=<?= e($transaction['id']) ?>" aria-label="Edit transaction">✎</a><form method="post" data-confirm="Delete this transaction?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="return_page" value="transactions"><input type="hidden" name="kind" value="<?= e($transaction['kind']) ?>"><input type="hidden" name="id" value="<?= e($transaction['id']) ?>"><input type="hidden" name="filter_month" value="<?= e($selectedMonth) ?>"><button class="icon-link danger-link" aria-label="Delete transaction">×</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state table-empty"><div class="empty-icon">↕</div><strong>No transactions found</strong><span>Try another month or add a transaction to get started.</span></div><?php endif; ?>
    </section></div>
  <?php elseif ($page === 'budgets'): ?>
    <div class="content-grid budget-layout"><section class="panel form-panel"><div class="panel-heading"><div><h2>Set a category budget</h2><p>Choose how much you plan to spend.</p></div></div><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="save_budget"><label>Month<input type="month" name="month" required value="<?= e($selectedMonth) ?>"></label><label>Expense category<select name="category_id" required><?php foreach ($expenseCategories as $category): ?><option value="<?= e($category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label><label>Monthly limit<input type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00"></label><button class="button button-primary button-wide">Save budget <span>→</span></button></form><p class="form-footnote">Saving a limit for the same category and month updates the existing budget.</p></section>
      <section class="panel"><div class="panel-heading"><div><h2>Budget overview</h2><p>Spending compared with your limits.</p></div><form method="get" class="month-picker"><input type="hidden" name="page" value="budgets"><input type="month" name="month" value="<?= e($selectedMonth) ?>" aria-label="Choose budget month"><button class="button button-secondary button-small">Go</button></form></div>
       <?php if ($budgetRows): ?><div class="budget-list budget-list-large"><?php foreach ($budgetRows as $budget): $percentRaw = (float)$budget['spent'] / max(1, (float)$budget['amount']) * 100; $percent = min(100, (int)round($percentRaw)); $over = $percentRaw > 100; ?><div class="budget-card-row"><div class="budget-icon" style="--category-color:<?= e($budget['color']) ?>"><i></i></div><div class="budget-card-main"><div class="budget-title-row"><span><strong><?= e($budget['name']) ?></strong><small><?= money($budget['spent']) ?> spent of <?= money($budget['amount']) ?></small></span><span class="budget-percent <?= $over ? 'text-danger' : '' ?>"><?= (int)round($percentRaw) ?>%</span></div><div class="progress-track"><span class="progress-fill <?= $over ? 'over' : '' ?>" style="width:<?= $percent ?>%"></span></div><div class="budget-card-foot"><span><?= $over ? money((float)$budget['spent'] - (float)$budget['amount']) . ' over budget' : money((float)$budget['amount'] - (float)$budget['spent']) . ' remaining' ?></span><form method="post" data-confirm="Remove this budget?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_budget"><input type="hidden" name="id" value="<?= e($budget['id']) ?>"><input type="hidden" name="month" value="<?= e($selectedMonth) ?>"><button class="text-button danger-link">Remove</button></form></div></div></div><?php endforeach; ?></div><?php else: ?><div class="empty-state"><div class="empty-icon">▤</div><strong>No budgets for <?= e(month_label($selectedMonth)) ?></strong><span>Create a category limit to make a plan for the month.</span></div><?php endif; ?>
      </section></div>
  <?php elseif ($page === 'savings'): ?>
    <section class="savings-summary"><div><span class="metric-label">Saved across goals</span><strong><?= money(array_sum(array_map(fn($g) => (float)$g['current_amount'], $goalRows))) ?></strong></div><div><span class="metric-label">Total goal amount</span><strong><?= money(array_sum(array_map(fn($g) => (float)$g['target_amount'], $goalRows))) ?></strong></div><div><span class="metric-label">Active goals</span><strong><?= count($goalRows) ?></strong></div><div class="summary-decoration">✦</div></section>
    <div class="savings-layout"><section class="goal-grid"><?php if ($goalRows): foreach ($goalRows as $goal): $goalPercent = min(100, (int)round((float)$goal['current_amount'] / max(1, (float)$goal['target_amount']) * 100)); ?><article class="panel goal-card"><div class="goal-card-head"><span class="goal-mark">◇</span><div class="row-actions"><a class="icon-link" href="?page=savings&edit_id=<?= e($goal['id']) ?>" aria-label="Edit goal">✎</a><form method="post" data-confirm="Delete this savings goal?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_goal"><input type="hidden" name="id" value="<?= e($goal['id']) ?>"><button class="icon-link danger-link" aria-label="Delete savings goal">×</button></form></div></div><h2><?= e($goal['title']) ?></h2><div class="goal-progress-value"><strong><?= money($goal['current_amount']) ?></strong><span>of <?= money($goal['target_amount']) ?></span></div><div class="progress-track goal-track"><span class="progress-fill" style="width:<?= $goalPercent ?>%"></span></div><div class="goal-meta"><span><?= $goalPercent ?>% complete</span><?php if ($goal['target_date']): ?><span>Target <?= e(date('M Y', strtotime($goal['target_date']))) ?></span><?php endif; ?></div><?php if ($goalPercent >= 100): ?><div class="goal-complete">Goal reached — congratulations!</div><?php else: ?><form method="post" class="contribution-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_savings"><input type="hidden" name="id" value="<?= e($goal['id']) ?>"><label class="sr-only" for="contribution-<?= e($goal['id']) ?>">Add to <?= e($goal['title']) ?></label><span>₹</span><input id="contribution-<?= e($goal['id']) ?>" type="number" min="0.01" step="0.01" name="amount" placeholder="Add savings" required><button class="button button-secondary button-small">Add</button></form><?php endif; ?></article><?php endforeach; else: ?><div class="panel empty-state goal-empty"><div class="empty-icon">◇</div><strong>Your first goal starts here</strong><span>Create a savings goal and watch your progress grow.</span></div><?php endif; ?></section>
      <aside class="panel goal-form-panel"><div class="panel-heading"><div><h2><?= $editGoal ? 'Edit your goal' : 'Create a savings goal' ?></h2><p>Give your next milestone a name.</p></div><?php if ($editGoal): ?><a class="icon-link" href="?page=savings" aria-label="Close edit">×</a><?php endif; ?></div><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="save_goal"><input type="hidden" name="id" value="<?= e($editGoal['id'] ?? '') ?>"><label>Goal name<input type="text" name="title" maxlength="120" required value="<?= e($editGoal['title'] ?? '') ?>" placeholder="e.g. Weekend getaway"></label><label>Target amount<input type="number" name="target_amount" min="0.01" step="0.01" required value="<?= e($editGoal['target_amount'] ?? '') ?>" placeholder="0.00"></label><label>Target date <span class="optional">Optional</span><input type="date" name="target_date" value="<?= e($editGoal['target_date'] ?? '') ?>"></label><button class="button button-primary button-wide"><?= $editGoal ? 'Save changes' : 'Create goal' ?><span>→</span></button></form><div class="goal-tip"><span>✦</span><p>Small, regular contributions can help you get closer to your goal.</p></div></aside></div>
  <?php elseif ($page === 'reports'): ?>
    <?php $annualIncome = array_sum(array_map(fn($r) => $r['income'], $reportMonths)); $annualExpense = array_sum(array_map(fn($r) => $r['expense'], $reportMonths)); $reportMax = max(1, ...array_map(fn($r) => max($r['income'], $r['expense']), $reportMonths)); ?>
    <section class="report-summary"><div><span class="metric-label">Total income · <?= e($selectedYear) ?></span><strong class="amount-income"><?= money($annualIncome) ?></strong></div><div><span class="metric-label">Total expenses · <?= e($selectedYear) ?></span><strong><?= money($annualExpense) ?></strong></div><div><span class="metric-label">Net savings · <?= e($selectedYear) ?></span><strong class="<?= $annualIncome - $annualExpense < 0 ? 'text-danger' : 'amount-income' ?>"><?= money($annualIncome - $annualExpense) ?></strong></div><form method="get" class="year-picker"><input type="hidden" name="page" value="reports"><select name="year" aria-label="Choose report year"><?php for ($year = (int)date('Y'); $year >= (int)date('Y') - 6; $year--): ?><option value="<?= $year ?>" <?= (int)$selectedYear === $year ? 'selected' : '' ?>><?= $year ?></option><?php endfor; ?></select><button class="button button-secondary button-small">View year</button></form></section>
    <section class="report-grid"><article class="panel report-chart-panel"><div class="panel-heading"><div><h2>Monthly cash flow</h2><p>Income and expenses for each month in <?= e($selectedYear) ?></p></div></div><div class="report-chart"><?php foreach ($reportMonths as $row): ?><div class="report-bar-group"><div class="report-bars"><span class="bar bar-income" style="height:<?= max(2, (int)round($row['income'] / $reportMax * 100)) ?>%" title="Income <?= money($row['income']) ?>"></span><span class="bar bar-expense" style="height:<?= max(2, (int)round($row['expense'] / $reportMax * 100)) ?>%" title="Expenses <?= money($row['expense']) ?>"></span></div><span class="bar-label"><?= e($row['label']) ?></span></div><?php endforeach; ?></div><div class="chart-legend"><span><i class="legend-dot income-dot"></i>Income</span><span><i class="legend-dot expense-dot"></i>Expenses</span></div></article>
      <article class="panel report-category-panel"><div class="panel-heading"><div><h2>Spending by category</h2><p>Annual expense breakdown</p></div></div><?php if ($reportCategories): ?><div class="category-report-list"><?php $categoryTotal = array_sum(array_map(fn($c) => (float)$c['total'], $reportCategories)); foreach ($reportCategories as $category): $categoryPercent = (float)$category['total'] / max(1, $categoryTotal) * 100; ?><div class="category-report-row"><div class="budget-title-row"><span><i class="legend-dot" style="background:<?= e($category['color']) ?>"></i><?= e($category['name']) ?></span><strong><?= money($category['total']) ?></strong></div><div class="progress-track"><span class="progress-fill" style="width:<?= (int)round($categoryPercent) ?>%;background:<?= e($category['color']) ?>"></span></div></div><?php endforeach; ?><div class="category-total"><span>Total spending</span><strong><?= money($categoryTotal) ?></strong></div></div><?php else: ?><div class="empty-state compact"><strong>No expenses in <?= e($selectedYear) ?></strong><span>Once you record expenses, this report will fill in.</span></div><?php endif; ?></article></section>
    <section class="panel monthly-table-panel"><div class="panel-heading"><div><h2>Monthly summary</h2><p>A month-by-month view of your finances.</p></div></div><div class="table-scroll"><table><thead><tr><th>Month</th><th class="align-right">Income</th><th class="align-right">Expenses</th><th class="align-right">Net savings</th></tr></thead><tbody><?php foreach ($reportMonths as $row): ?><tr><td class="table-main"><?= e(date('F', strtotime($row['key'] . '-01'))) ?></td><td class="align-right amount-income"><?= money($row['income']) ?></td><td class="align-right"><?= money($row['expense']) ?></td><td class="align-right <?= $row['income'] - $row['expense'] < 0 ? 'text-danger' : 'amount-income' ?>"><?= money($row['income'] - $row['expense']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php elseif ($page === 'categories'): ?>
    <div class="content-grid category-layout"><section class="panel form-panel"><div class="panel-heading"><div><h2>Add a category</h2><p>Make a category that fits your life.</p></div></div><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="add_category"><label>Category name<input type="text" name="name" maxlength="60" required placeholder="e.g. Pet care"></label><label>Type<select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select></label><label>Category color<input type="color" name="color" value="#6c63ff" class="color-input"></label><button class="button button-primary button-wide">Add category <span>→</span></button></form></section><section class="panel categories-panel"><div class="panel-heading"><div><h2>Your categories</h2><p>Default and custom categories available to you.</p></div></div><?php foreach (['expense' => 'Expenses', 'income' => 'Income'] as $kind => $kindLabel): ?><div class="category-group"><h3><?= e($kindLabel) ?></h3><div class="category-chip-list"><?php foreach ($customCategories as $category): if ($category['type'] !== $kind) continue; ?><div class="category-chip"><i class="legend-dot" style="background:<?= e($category['color']) ?>"></i><span><?= e($category['name']) ?></span><?php if ((int)($category['user_id'] ?? 0) === $userId): ?><form method="post" data-confirm="Remove this category? It must not be in use."><?= csrf_field() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= e($category['id']) ?>"><button aria-label="Delete <?= e($category['name']) ?>">×</button></form><?php else: ?><span class="default-tag">Default</span><?php endif; ?></div><?php endforeach; ?></div></div><?php endforeach; ?></section></div>
  <?php elseif ($page === 'profile'): ?>
    <div class="profile-layout"><section class="panel profile-card"><div class="profile-avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></div><div><h2><?= e($user['name']) ?></h2><p><?= e($user['email']) ?></p><span class="profile-since">Member since <?= e(date('F Y', strtotime((string)($_SESSION['user']['created_at'] ?? date('Y-m-d'))))) ?></span></div></section><section class="panel profile-form-panel"><div class="panel-heading"><div><h2>Account details</h2><p>Update your name or email address.</p></div></div><form method="post" class="form-stack profile-form"><?= csrf_field() ?><input type="hidden" name="action" value="update_profile"><label>Full name<input type="text" name="name" maxlength="100" required value="<?= e($user['name']) ?>"></label><label>Email address<input type="email" name="email" required value="<?= e($user['email']) ?>"></label><button class="button button-primary">Save profile <span>→</span></button></form><div class="privacy-note"><span>◈</span><div><strong>Your finances stay yours</strong><p>Only your account can access its transactions, budgets and savings goals.</p></div></div></section></div>
  <?php endif; ?>
  <footer class="app-footer"><span>Pennywise · Personal Finance Manager</span><span>Built for more confident money habits.</span></footer>
  </main></div></div></div></body></html>
