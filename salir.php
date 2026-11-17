<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Location: '.hh_base());exit;}
try {
    $brandId = hh_brand()['id'];
    hh_require_auth($brandId);
    hh_check_csrf(hh_post('csrf'), $brandId);
} catch (HttpError $e) {
    http_response_code($e->status);
    exit($e->getMessage());
}
$_SESSION=[];
setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>hh_base(),'secure'=>hh_is_https(),'httponly'=>true,'samesite'=>'Lax']);
session_destroy();header('Location: '.hh_base());
