<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function hh_page(string $title,string $intro,string $content,string $error=''):void {
    hh_headers();header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.hh_e($title).' · Tus marcas</title><link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="assets/access.css"><link rel="stylesheet" href="assets/brands.css"></head><body class="access-page"><main class="access-card"><div class="access-brand-strip"><img src="assets/logos/sublimart.png" alt="Sublimart"><img src="assets/logos/argento.png" alt="Argento 100x100"><img src="assets/logos/merchandising.png" alt="El Merchandising de tu club"></div><div class="eyebrow">TUS MARCAS · DOCUMENTOS</div><h1>'.hh_e($title).'</h1><p class="access-intro">'.hh_e($intro).'</p>';
    if($error)echo '<p class="error-message" role="alert">'.hh_e($error).'</p>';
    echo $content.'</main></body></html>';
}
