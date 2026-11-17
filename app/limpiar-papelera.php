<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/domain.php';
$config=hh_config();
foreach(hh_brands() as $id=>$brand){
    $_GET['brand']=$id;$db=hh_connect($config,$id);hh_migrate($db,$id);$total=0;
    do{$count=hh_purge($db,hh_now());$total+=$count;}while($count===100);
    echo $brand['name'].': '.$total.' documentos vencidos eliminados'.PHP_EOL;
}
