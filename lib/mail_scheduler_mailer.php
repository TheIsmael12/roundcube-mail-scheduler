<?php

/**
 * Mail Scheduler · motor de envío (se ejecuta desde bin/worker.php)
 *
 * - Entrega los envíos programados que ya han vencido, con reintentos.
 * - Guarda una copia en la carpeta Enviados del usuario.
 * - Procesa las respuestas automáticas (fuera de la oficina, vacaciones…)
 *   con protección anti-bucle según RFC 3834.
 *
 * @license GNU GPLv3+
 */
class mail_scheduler_mailer
{
    /** @var rcube */
    protected $rc;

    /** @var mail_scheduler_store */
    protected $store;

    /** @var callable|null */
    protected $logger;

    /** @var array Credenciales del usuario actualmente conectado */
    protected $creds;

    /** @var int|null */
    protected $imap_user;

    /** @var rcube_smtp|null */
    protected $smtp;

    /** @var int|null */
    protected $smtp_user;

    public function __construct(rcube $rc, mail_scheduler_store $store, $logger = null)
    {
        $this->rc     = $rc;
        $this->store  = $store;
        $this->logger = $logger;
    }

    protected function log($msg)
    {
        if ($this->logger) {
            call_user_func($this->logger, $msg);
        }

        if ($this->rc->config->get('mail_scheduler_debug')) {
            rcube::write_log('mail_scheduler', $msg);
        }
    }

    /* ==================================================================
     * Envíos programados
     * ================================================================== */

    /**
     * Procesa todos los envíos vencidos.
     *
     * @return array Resumen [sent, failed, retried]
     */
    public function run_scheduled($limit = 50)
    {
        $stats = ['sent' => 0, 'failed' => 0, 'retried' => 0];
        $now   = time();

        // Mensajes atascados en «enviando» durante más de 15 minutos: el proceso
        // se interrumpió. No se reintentan solos para no duplicar correos.
        foreach ($this->store->stale_sending($now - 900) as $id) {
            $this->store->update_message($id, [
                'status'     => mail_scheduler_store::STATUS_FAILED,
                'last_error' => 'interrupted',
            ]);
        }

        foreach ($this->store->due_messages($now, $limit) as $row) {
            if (!$this->store->claim_message($row['id'], mail_scheduler_store::STATUS_PENDING, mail_scheduler_store::STATUS_SENDING)) {
                continue; // otro proceso se lo ha quedado
            }

            $full = $this->store->get_message($row['id']);

            try {
                $result = $this->deliver($full);
            }
            catch (Exception $e) {
                $result = ['ok' => false, 'error' => $e->getMessage(), 'temporary' => true];
            }

            if ($result['ok']) {
                $this->after_sent($full);
                $stats['sent']++;
                $this->log("#{$full['id']} enviado (usuario {$full['user_id']})");
            }
            else {
                $retry = $this->after_failure($full, $result);
                $stats[$retry ? 'retried' : 'failed']++;
                $this->log("#{$full['id']} " . ($retry ? 'reintento' : 'fallido') . ': ' . $result['error']);
            }
        }

        $this->disconnect();

        return $stats;
    }

