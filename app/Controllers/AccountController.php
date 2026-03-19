<?php
namespace App\Controllers;

use App\Config;
use App\Models\UserModel;

class AccountController {
    private Config $cfg; private UserModel $users;
    public function __construct(){
        $this->cfg = new Config(__DIR__."/../../.env");
        $this->users = new UserModel($this->cfg);
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    }

    public function showPreferences(){
        if(!isset($_SESSION["user_id"])){ header("Location:/login"); exit; }
        $user = $this->users->findById((int)$_SESSION["user_id"]);
        $themes = ["Yeti","Cerulean","Cosmo","Cyborg","Flatly","Journal","Litera","Lumen","Lux","Materia","Minty","Morph","Pulse","Quartz","Sandstone","Simplex","Sketchy","Slate","Solar","Spacelab","Superhero","United","Vapor","Zephyr"];
        $this->render("account/preferences", ["user"=>$user, "themes"=>$themes, "theme"=>$user["theme"] ?? "Yeti"]);
    }

    public function savePreferences(){
        if(!isset($_SESSION["user_id"])){ header("Location:/login"); exit; }
        $theme = preg_replace("/[^A-Za-z]/","", $_POST["theme"] ?? "Yeti");
        $sql = "INSERT INTO user_settings (user_id, theme)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE theme=VALUES(theme)";
        $this->cfg->db->prepare($sql)->execute([$_SESSION["user_id"], $theme]);
        header("Location: /account/preferences"); exit;
    }

    private function render(string $view, array $data=[]){
        extract($data);
        $view = $view;
        require __DIR__ . "/../Views/layout.php";
    }
}
