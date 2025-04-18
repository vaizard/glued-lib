<?php
return function ($exception, $inspector) {
    global $settings;
    global $container;
    header("Content-Type: application/json");
    $r['code']    = $exception->getCode();
    $r['message'] = $exception->getMessage();
    $r['details'] = null;
    $r['title']   = $inspector->getExceptionName();
    $r['file']    = $exception->getFile() . ' ' . $exception->getLine();
    $r['date']    = date('Y-m-d H:i:s');
    $r['trace']   = $exception->getTrace();
    $short        = explode('\\', $r['title']);
    $short        = (string) array_pop($short);
    $http         = '500 Internal Server Error';

    if ($r['code'] == 400) { $http = '400 Bad Request'; }
    if ($r['code'] == 401) { $http = '401 Unauthorized'; }
    if ($r['code'] == 403) { $http = '403 Forbidden'; }
    if ($r['code'] == 404) { $http = '404 Not found'; }
    if ($r['code'] == 410) { $http = '410 Gone'; }

    if ($short == "ExtendedException")      { $r['details'] = $exception->getDetails(); }
    if ($short == "HttpNotFoundException")  { $http = '404 Not Found'; $r['details'] = "Try: " . $container->get('settings')['glued']['protocol'].$container->get('settings')['glued']['hostname'].$container->get('routecollector')->getRouteParser()->UrlFor('be_core_routes'); }
    if ($r['title'] == "mysqli_sql_exception") {
        $container->get('logger')->error("EXCEPTION HANDLER", [ "SQL query" => $container->get('db')->getLastQuery(), "Exception" => $r ]);
        $r['details'] = "Query logged.";
    }

    header($_SERVER['SERVER_PROTOCOL'].' '.$http);
    echo json_encode($r, JSON_UNESCAPED_SLASHES);
    exit;
};
