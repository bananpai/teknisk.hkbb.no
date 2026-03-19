<?php
namespace App\Middleware;

class AuthMiddleware {
    public static function handle() {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        if (!isset($_SESSION["user_id"])) {
            header("Location: /login"); exit;
        }
    }
}
