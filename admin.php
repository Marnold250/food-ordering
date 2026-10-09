<?php
/**
 * Admin dashboard entry point
 * Redirects to the main dashboard which handles role-based access
 */
require_once __DIR__ . '/backend/core.php';

if (!logged_in()) {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

if (!has_role('admin')) {
    flash('error', 'Admin access required.');
    header('Location: ' . base_url('index.php?page=account'));
    exit;
}

header('Location: ' . base_url('dashboard.php'));
exit;