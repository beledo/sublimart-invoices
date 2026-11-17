<?php
declare(strict_types=1);
require_once __DIR__ . '/app/page.php';
try {
    $brand = hh_brand();
} catch (HttpError $e) {
    header('Location: ' . hh_base());
    exit;
}
$brandId = $brand['id'];
hh_require_auth($brandId);
$error = '';
$done = false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $lock = null;
    try{
        hh_check_csrf(hh_post('csrf'), $brandId);
        $lock = fopen(HH_DATA . '/config-lock.php', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('No se pudieron bloquear los ajustes de acceso.');
        // Read under the lock so simultaneous changes to two brands keep both passwords.
        $config = hh_config();
        if (!hh_authenticated($config, $brandId)) throw new HttpError(401, 'Tu sesión terminó. Volvé a entrar.');
        $current = hh_post('current');
        $currentHash = $config['password_hashes'][$brandId];
        if (!hh_verify_attempt(static fn(): bool => strlen($current) <= 72 && password_verify($current, $currentHash), $brandId)) {
            throw new HttpError(400, 'La contraseña actual no es correcta.');
        }
        $newHash = hh_new_password(hh_post('password'), hh_post('confirm'));
        $config['password_hashes'][$brandId] = $newHash;
        hh_write_config($config);
        hh_sign_in($brandId, $newHash);
        $done = true;
    }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){$error='No se pudo guardar la contraseña. Revisá los permisos del directorio privado.';http_response_code(503);}
    finally {
        if (is_resource($lock)) fclose($lock);
    }
}
$minimum = HH_PASSWORD_MIN_LENGTH;
$form = $done ? '<p class="access-success">Contraseña guardada para esta marca.</p>' : '';
$form .= '<form method="post" action="' . hh_e(hh_brand_url($brandId, 'cuenta.php')) . '">'
    . '<input type="hidden" name="csrf" value="' . hh_e(hh_csrf($brandId)) . '">'
    . '<label>Contraseña actual<input type="password" name="current" autocomplete="current-password" required maxlength="72"></label>'
    . '<label>Nueva contraseña<input type="password" name="password" autocomplete="new-password" required minlength="' . $minimum . '" maxlength="72"></label>'
    . '<label>Repetí la nueva contraseña<input type="password" name="confirm" autocomplete="new-password" required minlength="' . $minimum . '" maxlength="72"></label>'
    . '<button class="button primary" type="submit">Guardar contraseña</button></form>'
    . '<a class="access-back" href="' . hh_e(hh_brand_url($brandId)) . '">Volver a los documentos</a>';
hh_page('Cambiar contraseña de ' . $brand['name'], 'Usá al menos ' . $minimum . ' caracteres.', $form, $error);
