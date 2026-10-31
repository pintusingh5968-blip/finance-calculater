<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|int|string $value): string
{
    return '₹' . number_format((float)$value, 2);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        throw new RuntimeException('Your session expired. Please try again.');
    }
}

function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function go(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function valid_amount(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $amount = (float)$value;
    return is_finite($amount) && $amount > 0 && $amount <= 999999999 ? $amount : null;
}

function valid_month(string $value): bool
{
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value);
}

function category_options(PDO $pdo, string $type): array
{
    $statement = $pdo->prepare('SELECT id, name, color FROM categories WHERE type = ? AND (user_id IS NULL OR user_id = ?) ORDER BY user_id IS NOT NULL, name');
    $statement->execute([$type, current_user()['id']]);
    return $statement->fetchAll();
}

function category_is_available(PDO $pdo, int $categoryId, string $type, int $userId): bool
{
    $statement = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND type = ? AND (user_id IS NULL OR user_id = ?)');
    $statement->execute([$categoryId, $type, $userId]);
    return (bool)$statement->fetchColumn();
}

function month_label(string $month): string
{
    $date = DateTime::createFromFormat('!Y-m', $month);
    return $date ? $date->format('F Y') : $month;
}

function page_url(string $page, array $params = []): string
{
    return '?' . http_build_query(['page' => $page] + $params);
}
