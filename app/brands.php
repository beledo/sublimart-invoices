<?php
declare(strict_types=1);

/** Brand selection belongs to the request, never to a shared session. */
function hh_brands(): array {
    return [
        'sublimart'=>['id'=>'sublimart','name'=>'Sublimart','tagline'=>'Personalización con tu estilo','logo'=>'assets/logos/sublimart.png','color'=>'#a53662','background'=>'#fff0f5'],
        'argento'=>['id'=>'argento','name'=>'Argento 100x100','tagline'=>'Personalizaciones con esencia argentina','logo'=>'assets/logos/argento.png','color'=>'#126b9b','background'=>'#eaf6fd'],
        'merchandising'=>['id'=>'merchandising','name'=>'El Merchandising de tu club','tagline'=>'Los colores de tu pasión','logo'=>'assets/logos/merchandising.png','color'=>'#a53662','background'=>'#fff0f5'],
    ];
}
function hh_brand(?string $id = null): array {
    $id ??= is_string($_GET['brand']??null) ? $_GET['brand'] : '';
    $brands=hh_brands();
    if (!isset($brands[$id])) throw new HttpError(400,'Elegí una de las tres marcas para continuar.');
    return $brands[$id];
}
function hh_brand_data(?string $id=null): string {
    return HH_DATA.'/brands/'.hh_brand($id)['id'];
}
function hh_prepare_brand_storage(string $id): void {
    $directory=hh_brand_data($id);
    foreach ([HH_DATA.'/brands',$directory,$directory.'/pdf'] as $path) {
        if (is_link($path)||(!is_dir($path)&&!@mkdir($path,0700))||!is_writable($path)||(fileperms($path)&0077)!==0)
            throw new RuntimeException('No se pudo preparar el directorio privado de la marca.');
    }
}
function hh_validate_databases(array $databases): void {
    $seen=[];
    foreach (hh_brands() as $id=>$brand) {
        $db=$databases[$id]??[];
        $host=(string)($db['db_host']??'');$name=(string)($db['db_name']??'');
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D',$host)||!preg_match('/^[a-zA-Z0-9_]+$/D',$name)||empty($db['db_user'])||!is_string($db['db_password']??null)||$db['db_password']==='')
            throw new HttpError(400,'Completá los datos MySQL de '.$brand['name'].'.');
        $key=strtolower($host).'|'.strtolower($name);
        if (isset($seen[$key])) throw new HttpError(400,'Cada marca necesita una base de datos diferente. No repitas el nombre de la base.');
        $seen[$key]=true;
    }
}
