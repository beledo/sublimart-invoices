<?php
declare(strict_types=1);

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Madrid');
umask(0077);
// Keep configuration, sessions and documents outside the web and Git roots.
function hh_data_directory(): string {
    $project=realpath(dirname(__DIR__));
    $rootValue=(string)($_SERVER['DOCUMENT_ROOT']??'');
    $webRoot=($rootValue!==''?realpath($rootValue):false) ?: $project;
    $configured=getenv('SUBLIMART_DATA_DIR');
    $candidate=$configured!==false&&$configured!==''?rtrim($configured,'/'):dirname($webRoot).'/.sublimart-docs-'.substr(hash('sha256',$project),0,12);
    $parent=realpath(dirname($candidate));
    $base=basename($candidate);
    if(!str_starts_with($candidate,'/')||$parent===false||$base===''||$base==='.'||$base==='..'||is_link($candidate))throw new RuntimeException('El directorio privado no es válido.');
    $private=rtrim($parent,'/').'/'.$base;
    foreach([$webRoot,$project] as $root)if($private===$root||str_starts_with($private,rtrim($root,'/').'/'))throw new RuntimeException('El directorio privado debe quedar fuera del sitio web.');
    if(file_exists($private)&&(!is_dir($private)||(fileperms($private)&0077)!==0))throw new RuntimeException('El directorio privado debe tener permisos 700.');
    return $private;
}
function hh_is_https(): bool { return in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true); }
if(PHP_SAPI!=='cli'&&!hh_is_https()&&!(PHP_SAPI==='cli-server'&&getenv('SUBLIMART_LOCAL_QA_HTTP')==='1'&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true))){
    http_response_code(403);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');exit('Abrí la aplicación con HTTPS.');
}
try { define('HH_DATA',hh_data_directory()); }
catch(RuntimeException $e){
    if(PHP_SAPI==='cli')throw $e;
    http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');exit($e->getMessage());
}
define('HH_CONFIG',HH_DATA.'/config.php');
const HH_FILE_PREFIX = "<?php http_response_code(404); exit; ?>\n";
const HH_DAY = 86400000;
const HH_PASSWORD_MIN_LENGTH = 8;

