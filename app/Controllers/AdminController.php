<?php
namespace App\Controllers;

use App\Config;
use App\Models\UserModel;

class AdminController {
    private Config $cfg; private UserModel $users;
    public function __construct(){
        $this->cfg = new Config(__DIR__."/../../.env");
        $this->users = new UserModel($this->cfg);
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    }

    public function dashboard(){
        if(!isset($_SESSION["user_id"])){
            if(isset($_SESSION["pending_user_id"]) && isset($_POST["totp_code"])){
                $user = $this->users->findById((int)$_SESSION["pending_user_id"]);
                if(!$user){ header("Location: /login"); exit; }
                $totp = $this->users->getTotp((int)$user["id"]);
                $svc = new \App\Auth\TotpService();
                if($totp && $svc->verify($totp["secret"], trim($_POST["totp_code"]))){
                    $_SESSION["user_id"] = (int)$user["id"];
                    unset($_SESSION["pending_user_id"]);
                } else {
                    header("Location: /login"); exit;
                }
            } else {
                header("Location: /login"); exit;
            }
        }
        $user = $this->users->findById((int)$_SESSION["user_id"]);
        $this->render("admin/dashboard", ["user"=>$user, "theme"=>$user["theme"] ?? "Yeti"]);
    }

    private function render(string $view, array $data=[]){
        extract($data);
        $view = $view;
        require __DIR__ . "/../Views/layout.php";
    }
}
