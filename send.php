<?php
/**
 * Приём заявок с сайта buh-online32.ru.
 * Письмо уходит с ящика на своём домене (иначе Яндекс отбивает чужой From),
 * плюс дублируется в лог — чтобы заявка не потерялась, если почта сбойнёт.
 *
 * Синтаксис намеренно совместим со старыми версиями PHP (5.x):
 * на хостинге может стоять что угодно, кампании Директа этого ждать не должны.
 */

header('Content-Type: application/json; charset=utf-8');

// Адреса лежат в config.php рядом с этим файлом: он не попадает в публичный
// репозиторий, чтобы личный ящик клиента не индексировался поисковиками.
// Образец — config.sample.php.
$cfg = array();
if (file_exists(dirname(__FILE__) . '/config.php')) {
    $cfg = include dirname(__FILE__) . '/config.php';
    if (!is_array($cfg)) { $cfg = array(); }
}
define('MAIL_FROM', isset($cfg['mail_from']) ? $cfg['mail_from'] : 'site@buh-online32.ru');
define('MAIL_TO',   isset($cfg['mail_to'])   ? $cfg['mail_to']   : '');
// лог кладём ВЫШЕ public_html: на Beget статику отдаёт nginx мимо Apache,
// поэтому .htaccess файл в публичной папке не защитит (там персональные данные)
define('LOG_FILE',  dirname(dirname(__FILE__)) . '/leads.log');

function reply($ok, $msg, $code) {
    if (!$code) { $code = 200; }
    if (function_exists('http_response_code')) { http_response_code($code); }
    echo json_encode(array('ok' => (bool)$ok, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

function val($arr, $key) {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : '';
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    reply(false, 'Метод не поддерживается', 405);
}

// данные приходят JSON-ом от fetch
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

// --- антиспам ---
// honeypot: поле скрыто от человека, боты его заполняют
if (val($data, 'company') !== '') {
    reply(true, 'Заявка отправлена', 200);   // тихо принимаем, но не шлём
}
// форму нельзя отправить быстрее 3 секунд после загрузки страницы
$elapsed = isset($data['elapsed']) ? (int)$data['elapsed'] : 999;
if ($elapsed < 3) {
    reply(true, 'Заявка отправлена', 200);
}

// --- валидация ---
$name   = val($data, 'name');
$phone  = val($data, 'phone');
$digits = preg_replace('/\D/', '', $phone);

if (mb_strlen($name, 'UTF-8') < 2) { reply(false, 'Укажите имя', 200); }
if (strlen($digits) < 10)          { reply(false, 'Укажите телефон', 200); }

$formName = val($data, 'form');
if ($formName === '') { $formName = 'Форма'; }
$topic   = val($data, 'topic');
$comment = val($data, 'comment');
$details = val($data, 'details');   // состав работ из калькулятора
$page    = val($data, 'page');

// --- письмо ---
$sep   = str_repeat('-', 34);
$lines = array();
$lines[] = 'Новая заявка с сайта buh-online32.ru';
$lines[] = $sep;
$lines[] = 'Имя:      ' . $name;
$lines[] = 'Телефон:  ' . $phone;
if ($topic   !== '') { $lines[] = 'Запрос:   ' . $topic; }
if ($comment !== '') { $lines[] = "\nКомментарий клиента:\n" . $comment; }
if ($details !== '') { $lines[] = "\nСобрано в калькуляторе:\n" . $details; }
$lines[] = $sep;
$lines[] = 'Форма:    ' . $formName;
if ($page !== '') { $lines[] = 'Страница: ' . $page; }
$lines[] = 'Время:    ' . date('d.m.Y H:i');

$body = implode("\n", $lines);

$subject = 'Заявка с сайта: ' . $name . ' - ' . $phone;
$encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$fromNm  = '=?UTF-8?B?' . base64_encode('Сайт buh-online32.ru') . '?=';

$headers  = 'From: ' . $fromNm . ' <' . MAIL_FROM . '>' . "\r\n";
$headers .= 'Reply-To: ' . MAIL_FROM . "\r\n";
$headers .= 'MIME-Version: 1.0' . "\r\n";
$headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
$headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
$headers .= 'X-Mailer: buh-online32-site' . "\r\n";

// без config.php адресата нет — заявку всё равно сохраняем, но помечаем причину
$sent = MAIL_TO ? @mail(MAIL_TO, $encSubj, $body, $headers, '-f' . MAIL_FROM) : false;
$status = MAIL_TO ? ($sent ? 'SENT' : 'MAIL_FAIL') : 'NO_CONFIG';

// пишем в лог всегда — страховка на случай проблем с доставкой
$logLine = date('Y-m-d H:i:s') . ' | ' . $status . ' | '
         . str_replace(array("\r", "\n"), ' / ', $body) . "\n";
@file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

// клиенту всегда отвечаем успехом: заявка уже в логе, даже если почта отвалилась
reply(true, 'Заявка отправлена', 200);
