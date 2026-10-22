<?php
// Recibe el formulario de contacto del sitio, guarda cada solicitud en un CSV
// privado (fuera de public_html) y envía un aviso por correo.
//
// Configuración opcional en ../../mansen-private/config.php (ver DESPLIEGUE.md).

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const RATE_LIMIT_MAX = 5;
const RATE_LIMIT_WINDOW = 600; // segundos

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Quita caracteres de control (en el mensaje se conservan los saltos de línea) y recorta.
function clean($value, int $max, bool $multiline = false): string
{
    if (!is_string($value)) {
        return '';
    }
    $pattern = $multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u';
    $value = preg_replace($pattern, '', str_replace("\r\n", "\n", $value)) ?? '';

    return mb_substr(trim($value), 0, $max);
}

// Límite por IP para que nadie llene el buzón con envíos automáticos.
function tooManyRequests(string $dir, string $ip): bool
{
    $handle = @fopen($dir . '/rate-limit.json', 'c+');
    if ($handle === false) {
        return false;
    }
    flock($handle, LOCK_EX);

    $now = time();
    $hits = json_decode((string) stream_get_contents($handle), true);
    $hits = is_array($hits) ? $hits : [];
    foreach ($hits as $key => $times) {
        $hits[$key] = array_values(array_filter((array) $times, fn ($t) => $t > $now - RATE_LIMIT_WINDOW));
        if (!$hits[$key]) {
            unset($hits[$key]);
        }
    }

    $key = hash('sha256', $ip);
    $limited = count($hits[$key] ?? []) >= RATE_LIMIT_MAX;
    if (!$limited) {
        $hits[$key][] = $now;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($hits));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $limited;
}

// Evita que Excel interprete como fórmula un valor que empiece por = + - @.
function csvSafe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// Carpeta privada junto a public_html: ahí viven config.php y el CSV de solicitudes.
$privateDir = dirname(__DIR__, 2) . '/mansen-private';
if (!is_dir($privateDir)) {
    @mkdir($privateDir, 0750, true);
}

$config = [
    'to' => 'gerencia@mansenlc.com.co',
    'from_name' => 'Sitio web Mansen Liquor Co',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => '',
    'smtp_pass' => '',
];
if (is_file($privateDir . '/config.php')) {
    $config = array_merge($config, (array) require $privateDir . '/config.php');
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'error' => 'invalid_request']);
}

// Campo trampa: las personas no lo ven; si llega con contenido lo envió un bot.
if (!empty($data['website'])) {
    respond(200, ['ok' => true]);
}

$submission = [
    'name' => clean($data['name'] ?? '', 120),
    'email' => clean($data['email'] ?? '', 254),
    'phone' => clean($data['phone'] ?? '', 40),
    'company' => clean($data['company'] ?? '', 120),
    'service' => clean($data['service'] ?? '', 120),
    'message' => clean($data['message'] ?? '', 2000, true),
    'locale' => clean($data['locale'] ?? '', 5),
];

$errors = [];
if (mb_strlen($submission['name']) < 2) {
    $errors[] = 'name';
}
if (!filter_var($submission['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if (mb_strlen($submission['message']) < 10) {
    $errors[] = 'message';
}
if (($data['consent'] ?? false) !== true) {
    $errors[] = 'consent';
}
if ($errors) {
    respond(422, ['ok' => false, 'error' => 'validation', 'fields' => $errors]);
}

if (tooManyRequests($privateDir, $_SERVER['REMOTE_ADDR'] ?? '')) {
    respond(429, ['ok' => false, 'error' => 'too_many_requests']);
}

$receivedAt = date('Y-m-d H:i:s');
$language = $submission['locale'] === 'en' ? 'Inglés' : 'Español';

// 1. Guardar en el CSV (separado por punto y coma para que Excel en español lo abra en columnas).
$saved = false;
$csvFile = $privateDir . '/solicitudes.csv';
$isNewFile = !is_file($csvFile);
$handle = @fopen($csvFile, 'a');
if ($handle !== false) {
    flock($handle, LOCK_EX);
    if ($isNewFile) {
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Fecha', 'Nombre', 'Email', 'Teléfono', 'Empresa', 'Servicio', 'Mensaje', 'Idioma', 'Autorizó tratamiento de datos'], ';', '"', '');
    }
    $row = array_map('csvSafe', [
        $receivedAt,
        $submission['name'],
        $submission['email'],
        $submission['phone'],
        $submission['company'],
        $submission['service'],
        $submission['message'],
        $language,
        'Sí',
    ]);
    $saved = fputcsv($handle, $row, ';', '"', '') !== false;
    flock($handle, LOCK_UN);
    fclose($handle);
} else {
    error_log('contact.php: no se pudo abrir ' . $csvFile);
}

// 2. Avisar por correo. Con SMTP configurado usa la cuenta de Hostinger; si no, mail() de PHP.
$sent = false;
try {
    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;

    $useSmtp = $config['smtp_user'] !== '' && $config['smtp_pass'] !== '';
    if ($useSmtp) {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->Port = (int) $config['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
    }

    $mail->setFrom($useSmtp ? $config['smtp_user'] : 'no-reply@mansenliquor.com', $config['from_name']);
    foreach (array_filter(array_map('trim', explode(',', $config['to']))) as $recipient) {
        $mail->addAddress($recipient);
    }
    $mail->addReplyTo($submission['email'], $submission['name']);

    $mail->Subject = 'Nueva solicitud de cotización: ' . $submission['name'];
    $mail->Body = implode("\n", [
        'Nueva solicitud de cotización desde mansenliquor.com',
        '',
        'Fecha: ' . $receivedAt,
        'Nombre: ' . $submission['name'],
        'Email: ' . $submission['email'],
        'Teléfono: ' . ($submission['phone'] !== '' ? $submission['phone'] : '—'),
        'Empresa: ' . ($submission['company'] !== '' ? $submission['company'] : '—'),
        'Servicio: ' . ($submission['service'] !== '' ? $submission['service'] : '—'),
        'Idioma del sitio: ' . $language,
        '',
        'Mensaje:',
        $submission['message'],
        '',
        '—',
        'Responde a este correo para contestarle directamente al cliente.',
    ]);

    $sent = $mail->send();
} catch (Throwable $e) {
    error_log('contact.php: no se pudo enviar el correo: ' . $e->getMessage());
}

if (!$saved && !$sent) {
    respond(500, ['ok' => false, 'error' => 'not_delivered']);
}

respond(200, ['ok' => true]);
