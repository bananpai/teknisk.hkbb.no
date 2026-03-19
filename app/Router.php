<?php
namespace App;

class Router {
    private array $routes = [];

    public function get(string $path, $handler){ $this->map("GET",$path,$handler); }
    public function post(string $path, $handler){ $this->map("POST",$path,$handler); }

    private function map(string $method, string $path, $handler){
        $this->routes[$method.$path] = ["handler"=>$handler,"middleware"=>[]];
    }

    public function dispatch(){
        $method = $_SERVER["REQUEST_METHOD"];
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        $key = $method.$path;

        $route = $this->routes[$key] ?? null;
        if(!$route){ http_response_code(404); echo "Not found"; return; }

        $handler = $route["handler"];
        if (is_array($handler)) {
            $controller = new $handler[0]();
            $methodName = $handler[1];
            echo $controller->$methodName($_REQUEST);
            return;
        }
        echo call_user_func($handler, $_REQUEST);
    }
}
