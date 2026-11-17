<?php
declare(strict_types=1);
require_once __DIR__ . '/app/page.php';
$config=hh_require_auth();$error='';$done=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        hh_check_csrf(hh_post('csrf'));
        $current=hh_post('current');
        if(!hh_verify_attempt(static fn():bool=>strlen($current)<=1024 && password_verify($current,$config['password_hash'])))throw new HttpError(400,'La contraseña actual no es correcta.');
        $config['password_hash']=hh_new_password(hh_post('password'),hh_post('confirm'));
        hh_write_config($config);
        hh_sign_in($config['password_hash']);$done=true;
    }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){$error='No se pudo guardar la contraseña. Revisá los permisos del directorio privado.';http_response_code(503);}
}
$form=($done?'<p class="access-success">Contraseña cambiada. Las otras sesiones deberán entrar de nuevo.</p>':'').'<form method="post"><input type="hidden" name="csrf" value="'.hh_e(hh_csrf()).'"><label>Contraseña actual<input type="password" name="current" autocomplete="current-password" required maxlength="1024"></label><label>Nueva contraseña<input type="password" name="password" autocomplete="new-password" required minlength="16" maxlength="72"></label><label>Repetí la nueva contraseña<input type="password" name="confirm" autocomplete="new-password" required minlength="16" maxlength="72"></label><button class="button primary" type="submit">Guardar contraseña</button></form><a class="access-back" href="./">Volver a los documentos</a>';
hh_page('Cambiar contraseña','Usá al menos 16 caracteres.',$form,$error);
