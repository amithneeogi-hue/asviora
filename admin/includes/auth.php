<?php
/**
 * Admin session guard. Include at the very top of every admin page
 * except login.php.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function current_admin(): ?array
{
    static $a = null;
    static $loaded = false;
    if ($loaded) {
        return $a;
    }
    $loaded = true;
    if (!admin_logged_in()) {
        return null;
    }
    $a = fetch_one('SELECT * FROM admins WHERE id = ? AND is_active = 1',
                   [(int) $_SESSION['admin_id']]);
    if (!$a) {
        unset($_SESSION['admin_id']);
    }
    return $a;
}

function admin_url(string $path = ''): string
{
    return url('admin/' . ltrim($path, '/'));
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) {
        $_SESSION['admin_redirect'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('admin/login.php');
    }
    return $a;
}

/** Is the current admin allowed to do more than write blog posts? */
function admin_can_manage(): bool
{
    $a = current_admin();
    return $a && in_array($a['role'], ['super', 'manager'], true);
}

function require_manage(): void
{
    if (!admin_can_manage()) {
        flash('Your role does not have access to that section.', 'error');
        redirect('admin/index.php');
    }
}

/** Highlight helper for the sidebar. */
function admin_active(string $file): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? '') === $file ? ' active' : '';
}
