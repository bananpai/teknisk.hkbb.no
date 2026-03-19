<?php
namespace App\Controllers;

use App\Config;
use App\Models\UserModel;
use App\Auth\AdLdap;
use App\Auth\TotpService;

class AuthController {
    private Config $cfg; private UserModel $users;

    public function __construct(){
        $this->cfg = new Config(__DIR__ . "/../../.env");
        $this->users = new UserModel($this->cfg);
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    }

    public function showLogin() {
        $theme = $_ENV["DEFAULT_THEME"] ?? "Yeti";
        $this->render("login", [
            "theme"=>$theme,
            "auth_driver"=>$_ENV["AUTH_DRIVER"] ?? "ldap"
        ]);
    }

    public function login() {
        $driver = $_ENV["AUTH_DRIVER"] ?? "ldap";
        if ($driver !== "ldap") { http_response_code(500); echo "Kun LDAP i denne konfigen"; return; }

        $username = trim($_POST["username"] ?? "");
        $password = (string)($_POST["password"] ?? "");
        $ldap = new AdLdap($_ENV);
        $ad = $ldap->validate($username, $password);

        if(!$ad){
            $this->render("login", [
                "error"=>"Feil brukernavn/passord",
                "theme"=>$_ENV["DEFAULT_THEME"] ?? "Yeti",
                "auth_driver"=>"ldap"
            ]);
            return;
        }

        $user = $this->users->findOrCreate($ad);
        $_SESSION["pending_user_id"] = (int)$user["id"];

        $totp = $this->users->getTotp($user["id"]);
        if(!$totp || !(int)$totp["is_enabled"]){
            $svc = new TotpService();
            $pair = $svc->create($_ENV["TOTP_ISSUER"] ?? "Teknisk", $user["username"]);
            $this->users->setTotp($user["id"], $pair["secret"]);
            $this->render("login", ["setup_uri"=>$pair["uri"], "need_totp"=>true, "theme"=>$user["theme"] ?? "Yeti"]);
            return;
        }
        $this->render("login", ["need_totp"=>true, "theme"=>$user["theme"] ?? "Yeti"]);
    }

    public function logout() {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        session_destroy();
        header("Location: /login"); exit;
    }

    private function render(string $view, array $data=[]){
        extract($data);
        $view = $view;
        require __DIR__ . "/../Views/layout.php";
    }
}
