<?php
// Disposable QA only. The selected MySQL user must have a separate, empty retry database.
declare(strict_types=1);
require_once dirname(__DIR__).'/app/domain.php';
$config=json_decode(file_get_contents(getenv('SM_TEST_CONFIG')),true,64,JSON_THROW_ON_ERROR);
$retry=getenv('SM_TEST_RETRY_DB');
if(!$retry)throw new RuntimeException('Set SM_TEST_RETRY_DB to a dedicated, empty test database.');
foreach(hh_brands() as $id=>$brand){
    $_GET['brand']=$id;$db=hh_connect($config,$id);hh_migrate($db,$id);
    $company=$db->query("SELECT data FROM hh_company WHERE id='company'")->fetchColumn();
    if(json_decode($company,true)['brand']!==$id)throw new RuntimeException('Wrong brand profile.');
}
$retryConfig=$config;$retryConfig['databases']['sublimart']['db_name']=$retry;
$_GET['brand']='sublimart';$db=hh_connect($retryConfig);
if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('The retry database must be empty.');
hh_migrate($db);
if((int)$db->query('SELECT COUNT(*) FROM hh_company')->fetchColumn()!==1)throw new RuntimeException('A previous database marker skipped the retry migration.');
echo "PASS: perfiles de las tres marcas y reintento en otra base después de una instalación parcial.\n";