    /**
     * Envía un mensaje programado y guarda la copia en Enviados.
     */
    public function deliver(array $row)
    {
        if (!$this->login_user($row['user_id'], $error)) {
            return ['ok' => false, 'error' => $error, 'temporary' => $error != 'nocredentials'];
        }

        list($headers, $body) = self::split_mime($row['mime']);

        $now = time();
        $headers = self::set_header($headers, 'Date', self::rfc_date($row['timezone']));

        // Cada repetición es un mensaje nuevo con su propio Message-ID
        if ($row['recurrence'] != 'none' && $row['sent_count'] > 0) {
            $headers = self::set_header($headers, 'Message-ID', $this->rc->gen_message_id($row['mail_from']));
        }

        $smtp = $this->smtp_for($row['user_id'], $error);
        if (!$smtp) {
            return ['ok' => false, 'error' => $error, 'temporary' => true];
        }

        $smtp_headers = self::remove_header($headers, 'Bcc');
        $opts = ['dsn' => !empty($row['options']['dsn'])];

        $sent = $smtp->send_mail($row['mail_from'], $row['recipients'], $smtp_headers, $body, $opts);

        if (!$sent) {
            $response = implode(' ', (array) $smtp->get_response());
            $error    = $smtp->get_error();
            $label    = is_array($error) && !empty($error['label']) ? $error['label'] : 'sendingfailed';

            // Errores permanentes (5xx) no se reintentan
            $temporary = !preg_match('/\b5\d\d\b/', $response);

            $this->smtp_reset();

            return ['ok' => false, 'error' => trim($label . ' ' . $response), 'temporary' => $temporary];
        }

        // Copia en Enviados (sin romper el envío si falla)
        if (!$this->rc->config->get('no_save_sent_messages') && empty($row['options']['nosave'])) {
            $this->save_copy($row['user_id'], $headers, $body, !empty($row['options']['sent_folder']) ? $row['options']['sent_folder'] : null);
        }

        // Marca como respondido/reenviado el mensaje original, si lo hay
        $this->flag_original($row);

        return ['ok' => true];
    }

    protected function after_sent(array $row)
    {
        $now  = time();
        $data = [
            'sent_count'   => $row['sent_count'] + 1,
            'last_sent_at' => $now,
            'last_error'   => null,
            'attempts'     => 0,
        ];

        $next = null;
        if ($row['recurrence'] != 'none') {
            $anchor = !empty($row['options']['anchor_day']) ? (int) $row['options']['anchor_day'] : null;
            $next   = mail_scheduler_time::next_occurrence($row['send_at'], $row['recurrence'], $row['timezone'], $anchor, $now);

            if ($next && $row['recurrence_until'] && $next > $row['recurrence_until']) {
                $next = null;
            }
        }

        if ($next) {
            $data['status']  = mail_scheduler_store::STATUS_PENDING;
            $data['send_at'] = $next;
        }
        else {
            $data['status'] = mail_scheduler_store::STATUS_SENT;
        }

        $this->store->update_message($row['id'], $data);
    }

    /**
     * @return bool true si se volverá a intentar
     */
    protected function after_failure(array $row, array $result)
    {
        $max      = (int) $this->rc->config->get('mail_scheduler_max_attempts', 5);
        $attempts = $row['attempts'] + 1;
        $delays   = [5, 15, 60, 180, 360]; // minutos

        if (!empty($result['temporary']) && $attempts < $max) {
            $delay = $delays[min($attempts - 1, count($delays) - 1)] * 60;

            $this->store->update_message($row['id'], [
                'status'     => mail_scheduler_store::STATUS_PENDING,
                'attempts'   => $attempts,
                'send_at'    => time() + $delay,
                'last_error' => $result['error'],
            ]);

            return true;
        }

        $this->store->update_message($row['id'], [
            'status'     => mail_scheduler_store::STATUS_FAILED,
            'attempts'   => $attempts,
            'last_error' => $result['error'],
        ]);

        return false;
    }

    protected function save_copy($user_id, $headers, $body, $folder = null)
    {
        $storage = $this->imap_for($user_id, $error);
        if (!$storage) {
            $this->log("No se pudo guardar en Enviados: {$error}");
            return false;
        }

        if (!$folder) {
            $prefs  = $this->user_prefs($user_id);
            $folder = !empty($prefs['sent_mbox']) ? $prefs['sent_mbox'] : $this->rc->config->get('sent_mbox', 'Sent');
        }

        if (!$storage->folder_exists($folder)) {
            $storage->create_folder($folder, true);
        }

        $message = rtrim($headers) . "\r\n\r\n" . $body;
        $saved   = $storage->save_message($folder, $message, '', false, ['SEEN']);

        if (!$saved) {
            $this->log("No se pudo guardar la copia en {$folder}");
        }

        return $saved;
    }

