<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
hh_require_auth();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Location: '.hh_base());exit;}
try{hh_check_csrf(hh_post('csrf'));}
catch(HttpError $e){http_response_code(403);exit('La sesión cambió. Volvé a la aplicación y recargá la página.');}
$_SESSION=[];
setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>hh_base(),'secure'=>hh_is_https(),'httponly'=>true,'samesite'=>'Lax']);
session_destroy();header('Location: '.hh_base().'entrar.php');
