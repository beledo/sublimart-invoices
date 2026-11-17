<?php
declare(strict_types=1);
require_once __DIR__ . '/app/page.php';
if (!is_file(HH_CONFIG)) {header('Location: '.hh_base().'instalar.php');exit;}
hh_session(); $config=hh_config(); $error='';
if(hh_authenticated($config)){header('Location: '.hh_base());exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        hh_check_csrf(hh_post('csrf'));
        $password=hh_post('password');
        if(!hh_verify_attempt(static fn():bool=>strlen($password)<=1024 && password_verify($password,$config['password_hash'])))throw new HttpError(401,'La contraseña no es correcta.');
        hh_sign_in($config['password_hash']);
        header('Location: '.hh_base());exit;
    }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){$error='No se pudo entrar. Revisá los permisos del directorio privado.';http_response_code(503);}
}
$form='<form method="post"><input type="hidden" name="csrf" value="'.hh_e(hh_csrf()).'"><label>Contraseña de acceso<input type="password" name="password" autocomplete="current-password" required maxlength="1024" autofocus></label><button class="button primary" type="submit">Entrar</button></form><p class="field-hint">Tus facturas y presupuestos sólo se muestran después de entrar.</p>';
hh_page('Entrar','Entrá y elegí la marca con la que querés trabajar.',$form,$error);