    protected function flag_original(array $row)
    {
        $opts = $row['options'];

        if (empty($opts['reply_uid']) && empty($opts['forward_uid'])) {
            return;
        }

        if ($row['recurrence'] != 'none' && $row['sent_count'] > 0) {
            return;
        }

        $storage = $this->imap_for($row['user_id'], $error);
        if (!$storage || empty($opts['mailbox'])) {
            return;
        }

        $uid  = !empty($opts['reply_uid']) ? $opts['reply_uid'] : $opts['forward_uid'];
        $flag = !empty($opts['reply_uid']) ? 'ANSWERED' : 'FORWARDED';

        if (preg_match('/^\d+$/', (string) $uid)) {
            $storage->set_flag($uid, $flag, $opts['mailbox']);
        }
    }

    /* ==================================================================
     * Respuestas automáticas
     * ================================================================== */

    public function run_autoreplies($max_per_rule = 50)
    {
        $stats = ['replied' => 0, 'skipped' => 0, 'errors' => 0];
        $now   = time();

        foreach ($this->store->active_autoreplies($now) as $rule) {
            try {
                $res = $this->process_autoreply($rule, $max_per_rule);
                $stats['replied'] += $res['replied'];
                $stats['skipped'] += $res['skipped'];
            }
            catch (Exception $e) {
                $stats['errors']++;
                $this->store->update_autoreply_state($rule['id'], ['last_error' => $e->getMessage()]);
                $this->log("Respuesta automática #{$rule['id']}: " . $e->getMessage());
            }
        }

        $this->disconnect();

        return $stats;
    }

    protected function process_autoreply(array $rule, $max)
    {
        $out = ['replied' => 0, 'skipped' => 0];

        $storage = $this->imap_for($rule['user_id'], $error);
        if (!$storage) {
            $this->store->update_autoreply_state($rule['id'], ['last_error' => $error]);
            return $out;
        }

        $conn = $storage->conn;

        if (!$conn->select('INBOX')) {
            throw new Exception('No se pudo abrir INBOX');
        }

        $uidvalidity = (string) $conn->data['UIDVALIDITY'];
        $uidnext     = (int) $conn->data['UIDNEXT'];

        // Primera ejecución (o buzón regenerado): empezamos desde ahora y no
        // respondemos a correos que ya estaban en la bandeja.
        if ($rule['last_uid'] === null || $rule['uidvalidity'] !== $uidvalidity) {
            if (!$uidnext) {
                $index   = $conn->search('INBOX', 'ALL', true);
                $uidnext = $index->max() + 1;
            }

            $this->store->update_autoreply_state($rule['id'], [
                'last_uid'    => max(0, $uidnext - 1),
                'uidvalidity' => $uidvalidity,
                'last_error'  => null,
            ]);

            return $out;
        }

        $last  = (int) $rule['last_uid'];
        $index = $conn->search('INBOX', 'UID ' . ($last + 1) . ':*', true);
        $uids  = array_filter((array) $index->get(), function ($uid) use ($last) {
            return (int) $uid > $last;
        });

        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, $max);

        if (empty($uids)) {
            return $out;
        }

        $own = $this->user_addresses($rule['user_id']);

        foreach ($uids as $uid) {
            $raw     = $conn->fetchPartHeader('INBOX', $uid, true);
            $headers = self::parse_headers((string) $raw);
            $reason  = $this->autoreply_skip_reason($rule, $headers, $own);

            if ($reason === null) {
                $sender = $this->reply_target($headers);

                if ($this->send_autoreply($rule, $headers, $sender)) {
                    $this->store->autoreply_log($rule['id'], $sender, time());
                    $out['replied']++;
                }
                else {
                    $out['skipped']++;
                }
            }
            else {
                $out['skipped']++;
            }

            $this->store->update_autoreply_state($rule['id'], ['last_uid' => (int) $uid]);
        }

