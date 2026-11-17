<?php
declare(strict_types=1);
require_once __DIR__ . '/app/page.php';
if (!is_file(HH_CONFIG)) {header('Location: '.hh_base().'instalar.php');exit;}
try {
    $brand = hh_brand();
} catch (HttpError $e) {
    header('Location: ' . hh_base());
    exit;
}
$brandId = $brand['id'];
$config = hh_config();
$passwordHash = $config['password_hashes'][$brandId];
$error = '';
if (hh_authenticated($config, $brandId)) { header('Location: ' . hh_brand_url($brandId)); exit; }
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        hh_check_csrf(hh_post('csrf'), $brandId);
        $password=hh_post('password');
        if (!hh_verify_attempt(static fn(): bool => strlen($password) <= 72 && password_verify($password, $passwordHash), $brandId)) {
            throw new HttpError(401, 'La contraseña no es correcta.');
        }
        hh_sign_in($brandId, $passwordHash);
        header('Location: ' . hh_brand_url($brandId));
        exit;
    }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){$error='No se pudo entrar. Revisá los permisos del directorio privado.';http_response_code(503);}
}
$form = '<form method="post" action="' . hh_e(hh_brand_url($brandId, 'entrar.php')) . '">'
    . '<input type="hidden" name="csrf" value="' . hh_e(hh_csrf($brandId)) . '">'
    . '<label>Contraseña de acceso<input type="password" name="password" autocomplete="current-password" required maxlength="72" autofocus></label>'
    . '<button class="button primary" type="submit">Entrar</button></form>'
    . '<a class="access-back" href="./">Elegir otra marca</a>';
hh_page('Entrar a ' . $brand['name'], 'Ingresá la contraseña para acceder a los documentos de esta marca.', $form, $error);
