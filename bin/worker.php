#!/usr/bin/env php
<?php

/**
 * Mail Scheduler · proceso en segundo plano
 *
 * Envía los correos programados que ya han vencido y procesa las respuestas
 * automáticas. Pensado para ejecutarse cada minuto desde cron:
 *
 *   * * * * * www-data php /ruta/roundcube/plugins/mail_scheduler/bin/worker.php
 *
 * Opciones:
 *   --only=scheduled|autoreply   Ejecutar solo una de las dos tareas
 *   --verbose                    Mostrar lo que va haciendo
 *   --help                       Esta ayuda
 *
 * @license GNU GPLv3+
 */

if (PHP_SAPI !== 'cli') {
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

$plugin_dir = dirname(__DIR__);

if (!defined('INSTALL_PATH')) {
    $candidates = [
        getenv('ROUNDCUBE_PATH') ?: null,
        realpath($plugin_dir . '/../..'),
    ];

    foreach ($candidates as $path) {
        if ($path && is_file(rtrim($path, '/') . '/program/include/clisetup.php')) {
            define('INSTALL_PATH', rtrim($path, '/') . '/');
            break;
        }
    }

    if (!defined('INSTALL_PATH')) {
        fwrite(STDERR, "No se encuentra Roundcube. Define ROUNDCUBE_PATH o instala el plugin en plugins/mail_scheduler.\n");
        exit(1);
    }
}

require_once INSTALL_PATH . 'program/include/clisetup.php';
require_once $plugin_dir . '/lib/mail_scheduler_store.php';
require_once $plugin_dir . '/lib/mail_scheduler_time.php';
require_once $plugin_dir . '/lib/mail_scheduler_mailer.php';

$opts = rcube_utils::get_opt(['o' => 'only', 'v' => 'verbose', 'h' => 'help']);

if (!empty($opts['help'])) {
    echo "Uso: php bin/worker.php [--only=scheduled|autoreply] [--verbose]\n";
    exit(0);
}

$verbose = !empty($opts['verbose']);
$only    = !empty($opts['only']) ? $opts['only'] : null;

$rc = rcube::get_instance(rcube::INIT_WITH_DB);

// Configuración del plugin (valores por defecto + config.inc.php)
foreach (['config.inc.php.dist', 'config.inc.php'] as $file) {
    if (is_file($plugin_dir . '/' . $file)) {
        $rc->config->load_from_file($plugin_dir . '/' . $file);
    }
}

// Evita ejecuciones solapadas
$lock_file = rtrim($rc->config->get('temp_dir', sys_get_temp_dir()), '/') . '/mail_scheduler.lock';
$lock      = @fopen($lock_file, 'c');

if (!$lock) {
    fwrite(STDERR, "No se puede crear el fichero de bloqueo {$lock_file}: revisa los permisos de temp_dir.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    if ($verbose) {
        echo "Ya hay otro proceso en marcha.\n";
    }
    exit(0);
}

$log = function ($msg) use ($verbose) {
    if ($verbose) {
        echo '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    }
};

// El proceso no tiene sesión web: creamos una vacía para rcube_smtp/rcube_imap
if (!isset($_SESSION)) {
    $_SESSION = [];
}

$store = new mail_scheduler_store($rc->get_dbh());

if ($rc->config->get('mail_scheduler_auto_install', true)) {
    $store->ensure_schema($plugin_dir);
}

$mailer = new mail_scheduler_mailer($rc, $store, $log);
$exit   = 0;

try {
    if (!$only || $only == 'scheduled') {
        $stats = $mailer->run_scheduled((int) $rc->config->get('mail_scheduler_batch_size', 50));
        $log(sprintf('Programados: %d enviados, %d reintentos, %d fallidos', $stats['sent'], $stats['retried'], $stats['failed']));
    }

    if ((!$only || $only == 'autoreply') && $rc->config->get('mail_scheduler_autoreply', true)) {
        $stats = $mailer->run_autoreplies();
        $log(sprintf('Respuestas automáticas: %d enviadas, %d omitidas, %d errores', $stats['replied'], $stats['skipped'], $stats['errors']));
    }

    // Mantenimiento
    $keep = (int) $rc->config->get('mail_scheduler_keep_days', 30);
    if ($keep > 0) {
        $store->purge_messages(time() - $keep * 86400);
    }

    $store->purge_unused_credentials();
}
catch (Exception $e) {
    rcube::raise_error($e, true, false);
    fwrite(STDERR, $e->getMessage() . "\n");
    $exit = 1;
}

flock($lock, LOCK_UN);
fclose($lock);

exit($exit);