final class HttpError extends RuntimeException {
    public int $status;
    public array $details;
    public function __construct(int $status, string $message, array $details = []) {
        parent::__construct($message); $this->status = $status; $this->details = $details;
    }
}
require_once __DIR__.'/brands.php';
function hh_now(): int { return (int) floor(microtime(true) * 1000); }
function hh_e(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function hh_base(): string {
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return ($base === '/' || $base === '.') ? '/' : $base . '/';
}
function hh_brand_url(string $brandId, string $page = ''): string {
    return hh_base() . $page . '?brand=' . rawurlencode($brandId);
}
function hh_headers(): void {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    if(hh_is_https())header('Strict-Transport-Security: max-age=31536000');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
}
function hh_session(?string $brandId = null): void {
    if ($brandId !== null) hh_brand($brandId);
    $name = $brandId === null ? 'SUBLIMARTINSTALLSESSID' : 'SUBLIMARTSESSID' . strtoupper($brandId);
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== $name) throw new LogicException('La sesión corresponde a otro acceso.');
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime','28800');
    ini_set('session.gc_probability','1');
    ini_set('session.gc_divisor','100');
    $sessionRoot = HH_DATA . '/sessions';
    $directory = $brandId === null ? $sessionRoot : $sessionRoot . '/' . $brandId;
    $directories = $brandId === null ? [$sessionRoot] : [$sessionRoot, $directory];
    foreach ($directories as $path) {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de sesiones.');
        }
    }
    session_save_path($directory);
    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => hh_base(),
        'secure' => hh_is_https(),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    if(!session_start())throw new RuntimeException('No se pudo iniciar la sesión.');
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function hh_csrf(?string $brandId = null): string { hh_session($brandId); return $_SESSION['csrf']; }
function hh_check_csrf(?string $token = null, ?string $brandId = null): void {
    $expected = hh_csrf($brandId);
    $token ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($expected, $token)) throw new HttpError(403, 'La sesión cambió. Recargá la página y volvé a intentarlo.');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new HttpError(403, 'No se permite guardar desde otra página.');
}
function hh_post(string $key): string { return is_string($_POST[$key]??null)?$_POST[$key]:''; }
function hh_new_password(string $password,string $confirm): string {
    if (strlen($password) > 72 || str_contains($password, "\0") || preg_match_all('/./us', $password) < HH_PASSWORD_MIN_LENGTH) {
        throw new HttpError(400, 'Elegí una contraseña de al menos ' . HH_PASSWORD_MIN_LENGTH . ' caracteres y de hasta 72 bytes.');
    }
    if(!hash_equals($password,$confirm))throw new HttpError(400,'Las contraseñas no coinciden.');
    return defined('PASSWORD_ARGON2ID')?password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]):password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
}
function hh_sign_in(string $brandId, string $passwordHash): void {
    hh_session($brandId);
    if(!session_regenerate_id(true))throw new RuntimeException('No se pudo renovar la sesión.');
    $now = time();
    $_SESSION = ['brand' => $brandId, 'auth' => hash('sha256', $passwordHash), 'issued' => $now, 'last_seen' => $now, 'csrf' => bin2hex(random_bytes(32))];
}
function hh_config(): array {
    if (!is_file(HH_CONFIG)) throw new HttpError(503, 'Primero hay que instalar la aplicación.');
    $config = require HH_CONFIG;
    if (!is_array($config)) throw new HttpError(503, 'Revisá la configuración de la aplicación.');
    if (!isset($config['password_hashes']) && is_string($config['password_hash'] ?? null)) {
        $config['password_hashes'] = array_fill_keys(array_keys(hh_brands()), $config['password_hash']);
    }
    unset($config['password_hash']);
    foreach (array_keys(hh_brands()) as $brandId) {
        $hash = $config['password_hashes'][$brandId] ?? null;
        if (!is_string($hash) || $hash === '') throw new HttpError(503, 'Revisá la configuración de la aplicación.');
    }
    return $config;
}
function hh_write_config(array $config): void {
    $temp = tempnam(HH_DATA, 'config-');
    if ($temp === false) throw new RuntimeException('No se pudo crear la configuración.');
    try {
        if (file_put_contents($temp, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) throw new RuntimeException('No se pudo guardar la configuración.');
        chmod($temp, 0600);
        if (!rename($temp, HH_CONFIG)) throw new RuntimeException('No se pudo completar la configuración.');
        if (function_exists('opcache_invalidate')) opcache_invalidate(HH_CONFIG, true);
    } finally { if (is_file($temp)) unlink($temp); }
}
function hh_authenticated(array $config, string $brandId): bool {
    hh_session($brandId);
    return ($_SESSION['brand'] ?? null) === $brandId
        && isset($_SESSION['auth'], $_SESSION['last_seen'], $_SESSION['issued'])
        && hash_equals(hash('sha256', $config['password_hashes'][$brandId]), (string) $_SESSION['auth'])
        && time() - (int) $_SESSION['last_seen'] < 8 * 3600
        && time() - (int) $_SESSION['issued'] < 24 * 3600;
}
function hh_require_auth(string $brandId, bool $api = false): array {
    if (!is_file(HH_CONFIG)) {
        if ($api) throw new HttpError(503, 'Abrí la página de instalación para conectar la base de datos.');
        header('Location: ' . hh_base() . 'instalar.php'); exit;
    }
    $config = hh_config();
    if (!hh_authenticated($config, $brandId)) {
        $login = hh_brand_url($brandId, 'entrar.php');
        if ($api) throw new HttpError(401, 'Ingresá la contraseña de esta marca para continuar.', ['login' => $login]);
        header('Location: ' . $login); exit;
    }
    $_SESSION['last_seen'] = time();
    return $config;
}
function hh_connect(array $config, ?string $brandId=null): PDO {
    $brand=hh_brand($brandId);
    $config=$config['databases'][$brand['id']]??[];
    $host = $config['db_host'] ?? '';
    $name = $config['db_name'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $host) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) throw new RuntimeException('Configuración MySQL inválida.');
    return new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
}
function hh_migrate(PDO $db, ?string $brandId=null): void {
    $brand=hh_brand($brandId);
    hh_prepare_brand_storage($brand['id']);
    $identity=$db->query('SELECT @@hostname AS host,@@port AS port,DATABASE() AS name')->fetch();
    $databaseKey=substr(hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR)),0,24);
    $marker=hh_brand_data($brand['id']).'/schema-v2-'.$databaseKey.'.php';
    if (is_file($marker)) return;
    $lock=fopen(hh_brand_data($brand['id']).'/schema-lock.php','c+');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('No se pudo bloquear la actualización de la base.');
    try {
        if (is_file($marker)) return;
        $schema=file_get_contents(__DIR__.'/schema.sql');
        if ($schema===false) throw new RuntimeException('No se pudo cargar el esquema.');
        foreach (explode(';',$schema) as $sql) if(trim($sql)!=='') $db->exec($sql);
        $hasNumberReservation = hh_query($db,
            'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            ['hh_documents', 'reserved_number_key']
        )->fetchColumn();
        if (!$hasNumberReservation) {
            $db->exec("ALTER TABLE hh_documents
                ADD COLUMN reserved_number_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin
                    GENERATED ALWAYS AS (CASE WHEN type='quote' AND deleted_at IS NOT NULL THEN NULL ELSE RTRIM(number_key) END) STORED,
                DROP INDEX hh_number_unique,
                ADD UNIQUE INDEX hh_number_unique (type, reserved_number_key)");
        }
        $company=['name'=>$brand['name'],'nif'=>'','address'=>'','postcode'=>'','city'=>'','email'=>'','phone'=>'','iban'=>'','brand'=>$brand['id']];
        hh_query($db,'INSERT IGNORE INTO hh_company (id,data,updated_at) VALUES (?,?,?)',['company',json_encode($company,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),hh_now()]);
        if (file_put_contents($marker,"<?php exit;\n",LOCK_EX)===false) throw new RuntimeException('No se pudo guardar la versión de la base.');
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
function hh_query(PDO $db, string $sql, array $params = []): PDOStatement {
    $statement = $db->prepare($sql);
    foreach (array_values($params) as $index => $value) {
        $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    }
    $statement->execute(); return $statement;
}
function hh_json(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
function hh_body(): array {
    if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new HttpError(415, 'Los datos deben enviarse en formato JSON.');
    $max = 18000000;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new HttpError(413, 'El documento es demasiado grande.');
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) throw new HttpError(413, 'El documento es demasiado grande.');
    try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new HttpError(400, 'No se pudieron leer los datos.'); }
    if (!is_array($body)) throw new HttpError(400, 'No se pudieron leer los datos.');
    return $body;
}
function hh_verify_attempt(callable $verify, string $scope = 'install'): bool {
    $key = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = HH_DATA . '/auth-attempts.php';
    $handle = fopen($file, 'c+');
    if (!$handle) throw new RuntimeException('No se pudo comprobar el acceso.');
    try {
        if(!flock($handle, LOCK_EX))throw new RuntimeException('No se pudo bloquear el registro de acceso.');
        $raw=stream_get_contents($handle,8192);
        $state=json_decode(substr($raw ?: '',strlen(HH_FILE_PREFIX)),true) ?: [];
        if(($state['until']??0)<=time())$state=['until'=>time()+900,'failures'=>[]];
        $failures=$state['failures'];
        if(($failures[$key]??0)>=8||array_sum($failures)>=50)throw new HttpError(429,'Demasiados intentos. Esperá 15 minutos para volver a entrar.');
        $ok=$verify();
        if($ok)unset($failures[$key]);else $failures[$key]=($failures[$key]??0)+1;
        $state['failures']=$failures;
        $record=HH_FILE_PREFIX.json_encode($state,JSON_THROW_ON_ERROR);
        if(!rewind($handle)||!ftruncate($handle,0)||fwrite($handle,$record)!==strlen($record)||!fflush($handle))throw new RuntimeException('No se pudo actualizar el registro de acceso.');
        return $ok;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
