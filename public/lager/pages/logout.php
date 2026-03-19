<?php
// public/lager/pages/logout.php

declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

unset($_SESSION['lager_user']);
unset($_SESSION['lager_pending_user_id']);

session_regenerate_id(true);

header('Location: /lager/login');
exit;
