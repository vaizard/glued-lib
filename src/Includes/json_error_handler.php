<?php
return function ($exception, $inspector) {
    global $settings;
    global $container;
    header("Content-Type: application/json");
    $r['code']    = $exception->getCode();
    $r['message'] = $exception->getMessage();
    $r['title']   = $inspector->getExceptionName() ;
    $r['file']    = $exception->getFile() . ' ' . $exception->getLine();
    $short        = explode('\\', $r['title']);
    $short        = (string) array_pop($short);
    $r['hint']    = "No hints, sorry.";
    $http         = '500 Internal Server Error';

    if ($short == "AuthJwtException")       { $http = '401 Unauthorized'; $r['hint'] = "Login at ".$settings['oidc']['uri']['login']; }
    if ($short == "AuthTokenException")     { $http = '401 Unauthorized'; $r['hint'] = "Login at ".$settings['oidc']['uri']['login']; }
    if ($short == "HttpNotFoundException")  { $http = '404 Not fond'; }
    if ($r['title'] == "mysqli_sql_exception") {
        $container->get('logger')->error("EXCEPTION HANDLER", [ "SQL query" => $container->get('db')->getLastQuery(), "Exception" => $r ]);
        $r['hint'] = "Query logged.";
    }

    header($_SERVER['SERVER_PROTOCOL'].' '.$http);
    echo json_encode($r, JSON_UNESCAPED_SLASHES);
    exit;
};
