<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';
if (is_file(HH_CONFIG)) {fwrite(STDERR,"La instancia ya está instalada.\n");exit(1);}
umask(0077);
if (!is_dir(HH_DATA) && !mkdir(HH_DATA,0700,true)) throw new RuntimeException('No se pudo crear el directorio privado.');
$keyFile=HH_DATA.'/install-key.php';
$key=bin2hex(random_bytes(24));
$file=@fopen($keyFile,'x');
if ($file===false) {fwrite(STDERR,"Ya hay una clave preparada. Si la perdiste antes de instalar, eliminá su archivo privado y generá otra.\n");exit(1);}
fwrite($file,"<?php return ".var_export(hash('sha256',$key),true).";\n");
fclose($file);
echo "Directorio privado: ".HH_DATA."\nClave temporal para instalar.php: ".$key."\n";
