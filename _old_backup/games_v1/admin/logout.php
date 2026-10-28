<?php
require_once __DIR__ . '/includes/auth.php';

unset($_SESSION['admin_id'], $_SESSION['admin_redirect']);
flash('You have signed out of the admin panel.', 'info');
redirect('admin/login.php');
