<?php
declare(strict_types=1);
session_start();

$currency = getenv('APP_CURRENCY') ?: 'KES';
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'db', getenv('DB_NAME') ?: 'invoicer');
try {
    $db = new PDO($dsn, getenv('DB_USER') ?: 'invoicer', getenv('DB_PASSWORD') ?: 'invoicer_secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { http_response_code(503); exit('Database is starting. Refresh in a few seconds.'); }

$db->exec("CREATE TABLE IF NOT EXISTS customers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(190) DEFAULT '', phone VARCHAR(60) DEFAULT '', address TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS documents (id INT AUTO_INCREMENT PRIMARY KEY, number VARCHAR(40) NOT NULL UNIQUE, type ENUM('invoice','quote') NOT NULL, customer_id INT NULL, issue_date DATE NOT NULL, due_date DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft', currency VARCHAR(8) NOT NULL DEFAULT 'KES', notes TEXT, subtotal DECIMAL(12,2) NOT NULL DEFAULT 0, tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0, total DECIMAL(12,2) NOT NULL DEFAULT 0, paid DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS document_items (id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, description VARCHAR(255) NOT NULL, quantity DECIMAL(10,2) NOT NULL DEFAULT 1, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0, line_total DECIMAL(12,2) NOT NULL DEFAULT 0, FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE) ENGINE=InnoDB");
$db->exec("CREATE TABLE IF NOT EXISTS business_profiles (id TINYINT PRIMARY KEY, business_name VARCHAR(180) NOT NULL, contact_email VARCHAR(190) DEFAULT '', phone VARCHAR(80) DEFAULT '', address TEXT, logo_file VARCHAR(80) DEFAULT NULL, logo_mime VARCHAR(40) DEFAULT NULL, verification_file VARCHAR(80) DEFAULT NULL, verification_mime VARCHAR(60) DEFAULT NULL, verification_name VARCHAR(190) DEFAULT NULL, verification_status VARCHAR(30) NOT NULL DEFAULT 'not_submitted', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$db->exec("INSERT IGNORE INTO business_profiles(id,business_name) VALUES(1,'Ledger')");
$db->exec("CREATE TABLE IF NOT EXISTS certificates (id INT AUTO_INCREMENT PRIMARY KEY, certificate_no VARCHAR(40) NOT NULL UNIQUE, customer_id INT NOT NULL UNIQUE, customer_name VARCHAR(160) NOT NULL, business_name VARCHAR(180) NOT NULL, issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE) ENGINE=InnoDB");

$existingCols = $db->query("SHOW COLUMNS FROM business_profiles")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('whatsapp_api_url', $existingCols, true)) {
    $db->exec("ALTER TABLE business_profiles 
        ADD COLUMN whatsapp_api_url VARCHAR(255) DEFAULT 'https://wa.tukonectdigital.co.ke/api/v1',
        ADD COLUMN whatsapp_api_key VARCHAR(255) DEFAULT '',
        ADD COLUMN whatsapp_enabled TINYINT(1) DEFAULT 0,
        ADD COLUMN whatsapp_auto_queue TINYINT(1) DEFAULT 0,
        ADD COLUMN whatsapp_template TEXT NULL");
}
$docCols = $db->query("SHOW COLUMNS FROM documents")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('whatsapp_status', $docCols, true)) {
    $db->exec("ALTER TABLE documents ADD COLUMN whatsapp_status VARCHAR(30) NOT NULL DEFAULT 'unsent'");
}
$db->exec("CREATE TABLE IF NOT EXISTS whatsapp_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NULL,
    customer_name VARCHAR(160) NOT NULL,
    recipient VARCHAR(60) NOT NULL,
    message TEXT NOT NULL,
    media_url VARCHAR(255) NULL,
    scheduled_at DATETIME NULL,
    status ENUM('queued','scheduled','sent','failed') NOT NULL DEFAULT 'queued',
    cerebro_id VARCHAR(100) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB");

$storagePath = getenv('APP_STORAGE_PATH') ?: (sys_get_temp_dir().'/ledger_private_uploads');
if ((!is_dir($storagePath) && !@mkdir($storagePath, 0750, true) && !is_dir($storagePath)) || !is_writable($storagePath)) {
    error_log('Private upload storage is unavailable or not writable: '.$storagePath);
    http_response_code(500);
    exit('Private upload storage is unavailable. Check the container upload volume permissions.');
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n, $c): string { return h($c).' '.number_format((float)$n, 2); }
function go($url='index.php'): never { header('Location: '.$url); exit; }
function nextNumber(PDO $db, string $type): string { $prefix = $type === 'quote' ? 'QUO' : 'INV'; $q=$db->prepare('SELECT COUNT(*) FROM documents WHERE type=? AND YEAR(created_at)=YEAR(CURDATE())'); $q->execute([$type]); return sprintf('%s-%s-%04d', $prefix, date('Y'), (int)$q->fetchColumn()+1); }
function uploadPrivateFile(array $file, string $kind): ?array {
    global $storagePath;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not complete. Try a smaller file.');
    $maxBytes = $kind === 'logo' ? 5 * 1024 * 1024 : 15 * 1024 * 1024;
    if (($file['size'] ?? 0) < 1 || $file['size'] > $maxBytes || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('The file is too large or invalid.');
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($file['tmp_name']);
    $allowed = $kind === 'logo' ? ['image/png'=>'png','image/jpeg'=>'jpg'] : ['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'];
    if (!isset($allowed[$mime])) throw new RuntimeException($kind === 'logo' ? 'Use a PNG or JPG logo.' : 'Use a PDF, PNG, or JPG verification document.');
    if (str_starts_with($mime, 'image/')) { $image = getimagesize($file['tmp_name']); if (!$image || $image[0] > 5000 || $image[1] > 5000) throw new RuntimeException('The image dimensions are invalid or too large.'); }
    $stored = $kind.'_'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $storagePath.'/'.$stored)) throw new RuntimeException('Could not save the uploaded file. Check storage permissions.');
    chmod($storagePath.'/'.$stored, 0640);
    return ['file'=>$stored,'mime'=>$mime,'name'=>substr(basename((string)($file['name']??'upload')),0,180)];
}
function privateFilePath(?string $filename): ?string { global $storagePath; return $filename && preg_match('/^(logo|verification)_[a-f0-9]{32}\.(png|jpg|pdf)$/',$filename) ? $storagePath.'/'.$filename : null; }
function imageDataUri(?string $filename, ?string $mime): string { $path=privateFilePath($filename); return $path && is_file($path) && $mime ? 'data:'.$mime.';base64,'.base64_encode((string)file_get_contents($path)) : ''; }
function streamPrivateFile(?string $filename, ?string $mime, string $downloadName, bool $attachment=true): never {
    $path=privateFilePath($filename); if (!$path || !is_file($path) || !$mime) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header('Content-Disposition: '.($attachment?'attachment':'inline').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_', $downloadName).'"');
    header('Content-Length: '.filesize($path)); readfile($path); exit;
}
function renderPdf(string $html, string $filename): never {
    if (!is_file(__DIR__.'/vendor/autoload.php')) { http_response_code(503); exit('PDF support is not installed. Rebuild the Docker image.'); }
    require_once __DIR__.'/vendor/autoload.php';
    $options=new Dompdf\Options(); $options->set('isRemoteEnabled',false); $options->set('defaultFont','DejaVu Sans');
    $pdf=new Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','portrait'); $pdf->render(); $pdf->stream($filename,['Attachment'=>true]); exit;
}

function formatWhatsAppRecipient(string $phone): string {
    $clean = preg_replace('/\D/', '', $phone);
    if (str_starts_with($clean, '0')) {
        $clean = '254' . substr($clean, 1);
    } elseif (strlen($clean) === 9 && in_array($clean[0], ['7', '1'], true)) {
        $clean = '254' . $clean;
    }
    return $clean;
}

function compileWhatsAppMessage(?string $template, array $doc, array $profile): string {
    if (!$template || trim($template) === '') {
        $template = "Hello {customer_name}, your {type} {number} from {business_name} for {currency} {total} has been issued.\nDue date: {due_date}\nBalance due: {balance}\nThank you for choosing us!";
    }
    $balance = number_format(max(0, (float)($doc['total'] ?? 0) - (float)($doc['paid'] ?? 0)), 2);
    $map = [
        '{customer_name}' => $doc['customer'] ?? 'Valued Customer',
        '{number}'        => $doc['number'] ?? '',
        '{type}'          => ucfirst($doc['type'] ?? 'invoice'),
        '{currency}'      => $doc['currency'] ?? 'KES',
        '{total}'         => number_format((float)($doc['total'] ?? 0), 2),
        '{subtotal}'      => number_format((float)($doc['subtotal'] ?? 0), 2),
        '{tax}'           => number_format((float)($doc['tax_amount'] ?? 0), 2),
        '{paid}'          => number_format((float)($doc['paid'] ?? 0), 2),
        '{balance}'       => $balance,
        '{issue_date}'    => !empty($doc['issue_date']) ? date('M j, Y', strtotime($doc['issue_date'])) : '',
        '{due_date}'      => !empty($doc['due_date']) ? date('M j, Y', strtotime($doc['due_date'])) : 'On receipt',
        '{business_name}' => $profile['business_name'] ?? 'Ledger',
        '{notes}'         => $doc['notes'] ?? ''
    ];
    return str_replace(array_keys($map), array_values($map), $template);
}

function callCerebroApi(string $baseUrl, string $apiKey, string $endpoint, array $payload): array {
    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    $json = json_encode($payload);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $json
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'error' => 'cURL error: ' . $err, 'status' => $status];
    $data = json_decode((string)$res, true);
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? ($data['message'] ?? 'HTTP ' . $status . ': ' . substr((string)$res, 0, 150));
        return ['success' => false, 'error' => $msg, 'status' => $status, 'data' => $data];
    }
    return ['success' => true, 'data' => $data, 'status' => $status];
}

function processWhatsAppQueue(PDO $db, array $profile, int $limit = 10): array {
    if (empty($profile['whatsapp_api_key'])) {
        return ['processed' => 0, 'failed' => 0, 'total' => 0, 'error' => 'WhatsApp API key is not configured in Settings.'];
    }
    $baseUrl = $profile['whatsapp_api_url'] ?: 'https://wa.tukonectdigital.co.ke/api/v1';
    $apiKey = $profile['whatsapp_api_key'];
    // FIFO Queue Processing: ORDER BY id ASC
    $stmt = $db->prepare("SELECT * FROM whatsapp_queue WHERE status = 'queued' ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();
    $processed = 0; $failed = 0;
    foreach ($items as $item) {
        $payload = [
            'recipient' => $item['recipient'],
            'message'   => $item['message']
        ];
        if (!empty($item['media_url'])) {
            $payload['mediaUrl'] = $item['media_url'];
        }
        $res = callCerebroApi($baseUrl, $apiKey, 'messages/send', $payload);
        if ($res['success']) {
            $cerebroId = $res['data']['id'] ?? null;
            $u = $db->prepare("UPDATE whatsapp_queue SET status='sent', cerebro_id=?, sent_at=NOW(), error_message=NULL WHERE id=?");
            $u->execute([$cerebroId, $item['id']]);
            if ($item['document_id']) {
                $db->prepare("UPDATE documents SET whatsapp_status='sent' WHERE id=?")->execute([$item['document_id']]);
            }
            $processed++;
        } else {
            $u = $db->prepare("UPDATE whatsapp_queue SET status='failed', error_message=? WHERE id=?");
            $u->execute([$res['error'] ?? 'API error', $item['id']]);
            $failed++;
        }
    }
    return ['processed' => $processed, 'failed' => $failed, 'total' => count($items), 'error' => null];
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$ownerRegistered = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
$route = trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: ''), '/');
$routePages = ['' => 'home', 'login' => 'login', 'register' => 'register', 'dashboard' => 'dashboard', 'invoices' => 'documents', 'quotes' => 'documents', 'documents' => 'documents', 'customers' => 'customers', 'reports' => 'reports', 'settings' => 'settings', 'new' => 'new', 'whatsapp-queue' => 'queue'];
if ($route === 'invoices') $_GET['type'] = 'invoice';
if ($route === 'quotes') $_GET['type'] = 'quote';
if ($route === 'reports/pdf') $_GET['page'] = 'reports_pdf';
if (preg_match('#^document/(\d+)/pdf$#', $route, $routeMatch)) { $_GET['page'] = 'document_pdf'; $_GET['id'] = $routeMatch[1]; }
if (preg_match('#^certificate/(\d+)/pdf$#', $route, $routeMatch)) { $_GET['page'] = 'certificate_pdf'; $_GET['id'] = $routeMatch[1]; }
if (preg_match('#^document/(\d+)$#', $route, $routeMatch)) { $_GET['page'] = 'view'; $_GET['id'] = $routeMatch[1]; }
$page = $_GET['page'] ?? ($routePages[$route] ?? 'home');
$profile = $db->query('SELECT * FROM business_profiles WHERE id=1')->fetch();
if (in_array($route,['media/logo','media/verification'],true)) {
    if (empty($_SESSION['authenticated'])) go('/login');
    if ($route==='media/logo') streamPrivateFile($profile['logo_file']??null,$profile['logo_mime']??null,'business-logo.'.(pathinfo($profile['logo_file']??'',PATHINFO_EXTENSION)?:'png'),false);
    streamPrivateFile($profile['verification_file']??null,$profile['verification_mime']??null,'business-verification.'.(pathinfo($profile['verification_file']??'',PATHINFO_EXTENSION)?:'pdf'),true);
}
$action = $_POST['action'] ?? '';
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid form token'); }
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) { $_SESSION['login_error']='An owner account has already been created for this workspace. Sign in to continue.'; go('/register'); }
    $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $username=trim($_POST['username']??''); $businessName=trim($_POST['business_name']??''); $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm_password']??'');
    if ($name==='' || $businessName==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($username)<3 || strlen($password)<8 || $password!==$confirm) { $_SESSION['login_error']='Enter your name and business name, use a valid email and a password with at least 8 characters, and make sure both passwords match.'; go('/register'); }
    $logoUpload=null; $verificationUpload=null;
    try {
        $logoUpload=uploadPrivateFile($_FILES['business_logo']??[],'logo');
        $verificationUpload=uploadPrivateFile($_FILES['verification_document']??[],'verification');
        $db->beginTransaction();
        $q=$db->prepare('INSERT INTO users(name,email,username,password_hash) VALUES(?,?,?,?)');
        $q->execute([$name,$email,$username,password_hash($password,PASSWORD_DEFAULT)]);
        $q=$db->prepare("UPDATE business_profiles SET business_name=?,contact_email=?,phone=?,address=?,logo_file=?,logo_mime=?,verification_file=?,verification_mime=?,verification_name=?,verification_status=? WHERE id=1");
        $q->execute([$businessName,$email,trim($_POST['phone']??''),trim($_POST['address']??''),$logoUpload['file']??null,$logoUpload['mime']??null,$verificationUpload['file']??null,$verificationUpload['mime']??null,$verificationUpload['name']??null,$verificationUpload?'submitted':'not_submitted']);
        $db->commit();
    } catch (Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        foreach([$logoUpload,$verificationUpload] as $upload) { if($upload && ($path=privateFilePath($upload['file'])) && is_file($path)) @unlink($path); }
        $_SESSION['login_error']=$e instanceof RuntimeException?$e->getMessage():'That email or username is already in use.'; go('/register');
    }
    session_regenerate_id(true); $_SESSION['authenticated']=true; $_SESSION['account_name']=$name; go('/dashboard');
}
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
    $q=$db->prepare('SELECT name,password_hash FROM users WHERE username=? OR email=? LIMIT 1'); $q->execute([$identifier,$identifier]); $account=$q->fetch();
    $accountValid=$account && password_verify($password,$account['password_hash']);
    $envValid=hash_equals(getenv('APP_USERNAME') ?: 'admin',$identifier) && hash_equals(getenv('APP_PASSWORD') ?: 'change-me-now',$password);
    if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') && ($accountValid || $envValid)) {
        session_regenerate_id(true); $_SESSION['authenticated'] = true; if($accountValid) $_SESSION['account_name']=$account['name']; go('/dashboard');
    }
    $_SESSION['login_error'] = 'That username or password did not match.'; go('/login');
}
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') { session_destroy(); go('/login'); }
if (!empty($_SESSION['authenticated']) && in_array($page,['login','register'],true)) go('/dashboard');
if (empty($_SESSION['authenticated']) && !in_array($page, ['home', 'login','register'], true)) go('/login');
if (empty($_SESSION['authenticated']) && in_array($page,['login','register'],true)) {
    $loginError=$_SESSION['login_error'] ?? ''; unset($_SESSION['login_error']); $authMode=$page;
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="icon" type="image/png" href="/assets/ledger-emblem-transparent.png"><link rel="apple-touch-icon" href="/assets/ledger-emblem-transparent.png"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ucfirst($authMode)?> · Ledger</title><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/style.css"></head><body class="auth-page"><div class="auth-side"><a class="brand" href="/"><img class="brand-full" src="/assets/ledger-wordmark-transparent.png" alt="Ledger" width="164" height="40"></a><div><span class="section-kicker">A LITTLE LESS ADMIN</span><h2>Keep your business<br>moving <em>forward.</em></h2><p>Invoices, quotes, customers, and payments in one calmer place.</p></div><small class="auth-side-foot">Simple tools. Professional results.</small></div><main class="auth-main"><a class="auth-home" href="/">← Back to home</a><form class="auth-card standalone-auth" method="post" enctype="multipart/form-data"><span class="auth-eyebrow"><?= $authMode==='register'?'CREATE YOUR WORKSPACE':'WELCOME BACK' ?></span><h1><?= $authMode==='register'?'Create your account':'Sign in to Ledger' ?></h1><p><?= $authMode==='register'?'Set up the owner account for your business.':'Pick up right where your business left off.' ?></p><?php if($loginError): ?><div class="auth-error"><?=h($loginError)?></div><?php endif ?><?php if($authMode==='register' && $ownerRegistered): ?><div class="auth-error">An owner account has already been created for this workspace. Sign in to continue.</div><a class="button primary full" href="/login">Go to sign in</a><?php else: ?><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=h($authMode)?>"><?php if($authMode==='register'): ?><label>Your name<input name="name" autocomplete="name" required></label><label>Work email<input type="email" name="email" autocomplete="email" required></label><label>Business name<input name="business_name" autocomplete="organization" required></label><label>Business phone<input name="phone" autocomplete="tel"></label><label>Business address<textarea name="address" rows="2"></textarea></label><label>Business logo (PNG or JPG)<input type="file" name="business_logo" accept="image/png,image/jpeg"></label><label>Business verification document (PDF, PNG, or JPG)<input type="file" name="verification_document" accept="application/pdf,image/png,image/jpeg"></label><label>Username<input name="username" autocomplete="username" minlength="3" required></label><label>Password<input type="password" name="password" autocomplete="new-password" minlength="8" required></label><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label><?php else: ?><label>Username or email<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><?php endif ?><button class="button primary full"><?= $authMode==='register'?'Create account':'Sign in' ?> <span>→</span></button><?php endif ?><div class="auth-switch"><?= $authMode==='register'?'Already have an account?':'New to Ledger?' ?> <a href="<?= $authMode==='register'?'/login':'/register' ?>"><?= $authMode==='register'?'Sign in':'Create an account' ?></a></div></form></main></body></html><?php exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid form token'); }
    if ($action === 'profile') {
        $businessName=trim($_POST['business_name']??''); $email=trim($_POST['contact_email']??'');
        if($businessName==='' || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) { $_SESSION['flash']='Enter a business name and a valid contact email.'; go('/settings'); }
        $logoUpload=null; $verificationUpload=null; $old=$db->query('SELECT logo_file,verification_file FROM business_profiles WHERE id=1')->fetch();
        try {
            $logoUpload=uploadPrivateFile($_FILES['business_logo']??[],'logo');
            $verificationUpload=uploadPrivateFile($_FILES['verification_document']??[],'verification');
            $q=$db->prepare("UPDATE business_profiles SET business_name=?,contact_email=?,phone=?,address=?,logo_file=COALESCE(?,logo_file),logo_mime=COALESCE(?,logo_mime),verification_file=COALESCE(?,verification_file),verification_mime=COALESCE(?,verification_mime),verification_name=COALESCE(?,verification_name),verification_status=IF(? IS NULL,verification_status,'submitted') WHERE id=1");
            $q->execute([$businessName,$email,trim($_POST['phone']??''),trim($_POST['address']??''),$logoUpload['file']??null,$logoUpload['mime']??null,$verificationUpload['file']??null,$verificationUpload['mime']??null,$verificationUpload['name']??null,$verificationUpload['file']??null]);
            foreach([['new'=>$logoUpload,'old'=>$old['logo_file']??null],['new'=>$verificationUpload,'old'=>$old['verification_file']??null]] as $pair) { if($pair['new'] && $pair['old'] && ($path=privateFilePath($pair['old'])) && is_file($path)) @unlink($path); }
            $_SESSION['flash']='Business profile updated.';
        } catch (Throwable $e) {
            foreach([$logoUpload,$verificationUpload] as $upload) { if($upload && ($path=privateFilePath($upload['file'])) && is_file($path)) @unlink($path); }
            $_SESSION['flash']=$e instanceof RuntimeException?$e->getMessage():'Could not save the business profile.';
        }
        go('/settings');
    }
    if ($action === 'customer') {
        $q=$db->prepare('INSERT INTO customers(name,email,phone,address) VALUES(?,?,?,?)'); $q->execute([trim($_POST['name']),trim($_POST['email'] ?? ''),trim($_POST['phone'] ?? ''),trim($_POST['address'] ?? '')]);
        $_SESSION['flash']='Customer added successfully.'; go('/customers');
    }
    if ($action === 'issue_certificate') {
        $customerId=(int)($_POST['customer_id']??0); $q=$db->prepare('SELECT id FROM customers WHERE id=?'); $q->execute([$customerId]);
        if(!$q->fetch()) { $_SESSION['flash']='Customer not found.'; go('/customers'); }
        $q=$db->prepare('SELECT id FROM certificates WHERE customer_id=?'); $q->execute([$customerId]);
        $existing=$q->fetch(); if(!$existing) { $count=(int)$db->query('SELECT COUNT(*) FROM certificates WHERE YEAR(issued_at)=YEAR(CURDATE())')->fetchColumn()+1; $number=sprintf('CERT-%s-%04d',date('Y'),$count); $q=$db->prepare('SELECT name FROM customers WHERE id=?'); $q->execute([$customerId]); $customerName=(string)$q->fetchColumn(); $q=$db->prepare('INSERT INTO certificates(certificate_no,customer_id,customer_name,business_name) VALUES(?,?,?,?)'); $q->execute([$number,$customerId,$customerName,$profile['business_name']]); $_SESSION['flash']='Customer certificate issued.'; }
        else { $_SESSION['flash']='This customer already has an issued certificate.'; }
        go('/customers');
    }
    if ($action === 'whatsapp_settings') {
        $apiUrl = trim($_POST['whatsapp_api_url'] ?? '');
        $apiKey = trim($_POST['whatsapp_api_key'] ?? '');
        $enabled = !empty($_POST['whatsapp_enabled']) ? 1 : 0;
        $autoQueue = !empty($_POST['whatsapp_auto_queue']) ? 1 : 0;
        $template = trim($_POST['whatsapp_template'] ?? '');
        $q = $db->prepare("UPDATE business_profiles SET whatsapp_api_url=?, whatsapp_api_key=?, whatsapp_enabled=?, whatsapp_auto_queue=?, whatsapp_template=? WHERE id=1");
        $q->execute([$apiUrl, $apiKey, $enabled, $autoQueue, $template]);
        $_SESSION['flash'] = 'WhatsApp integration settings saved.';
        go('/settings');
    }
    if ($action === 'send_whatsapp') {
        $docId = (int)($_POST['document_id'] ?? 0);
        $q = $db->prepare("SELECT d.*, c.name customer, c.phone, c.email FROM documents d LEFT JOIN customers c ON c.id=d.customer_id WHERE d.id=?");
        $q->execute([$docId]);
        $doc = $q->fetch();
        if (!$doc) { $_SESSION['flash'] = 'Document not found.'; go('/invoices'); }
        $phone = trim($_POST['recipient'] ?? ($doc['phone'] ?? ''));
        $recipient = formatWhatsAppRecipient($phone);
        if (strlen($recipient) < 9) {
            $_SESSION['flash'] = 'Please enter a valid recipient phone number with country code (e.g. 254712345678).';
            go('/document/' . $docId);
        }
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            $message = compileWhatsAppMessage($profile['whatsapp_template'] ?? null, $doc, $profile);
        }
        $mode = $_POST['send_mode'] ?? 'immediate';
        $scheduleTime = trim($_POST['scheduled_at'] ?? '');
        if ($mode === 'schedule' && $scheduleTime) {
            $scheduledDt = new DateTime($scheduleTime);
            if ($scheduledDt <= new DateTime()) {
                $_SESSION['flash'] = 'Scheduled time must be in the future.';
                go('/document/' . $docId);
            }
            $isoUtc = (clone $scheduledDt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            if (!empty($profile['whatsapp_api_key'])) {
                $res = callCerebroApi($profile['whatsapp_api_url'], $profile['whatsapp_api_key'], 'messages/schedule', [
                    'recipient' => $recipient,
                    'message' => $message,
                    'scheduledAt' => $isoUtc
                ]);
                if ($res['success']) {
                    $cerebroId = $res['data']['id'] ?? null;
                    $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, scheduled_at, status, cerebro_id) VALUES(?,?,?,?,?, 'scheduled', ?)");
                    $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message, $scheduledDt->format('Y-m-d H:i:s'), $cerebroId]);
                    $db->prepare("UPDATE documents SET whatsapp_status='scheduled' WHERE id=?")->execute([$docId]);
                    $_SESSION['flash'] = 'WhatsApp message scheduled for ' . $scheduledDt->format('M j, Y H:i') . ' (Cerebro ID: ' . $cerebroId . ').';
                } else {
                    $_SESSION['flash'] = 'Failed to schedule WhatsApp message: ' . ($res['error'] ?? 'API error');
                }
            } else {
                $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, scheduled_at, status) VALUES(?,?,?,?,?, 'scheduled')");
                $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message, $scheduledDt->format('Y-m-d H:i:s')]);
                $db->prepare("UPDATE documents SET whatsapp_status='scheduled' WHERE id=?")->execute([$docId]);
                $_SESSION['flash'] = 'WhatsApp message queued and scheduled locally for ' . $scheduledDt->format('M j, Y H:i') . '.';
            }
        } elseif ($mode === 'queue') {
            $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, status) VALUES(?,?,?,?, 'queued')");
            $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message]);
            $db->prepare("UPDATE documents SET whatsapp_status='queued' WHERE id=?")->execute([$docId]);
            $_SESSION['flash'] = 'Invoice queued in FIFO order for WhatsApp dispatch.';
        } else {
            if (empty($profile['whatsapp_api_key'])) {
                $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, status, error_message) VALUES(?,?,?,?, 'queued', 'Awaiting API Key in Settings')");
                $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message]);
                $db->prepare("UPDATE documents SET whatsapp_status='queued' WHERE id=?")->execute([$docId]);
                $_SESSION['flash'] = 'API Key is missing in Settings. Message placed in FIFO queue.';
            } else {
                $res = callCerebroApi($profile['whatsapp_api_url'], $profile['whatsapp_api_key'], 'messages/send', [
                    'recipient' => $recipient,
                    'message' => $message
                ]);
                if ($res['success']) {
                    $cerebroId = $res['data']['id'] ?? null;
                    $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, status, cerebro_id, sent_at) VALUES(?,?,?,?, 'sent', ?, NOW())");
                    $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message, $cerebroId]);
                    $db->prepare("UPDATE documents SET whatsapp_status='sent' WHERE id=?")->execute([$docId]);
                    $_SESSION['flash'] = 'WhatsApp message dispatched to ' . $recipient . ' (ID: ' . $cerebroId . ').';
                } else {
                    $ins = $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, status, error_message) VALUES(?,?,?,?, 'failed', ?)");
                    $ins->execute([$docId, $doc['customer'] ?? 'Customer', $recipient, $message, $res['error'] ?? 'API error']);
                    $_SESSION['flash'] = 'WhatsApp dispatch failed: ' . ($res['error'] ?? 'API error');
                }
            }
        }
        go('/document/' . $docId);
    }
    if ($action === 'process_queue') {
        $res = processWhatsAppQueue($db, $profile, 10);
        if (!empty($res['error'])) {
            $_SESSION['flash'] = $res['error'];
        } else {
            $_SESSION['flash'] = sprintf('Processed %d queued message(s) in FIFO order. (%d failed, %d in batch)', $res['processed'], $res['failed'], $res['total']);
        }
        go('/whatsapp-queue');
    }
    if ($action === 'document') {
        $type = $_POST['type'] === 'quote' ? 'quote' : 'invoice';
        $descriptions=$_POST['description'] ?? []; $quantities=$_POST['quantity'] ?? []; $prices=$_POST['price'] ?? [];
        $items=[]; $subtotal=0;
        foreach ($descriptions as $i=>$desc) { $desc=trim($desc); if ($desc==='') continue; $qty=max(0.01,(float)($quantities[$i] ?? 1)); $price=max(0,(float)($prices[$i] ?? 0)); $line=round($qty*$price,2); $items[]=[$desc,$qty,$price,$line]; $subtotal+=$line; }
        if (!$items) { $_SESSION['flash']='Add at least one line item.'; go('/new?type='.$type); }
        $taxRate=max(0,(float)($_POST['tax_rate'] ?? 0)); $tax=round($subtotal*$taxRate/100,2); $total=$subtotal+$tax;
        $number=nextNumber($db,$type);
        $db->beginTransaction();
        $q=$db->prepare('INSERT INTO documents(number,type,customer_id,issue_date,due_date,status,currency,notes,subtotal,tax_rate,tax_amount,total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$number,$type,(int)($_POST['customer_id'] ?? 0) ?: null,$_POST['issue_date'],$_POST['due_date'] ?: null,$type==='quote'?'sent':'unpaid',$currency,trim($_POST['notes'] ?? ''),$subtotal,$taxRate,$tax,$total]); $id=(int)$db->lastInsertId();
        $q=$db->prepare('INSERT INTO document_items(document_id,description,quantity,unit_price,line_total) VALUES(?,?,?,?,?)'); foreach($items as $it) $q->execute([$id,...$it]);
        $db->commit();
        if (!empty($profile['whatsapp_auto_queue']) && !empty($profile['whatsapp_enabled']) && !empty($_POST['customer_id'])) {
            $cq = $db->prepare("SELECT name, phone FROM customers WHERE id=?");
            $cq->execute([(int)$_POST['customer_id']]);
            $cRow = $cq->fetch();
            if ($cRow && !empty($cRow['phone'])) {
                $rcp = formatWhatsAppRecipient($cRow['phone']);
                if (strlen($rcp) >= 9) {
                    $msg = compileWhatsAppMessage($profile['whatsapp_template'] ?? null, ['number'=>$number,'type'=>$type,'total'=>$total,'subtotal'=>$subtotal,'tax_amount'=>$tax,'paid'=>0,'issue_date'=>$_POST['issue_date'],'due_date'=>$_POST['due_date'],'currency'=>$currency,'customer'=>$cRow['name'],'notes'=>trim($_POST['notes']??'')], $profile);
                    $db->prepare("INSERT INTO whatsapp_queue(document_id, customer_name, recipient, message, status) VALUES(?,?,?,?,'queued')")->execute([$id, $cRow['name'], $rcp, $msg]);
                    $db->prepare("UPDATE documents SET whatsapp_status='queued' WHERE id=?")->execute([$id]);
                }
            }
        }
        $_SESSION['flash']=ucfirst($type).' '.$number.' created.'; go('/document/'.$id);
    }
    if ($action === 'convert') { $q=$db->prepare("UPDATE documents SET type='invoice',number=?,status='unpaid',issue_date=CURDATE(),due_date=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=? AND type='quote'"); $q->execute([nextNumber($db,'invoice'),(int)$_POST['id']]); $_SESSION['flash']='Quote converted to invoice.'; go('?page=view&id='.(int)$_POST['id']); }
    if ($action === 'payment') { $id=(int)$_POST['id']; $amount=max(0,(float)$_POST['amount']); $q=$db->prepare("SELECT total,paid FROM documents WHERE id=? AND type='invoice'"); $q->execute([$id]); $balance=$q->fetch(); if($balance){$paid=min((float)$balance['total'],(float)$balance['paid']+$amount);$status=$paid >= (float)$balance['total'] ? 'paid' : 'partial';$q=$db->prepare('UPDATE documents SET paid=?,status=? WHERE id=?');$q->execute([$paid,$status,$id]);} $_SESSION['flash']='Payment recorded.'; go('/document/'.$id); }
}
$flash=$_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$customers=$db->query('SELECT c.*,ce.certificate_no FROM customers c LEFT JOIN certificates ce ON ce.customer_id=c.id ORDER BY c.name')->fetchAll();
$businessName=$profile['business_name'] ?: 'Ledger';
$businessLogoUrl=($profile['logo_file']??null)?'/media/logo':'/assets/ledger-wordmark-transparent.png';
$businessLogo=imageDataUri($profile['logo_file']??null,$profile['logo_mime']??null);
if($page==='document_pdf') {
    $q=$db->prepare('SELECT d.*,c.name customer,c.email,c.phone,c.address FROM documents d LEFT JOIN customers c ON c.id=d.customer_id WHERE d.id=?'); $q->execute([(int)($_GET['id']??0)]); $doc=$q->fetch();
    if(!$doc) { http_response_code(404); exit('Document not found.'); }
    $q=$db->prepare('SELECT * FROM document_items WHERE document_id=?'); $q->execute([$doc['id']]); $items=$q->fetchAll();
    $rows=''; foreach($items as $item) $rows.='<tr><td>'.h($item['description']).'</td><td>'.number_format((float)$item['quantity'],2).'</td><td>'.money($item['unit_price'],$doc['currency']).'</td><td>'.money($item['line_total'],$doc['currency']).'</td></tr>';
    $logo=$businessLogo?'<img class="logo" src="'.$businessLogo.'">':'<strong class="business">'.h($businessName).'</strong>';
    $html='<!doctype html><html><head><meta charset="utf-8"><style>body{font:12px DejaVu Sans;color:#18264b}.head{display:flex;justify-content:space-between;border-bottom:2px solid #7793ff;padding-bottom:18px}.logo{max-width:180px;max-height:64px}.business{font-size:22px}.kind{text-align:right;color:#516bdd}.kind b{display:block;font-size:22px}.parties{display:flex;justify-content:space-between;padding:26px 0}.parties div{width:48%}small{display:block;color:#66738c;font-size:9px;letter-spacing:1px}h3{margin:8px 0}table{width:100%;border-collapse:collapse;margin-top:12px}th{background:#eaf1ff;text-align:left;color:#253b85}th,td{padding:10px;border-bottom:1px solid #dce4f1}.summary{width:250px;margin:18px 0 0 auto}.summary div{display:flex;justify-content:space-between;padding:7px}.total{border-top:2px solid #7793ff;font-size:16px;font-weight:bold}.notes{margin-top:30px}.footer{margin-top:40px;text-align:center;color:#69758d;border-top:1px solid #dce4f1;padding-top:14px}</style></head><body><div class="head"><div>'.$logo.'<p>'.h($businessName).'</p></div><div class="kind"><b>'.strtoupper(h($doc['type'])).'</b>'.h($doc['number']).'</div></div><div class="parties"><div><small>BILL TO</small><h3>'.h($doc['customer']??'Customer').'</h3>'.h($doc['email']??'').'<br>'.h($doc['phone']??'').'<br>'.nl2br(h($doc['address']??'')).'</div><div><small>ISSUE DATE</small>'.date('M j, Y',strtotime($doc['issue_date'])).'<p><small>DUE DATE</small>'.($doc['due_date']?date('M j, Y',strtotime($doc['due_date'])):'—').'</p></div></div><table><thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>'.$rows.'</tbody></table><div class="summary"><div><span>Subtotal</span><b>'.money($doc['subtotal'],$doc['currency']).'</b></div><div><span>Tax ('.number_format((float)$doc['tax_rate'],2).'%)</span><b>'.money($doc['tax_amount'],$doc['currency']).'</b></div><div class="total"><span>Total</span><b>'.money($doc['total'],$doc['currency']).'</b></div><div><span>Paid</span><b>'.money($doc['paid'],$doc['currency']).'</b></div><div><span>Balance</span><b>'.money($doc['total']-$doc['paid'],$doc['currency']).'</b></div></div>'.($doc['notes']?'<div class="notes"><small>NOTES</small>'.nl2br(h($doc['notes'])).'</div>':'').'<div class="footer">Thank you for your business.</div></body></html>';
    renderPdf($html,$doc['number'].'.pdf');
}
if($page==='certificate_pdf') {
    $q=$db->prepare('SELECT ce.certificate_no,ce.issued_at,ce.customer_name,ce.business_name FROM certificates ce WHERE ce.customer_id=?'); $q->execute([(int)($_GET['id']??0)]); $certificate=$q->fetch();
    if(!$certificate) { http_response_code(404); exit('No customer certificate has been issued.'); }
    $logo=$businessLogo?'<img class="logo" src="'.$businessLogo.'">':'<strong>'.h($businessName).'</strong>';
    $html='<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:35px}body{font:14px DejaVu Sans;color:#101b45;text-align:center;border:5px double #7793ff;padding:44px 35px}.logo{max-width:180px;max-height:70px;margin-bottom:25px}.eyebrow{color:#e69a00;font-size:11px;letter-spacing:3px}h1{font-size:30px;margin:18px 0;color:#13204a}p{line-height:1.8;color:#53617d}.person{font-size:27px;color:#3f5fdb;font-weight:bold;margin:25px}.business{font-size:18px;font-weight:bold;color:#17264b}.number{margin-top:40px;border-top:1px solid #c9d5ef;padding-top:18px;font-size:11px;color:#5c6a84}</style></head><body>'.$logo.'<div class="eyebrow">CUSTOMER RECOGNITION</div><h1>Certificate of Customer Relationship</h1><p>This certificate confirms that</p><div class="person">'.h($certificate['customer_name']).'</div><p>is a valued customer of</p><div class="business">'.h($certificate['business_name']).'</div><p>Issued on '.date('F j, Y',strtotime($certificate['issued_at'])).'</p><div class="number">Certificate No. '.h($certificate['certificate_no']).'</div></body></html>';
    renderPdf($html,$certificate['certificate_no'].'.pdf');
}
if($page==='reports_pdf') {
    $summary=$db->query("SELECT COUNT(*) invoice_count,COALESCE(SUM(total),0) invoiced,COALESCE(SUM(paid),0) collected,COALESCE(SUM(total-paid),0) outstanding FROM documents WHERE type='invoice'")->fetch();
    $monthly=$db->query("SELECT DATE_FORMAT(MIN(issue_date),'%Y-%m') month,COUNT(*) count,SUM(total) amount FROM documents WHERE type='invoice' AND issue_date>=DATE_SUB(CURDATE(),INTERVAL 11 MONTH) GROUP BY YEAR(issue_date),MONTH(issue_date) ORDER BY MIN(issue_date)")->fetchAll();
    $rows=''; foreach($monthly as $row) $rows.='<tr><td>'.h(date('F Y',strtotime($row['month'].'-01'))).'</td><td>'.(int)$row['count'].'</td><td>'.money($row['amount'],$currency).'</td></tr>';
    $html='<!doctype html><html><head><meta charset="utf-8"><style>body{font:12px DejaVu Sans;color:#14214d}h1{font-size:24px}small{color:#6b7890}.cards{display:flex;gap:12px;margin:25px 0}.card{width:25%;padding:14px;background:#edf3ff;border:1px solid #dce6fa}.card b{display:block;font-size:17px;margin-top:8px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{text-align:left;padding:10px;border-bottom:1px solid #dce4f1}th{background:#eaf1ff}</style></head><body><small>'.h($businessName).' · SALES REPORT</small><h1>Invoice and collection summary</h1><p>Generated '.date('F j, Y').'</p><div class="cards"><div class="card">Invoices<b>'.(int)$summary['invoice_count'].'</b></div><div class="card">Total invoiced<b>'.money($summary['invoiced'],$currency).'</b></div><div class="card">Collected<b>'.money($summary['collected'],$currency).'</b></div><div class="card">Outstanding<b>'.money($summary['outstanding'],$currency).'</b></div></div><h2>Monthly sales · last 12 months</h2><table><thead><tr><th>Month</th><th>Invoices</th><th>Amount invoiced</th></tr></thead><tbody>'.$rows.'</tbody></table></body></html>';
    renderPdf($html,'ledger-sales-report-'.date('Y-m-d').'.pdf');
}
$pageTitle=['dashboard'=>'Overview','documents'=>($_GET['type']??'')==='quote'?'Quotes':(($_GET['type']??'')==='invoice'?'Invoices':'Invoices & quotes'),'customers'=>'Customers','reports'=>'Reports','new'=>'Create document','view'=>'Document','settings'=>'Business profile','queue'=>'WhatsApp Queue'][$page] ?? 'Overview';
if ($page === 'home') { ?><!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="icon" type="image/png" href="/assets/ledger-emblem-transparent.png"><link rel="apple-touch-icon" href="/assets/ledger-emblem-transparent.png"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Create professional sales invoices and quotes, manage customer payments, and keep your small business organized with Ledger."><title>Ledger — Invoicing that keeps business moving</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/style.css"></head><body class="marketing"><header class="site-nav"><a class="brand" href="/"><img class="brand-full" src="/assets/ledger-wordmark-transparent.png" alt="Ledger" width="164" height="40"></a><nav><a href="#features">Features</a><a href="#how-it-works">How it works</a></nav><div class="site-actions"><a class="login-link" href="#auth-card" data-auth-open="login">Sign in</a><a class="button primary" href="#auth-card" data-auth-open="register">Get started <span>→</span></a></div></header><main><section class="hero"><div class="hero-copy"><div class="hero-kicker"><span>✦</span> SIMPLE INVOICING FOR SMALL BUSINESS</div><h1>More time for<br>what you <em>do best.</em></h1><p class="hero-lead">Create polished invoices and quotes, keep track of payments, and spend less time chasing paperwork.</p><div class="hero-buttons"><a class="button primary" href="#auth-card" data-auth-open="register">Create your first invoice <span>→</span></a><a class="hero-secondary" href="#features">Explore features <span>↓</span></a></div><div class="hero-proof"><div class="proof-avatars"><span>J</span><span>M</span><span>A</span><span>+</span></div><span>Made for independent businesses<br>and the people behind them.</span></div></div><div class="hero-visual auth-visual"><div class="auth-halo"></div><div class="auth-card" id="auth-card"><div class="auth-card-top"><a class="auth-card-brand" href="/"><img class="brand-icon" src="/assets/ledger-emblem-transparent.png" alt=""> Ledger</a><span class="secure-tag">✦ &nbsp; YOUR BUSINESS, IN GOOD HANDS</span></div><section class="auth-card-welcome auth-card-view" id="auth-welcome"><div class="welcome-illustration"><div class="welcome-ring ring-a"></div><div class="welcome-ring ring-b"></div><div class="welcome-doc"><span>LEDGERLY</span><b>INVOICE</b><i></i><i></i><i></i><strong>✓</strong></div><div class="welcome-spark spark-a">✦</div><div class="welcome-spark spark-b">✧</div><div class="welcome-orb"></div></div><div class="auth-welcome-copy"><span class="auth-eyebrow">BILL WITH A LITTLE MORE EASE</span><h2>Your business,<br><em>beautifully in order.</em></h2><p>Quotes, invoices, and payments. All in one calm, clear workspace.</p></div><div class="welcome-actions"><button type="button" class="button primary" data-auth-open="login">Sign in <span>→</span></button><button type="button" class="button auth-outline" data-auth-open="register">Create your account</button></div><div class="auth-card-foot"><span>✓ &nbsp; Secure workspace</span><span>Free to get started</span></div></section><section class="auth-card-view auth-card-form" id="auth-login" hidden><button class="auth-back" type="button" data-auth-open="welcome">← Back</button><span class="auth-eyebrow">WELCOME BACK</span><h2>Good to see you.</h2><p>Sign in to keep your business moving.</p><div class="auth-tabs"><button type="button" class="selected" data-auth-open="login">Sign in</button><button type="button" data-auth-open="register">Create account</button></div><form method="post" action="/login"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="login"><label>Username or email<input name="username" autocomplete="username" placeholder="you@yourbusiness.com" required></label><label>Password<input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required></label><button class="button primary full">Sign in <span>→</span></button></form><div class="auth-card-foot"><span>Secure sign in</span><a href="/login">More sign-in options</a></div></section><section class="auth-card-view auth-card-form" id="auth-register" hidden><button class="auth-back" type="button" data-auth-open="welcome">← Back</button><span class="auth-eyebrow">A FRESH START</span><h2>Make it official.</h2><p>Create the owner account for your workspace.</p><?php if($ownerRegistered): ?><div class="auth-error">This workspace already has an owner account. Please sign in.</div><button type="button" class="button primary full" data-auth-open="login">Go to sign in <span>→</span></button><?php else: ?><div class="auth-tabs"><button type="button" data-auth-open="login">Sign in</button><button type="button" class="selected" data-auth-open="register">Create account</button></div><form method="post" enctype="multipart/form-data" action="/register"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="register"><label>Your name<input name="name" autocomplete="name" placeholder="Alex Morgan" required></label><label>Work email<input type="email" name="email" autocomplete="email" placeholder="alex@yourbusiness.com" required></label><label>Business name<input name="business_name" autocomplete="organization" placeholder="Your business name" required></label><div class="auth-input-row"><label>Business phone<input name="phone" autocomplete="tel" placeholder="+254 700 000 000"></label><label>Business address<input name="address" autocomplete="street-address" placeholder="Street, city"></label></div><label>Business logo (PNG or JPG)<input type="file" name="business_logo" accept="image/png,image/jpeg"></label><label>Verification document (PDF, PNG, or JPG)<input type="file" name="verification_document" accept="application/pdf,image/png,image/jpeg"></label><div class="auth-input-row"><label>Username<input name="username" autocomplete="username" minlength="3" placeholder="alexmorgan" required></label><label>Password<input type="password" name="password" autocomplete="new-password" minlength="8" placeholder="8+ characters" required></label></div><label>Confirm password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" placeholder="Enter password again" required></label><button class="button primary full">Create account <span>→</span></button></form><?php endif ?><div class="auth-card-foot"><span>One owner account per workspace</span><a href="/register">Open sign up page</a></div></section></div></div></section><section class="trust-strip"><span>EVERYDAY TOOLS FOR BETTER BUSINESS</span><div><b>Invoices</b><b>Quotes</b><b>Customers</b><b>Payments</b></div></section><section class="features-section" id="features"><div class="section-intro"><div class="section-kicker">THE BUSYWORK, HANDLED</div><h2>Everything you need<br>to get paid <em>with ease.</em></h2><p>Simple tools for the important parts of running a business. Clear, connected, and ready when you are.</p></div><div class="feature-grid"><article class="feature-card feature-primary"><span class="feature-icon">▤</span><h3>Invoices that look the part</h3><p>Build clear, professional invoices with your customer, services, tax, and due date all in one place.</p><div class="feature-mini"><span>INV-2026-0043</span><b>KES 56,000</b><small>✓ &nbsp; Ready to send</small></div></article><article class="feature-card"><span class="feature-icon lilac-bg">◇</span><h3>Quotes to move work forward</h3><p>Share an estimate, win the work, then turn an approved quote into an invoice in a click.</p><div class="quote-mini"><span>QUOTE</span><b>Website refresh</b><div><i></i><i></i><i></i></div><small>Convert to invoice <b>→</b></small></div></article><article class="feature-card"><span class="feature-icon green-bg">◷</span><h3>Know what's been paid</h3><p>See what's outstanding and record full or partial payments as they come in.</p><div class="payment-mini"><span>Payment progress</span><b>75% paid</b><i><u></u></i></div></article><article class="feature-card"><span class="feature-icon orange-bg">♙</span><h3>Customer details, organized</h3><p>Keep client contact and billing details close by, ready for the next invoice or quote.</p><div class="customer-mini"><span>BS</span><div><b>Brightside Studio</b><small>hello@brightside.co</small></div><i>•••</i></div></article></div></section><section class="how-section" id="how-it-works"><div class="how-copy"><div class="section-kicker">A BETTER WAY TO BILL</div><h2>From first hello<br>to <em>paid in full.</em></h2><p>Keep your sales paperwork moving without losing track of the details.</p><a class="button primary" href="#auth-card" data-auth-open="register">Start creating <span>→</span></a></div><div class="steps"><article><span>01</span><div><h3>Add your customer</h3><p>Save your client's contact and billing information once.</p></div><b>♙</b></article><article><span>02</span><div><h3>Build your document</h3><p>Add services, quantities, pricing, tax, and payment terms.</p></div><b>▤</b></article><article><span>03</span><div><h3>Track it to completion</h3><p>Print or save a PDF, record payments, and keep your books current.</p></div><b>✓</b></article></div></section><section class="cta-section"><img class="cta-mark" src="/assets/ledger-emblem-transparent.png" alt="Ledger"><div><div class="section-kicker">YOUR NEXT INVOICE STARTS HERE</div><h2>Make room for the work<br>you love doing.</h2></div><a class="button cta-button" href="#auth-card" data-auth-open="register">Get started <span>→</span></a><div class="cta-deco"></div></section></main><footer class="site-footer"><a class="brand" href="/"><img class="brand-full" src="/assets/ledger-wordmark-transparent.png" alt="Ledger" width="164" height="40"></a><span>Simple tools. Professional results.</span><span>© <?=date('Y')?> Ledger</span></footer><button class="scroll-top-button" id="scroll-to-top" type="button" aria-label="Scroll to top" title="Scroll to top"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 14 6-6 6 6M12 8v12"/></svg></button><script>document.querySelectorAll("[data-auth-open]").forEach(control=>control.addEventListener("click",event=>{const target=control.dataset.authOpen;if(control.tagName==="A")event.preventDefault();document.querySelectorAll(".auth-card-view").forEach(view=>view.hidden=true);const view=document.getElementById(target==="welcome"?"auth-welcome":"auth-"+target);if(view)view.hidden=false;document.getElementById("auth-card")?.scrollIntoView({behavior:"smooth",block:"center"});if(target!=="welcome")setTimeout(()=>view?.querySelector("input")?.focus({preventScroll:true}),180)}));const reduceMotion=window.matchMedia("(prefers-reduced-motion: reduce)").matches;const siteNav=document.querySelector(".site-nav");const topButton=document.getElementById("scroll-to-top");let chromeFrame=false;const updateChrome=()=>{siteNav?.classList.toggle("is-scrolled",window.scrollY>12);topButton?.classList.toggle("is-visible",window.scrollY>420);chromeFrame=false};window.addEventListener("scroll",()=>{if(!chromeFrame){chromeFrame=true;requestAnimationFrame(updateChrome)}},{passive:true});topButton?.addEventListener("click",()=>window.scrollTo({top:0,behavior:reduceMotion?"auto":"smooth"}));updateChrome();if(!reduceMotion&&"IntersectionObserver" in window){const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add("is-visible");observer.unobserve(entry.target)}}),{threshold:.14});document.querySelectorAll(".features-section .feature-card,.how-section,.cta-section").forEach((el,index)=>{el.classList.add("scroll-enter");el.style.transitionDelay=(index%4)*55+"ms";observer.observe(el)})}const authCard=document.getElementById("auth-card");if(authCard&&!reduceMotion){let scrollFrame=false;const updateDepth=()=>{const box=authCard.getBoundingClientRect();const distance=(box.top+box.height/2-window.innerHeight/2)/window.innerHeight;authCard.style.setProperty("--auth-scroll-y",(-distance*5)+"px");authCard.style.setProperty("--auth-scroll-x",(distance*1.4)+"deg");scrollFrame=false};window.addEventListener("scroll",()=>{if(!scrollFrame){scrollFrame=true;requestAnimationFrame(updateDepth)}},{passive:true});authCard.addEventListener("pointermove",event=>{if(!window.matchMedia("(hover:hover)").matches)return;const box=authCard.getBoundingClientRect();const x=(event.clientX-box.left)/box.width-.5;authCard.style.setProperty("--auth-pointer-y",(x*2.2)+"deg")});authCard.addEventListener("pointerleave",()=>authCard.style.setProperty("--auth-pointer-y","0deg"));updateDepth()}</script></body></html><?php exit; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="icon" type="image/png" href="/assets/ledger-emblem-transparent.png"><link rel="apple-touch-icon" href="/assets/ledger-emblem-transparent.png"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($pageTitle)?> · Ledger</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/style.css"></head><body>
<aside class="sidebar"><a class="brand" href="/dashboard"><img class="brand-full" src="<?=h($businessLogoUrl)?>" alt="<?=h($businessName)?>" width="164" height="40"></a><div class="nav-label">WORKSPACE</div><a class="nav <?= $page==='dashboard'?'active':'' ?>" href="/dashboard"><span>◫</span> Overview</a><a class="nav <?= $page==='documents' && ($_GET['type']??'')!=='quote'?'active':'' ?>" href="/invoices"><span>▤</span> Invoices</a><a class="nav <?= $page==='documents' && ($_GET['type']??'')==='quote'?'active':'' ?>" href="/quotes"><span>◇</span> Quotes</a><a class="nav <?= $page==='customers'?'active':'' ?>" href="/customers"><span>♙</span> Customers</a><a class="nav <?= $page==='reports'?'active':'' ?>" href="/reports"><span>▥</span> Reports</a><a class="nav <?= $page==='queue'?'active':'' ?>" href="/whatsapp-queue"><span>⇄</span> WhatsApp Queue</a><div class="nav-label spaced">PREFERENCES</div><a class="nav <?= $page==='settings'?'active':'' ?>" href="/settings"><span>⚙</span> Business settings</a><form method="post" class="logout-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="nav logout"><span>↪</span> Sign out</button></form><div class="sidebar-bottom"><div class="avatar">AC</div><div><b><?=h($businessName)?></b><small><?=h($_SESSION['account_name']??'Administrator')?></small></div><span class="dots">•••</span></div></aside>
<main class="main"><header class="topbar"><div><div class="eyebrow">YOUR BUSINESS / <?=h(strtoupper($pageTitle))?></div><h1><?=h($pageTitle)?></h1></div><div class="top-actions"><span class="today">◷ &nbsp; <?=date('D, M j, Y')?></span><a class="button primary" href="/new?type=invoice">＋ &nbsp; New invoice</a></div></header>
<?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>
<?php if($page==='dashboard'):
$stats=$db->query("SELECT COUNT(*) invoices,COALESCE(SUM(total-paid),0) outstanding,COALESCE(SUM(CASE WHEN MONTH(issue_date)=MONTH(CURDATE()) AND YEAR(issue_date)=YEAR(CURDATE()) THEN total ELSE 0 END),0) monthly FROM documents WHERE type='invoice'")->fetch();
$quoteCount=(int)$db->query("SELECT COUNT(*) FROM documents WHERE type='quote' AND status='sent'")->fetchColumn(); $recent=$db->query('SELECT d.*,c.name customer FROM documents d LEFT JOIN customers c ON c.id=d.customer_id ORDER BY d.created_at DESC LIMIT 6')->fetchAll(); ?>
<section class="welcome"><div><div class="welcome-tag">MONDAY MOTIVATION ✦</div><h2>Good business starts with<br>great relationships.</h2><p>Keep your sales moving. Your books are looking good.</p></div><div class="welcome-art"><div class="sun"></div><div class="plant">✳</div><div class="receipt">▤<small>INVOICE<br>PAID ✓</small></div></div></section>
<section class="stats"><article class="stat"><div class="stat-head">TOTAL INVOICES <span class="stat-icon blue">▤</span></div><strong><?=$stats['invoices']?></strong><small>All time</small></article><article class="stat"><div class="stat-head">OUTSTANDING <span class="stat-icon orange">◷</span></div><strong><?=money($stats['outstanding'],$currency)?></strong><small>Awaiting payment</small></article><article class="stat"><div class="stat-head">THIS MONTH <span class="stat-icon green">↗</span></div><strong><?=money($stats['monthly'],$currency)?></strong><small>Invoiced this month</small></article><article class="stat"><div class="stat-head">OPEN QUOTES <span class="stat-icon purple">⌑</span></div><strong><?=$quoteCount?></strong><small>Waiting for a response</small></article></section>
<section class="content-grid"><div class="panel"><div class="panel-head"><div><h3>Recent activity</h3><p>Your latest invoices and quotes</p></div><a class="text-link" href="/invoices">View all →</a></div><div class="table-wrap"><table><thead><tr><th>DOCUMENT</th><th>CUSTOMER</th><th>DATE</th><th>AMOUNT</th><th>STATUS</th></tr></thead><tbody><?php if(!$recent): ?><tr><td colspan="5" class="empty">No documents yet. Create your first invoice to get started.</td></tr><?php endif; foreach($recent as $d): ?><tr><td><a class="doc-link" href="/document/<?=$d['id']?>"><?=h($d['number'])?><small><?=ucfirst($d['type'])?></small></a></td><td><?=h($d['customer']??'No customer')?></td><td><?=date('M j, Y',strtotime($d['issue_date']))?></td><td class="amount"><?=money($d['total'],$d['currency'])?></td><td><span class="badge <?=h($d['status'])?>"><?=h(ucfirst($d['status']))?></span></td></tr><?php endforeach ?></tbody></table></div></div><div class="quick-panel"><h3>Quick actions</h3><p>What would you like to do today?</p><a class="quick-action" href="/new?type=invoice"><span class="qa-icon blue">＋</span><span><b>Create an invoice</b><small>Bill a customer for your work</small></span><i>→</i></a><a class="quick-action" href="/new?type=quote"><span class="qa-icon purple">◇</span><span><b>Send a quote</b><small>Share an estimate with a client</small></span><i>→</i></a><a class="quick-action" href="/customers"><span class="qa-icon green">♙</span><span><b>Add a customer</b><small>Grow your customer list</small></span><i>→</i></a><div class="tip"><span>✦</span><div><b>A little tip</b><p>Turn an approved quote into an invoice in just one click.</p></div></div></div></section>
<?php elseif($page==='customers'): ?><div class="content-grid customers-grid"><div class="panel"><div class="panel-head"><div><h3>Your customers</h3><p><?=count($customers)?> saved customers</p></div></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>PHONE</th><th>ADDED</th><th>CERTIFICATE</th></tr></thead><tbody><?php if(!$customers): ?><tr><td colspan="5" class="empty">No customers yet. Add your first customer using the form.</td></tr><?php endif; foreach($customers as $c): ?><tr><td class="customer-name"><span class="avatar small-avatar"><?=h(strtoupper(substr($c['name'],0,1)))?></span><?=h($c['name'])?></td><td><?=h($c['email'])?></td><td><?=h($c['phone'])?></td><td><?=date('M j, Y',strtotime($c['created_at']))?></td><td><?php if($c['certificate_no']): ?><a class="text-link" href="/certificate/<?=$c['id']?>/pdf">Download</a><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="issue_certificate"><input type="hidden" name="customer_id" value="<?=$c['id']?>"><button class="button light certificate-button">Issue certificate</button></form><?php endif ?></td></tr><?php endforeach ?></tbody></table></div></div><div class="panel form-panel"><h3>Add a customer</h3><p>Keep client details ready for your next invoice.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="customer"><label>Customer name<input name="name" required placeholder="e.g. Acme Studio"></label><label>Email address<input type="email" name="email" placeholder="hello@example.com"></label><label>Phone number<input name="phone" placeholder="+254 700 000 000"></label><label>Billing address<textarea name="address" rows="3" placeholder="Street, city, country"></textarea></label><button class="button primary full">Save customer</button></form></div></div>
<?php elseif($page==='documents'): $typeFilter=in_array($_GET['type']??'', ['invoice','quote'], true)?$_GET['type']:null; $q=$db->prepare('SELECT d.*,c.name customer FROM documents d LEFT JOIN customers c ON c.id=d.customer_id WHERE (? IS NULL OR d.type=?) ORDER BY d.created_at DESC'); $q->execute([$typeFilter,$typeFilter]); $docs=$q->fetchAll(); ?><div class="panel"><div class="panel-head"><div><h3><?=h($pageTitle)?></h3><p>Manage invoices and customer quotes</p></div><div class="inline-actions"><a class="button light" href="/new?type=quote">＋ New quote</a><a class="button primary" href="/new?type=invoice">＋ New invoice</a></div></div><div class="table-wrap"><table><thead><tr><th>DOCUMENT</th><th>CUSTOMER</th><th>ISSUE DATE</th><th>DUE DATE</th><th>TOTAL</th><th>STATUS</th></tr></thead><tbody><?php if(!$docs): ?><tr><td colspan="6" class="empty">No documents created yet.</td></tr><?php endif; foreach($docs as $d): ?><tr><td><a class="doc-link" href="/document/<?=$d['id']?>"><?=h($d['number'])?><small><?=ucfirst($d['type'])?></small></a></td><td><?=h($d['customer']??'No customer')?></td><td><?=date('M j, Y',strtotime($d['issue_date']))?></td><td><?=$d['due_date']?date('M j, Y',strtotime($d['due_date'])):'—'?></td><td class="amount"><?=money($d['total'],$d['currency'])?></td><td><span class="badge <?=h($d['status'])?>"><?=h(ucfirst($d['status']))?></span></td></tr><?php endforeach ?></tbody></table></div></div>
<?php elseif($page==='reports'): $report=$db->query("SELECT COUNT(*) invoice_count,COALESCE(SUM(total),0) invoiced,COALESCE(SUM(paid),0) collected,COALESCE(SUM(total-paid),0) outstanding FROM documents WHERE type='invoice'")->fetch(); $monthly=$db->query("SELECT DATE_FORMAT(MIN(issue_date),'%Y-%m') month,COUNT(*) count,SUM(total) amount FROM documents WHERE type='invoice' AND issue_date>=DATE_SUB(CURDATE(),INTERVAL 11 MONTH) GROUP BY YEAR(issue_date),MONTH(issue_date) ORDER BY MIN(issue_date)")->fetchAll(); ?><section class="stats"><article class="stat"><div class="stat-head">INVOICES</div><strong><?=$report['invoice_count']?></strong><small>All time</small></article><article class="stat"><div class="stat-head">TOTAL INVOICED</div><strong><?=money($report['invoiced'],$currency)?></strong><small>Sales value</small></article><article class="stat"><div class="stat-head">COLLECTED</div><strong><?=money($report['collected'],$currency)?></strong><small>Payments received</small></article><article class="stat"><div class="stat-head">OUTSTANDING</div><strong><?=money($report['outstanding'],$currency)?></strong><small>Remaining balance</small></article></section><div class="panel"><div class="panel-head"><div><h3>Monthly sales</h3><p>Invoice totals for the last 12 months with activity</p></div><a class="button primary" href="/reports/pdf">Download PDF report</a></div><div class="table-wrap"><table><thead><tr><th>MONTH</th><th>INVOICES</th><th>AMOUNT INVOICED</th></tr></thead><tbody><?php if(!$monthly): ?><tr><td class="empty" colspan="3">No sales recorded yet.</td></tr><?php endif; foreach($monthly as $m): ?><tr><td><?=date('F Y',strtotime($m['month'].'-01'))?></td><td><?=$m['count']?></td><td class="amount"><?=money($m['amount'],$currency)?></td></tr><?php endforeach ?></tbody></table></div></div>
<?php elseif($page==='new'): $type=($_GET['type']??'invoice')==='quote'?'quote':'invoice'; ?><div class="panel form-panel document-form"><div class="panel-head"><div><h3>New <?=h($type)?></h3><p>Add your customer and line items to prepare a <?=h($type)?>.</p></div></div><form method="post" id="doc-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="document"><input type="hidden" name="type" value="<?=h($type)?>"><div class="form-row"><label>Customer<select name="customer_id" required><option value="">Choose customer</option><?php foreach($customers as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach ?></select></label><label>Issue date<input type="date" name="issue_date" value="<?=date('Y-m-d')?>" required></label><label>Due date<input type="date" name="due_date" value="<?=date('Y-m-d',strtotime('+30 days'))?>"></label></div><div class="line-head"><h4>Items & services</h4><button type="button" class="button light" onclick="addLine()">＋ Add line</button></div><div class="table-wrap"><table class="items-table"><thead><tr><th>DESCRIPTION</th><th>QTY</th><th>UNIT PRICE (<?=$currency?>)</th><th>AMOUNT</th><th></th></tr></thead><tbody id="items"><tr><td><input name="description[]" required placeholder="e.g. Design services"></td><td><input name="quantity[]" type="number" min="0.01" step="0.01" value="1" oninput="calc()"></td><td><input name="price[]" type="number" min="0" step="0.01" value="0" oninput="calc()"></td><td class="amount line-total">0.00</td><td><button type="button" class="remove" onclick="removeLine(this)">×</button></td></tr></tbody></table></div><div class="form-bottom"><label>Notes<textarea name="notes" rows="4" placeholder="Payment terms, thank you note or other details"></textarea></label><div class="totals"><label>Tax rate (%)<input name="tax_rate" type="number" min="0" step="0.01" value="0" oninput="calc()"></label><div><span>Subtotal</span><b id="subtotal"><?=h($currency)?> 0.00</b></div><div><span>Tax</span><b id="tax"><?=h($currency)?> 0.00</b></div><div class="total-line"><span>Total</span><b id="total"><?=h($currency)?> 0.00</b></div></div></div><div class="form-actions"><a class="button light" href="/invoices">Cancel</a><button class="button primary">Create <?=h($type)?></button></div></form></div>
<?php elseif($page==='view'): $q=$db->prepare('SELECT d.*,c.name customer,c.email,c.phone,c.address FROM documents d LEFT JOIN customers c ON c.id=d.customer_id WHERE d.id=?');$q->execute([(int)($_GET['id']??0)]);$doc=$q->fetch(); if(!$doc): ?><div class="panel empty">Document not found.</div><?php else: $q=$db->prepare('SELECT * FROM document_items WHERE document_id=?');$q->execute([$doc['id']]);$items=$q->fetchAll(); ?><div class="document-toolbar"><a class="button light" href="/invoices">← Back to documents</a><div class="inline-actions"><?php if($doc['type']==='quote'): ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?=$doc['id']?>"><button class="button primary">Convert to invoice</button></form><?php elseif($doc['status']!=='paid'): ?><form method="post" class="payment-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?=$doc['id']?>"><input name="amount" type="number" min="0.01" step="0.01" max="<?=h($doc['total']-$doc['paid'])?>" placeholder="Payment amount" required><button class="button primary">Record payment</button></form><?php endif ?><a class="button light" href="/document/<?=$doc['id']?>/pdf">↓ Download PDF</a><button class="button light" onclick="window.print()">Print</button><a class="button whatsapp" href="#whatsapp-send">Send via WhatsApp</a></div></div><div class="invoice-paper"><div class="paper-top"><div><div class="paper-brand"><?php if($profile['logo_file']): ?><img class="paper-business-logo" src="/media/logo" alt="<?=h($businessName)?> logo"><?php else: ?><img class="brand-icon" src="/assets/ledger-emblem-transparent.png" alt=""> <?=h($businessName)?><?php endif ?></div><p><?=h($profile['contact_email']?:$profile['phone'])?></p></div><div class="paper-type"><h2><?=strtoupper(h($doc['type']))?></h2><b><?=h($doc['number'])?></b></div></div><div class="paper-parties"><div><small>BILL TO</small><h3><?=h($doc['customer']??'Customer')?></h3><p><?=h($doc['email']??'')?><?= $doc['phone']?' · '.h($doc['phone']):'' ?><br><?=nl2br(h($doc['address']??''))?></p></div><div><div><small>ISSUE DATE</small><b><?=date('M j, Y',strtotime($doc['issue_date']))?></b></div><div><small>DUE DATE</small><b><?=$doc['due_date']?date('M j, Y',strtotime($doc['due_date'])):'—'?></b></div><div><small>STATUS</small><span class="badge <?=h($doc['status'])?>"><?=h(ucfirst($doc['status']))?></span></div></div></div><table class="paper-table"><thead><tr><th>DESCRIPTION</th><th>QTY</th><th>UNIT PRICE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($items as $it): ?><tr><td><?=h($it['description'])?></td><td><?=number_format((float)$it['quantity'],2)?></td><td><?=money($it['unit_price'],$doc['currency'])?></td><td><?=money($it['line_total'],$doc['currency'])?></td></tr><?php endforeach ?></tbody></table><div class="paper-summary"><div><span>Subtotal</span><b><?=money($doc['subtotal'],$doc['currency'])?></b></div><div><span>Tax (<?=number_format((float)$doc['tax_rate'],2)?>%)</span><b><?=money($doc['tax_amount'],$doc['currency'])?></b></div><div class="total-line"><span>Total</span><b><?=money($doc['total'],$doc['currency'])?></b></div><?php if($doc['paid']>0): ?><div><span>Paid</span><b><?=money($doc['paid'],$doc['currency'])?></b></div><div><span>Balance due</span><b><?=money($doc['total']-$doc['paid'],$doc['currency'])?></b></div><?php endif ?></div><?php if($doc['notes']): ?><div class="paper-notes"><small>NOTES</small><p><?=nl2br(h($doc['notes']))?></p></div><?php endif ?><div class="paper-footer">Thank you for your business.</div></div>
<div class="whatsapp-card" id="whatsapp-send">
<div class="whatsapp-card-head">
    <h4>WhatsApp Automated Dispatch <span class="whatsapp-tag">Cerebro API</span></h4>
    <div>Status: <span class="badge <?=h($doc['whatsapp_status']?:'unsent')?>"><?=ucfirst(h($doc['whatsapp_status']?:'unsent'))?></span></div>
</div>
<form method="post">
    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
    <input type="hidden" name="action" value="send_whatsapp">
    <input type="hidden" name="document_id" value="<?=$doc['id']?>">
    <div class="form-row">
        <label>Customer recipient phone number (format: 254XXXXXXXXX)
            <input name="recipient" value="<?=h($doc['phone']??'')?>" placeholder="e.g. 254712345678" required>
        </label>
        <label>Dispatch mode
            <select name="send_mode" onchange="document.getElementById('schedule-box').style.display = this.value === 'schedule' ? 'block' : 'none'">
                <option value="immediate">Send Immediately (Via Cerebro Queue)</option>
                <option value="queue">Enqueue (FIFO Invoice Queue)</option>
                <option value="schedule">Schedule for Later</option>
            </select>
        </label>
    </div>
    <div id="schedule-box" style="display:none; margin-bottom: 12px;">
        <label>Schedule date & time (future datetime)
            <input type="datetime-local" name="scheduled_at" value="<?=date('Y-m-d\TH:i', strtotime('+1 day'))?>">
        </label>
    </div>
    <label>Message content (customizable)
        <textarea name="message" rows="4"><?=h(compileWhatsAppMessage($profile['whatsapp_template']??null, $doc, $profile))?></textarea>
    </label>
    <div class="template-hints">Placeholders: <code>{customer_name}</code>, <code>{number}</code>, <code>{type}</code>, <code>{currency}</code>, <code>{total}</code>, <code>{balance}</code>, <code>{due_date}</code>, <code>{business_name}</code></div>
    <div style="margin-top:14px; display:flex; gap:10px;">
        <button class="button whatsapp">Send / Queue WhatsApp</button>
    </div>
</form>
</div>
<?php endif ?>
<?php elseif($page==='settings'): ?><div class="panel form-panel profile-panel"><div class="panel-head"><div><h3>Business profile</h3><p>These details and your logo appear on invoices and customer certificates.</p></div></div><?php if($profile['logo_file']): ?><div class="profile-logo-preview"><img src="/media/logo" alt="Business logo"></div><?php endif ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="profile"><label>Business name<input name="business_name" value="<?=h($profile['business_name'])?>" required></label><div class="form-row"><label>Contact email<input type="email" name="contact_email" value="<?=h($profile['contact_email'])?>"></label><label>Business phone<input name="phone" value="<?=h($profile['phone'])?>"></label></div><label>Business address<textarea name="address" rows="3"><?=h($profile['address'])?></textarea></label><label>Update business logo <small>PNG or JPG · up to 5 MB</small><input type="file" name="business_logo" accept="image/png,image/jpeg"></label><div class="verification-state"><b>Verification document</b><span class="badge <?= $profile['verification_status']==='submitted'?'sent':'draft' ?>"><?=h(ucfirst(str_replace('_',' ',$profile['verification_status'])))?></span><?php if($profile['verification_file']): ?><a href="/media/verification">Download current document<?= $profile['verification_name']?' · '.h($profile['verification_name']):'' ?></a><?php endif ?></div><label>Upload or replace verification document <small>PDF, PNG, or JPG · up to 15 MB</small><input type="file" name="verification_document" accept="application/pdf,image/png,image/jpeg"></label><button class="button primary">Save business profile</button></form><p class="settings-currency">Documents use <?=h($currency)?>. Set <code>APP_CURRENCY</code> in `.env` to change it.</p></div>

<div class="panel form-panel profile-panel" style="margin-top:24px;">
<div class="panel-head">
    <div>
        <h3>WhatsApp Integration & Automated Dispatch</h3>
        <p>Connect with your production Cerebro WhatsApp engine (Baileys) to send automated, scheduled, and FIFO-queued invoices.</p>
    </div>
</div>
<form method="post">
    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
    <input type="hidden" name="action" value="whatsapp_settings">
    <div class="form-row">
        <label>Cerebro API Base URL
            <input name="whatsapp_api_url" value="<?=h($profile['whatsapp_api_url']?:'https://wa.tukonectdigital.co.ke/api/v1')?>" placeholder="https://wa.tukonectdigital.co.ke/api/v1" required>
        </label>
        <label>Developer API Key (X-API-Key)
            <input name="whatsapp_api_key" type="password" value="<?=h($profile['whatsapp_api_key']??'')?>" placeholder="wak_xxxxxxxxxxxx">
        </label>
    </div>
    <div class="form-row" style="margin-top:10px;">
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="whatsapp_enabled" value="1" <?=!empty($profile['whatsapp_enabled'])?'checked':''?>>
            Enable WhatsApp messaging feature
        </label>
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="whatsapp_auto_queue" value="1" <?=!empty($profile['whatsapp_auto_queue'])?'checked':''?>>
            Automatically enqueue invoices to FIFO queue on creation
        </label>
    </div>
    <label style="margin-top:12px;">Default WhatsApp Invoice Message Template
        <textarea name="whatsapp_template" rows="4" placeholder="Leave blank for standard default template"><?=h($profile['whatsapp_template']??'')?></textarea>
    </label>
    <div class="template-hints">Available tags: <code>{customer_name}</code>, <code>{number}</code>, <code>{type}</code>, <code>{currency}</code>, <code>{total}</code>, <code>{subtotal}</code>, <code>{balance}</code>, <code>{due_date}</code>, <code>{business_name}</code></div>
    <button class="button primary" style="margin-top:16px;">Save WhatsApp Settings</button>
</form>
</div>

<?php elseif($page==='queue'):
$counts = $db->query("SELECT 
    COUNT(*) total,
    SUM(status='queued') queued,
    SUM(status='scheduled') scheduled,
    SUM(status='sent') sent,
    SUM(status='failed') failed
    FROM whatsapp_queue")->fetch();
$items = $db->query("SELECT q.*, d.number doc_number, d.type doc_type FROM whatsapp_queue q LEFT JOIN documents d ON d.id=q.document_id ORDER BY q.id DESC LIMIT 100")->fetchAll();
?>
<section class="stats">
    <article class="stat"><div class="stat-head">QUEUED (FIFO) <span class="stat-icon blue">▤</span></div><strong><?=(int)$counts['queued']?></strong><small>Pending dispatch</small></article>
    <article class="stat"><div class="stat-head">SCHEDULED <span class="stat-icon purple">◷</span></div><strong><?=(int)$counts['scheduled']?></strong><small>Future delivery</small></article>
    <article class="stat"><div class="stat-head">SENT <span class="stat-icon green">✓</span></div><strong><?=(int)$counts['sent']?></strong><small>Successfully delivered</small></article>
    <article class="stat"><div class="stat-head">FAILED <span class="stat-icon orange">!</span></div><strong><?=(int)$counts['failed']?></strong><small>Delivery errors</small></article>
</section>
<div class="panel">
    <div class="panel-head">
        <div>
            <h3>FIFO WhatsApp Message Queue</h3>
            <p>Messages are dispatched in sequential First-In-First-Out order with automated delay spacing.</p>
        </div>
        <div class="inline-actions">
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                <input type="hidden" name="action" value="process_queue">
                <button class="button primary">Process Queue Now (FIFO)</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>QUEUE ID</th>
                    <th>DOCUMENT</th>
                    <th>CUSTOMER</th>
                    <th>RECIPIENT</th>
                    <th>MESSAGE PREVIEW</th>
                    <th>SCHEDULED</th>
                    <th>STATUS</th>
                    <th>CEREBRO ID / ERROR</th>
                    <th>DATE</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$items): ?>
                    <tr><td colspan="9" class="empty">No messages in queue. Invoices can be dispatched or queued from their document view.</td></tr>
                <?php endif; foreach($items as $it): ?>
                    <tr>
                        <td>#<?=$it['id']?></td>
                        <td>
                            <?php if($it['doc_number']): ?>
                                <a class="doc-link" href="/document/<?=$it['document_id']?>"><?=h($it['doc_number'])?><small><?=ucfirst($it['doc_type'])?></small></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?=h($it['customer_name'])?></td>
                        <td><b><?=h($it['recipient'])?></b></td>
                        <td style="max-width:240px; font-size:12px; color:#475569;"><?=h(mb_strimwidth($it['message'], 0, 75, '...'))?></td>
                        <td><?=$it['scheduled_at'] ? date('M j, Y H:i', strtotime($it['scheduled_at'])) : '<span style="color:#94a3b8;">Immediate</span>'?></td>
                        <td><span class="badge <?=h($it['status'])?>"><?=ucfirst(h($it['status']))?></span></td>
                        <td>
                            <?php if($it['cerebro_id']): ?>
                                <code><?=h($it['cerebro_id'])?></code>
                            <?php elseif($it['error_message']): ?>
                                <small style="color:#ef4444;"><?=h(mb_strimwidth($it['error_message'], 0, 50, '...'))?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?=date('M j, H:i', strtotime($it['created_at']))?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?><div class="panel empty">Page not found.</div><?php endif ?><footer class="footer">Made for the people building their business. &nbsp;·&nbsp; Ledger</footer></main>
<script>function addLine(){const b=document.querySelector('#items');const r=b.rows[0].cloneNode(true);r.querySelectorAll('input').forEach(i=>{if(i.name==='description[]')i.value='';else if(i.name==='quantity[]')i.value='1';else i.value='0'});b.appendChild(r)}function removeLine(btn){const b=document.querySelector('#items');if(b.rows.length>1)btn.closest('tr').remove();else btn.closest('tr').querySelectorAll('input').forEach(i=>i.value=i.name==='quantity[]'?'1':i.name==='price[]'?'0':'');calc()}function calc(){let s=0;document.querySelectorAll('#items tr').forEach(r=>{const q=parseFloat(r.querySelector('[name="quantity[]"]').value)||0,p=parseFloat(r.querySelector('[name="price[]"]').value)||0,t=q*p;s+=t;r.querySelector('.line-total').textContent=t.toFixed(2)});const tax=s*(parseFloat(document.querySelector('[name="tax_rate"]')?.value)||0)/100;const c=<?=json_encode($currency)?>;document.querySelector('#subtotal').textContent=c+' '+s.toFixed(2);document.querySelector('#tax').textContent=c+' '+tax.toFixed(2);document.querySelector('#total').textContent=c+' '+(s+tax).toFixed(2)}calc();</script></body></html>
