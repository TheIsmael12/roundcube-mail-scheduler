<?php
/**
 * Prueba de la capa de datos contra una base real (MySQL/MariaDB o PostgreSQL)
 * con el esquema de Roundcube y el del plugin ya cargados:
 *
 *   ROUNDCUBE_PATH=/ruta/roundcube php tests/db.php "pgsql://usuario:clave@host/bd"
 */

$rc_path = getenv('ROUNDCUBE_PATH') ?: dirname(__DIR__, 3);
define('INSTALL_PATH', rtrim($rc_path, '/') . '/');
require INSTALL_PATH . 'program/include/clisetup.php';
require __DIR__ . '/../lib/mail_scheduler_store.php';
require __DIR__ . '/../lib/mail_scheduler_time.php';

$db = rcube_db::factory($argv[1]);
$db->set_debug(false);
$db->db_connect('w');
if (!$db->is_connected()) { echo "no conecta: " . $db->is_error() . "\n"; exit(1); }

$db->query("INSERT INTO " . $db->table_name('users', true) . " (username, mail_host, created, language) VALUES ('t@example.com', 'h', " . $db->now() . ", 'es_ES')");
$uid = $db->insert_id('users');

$s = new mail_scheduler_store($db);
$ok = function ($n, $c) { echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n"; if (!$c) $GLOBALS['fail'] = 1; };

$id = $s->insert_message([
    'user_id' => $uid, 'send_at' => time() - 10, 'timezone' => 'Europe/Madrid', 'recurrence' => 'weekly',
    'subject' => 'Prueba ñ', 'mail_from' => 'a@b.c', 'recipients' => json_encode(['x@y.z']), 'rcpt_display' => 'x@y.z',
    'options' => json_encode(['dsn' => true]), 'mime' => base64_encode("Subject: x\r\n\r\ncuerpo"), 'size' => 10,
]);
$ok('insert_message devuelve id', $id > 0);
$due = $s->due_messages(time());
$ok('due_messages lo encuentra', count($due) == 1 && $due[0]['subject'] === 'Prueba ñ');
$ok('claim_message bloquea', $s->claim_message($id, 'pending', 'sending'));
$ok('segundo claim falla', !$s->claim_message($id, 'pending', 'sending'));
$s->update_message($id, ['status' => 'pending', 'send_at' => time() + 3600, 'sent_count' => 1, 'last_sent_at' => time()]);
$full = $s->get_message($id, $uid);
$ok('get_message decodifica MIME y opciones', $full['mime'] === "Subject: x\r\n\r\ncuerpo" && $full['options']['dsn'] === true);
$ok('list_messages pendientes', count($s->list_messages($uid, 'pending')) == 1);
$ok('list_messages enviados', count($s->list_messages($uid, 'sent')) == 1);
$ok('count_messages', $s->count_messages($uid, ['pending', 'failed']) == 1);

$ar = $s->save_autoreply(['name' => 'Vacaciones', 'enabled' => 1, 'timezone' => 'UTC', 'identity_id' => 1,
    'subject' => 's', 'body' => '<p>b</p>', 'is_html' => 1, 'interval_days' => 4, 'options' => ['exclude' => ['*@x.com']]], null, $uid);
$ok('save_autoreply inserta', $ar > 0);
$ok('active_autoreplies', count($s->active_autoreplies(time())) == 1);
$s->autoreply_log($ar, 'z@z.com', time());
$s->autoreply_log($ar, 'z@z.com', time());
$ok('autoreply_recently_sent', $s->autoreply_recently_sent($ar, 'z@z.com', time() - 60));
$ok('save_autoreply actualiza', $s->save_autoreply(['name' => 'Otra'], $ar, $uid) == $ar && $s->get_autoreply($ar)['name'] === 'Otra');

$ok('save_credentials', $s->save_credentials($uid, 't@example.com', 'cifrada', 'imap', 993, 'ssl'));
$ok('get_credentials', $s->get_credentials($uid)['ssl'] === 'ssl');
$s->purge_unused_credentials();
$ok('purge mantiene credenciales en uso', $s->has_credentials($uid));
$ok('delete_autoreply', $s->delete_autoreply($ar, $uid));
$ok('delete_message', $s->delete_message($id, $uid));
$s->purge_unused_credentials();
$ok('purge borra credenciales sin uso', !$s->has_credentials($uid));

echo empty($GLOBALS['fail']) ? "OK\n" : "FALLOS\n";
exit(empty($GLOBALS['fail']) ? 0 : 1);
