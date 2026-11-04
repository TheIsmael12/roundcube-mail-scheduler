<?php

/**
 * Pruebas unitarias sin dependencias (se ejecutan dentro de una instalación
 * de Roundcube, p. ej. la imagen Docker oficial):
 *
 *   php plugins/mail_scheduler/tests/run.php
 */

$rc_path = getenv('ROUNDCUBE_PATH') ?: dirname(__DIR__, 3);
define('INSTALL_PATH', rtrim($rc_path, '/') . '/');

require_once INSTALL_PATH . 'program/include/clisetup.php';
require_once __DIR__ . '/../lib/mail_scheduler_time.php';
require_once __DIR__ . '/../lib/mail_scheduler_store.php';
require_once __DIR__ . '/../lib/mail_scheduler_mailer.php';

$failed = 0;
$count  = 0;

function check($name, $cond)
{
    global $failed, $count;
    $count++;

    if ($cond) {
        echo "  ✓ {$name}\n";
    }
    else {
        echo "  ✗ {$name}\n";
        $failed++;
    }
}

$tz = 'Europe/Madrid';

echo "Fechas\n";

$ts = mail_scheduler_time::parse_local('2026-11-05', '08:00', $tz);
check('parse_local convierte a UTC', $ts === gmmktime(7, 0, 0, 11, 5, 2026));
check('parse_local rechaza el 31 de febrero', mail_scheduler_time::parse_local('2026-02-31', '10:00', $tz) === false);
check('parse_local rechaza formatos raros', mail_scheduler_time::parse_local('05/11/2026', '8', $tz) === false);
check('local formatea en la zona del usuario', mail_scheduler_time::local($ts, $tz, 'H:i') === '08:00');
check('zona no válida → UTC', mail_scheduler_time::zone('No/Existe')->getName() === 'UTC');

echo "Repeticiones\n";

$now = gmmktime(0, 0, 0, 1, 1, 2026);

// Semanal cruzando el cambio de hora de marzo: sigue a las 08:00 locales
$monday = mail_scheduler_time::parse_local('2026-03-23', '08:00', $tz);
$next   = mail_scheduler_time::next_occurrence($monday, 'weekly', $tz, null, $now);
check('semanal mantiene la hora local tras el cambio de horario', mail_scheduler_time::local($next, $tz, 'Y-m-d H:i') === '2026-03-30 08:00');

$friday = mail_scheduler_time::parse_local('2026-11-06', '09:30', $tz);
$next   = mail_scheduler_time::next_occurrence($friday, 'weekdays', $tz, null, $now);
check('laborables salta el fin de semana', mail_scheduler_time::local($next, $tz, 'Y-m-d H:i') === '2026-11-09 09:30');

$jan31 = mail_scheduler_time::parse_local('2026-01-31', '10:00', $tz);
$feb   = mail_scheduler_time::next_occurrence($jan31, 'monthly', $tz, 31, $now);
$mar   = mail_scheduler_time::next_occurrence($feb, 'monthly', $tz, 31, $now);
check('mensual el día 31 cae el último día de febrero', mail_scheduler_time::local($feb, $tz, 'Y-m-d') === '2026-02-28');
check('y vuelve al 31 en marzo', mail_scheduler_time::local($mar, $tz, 'Y-m-d') === '2026-03-31');

$daily = mail_scheduler_time::parse_local('2026-11-01', '07:00', $tz);
$later = mail_scheduler_time::parse_local('2026-11-04', '12:00', $tz);
$next  = mail_scheduler_time::next_occurrence($daily, 'daily', $tz, null, $later);
check('diario salta las ejecuciones perdidas', mail_scheduler_time::local($next, $tz, 'Y-m-d H:i') === '2026-11-05 07:00');
check('sin repetición no hay siguiente', mail_scheduler_time::next_occurrence($daily, 'none', $tz) === null);

$quick = mail_scheduler_time::quick_options($tz, 8, 15, mail_scheduler_time::parse_local('2026-11-04', '10:20', $tz));
$ids   = array_column($quick, 'id');
check('sugerencias de un miércoles por la mañana', $ids === ['in1h', 'afternoon', 'tomorrow', 'monday']);
check('«dentro de una hora» redondea al cuarto', $quick[0]['time'] === '11:30');
check('«el lunes» es el próximo lunes', $quick[3]['date'] === '2026-11-09');

echo "Cabeceras MIME\n";

$raw = "From: Ana <ana@example.com>\r\nTo: luis@example.com\r\nBcc: oculto@example.com,\r\n otro@example.com\r\nSubject: Hola\r\nDate: Mon, 1 Jan 2024 00:00:00 +0000\r\n\r\nCuerpo\r\n\r\nFin";
list($h, $b) = mail_scheduler_mailer::split_mime($raw);
check('split_mime separa cabeceras y cuerpo', $b === "Cuerpo\r\n\r\nFin");

$nobcc = mail_scheduler_mailer::remove_header($h, 'Bcc');
check('remove_header quita Bcc y su continuación', strpos($nobcc, 'oculto') === false && strpos($nobcc, 'otro@') === false && strpos($nobcc, 'Subject: Hola') !== false);

$dated = mail_scheduler_mailer::set_header($h, 'Date', 'Tue, 2 Jan 2024 00:00:00 +0000');
check('set_header sustituye sin duplicar', substr_count($dated, 'Date:') === 1 && strpos($dated, '2 Jan 2024') !== false);

$parsed = mail_scheduler_mailer::parse_headers("Subject: Uno\r\n dos\r\nAuto-Submitted: auto-replied\r\n");
check('parse_headers despliega líneas', $parsed['subject'] === 'Uno dos' && $parsed['auto-submitted'] === 'auto-replied');

check('addresses extrae direcciones', mail_scheduler_mailer::addresses('"Ana" <Ana@Example.com>, luis@example.com') === ['ana@example.com', 'luis@example.com']);

echo "\n{$count} comprobaciones, {$failed} fallos\n";

exit($failed ? 1 : 0);