        if ($out['replied']) {
            $this->store->update_autoreply_state($rule['id'], [
                'reply_count' => $rule['reply_count'] + $out['replied'],
                'last_error'  => null,
            ]);
        }

        return $out;
    }

    /**
     * Decide si NO hay que responder a un mensaje (RFC 3834 y reglas del usuario).
     *
     * @return string|null Motivo para no responder, o null si hay que responder
     */
    protected function autoreply_skip_reason(array $rule, array $h, array $own)
    {
        $opts   = $rule['options'];
        $sender = $this->reply_target($h);

        if (!$sender || !strpos($sender, '@')) {
            return 'nosender';
        }

        // Nunca a nosotros mismos
        if (in_array($sender, $own)) {
            return 'self';
        }

        // Mensajes automáticos (RFC 3834) y remitentes de sistema: siempre se ignoran
        $auto = isset($h['auto-submitted']) ? strtolower(trim($h['auto-submitted'])) : 'no';
        if ($auto !== '' && $auto !== 'no') {
            return 'auto-submitted';
        }

        if (preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounce[s]?|daemon|listserv|majordomo)([+.\-_].*)?@/i', $sender)) {
            return 'system-sender';
        }

        if (isset($h['x-auto-response-suppress']) && preg_match('/\b(all|oof|autoreply)\b/i', $h['x-auto-response-suppress'])) {
            return 'suppressed';
        }

        if (!empty($opts['skip_bulk'])) {
            if (isset($h['precedence']) && preg_match('/\b(bulk|list|junk|auto_reply)\b/i', $h['precedence'])) {
                return 'bulk';
            }

            foreach (['list-id', 'list-unsubscribe', 'list-post', 'x-mailing-list', 'feedback-id', 'x-campaign', 'x-mailchimp-id'] as $hdr) {
                if (!empty($h[$hdr])) {
                    return 'list';
                }
            }

            if (isset($h['x-spam-flag']) && stripos($h['x-spam-flag'], 'yes') !== false) {
                return 'spam';
            }
        }

        // Solo si mi dirección aparece en Para/CC (evita responder a copias ocultas de listas)
        if (!empty($opts['only_direct'])) {
            $rcpts = array_merge(
                self::addresses(isset($h['to']) ? $h['to'] : ''),
                self::addresses(isset($h['cc']) ? $h['cc'] : '')
            );

            if (!array_intersect($own, $rcpts)) {
                return 'not-direct';
            }
        }

        $domain = strtolower(substr(strrchr($sender, '@'), 1));

        if (!empty($opts['domains'])) {
            $allowed = array_map('strtolower', (array) $opts['domains']);
            if (!in_array($domain, $allowed)) {
                return 'domain';
            }
        }

        foreach ((array) $opts['exclude'] as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }

            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            if (strpos($pattern, '@') === false) {
                $regex = '/^.*@' . str_replace('\*', '.*', preg_quote(ltrim($pattern, '@'), '/')) . '$/';
            }

            if (preg_match($regex, $sender)) {
                return 'excluded';
            }
        }

        // Una vez cada N días por remitente
        $days = max(1, (int) $rule['interval_days']);
        if ($this->store->autoreply_recently_sent($rule['id'], $sender, time() - $days * 86400)) {
            return 'recent';
        }

        return null;
    }

    protected function reply_target(array $h)
    {
        $list = self::addresses(!empty($h['reply-to']) ? $h['reply-to'] : (isset($h['from']) ? $h['from'] : ''));

        if (empty($list) && !empty($h['return-path'])) {
            $list = self::addresses($h['return-path']);
        }

        return $list ? $list[0] : null;
    }

    protected function send_autoreply(array $rule, array $h, $to)
    {
        $user = new rcube_user($rule['user_id']);
        $identity = null;

        if ($rule['identity_id']) {
            $identity = $user->get_identity($rule['identity_id']);
        }

        if (!$identity) {
            $identity = $user->get_identity();
        }

        if (!$identity) {
            throw new Exception('El usuario no tiene identidades');
        }

        $orig_subject = isset($h['subject']) ? rcube_mime::decode_header($h['subject'], 'UTF-8') : '';
        $vars = self::template_vars($rule, $orig_subject, $h);

        $subject = trim($rule['subject']) !== '' ? strtr($rule['subject'], $vars) : 'Re: ' . $orig_subject;
        $body    = strtr((string) $rule['body'], $rule['is_html'] ? array_map('htmlspecialchars', $vars) : $vars);

        $signature = null;
        $sig_id    = $rule['signature_id'];

        if ($sig_id) {
            $sig_identity = $user->get_identity($sig_id);
            if ($sig_identity && trim((string) $sig_identity['signature']) !== '') {
                $signature = $sig_identity;
            }
        }

        $mime = $this->build_message($identity, $to, $subject, $body, (bool) $rule['is_html'], $signature, $h, $rule['timezone']);

        $smtp = $this->smtp_for($rule['user_id'], $error);
        if (!$smtp) {
            throw new Exception($error);
        }

        $headers = $mime->txtHeaders();
        $msg     = $mime->get();

        $sent = $smtp->send_mail($identity['email'], [$to], $headers, $msg, []);

        if (!$sent) {
            $this->smtp_reset();
            $this->log('Fallo al enviar respuesta automática a ' . $to . ': ' . implode(' ', (array) $smtp->get_response()));
            return false;
        }

        if (!empty($rule['options']['save_sent'])) {
            $this->save_copy($rule['user_id'], $headers, $msg);
        }

        return true;
    }

    public static function template_vars(array $rule, $orig_subject, array $h = [])
    {
        $from_name = '';
        if (!empty($h['from'])) {
            $list = rcube_mime::decode_address_list($h['from'], 1, true, 'UTF-8');
            $first = reset($list);
            $from_name = $first ? ($first['name'] ?: $first['mailto']) : '';
        }

        $until = $rule['date_until'] ? mail_scheduler_time::local($rule['date_until'], $rule['timezone'], 'd/m/Y H:i') : '';
        $since = $rule['date_from'] ? mail_scheduler_time::local($rule['date_from'], $rule['timezone'], 'd/m/Y H:i') : '';

        return [
            '{subject}' => $orig_subject,
            '{sender}'  => $from_name,
            '{until}'   => $until,
            '{since}'   => $since,
        ];
    }

    /**
     * Construye el mensaje de respuesta automática (HTML + texto plano).
     */
    protected function build_message(array $identity, $to, $subject, $body, $is_html, $signature = null, array $orig = [], $tz = 'UTC')
    {
        $charset = 'UTF-8';
        $from    = format_email_recipient($identity['email'], $identity['name']);

        $mime = new Mail_mime("\r\n");
        $mime->setParam('text_encoding', 'quoted-printable');
        $mime->setParam('html_encoding', 'quoted-printable');
        $mime->setParam('head_encoding', 'quoted-printable');
        $mime->setParam('head_charset', $charset);
        $mime->setParam('html_charset', $charset);
        $mime->setParam('text_charset', $charset . ';' . ' format=flowed');

        $headers = [
            'Date'                     => self::rfc_date($tz),
            'From'                     => $from,
            'To'                       => $to,
            'Subject'                  => $subject,
            'Message-ID'               => $this->rc->gen_message_id($identity['email']),
            'Auto-Submitted'           => 'auto-replied',
            'X-Auto-Response-Suppress' => 'All',
            'User-Agent'               => 'Roundcube Mail Scheduler',
        ];

        if (!empty($identity['reply-to'])) {
            $headers['Reply-To'] = $identity['reply-to'];
        }

        if (!empty($orig['message-id'])) {
            $msgid = trim($orig['message-id']);
            $headers['In-Reply-To'] = $msgid;
            $headers['References']  = trim((!empty($orig['references']) ? trim($orig['references']) . ' ' : '') . $msgid);
        }

        $mime->headers($headers, true);

        if ($is_html) {
            $html = $body;

            if ($signature) {
                $sig_html = !empty($signature['html_signature'])
                    ? $signature['signature']
                    : nl2br(htmlspecialchars($signature['signature'], ENT_COMPAT, 'UTF-8'));

                $html .= '<div class="pre" style="margin-top:1em">-- <br>' . $sig_html . '</div>';
            }

            $html = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>'
                . '<body style="font-family: Verdana, Geneva, sans-serif; font-size: 10pt">' . $html . '</body></html>';

            $h2t  = new rcube_html2text($html, false, true, 0, $charset);
            $text = rtrim($h2t->get_text());

            $mime->setHTMLBody($html);
            $mime->setTXTBody(rcube_mime::format_flowed($text, 79, $charset));
        }
        else {
            $text = $body;

            if ($signature) {
                $sig_text = $signature['signature'];
                if (!empty($signature['html_signature'])) {
                    $h2t      = new rcube_html2text($sig_text, false, true, 0, $charset);
                    $sig_text = rtrim($h2t->get_text());
                }

                $text = rtrim($text) . "\r\n\r\n-- \r\n" . $sig_text;
            }

            $mime->setTXTBody(rcube_mime::format_flowed($text, 79, $charset));
        }

        return $mime;
    }

    /* ==================================================================
     * Conexiones con las credenciales del usuario
     * ================================================================== */

    protected function load_credentials($user_id, &$error = null)
    {
        if ($this->creds && $this->creds['user_id'] == $user_id) {
            return $this->creds;
        }

        $row = $this->store->get_credentials($user_id);

        if (!$row) {
            $error = 'nocredentials';
            return null;
        }

        $password = $this->rc->decrypt($row['password']);

        if ($password === false || $password === null) {
            $error = 'nocredentials';
            return null;
        }

        $this->disconnect();

        $row['user_id']       = (int) $user_id;
        $row['password_enc']  = $row['password'];
        $row['password']      = $password;
        $this->creds          = $row;

        // rcube_smtp/rcube_imap leen estos datos de la «sesión»
        $_SESSION['username']     = $row['username'];
        $_SESSION['password']     = $row['password_enc'];
        $_SESSION['storage_host'] = $row['host'];
        $_SESSION['storage_port'] = $row['port'];
        $_SESSION['storage_ssl']  = $row['ssl'];

        return $row;
    }

    protected function login_user($user_id, &$error = null)
    {
        return (bool) $this->load_credentials($user_id, $error);
    }

    /**
     * @return rcube_smtp|null
     */
    protected function smtp_for($user_id, &$error = null)
    {
        $creds = $this->load_credentials($user_id, $error);
        if (!$creds) {
            return null;
        }

        if ($this->smtp && $this->smtp_user == $user_id) {
            return $this->smtp;
        }

        $smtp_user = (string) $this->rc->config->get('smtp_user', '%u');
        $smtp_pass = (string) $this->rc->config->get('smtp_pass', '%p');

        $user = str_replace('%u', $creds['username'], $smtp_user);
        $pass = $smtp_pass == '%p' ? $creds['password'] : $smtp_pass;

        $smtp = new rcube_smtp();

        if (!$smtp->connect(null, null, $user, $pass)) {
            $err   = $smtp->get_error();
            $error = 'smtp: ' . (is_array($err) && !empty($err['label']) ? $err['label'] : 'connect') . ' ' . implode(' ', (array) $smtp->get_response());
            return null;
        }

        $this->smtp      = $smtp;
        $this->smtp_user = $user_id;

        return $smtp;
    }

    protected function smtp_reset()
    {
        if ($this->smtp) {
            $this->smtp->disconnect();
        }

        $this->smtp      = null;
        $this->smtp_user = null;
    }

    /**
     * @return rcube_imap|null
     */
    protected function imap_for($user_id, &$error = null)
    {
        $creds = $this->load_credentials($user_id, $error);
        if (!$creds) {
            return null;
        }

        $storage = $this->rc->get_storage();

        if ($this->imap_user == $user_id && $storage->is_connected()) {
            return $storage;
        }

        $storage->close();

        $ssl = $creds['ssl'] ?: null;
        if (!$storage->connect($creds['host'], $creds['username'], $creds['password'], (int) $creds['port'], $ssl)) {
            $error = 'imap: ' . $storage->get_error_str();
            return null;
        }

        $this->imap_user = $user_id;

        return $storage;
    }

    public function disconnect()
    {
        $this->smtp_reset();

        if ($this->imap_user) {
            $this->rc->get_storage()->close();
            $this->imap_user = null;
        }

        $this->creds = null;
    }

    protected function user_prefs($user_id)
    {
        $user = new rcube_user($user_id);

        return (array) $user->get_prefs();
    }

    protected function user_addresses($user_id)
    {
        $user = new rcube_user($user_id);
        $list = [];

        foreach ((array) $user->list_emails() as $identity) {
            $list[] = strtolower($identity['email']);
        }

        if (!empty($this->creds['username']) && strpos($this->creds['username'], '@')) {
            $list[] = strtolower($this->creds['username']);
        }

        return array_values(array_unique($list));
    }

    /* ==================================================================
     * Utilidades MIME
     * ================================================================== */

    /**
     * Fecha RFC 2822 en la zona horaria del usuario.
     */
    public static function rfc_date($tz)
    {
        $dt = new DateTime('now', mail_scheduler_time::zone($tz));

        return $dt->format('D, d M Y H:i:s O');
    }

    public static function split_mime($raw)
    {
        $raw = (string) $raw;
        $pos = strpos($raw, "\r\n\r\n");
        $sep = 4;

        if ($pos === false) {
            $pos = strpos($raw, "\n\n");
            $sep = 2;
        }

        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + $sep)];
    }

    /**
     * Quita una cabecera (incluidas sus líneas de continuación).
     */
    public static function remove_header($headers, $name)
    {
        $lines = preg_split('/\r?\n/', rtrim($headers, "\r\n"));
        $out   = [];
        $skip  = false;
        $match = strtolower($name) . ':';

        foreach ($lines as $line) {
            if ($skip && ($line !== '' && ($line[0] == ' ' || $line[0] == "\t"))) {
                continue;
            }

            $skip = stripos($line, $match) === 0;

            if (!$skip) {
                $out[] = $line;
            }
        }

        return implode("\r\n", $out);
    }

    public static function set_header($headers, $name, $value)
    {
        $headers = self::remove_header($headers, $name);

        return $name . ': ' . $value . "\r\n" . $headers;
    }

    /**
     * Cabeceras crudas → array (nombre en minúsculas => valor sin plegar).
     */
    public static function parse_headers($raw)
    {
        $raw  = preg_replace('/\r?\n[ \t]+/', ' ', (string) $raw);
        $list = [];

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (!strpos($line, ':')) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if (!isset($list[$name])) {
                $list[$name] = trim($value);
            }
        }

        return $list;
    }

    /**
     * Direcciones (en minúsculas) de una cabecera de tipo dirección.
     */
    public static function addresses($value)
    {
        $out = [];

        if (trim((string) $value) === '') {
            return $out;
        }

        foreach ((array) rcube_mime::decode_address_list($value, null, false, 'UTF-8') as $addr) {
            if (!empty($addr['mailto'])) {
                $out[] = strtolower(trim($addr['mailto']));
            }
        }

        return $out;
    }
}
