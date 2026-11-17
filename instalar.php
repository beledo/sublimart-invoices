<?php
declare(strict_types=1);
require_once __DIR__.'/app/page.php';
if(is_file(HH_CONFIG)){header('Location: '.hh_base().'entrar.php');exit;}
$keyFile=HH_DATA.'/install-key.php';
if(!is_file($keyFile)){
    http_response_code(503);hh_page('Preparar instalación','Prepará la clave de instalación en tu servidor.','<p>En SSH, desde la carpeta de esta aplicación, ejecutá <code>php app/preparar-instalacion.php</code>. Después recargá esta página.</p>');exit;
}
hh_session();$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $lock=null;
    try{
        hh_check_csrf(hh_post('csrf'));
        if(PHP_VERSION_ID<80300||PHP_INT_SIZE<8||!extension_loaded('pdo_mysql')||!extension_loaded('zip'))throw new HttpError(400,'Activá PHP 8.3 o superior de 64 bits y las extensiones pdo_mysql y zip.');
        $lock=fopen(HH_DATA.'/install-lock.php','c+');
        if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo bloquear la instalación.');
        if(is_file(HH_CONFIG))throw new HttpError(409,'La aplicación ya está instalada.');
        if(!is_file($keyFile))throw new HttpError(400,'La clave de instalación ya no está disponible.');
        $expected=require $keyFile;$key=trim(hh_post('install_key'));
        if(!hh_verify_attempt(static fn():bool=>is_string($expected)&&strlen($key)===48&&hash_equals($expected,hash('sha256',$key))))throw new HttpError(400,'La clave de instalación no coincide.');
        $databases=[];
        foreach(hh_brands() as $id=>$brand){
            $databases[$id]=['db_host'=>trim(hh_post($id.'_host')),'db_name'=>trim(hh_post($id.'_name')),'db_user'=>trim(hh_post($id.'_user')),'db_password'=>hh_post($id.'_password')];
        }
        hh_validate_databases($databases);
        $config=['databases'=>$databases,'password_hash'=>hh_new_password(hh_post('password'),hh_post('confirm'))];
        $connections=[];$resolved=[];
        foreach(hh_brands() as $id=>$brand){
            try{$db=hh_connect($config,$id);}
            catch(Throwable $e){throw new HttpError(400,'No se pudo conectar a la base de '.$brand['name'].'. Comprobá servidor, nombre completo, usuario y contraseña de MySQL.');}
            $identity=$db->query('SELECT @@hostname AS host,@@port AS port,DATABASE() AS name')->fetch();
            $identityKey=strtolower($identity['host']).':'.$identity['port'].'/'.strtolower($identity['name']);
            if(isset($resolved[$identityKey]))throw new HttpError(400,'Dos marcas apuntan a la misma base de datos. Usá tres bases diferentes.');
            $resolved[$identityKey]=true;
            $existing=(int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
            if($existing>0){
                $owned=(int)hh_query($db,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sm_installation'")->fetchColumn();
                $marker=$owned?$db->query('SELECT brand_id FROM sm_installation WHERE id=1')->fetchColumn():false;
                if($marker!==$id)throw new HttpError(400,'La base de '.$brand['name'].' ya contiene datos de otra instalación. Creá una base nueva y vacía.');
            }
            $connections[$id]=$db;
        }
        foreach($connections as $id=>$db){
            $db->exec('CREATE TABLE IF NOT EXISTS sm_installation (id INT PRIMARY KEY, brand_id VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            hh_query($db,'INSERT IGNORE INTO sm_installation (id,brand_id) VALUES (1,?)',[$id]);
            hh_migrate($db,$id);
        }
        hh_write_config($config);unlink($keyFile);hh_sign_in($config['password_hash']);
        header('Location: '.hh_base());exit;
    }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
    catch(Throwable $e){error_log('Sublimart: falló la instalación ('.get_class($e).').');$error='No se pudo completar la instalación. Comprobá los permisos privados y los permisos MySQL para crear tablas.';http_response_code(503);}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
function old(string $key,string $default=''):string{return hh_e(hh_post($key)?:$default);}
$form='<form method="post"><input type="hidden" name="csrf" value="'.hh_e(hh_csrf()).'"><label>Clave de instalación<input name="install_key" type="password" autocomplete="off" required maxlength="100"></label><p class="field-hint">La clave que te mostró el comando de preparación.</p>';
foreach(hh_brands() as $id=>$brand){
    $form.='<fieldset class="install-brand"><legend>'.hh_e($brand['name']).'</legend><label>Servidor MySQL<input name="'.$id.'_host" required maxlength="100" value="'.old($id.'_host','localhost').'"></label><label>Nombre completo de la base de datos<input name="'.$id.'_name" required maxlength="100" placeholder="u123456789_'.$id.'" value="'.old($id.'_name').'"></label><label>Usuario MySQL<input name="'.$id.'_user" required maxlength="100" value="'.old($id.'_user').'"></label><label>Contraseña MySQL<input type="password" name="'.$id.'_password" autocomplete="off" required maxlength="1024"></label></fieldset>';
}
$form.='<div class="section-heading"><h2>Tu acceso a las tres marcas</h2></div><label>Contraseña de acceso<input type="password" name="password" autocomplete="new-password" required minlength="16" maxlength="72"></label><label>Repetí la contraseña<input type="password" name="confirm" autocomplete="new-password" required minlength="16" maxlength="72"></label><p class="field-hint">Al menos 16 caracteres. Una contraseña para entrar y elegir cualquiera de las tres marcas.</p><button class="button primary" type="submit">Instalar las tres marcas</button></form><p class="field-hint">Los logos y nombres ya están cargados. Podés completar los datos fiscales y de contacto de cada marca después de entrar.</p>';
hh_page('Tu espacio, listo para las tres.','Creá tres bases MySQL vacías en Hostinger, una por marca, y completá sus datos. Las tablas se crean automáticamente.',$form,$error);
