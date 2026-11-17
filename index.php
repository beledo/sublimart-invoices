<?php
declare(strict_types=1);
require_once __DIR__.'/app/page.php';
hh_require_auth();hh_headers();
header('Content-Type: text/html; charset=utf-8');
if (!isset($_GET['brand'])) {
    $cards='';
    foreach(hh_brands() as $brand) {
        $cards.='<a class="brand-card brand-'.hh_e($brand['id']).'" href="?brand='.hh_e($brand['id']).'"><div class="brand-card-art"><img src="'.hh_e($brand['logo']).'" alt="Logo de '.hh_e($brand['name']).'"></div><div class="brand-card-copy"><span class="brand-card-kicker">TU ESPACIO DE TRABAJO</span><h2>'.hh_e($brand['name']).'</h2><p>'.hh_e($brand['tagline']).'</p><span class="brand-card-cta">Entrar a la marca <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 18 18 6M6 6h12v12"/></svg></span></span></div></a>';
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Elegí tu marca · Documentos</title><link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="assets/brands.css"></head><body class="selector-page"><header class="selector-top"><a href="./" class="suite-wordmark">tus marcas<span> / documentos</span></a><div class="selector-session"><a href="cuenta.php">Tu acceso</a><form action="salir.php" method="post"><input type="hidden" name="csrf" value="'.hh_e(hh_csrf()).'"><button type="submit">Cerrar sesión</button></form></div></header><main class="selector-main"><div class="selector-intro"><span class="eyebrow">TRES MARCAS. UN MISMO LUGAR.</span><h1>¿Con qué marca<br>trabajamos hoy?</h1><p>Elegí tu espacio para crear presupuestos,<br class="desktop-break"> gestionar clientes y tener todo al día.</p></div><div class="brand-grid">'.$cards.'</div><p class="selector-foot"><span aria-hidden="true">●</span> Cada marca tiene sus propios clientes, documentos y numeración.</p></main><footer class="selector-footer"><span>Sublimart · Argento 100x100 · El Merchandising de tu club</span><span>Hecho para tu día a día.</span></footer></body></html>';
    exit;
}
try{$brand=hh_brand();}
catch(HttpError $e){http_response_code($e->status);hh_page('Elegí una marca',$e->getMessage(),'<a class="button primary" href="./">Ver mis marcas</a>');exit;}
$links='';
foreach(hh_brands() as $item){
    $current=$item['id']===$brand['id'];
    $links.='<a class="switch-brand-link'.($current?' current':'').'" href="?brand='.hh_e($item['id']).'"'.($current?' aria-current="true"':'').'><img src="'.hh_e($item['logo']).'" alt=""><span>'.hh_e($item['name']).'<small>'.($current?'Marca actual':'Entrar a esta marca').'</small></span><span aria-hidden="true">'.($current?'<svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>':'<svg viewBox="0 0 24 24"><path d="M6 18 18 6M6 6h12v12"/></svg>').'</span></a>';
}
$template=file_get_contents(__DIR__.'/app/template.html');
echo strtr($template,[
    '{{CSRF}}'=>hh_e(hh_csrf()),'{{BASE}}'=>hh_e(hh_base()),
    '{{BRAND_ID}}'=>hh_e($brand['id']),'{{BRAND_NAME}}'=>hh_e($brand['name']),
    '{{BRAND_LOGO}}'=>hh_e($brand['logo']),'{{BRAND_LINKS}}'=>$links,
]);
