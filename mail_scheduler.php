<?php

/**
 * Mail Scheduler
 *
 * Programación de envíos y respuestas automáticas para Roundcube Webmail.
 *
 *  - «Programar envío» en el redactor: sugerencias rápidas, fecha y hora a
 *    medida, envíos periódicos y elección de firma.
 *  - Sección «Envíos programados» en Ajustes para revisar, reprogramar,
 *    enviar ya, volver a borrador o cancelar.
 *  - Sección «Respuestas automáticas» (fuera de la oficina, vacaciones…) con
 *    periodo, identidad, firma y reglas anti-bucle.
 *
 * El envío real lo hace bin/worker.php, que se ejecuta desde cron.
 *
 * Compatible con Roundcube 1.5, 1.6 y 1.7 (probado en 1.5.10, 1.6.11 y 1.7.5).
 *
 * @version 1.0.0
 * @license GNU GPLv3+
 * @author  TheIsmael12
 * @url     https://github.com/TheIsmael12/roundcube-mail-scheduler
 */
class mail_scheduler extends rcube_plugin
{
    public $task = 'mail|settings|login|logout';

    /** @var rcmail */
    protected $rc;

    /** @var mail_scheduler_store */
    protected $store;

    /** @var array|null Registro que se está editando */
    protected $record;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->load_config('config.inc.php.dist');
        $this->load_config();

        require_once __DIR__ . '/lib/mail_scheduler_store.php';
        require_once __DIR__ . '/lib/mail_scheduler_time.php';

        $this->add_hook('login_after', [$this, 'login_after']);

        if ($this->rc->task == 'mail') {
            $this->add_hook('message_before_send', [$this, 'message_before_send']);

            if ($this->rc->action == 'compose') {
                $this->add_hook('render_page', [$this, 'compose_page']);
                $this->compose_ui();
            }
        }
        else if ($this->rc->task == 'settings') {
            $this->add_texts('localization/');
            $this->add_hook('settings_actions', [$this, 'settings_actions']);

            $this->register_action('plugin.mail_scheduler', [$this, 'action_scheduled']);
            $this->register_action('plugin.mail_scheduler-edit', [$this, 'action_scheduled_edit']);
            $this->register_action('plugin.mail_scheduler-action', [$this, 'action_scheduled_ajax']);
            $this->register_action('plugin.mail_scheduler-autoreplies', [$this, 'action_autoreplies']);
            $this->register_action('plugin.mail_scheduler-autoreply-edit', [$this, 'action_autoreply_edit']);
            $this->register_action('plugin.mail_scheduler-autoreply-save', [$this, 'action_autoreply_save']);
            $this->register_action('plugin.mail_scheduler-autoreply-action', [$this, 'action_autoreply_ajax']);
        }
    }

    /**
     * @return mail_scheduler_store
     */
    protected function store()
    {
        if (!$this->store) {
            $this->store = new mail_scheduler_store($this->rc->get_dbh());

            if ($this->rc->config->get('mail_scheduler_auto_install', true)) {
                $this->store->ensure_schema($this->home);
            }
        }

        return $this->store;
    }

    /**
     * Zona horaria efectiva del usuario.
     */
    protected function user_timezone()
    {
        // Ojo: rcube_config::get_timezone() devuelve un desfase en horas en
        // algunas versiones; aquí necesitamos el nombre de la zona.
        $tz = $this->rc->config->get('timezone');

        if (!$tz || $tz == 'auto') {
            $tz = !empty($_SESSION['timezone']) && !is_numeric($_SESSION['timezone'])
                ? $_SESSION['timezone']
                : date_default_timezone_get();
        }

        try {
            new DateTimeZone($tz);
        }
        catch (Exception $e) {
            $tz = date_default_timezone_get();
        }

        return $tz;
    }

    protected function include_assets()
    {
        $this->include_script('mail_scheduler.js');
        $this->include_stylesheet($this->local_skin_path() . '/styles/mail_scheduler.css');
    }

    /* ==================================================================
     * Credenciales para el proceso en segundo plano
     * ================================================================== */

    /**
     * Guarda (cifradas con la des_key de Roundcube) las credenciales de la
     * sesión actual para que el proceso de cron pueda enviar en su nombre.
     */
    protected function save_credentials()
    {
        if (!$this->rc->config->get('mail_scheduler_store_credentials', true)) {
            return true;
        }

        if (empty($_SESSION['password']) || empty($_SESSION['username']) || empty($this->rc->user->ID)) {
            return false;
        }

        return $this->store()->save_credentials(
            $this->rc->user->ID,
            $_SESSION['username'],
            $_SESSION['password'], // ya va cifrado con la des_key
            $_SESSION['storage_host'],
            !empty($_SESSION['storage_port']) ? $_SESSION['storage_port'] : 143,
            !empty($_SESSION['storage_ssl']) ? $_SESSION['storage_ssl'] : ''
        );
    }

    /**
     * Al iniciar sesión se refrescan las credenciales guardadas (por si el
     * usuario ha cambiado la contraseña).
     */
    public function login_after($args)
    {
        try {
            if (!empty($this->rc->user->ID) && $this->store()->has_credentials($this->rc->user->ID)) {
                $this->save_credentials();
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return $args;
    }

    /* ==================================================================
     * Redactor: botón «Programar envío»
     * ================================================================== */

    protected function compose_ui()
    {
        $this->add_texts('localization/', [
            'schedule', 'schedulesend', 'scheduletitle', 'schedulebutton', 'quickpicks',
            'quick_in1h', 'quick_afternoon', 'quick_tomorrow', 'quick_tomorrowpm', 'quick_monday',
            'customdatetime', 'date', 'time', 'repeat', 'rec_none', 'rec_daily', 'rec_weekdays',
            'rec_weekly', 'rec_monthly', 'repeatuntil', 'repeatuntilhint', 'signature', 'sig_keep',
            'sig_none', 'sig_of', 'sig_preview', 'willbesent', 'willrepeat', 'invaliddatetime', 'datepast',
            'datetoofar', 'invaliduntil', 'timezonehint', 'nosignatures', 'scheduledfor',
        ]);

        $this->include_assets();

        $this->add_button([
            'type'       => 'link',
            'label'      => 'mail_scheduler.schedule',
            'title'      => 'mail_scheduler.scheduletitle',
            'command'    => 'plugin.mail_scheduler-compose',
            'class'      => 'button schedule disabled',
            'classact'   => 'button schedule',
            'innerclass' => 'inner',
        ], 'toolbar');
    }

    public function compose_page($args)
    {
        if ($args['template'] != 'compose') {
            return $args;
        }

        $tz = $this->user_timezone();

        $this->rc->output->set_env('mail_scheduler', [
            'timezone'  => $tz,
            'quick'     => mail_scheduler_time::quick_options(
                $tz,
                (int) $this->rc->config->get('mail_scheduler_morning_hour', 8),
                (int) $this->rc->config->get('mail_scheduler_afternoon_hour', 15)
            ),
            'min_delay' => (int) $this->rc->config->get('mail_scheduler_min_delay', 60),
            'max_days'  => (int) $this->rc->config->get('mail_scheduler_max_days', 365),
            'now_local' => mail_scheduler_time::local(time(), $tz, 'Y-m-d H:i'),
            'morning'   => (int) $this->rc->config->get('mail_scheduler_morning_hour', 8),
            'locale'    => str_replace('_', '-', $_SESSION['language'] ?? 'en_US'),
        ]);

        return $args;
    }

    /**
     * Intercepta el envío cuando viene marcado como programado: en vez de
     * entregarlo por SMTP, guarda el mensaje ya compuesto y cierra el redactor.
     */
    public function message_before_send($args)
    {
        if ($this->rc->action != 'send') {
            return $args;
        }

        $date = rcube_utils::get_input_string('_schedule_date', rcube_utils::INPUT_POST);
        $time = rcube_utils::get_input_string('_schedule_time', rcube_utils::INPUT_POST);

        if ($date === '' || $date === null) {
            return $args; // envío normal
        }

        $this->add_texts('localization/');

        $tz       = $this->user_timezone();
        $send_at  = mail_scheduler_time::parse_local($date, $time, $tz);
        $min      = (int) $this->rc->config->get('mail_scheduler_min_delay', 60);
        $max_days = (int) $this->rc->config->get('mail_scheduler_max_days', 365);

        if ($send_at === false) {
            $this->abort_send('mail_scheduler.invaliddatetime');
        }

        if ($send_at < time() + max(0, $min) - 30) {
            $this->abort_send('mail_scheduler.datepast');
        }

        if ($max_days > 0 && $send_at > time() + $max_days * 86400) {
            $this->abort_send('mail_scheduler.datetoofar', ['n' => $max_days]);
        }

        $recurrence = rcube_utils::get_input_string('_schedule_recurrence', rcube_utils::INPUT_POST);
        if (!in_array($recurrence, mail_scheduler_time::RECURRENCES)) {
            $recurrence = 'none';
        }

        $until = null;
        if ($recurrence != 'none' && ($until_date = rcube_utils::get_input_string('_schedule_until', rcube_utils::INPUT_POST))) {
            $until = mail_scheduler_time::parse_local($until_date, '23:59', $tz);
            if ($until === false || $until < $send_at) {
                $this->abort_send('mail_scheduler.invaliduntil');
            }
        }

        /** @var Mail_mime $message */
        $message = $args['message'];
        $headers = $message->headers();
        $raw     = $message->getMessage();

        if (is_a($raw, 'PEAR_Error')) {
            $this->abort_send('sendingfailed');
        }

        $max_size = (int) $this->rc->config->get('mail_scheduler_max_size', 26214400);
        if ($max_size > 0 && strlen($raw) > $max_size) {
            $this->abort_send('mail_scheduler.toobig', ['size' => $this->rc->show_bytes($max_size)]);
        }

        // Destinatarios de sobre (igual que rcube::deliver_message)
        $recipients = (array) $args['mailto'];
        foreach (['Cc', 'Bcc'] as $hdr) {
            if (!empty($headers[$hdr])) {
                $recipients[] = $headers[$hdr];
            }
        }

        $compose_id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        $compose    = isset($_SESSION['compose_data_' . $compose_id]) ? $_SESSION['compose_data_' . $compose_id] : [];

        $options = [
            'dsn'        => !empty($_POST['_dsn']),
            'anchor_day' => (int) mail_scheduler_time::local($send_at, $tz, 'j'),
        ];

        if (isset($_POST['_store_target'])) {
            $options['sent_folder'] = rcube_utils::get_input_string('_store_target', rcube_utils::INPUT_POST, true);
            if ($options['sent_folder'] === '') {
                $options['nosave'] = true;
            }
        }

        foreach (['reply_uid', 'forward_uid', 'mailbox'] as $key) {
            if (!empty($compose[$key]) && is_scalar($compose[$key])) {
                $options[$key] = (string) $compose[$key];
            }
        }

        $display = [];
        foreach (['To', 'Cc', 'Bcc'] as $hdr) {
            if (!empty($headers[$hdr])) {
                $display[] = rcube_mime::decode_header($headers[$hdr], RCUBE_CHARSET);
            }
        }

        if (!$this->save_credentials()) {
            $this->abort_send('mail_scheduler.nocredentials');
        }

        $id = $this->store()->insert_message([
            'user_id'          => $this->rc->user->ID,
            'identity_id'      => (int) rcube_utils::get_input_string('_from', rcube_utils::INPUT_POST) ?: null,
            'send_at'          => $send_at,
            'timezone'         => $tz,
            'recurrence'       => $recurrence,
            'recurrence_until' => $until,
            'subject'          => mb_substr(rcube_mime::decode_header(isset($headers['Subject']) ? $headers['Subject'] : '', RCUBE_CHARSET), 0, 250),
            'mail_from'        => (string) $args['from'],
            'recipients'       => json_encode(array_values($recipients)),
            'rcpt_display'     => implode(', ', $display),
            'options'          => json_encode($options),
            'mime'             => base64_encode($raw),
            'size'             => strlen($raw),
        ]);

        if (!$id) {
            $this->abort_send('mail_scheduler.errorsaving');
        }

        $this->finish_compose($compose_id);

        $msg = $this->gettext([
            'name' => $recurrence == 'none' ? 'scheduledok' : 'scheduledrecurringok',
            'vars' => ['date' => $this->format_date($send_at, $tz)],
        ]);

        $this->rc->output->command('sent_successfully', 'confirmation', $msg, [], false);
        $this->rc->output->send('iframe');
    }

    /**
     * Cancela el envío mostrando un error en el redactor.
     */
    protected function abort_send($label, $vars = [])
    {
        $this->rc->output->show_message($label, 'error', $vars);
        $this->rc->output->send('iframe');
        exit;
    }

    /**
     * Limpieza equivalente a la de un envío normal: borrador anterior,
     * adjuntos temporales y datos de la sesión de redacción.
     */
    protected function finish_compose($compose_id)
    {
        $drafts = $this->rc->config->get('drafts_mbox');
        $old_id = rcube_utils::get_input_string('_draft_saveid', rcube_utils::INPUT_POST);

        if ($old_id && $drafts) {
            $this->rc->get_storage()->delete_message($old_id, $drafts);
        }

        if (method_exists($this->rc, 'delete_uploaded_files')) {
            // Roundcube 1.7+
            $this->rc->delete_uploaded_files($compose_id);
        }
        else {
            $this->rc->plugins->exec_hook('attachments_cleanup', ['group' => $compose_id]);
        }

        $this->rc->session->remove('compose_data_' . $compose_id);
        $_SESSION['last_compose_session'] = $compose_id;

        $this->rc->output->command('remove_compose_data', $compose_id);
    }

    /* ==================================================================
     * Ajustes
     * ================================================================== */

    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.mail_scheduler',
            'class'  => 'mail-scheduler',
            'label'  => 'scheduled',
            'title'  => 'scheduledtitle',
            'domain' => 'mail_scheduler',
        ];

        if ($this->rc->config->get('mail_scheduler_autoreply', true)) {
            $args['actions'][] = [
                'action' => 'plugin.mail_scheduler-autoreplies',
                'class'  => 'mail-autoreply',
                'label'  => 'autoreplies',
                'title'  => 'autorepliestitle',
                'domain' => 'mail_scheduler',
            ];
        }

        return $args;
    }

    /* ---------------------------- Envíos ---------------------------- */

    public function action_scheduled()
    {
        $this->include_assets();
        $this->refresh_credentials();

        $filter = rcube_utils::get_input_string('_filter', rcube_utils::INPUT_GET);
        if (!in_array($filter, ['pending', 'sent', 'all'])) {
            $filter = 'pending';
        }

        $this->rc->output->set_env('mail_scheduler_filter', $filter);
        $this->rc->output->add_label('mail_scheduler.confirmcancel', 'mail_scheduler.cancelsend',
            'mail_scheduler.listempty_pending', 'mail_scheduler.listempty_sent', 'mail_scheduler.listempty_all');

        $this->register_handler('plugin.scheduledlist', [$this, 'render_scheduled_list']);
        $this->register_handler('plugin.scheduledfilter', [$this, 'render_scheduled_filter']);

        $this->rc->output->set_pagetitle($this->gettext('scheduled'));
        $this->rc->output->send('mail_scheduler.scheduled');
    }

    public function render_scheduled_filter($attrib)
    {
        $filter  = $this->rc->output->get_env('mail_scheduler_filter');
        $pending = $this->store()->count_messages($this->rc->user->ID, ['pending', 'sending', 'failed']);
        $html    = '';

        foreach (['pending', 'sent', 'all'] as $key) {
            $label = rcube::Q($this->gettext('filter_' . $key));

            if ($key == 'pending' && $pending) {
                $label .= html::span(['class' => 'ms-count'], $pending);
            }

            $html .= html::a([
                'href'          => '#' . $key,
                'class'         => 'ms-filter-btn' . ($filter == $key ? ' active' : ''),
                'data-filter'   => $key,
                'role'          => 'tab',
                'aria-selected' => $filter == $key ? 'true' : 'false',
                'onclick'       => "return rcmail.mail_scheduler_filter('{$key}')",
            ], $label);
        }

        return html::div(['class' => 'ms-filter', 'role' => 'tablist', 'id' => $attrib['id'] ?? 'ms-filter'], $html);
    }

    public function render_scheduled_list($attrib)
    {
        $attrib += ['id' => 'scheduled-table'];

        $filter = $this->rc->output->get_env('mail_scheduler_filter') ?: 'pending';
        $table  = new html_table($attrib + ['cols' => 1]);

        $this->scheduled_rows($table, $filter);

        $this->rc->output->add_gui_object('scheduledlist', $attrib['id']);
        $this->rc->output->include_script('list.js');

        return $table->show();
    }

    protected function scheduled_rows(html_table $table, $filter)
    {
        $list = $this->store()->list_messages($this->rc->user->ID, $filter);

        foreach ($list as $row) {
            $table->set_row_attribs(['id' => 'rcmrow' . $row['id'], 'class' => 'ms-status-' . $row['status']]);
            $table->add('name', $this->scheduled_row_html($row));
        }

        return count($list);
    }

    protected function scheduled_row_html(array $row)
    {
        $subject = $row['subject'] !== '' ? $row['subject'] : $this->rc->gettext('nosubject');
        $to      = $row['rcpt_display'] !== '' ? $row['rcpt_display'] : '—';

        if ($row['status'] == 'sent') {
            $when = $this->format_date($row['last_sent_at'] ?: $row['send_at'], $row['timezone'], true);
        }
        else {
            $when = $this->format_date($row['send_at'], $row['timezone'], true);
        }

        $badges = $this->status_badge($row);

        if ($row['recurrence'] != 'none') {
            $badges .= html::span(['class' => 'ms-badge ms-badge-repeat', 'title' => $this->gettext('rec_' . $row['recurrence'])],
                rcube::Q($this->gettext('rec_short_' . $row['recurrence'])));
        }

        return html::span('ms-row-top',
                html::span('ms-subject', rcube::Q($subject))
                . html::span('ms-badges', $badges)
            )
            . html::span('ms-row-meta',
                html::span('ms-to', rcube::Q($this->gettext('to') . ' ' . $to))
            )
            . html::span('ms-row-when', html::span('ms-when', rcube::Q($when)));
    }

    protected function status_badge(array $row)
    {
        $status = $row['status'];

        if ($status == 'pending' && $row['attempts'] > 0) {
            $status = 'retrying';
        }

        return html::span(['class' => 'ms-badge ms-badge-' . $status], rcube::Q($this->gettext('status_' . $status)));
    }

    public function action_scheduled_edit()
    {
        $this->include_assets();

        $id  = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_GET);
        $row = $id ? $this->store()->get_message($id, $this->rc->user->ID) : null;

        if (!$row) {
            $this->rc->output->show_message('mail_scheduler.notfound', 'error');
            $this->rc->output->send('iframe');
        }

        $this->record = $row;

        $this->rc->output->set_env('mail_scheduler_id', $row['id']);
        $this->rc->output->set_env('mail_scheduler_status', $row['status']);
        $this->rc->output->add_label('mail_scheduler.confirmcancel', 'mail_scheduler.cancelsend',
            'mail_scheduler.confirmsendnow', 'mail_scheduler.sendnow', 'mail_scheduler.confirmtodraft',
            'mail_scheduler.todraft', 'mail_scheduler.invaliddatetime');

        $this->register_handler('plugin.scheduledform', [$this, 'render_scheduled_form']);
        $this->register_handler('plugin.scheduledpreview', [$this, 'render_scheduled_preview']);
        $this->register_handler('plugin.scheduledsummary', [$this, 'render_scheduled_summary']);

        $this->rc->output->set_pagetitle($row['subject']);
        $this->rc->output->send('mail_scheduler.scheduled_edit');
    }

    /**
     * Cabecera tipo «tarjeta» con asunto, estado, remitente y destinatarios.
     */
    public function render_scheduled_summary($attrib)
    {
        $row     = $this->record;
        $subject = $row['subject'] !== '' ? $row['subject'] : $this->rc->gettext('nosubject');
        $parts   = $this->parse_preview($row['mime']);

        $meta = html::div('ms-meta-row', html::span('ms-meta-label', rcube::Q($this->rc->gettext('from')))
            . html::span('ms-meta-value', rcube::Q($parts['from'])));

        foreach (['to' => 'to', 'cc' => 'cc', 'bcc' => 'bcc'] as $key => $label) {
            if (!empty($parts[$key])) {
                $meta .= html::div('ms-meta-row', html::span('ms-meta-label', rcube::Q($this->rc->gettext($label)))
                    . html::span('ms-meta-value', rcube::Q($parts[$key])));
            }
        }

        if (!empty($parts['attachments'])) {
            $items = '';
            foreach ($parts['attachments'] as $file) {
                $items .= html::span('ms-attachment', rcube::Q($file['name']) . ' ' . html::span('ms-size', '(' . $this->rc->show_bytes($file['size']) . ')'));
            }

            $meta .= html::div('ms-meta-row', html::span('ms-meta-label', rcube::Q($this->rc->gettext('attachments')))
                . html::span('ms-meta-value ms-attachments', $items));
        }

        $stats = [];
        if ($row['sent_count'] > 0) {
            $stats[] = $this->gettext(['name' => $row['sent_count'] == 1 ? 'sentcount1' : 'sentcount', 'vars' => ['n' => $row['sent_count']]]);
        }
        if ($row['last_sent_at']) {
            $stats[] = $this->gettext(['name' => 'lastsent', 'vars' => ['date' => $this->format_date($row['last_sent_at'], $row['timezone'])]]);
        }

        $error = '';
        if ($row['last_error'] && $row['status'] != 'sent') {
            $error = html::div(['class' => 'boxerror ms-error'],
                html::strong([], rcube::Q($this->gettext($row['status'] == 'failed' ? 'errorfailed' : 'errorretry')))
                . html::br() . rcube::Q($this->explain_error($row['last_error']))
            );
        }

        return html::div(['class' => 'ms-summary', 'id' => $attrib['id'] ?? 'ms-summary'],
            html::div('ms-summary-head',
                html::tag('h2', 'ms-summary-subject', rcube::Q($subject))
                . html::span('ms-badges', $this->status_badge($row))
            )
            . html::div('ms-meta', $meta)
            . ($stats ? html::p('ms-stats', rcube::Q(implode(' · ', $stats))) : '')
            . $error
        );
    }

    protected function explain_error($error)
    {
        if ($error == 'nocredentials') {
            return $this->gettext('err_nocredentials');
        }

        if ($error == 'interrupted') {
            return $this->gettext('err_interrupted');
        }

        return $error;
    }

    /**
     * Formulario de reprogramación (fecha, hora, repetición y fin).
     */
    public function render_scheduled_form($attrib)
    {
        $row      = $this->record;
        $tz       = $row['timezone'];
        $editable = in_array($row['status'], ['pending', 'failed']);

        $date = new html_inputfield(['name' => '_date', 'id' => 'ms-date', 'type' => 'date', 'class' => 'form-control', 'required' => true]);
        $time = new html_inputfield(['name' => '_time', 'id' => 'ms-time', 'type' => 'time', 'class' => 'form-control', 'required' => true]);

        $rec = new html_select(['name' => '_recurrence', 'id' => 'ms-recurrence', 'class' => 'custom-select']);
        foreach (mail_scheduler_time::RECURRENCES as $r) {
            $rec->add($this->gettext('rec_' . $r), $r);
        }

        $until = new html_inputfield(['name' => '_until', 'id' => 'ms-until', 'type' => 'date', 'class' => 'form-control']);

        $table = new html_table(['cols' => 2, 'class' => 'propform']);

        $table->add('title', html::label('ms-date', rcube::Q($this->gettext('sendon'))));
        $table->add(null, html::div('ms-datetime',
            $date->show(mail_scheduler_time::local($row['send_at'], $tz, 'Y-m-d'), ['disabled' => !$editable])
            . $time->show(mail_scheduler_time::local($row['send_at'], $tz, 'H:i'), ['disabled' => !$editable])
        ) . html::div('form-text ms-hint', rcube::Q($this->gettext(['name' => 'timezonehint', 'vars' => ['tz' => $tz]]))));

        $table->add('title', html::label('ms-recurrence', rcube::Q($this->gettext('repeat'))));
        $table->add(null, $rec->show($row['recurrence'], ['disabled' => !$editable]));

        $table->set_row_attribs(['class' => 'ms-until-row' . ($row['recurrence'] == 'none' ? ' hidden' : '')]);
        $table->add('title', html::label('ms-until', rcube::Q($this->gettext('repeatuntil'))));
        $table->add(null, $until->show($row['recurrence_until'] ? mail_scheduler_time::local($row['recurrence_until'], $tz, 'Y-m-d') : '', ['disabled' => !$editable])
            . html::div('form-text ms-hint', rcube::Q($this->gettext('repeatuntilhint'))));

        $hidden = new html_hiddenfield(['name' => '_id', 'value' => $row['id']]);

        $form = html::tag('fieldset', null,
            html::tag('legend', null, rcube::Q($this->gettext('schedulefieldset')))
            . $table->show()
        );

        return html::tag('form', [
                'id'       => $attrib['id'] ?? 'ms-form',
                'class'    => 'ms-form',
                'onsubmit' => 'return false',
            ], $hidden->show() . $form);
    }

    /**
     * Vista previa en texto del cuerpo (sin ejecutar HTML).
     */
    public function render_scheduled_preview($attrib)
    {
        $parts = $this->parse_preview($this->record['mime']);
        $text  = trim(str_replace(["\r\n", "\r"], "\n", $parts['text']));
        $text  = preg_replace("/\n{3,}/", "\n\n", $text);

        if ($text === '') {
            $text = $this->gettext('emptybody');
        }

        if (mb_strlen($text) > 6000) {
            $text = mb_substr($text, 0, 6000) . "\n…";
        }

        return html::tag('fieldset', ['class' => 'ms-preview-box'],
            html::tag('legend', null, rcube::Q($this->gettext('preview')))
            . html::div(['class' => 'ms-preview', 'id' => $attrib['id'] ?? 'ms-preview'], rcube::Q($text))
        );
    }

    /**
     * Extrae remitente, destinatarios, texto y adjuntos de un MIME guardado.
     */
    protected function parse_preview($raw)
    {
        $out = ['from' => '', 'to' => '', 'cc' => '', 'bcc' => '', 'text' => '', 'attachments' => []];

        require_once __DIR__ . '/lib/mail_scheduler_mailer.php';

        list($head) = mail_scheduler_mailer::split_mime($raw);
        $headers = mail_scheduler_mailer::parse_headers($head);

        foreach (['from', 'to', 'cc', 'bcc'] as $key) {
            if (!empty($headers[$key])) {
                $out[$key] = rcube_mime::decode_header($headers[$key], RCUBE_CHARSET);
            }
        }

        $struct = rcube_mime::parse_message($raw);

        if ($struct) {
            $html = null;
            $text = null;
            $this->walk_parts($struct, $html, $text, $out['attachments']);

            if ($text !== null) {
                $out['text'] = $text;
            }
            else if ($html !== null) {
                $h2t = new rcube_html2text($html, false, false, 0, RCUBE_CHARSET);
                $out['text'] = $h2t->get_text();
            }
        }

        return $out;
    }

    protected function walk_parts($part, &$html, &$text, &$attachments)
    {
        if (!empty($part->parts)) {
            foreach ($part->parts as $p) {
                $this->walk_parts($p, $html, $text, $attachments);
            }

            return;
        }

        $type     = strtolower($part->mimetype);
        $filename = !empty($part->filename) ? $part->filename : null;
        $is_att   = $filename || (isset($part->disposition) && $part->disposition == 'attachment');

        if (!$is_att && $type == 'text/plain' && $text === null) {
            $text = rcube_charset::convert((string) $part->body, $part->charset ?: RCUBE_CHARSET);
        }
        else if (!$is_att && $type == 'text/html' && $html === null) {
            $html = rcube_charset::convert((string) $part->body, $part->charset ?: RCUBE_CHARSET);
        }
        else if ($is_att || !preg_match('#^(text|multipart)/#', $type)) {
            if (!empty($part->headers['content-id']) && empty($filename)) {
                return; // imagen incrustada
            }

            $attachments[] = [
                'name' => $filename ?: $type,
                'size' => strlen((string) $part->body),
            ];
        }
    }

    /**
     * Peticiones AJAX desde la lista y el detalle de envíos.
     */
    public function action_scheduled_ajax()
    {
        $act  = rcube_utils::get_input_string('_act', rcube_utils::INPUT_POST);
        $id   = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $uid  = $this->rc->user->ID;
        $row  = $id ? $this->store()->get_message($id, $uid, $act == 'todraft') : null;

        if ($act == 'list') {
            return $this->send_list_update(rcube_utils::get_input_string('_filter', rcube_utils::INPUT_POST));
        }

        if (!$row) {
            $this->rc->output->show_message('mail_scheduler.notfound', 'error');
            return $this->rc->output->send();
        }

        switch ($act) {
        case 'save':
            $tz   = $row['timezone'];
            $date = rcube_utils::get_input_string('_date', rcube_utils::INPUT_POST);
            $time = rcube_utils::get_input_string('_time', rcube_utils::INPUT_POST);
            $rec  = rcube_utils::get_input_string('_recurrence', rcube_utils::INPUT_POST);
            $ts   = mail_scheduler_time::parse_local($date, $time, $tz);

            if (!in_array($row['status'], ['pending', 'failed'])) {
                $this->rc->output->show_message('mail_scheduler.notEditable', 'error');
                break;
            }

            if ($ts === false) {
                $this->rc->output->show_message('mail_scheduler.invaliddatetime', 'error');
                break;
            }

            if ($ts < time() + 30) {
                $this->rc->output->show_message('mail_scheduler.datepast', 'error');
                break;
            }

            if (!in_array($rec, mail_scheduler_time::RECURRENCES)) {
                $rec = 'none';
            }

            $until = null;
            $until_date = rcube_utils::get_input_string('_until', rcube_utils::INPUT_POST);
            if ($rec != 'none' && $until_date) {
                $until = mail_scheduler_time::parse_local($until_date, '23:59', $tz);
                if ($until === false || $until < $ts) {
                    $this->rc->output->show_message('mail_scheduler.invaliduntil', 'error');
                    break;
                }
            }

            $options = $row['options'];
            $options['anchor_day'] = (int) mail_scheduler_time::local($ts, $tz, 'j');

            $this->store()->update_message($id, [
                'send_at'          => $ts,
                'recurrence'       => $rec,
                'recurrence_until' => $until,
                'status'           => 'pending',
                'attempts'         => 0,
                'last_error'       => null,
                'options'          => json_encode($options),
            ], $uid);

            $this->save_credentials();
            $this->rc->output->show_message($this->gettext(['name' => 'rescheduledok', 'vars' => ['date' => $this->format_date($ts, $tz)]]), 'confirmation');
            $this->rc->output->command('mail_scheduler_saved', ['id' => $id]);
            break;

        case 'sendnow':
            if (!in_array($row['status'], ['pending', 'failed'])) {
                break;
            }

            $this->store()->update_message($id, [
                'send_at'    => time(),
                'status'     => 'pending',
                'attempts'   => 0,
                'last_error' => null,
            ], $uid);

            $this->save_credentials();
            $this->rc->output->show_message('mail_scheduler.sendnowok', 'confirmation');
            $this->rc->output->command('mail_scheduler_saved', ['id' => $id]);
            break;

        case 'cancel':
            $this->store()->delete_message($id, $uid);
            $this->rc->output->show_message('mail_scheduler.cancelledok', 'confirmation');
            $this->rc->output->command('mail_scheduler_removed', ['id' => $id]);
            break;

        case 'todraft':
            if (!in_array($row['status'], ['pending', 'failed'])) {
                break;
            }

            $storage = $this->rc->get_storage();
            $drafts  = $this->rc->config->get('drafts_mbox');

            if (!$drafts || !$storage->folder_exists($drafts)) {
                $storage->create_folder($drafts, true);
            }

            $mime  = $row['mime'];
            $saved = $storage->save_message($drafts, $mime, '', false);

            if (!$saved) {
                $this->rc->output->show_message('errorsaving', 'error');
                break;
            }

            if (is_bool($saved)) {
                list($head) = $this->split_head($mime);
                $hdrs  = mail_scheduler_mailer::parse_headers($head);
                $index = $storage->search_once($drafts, 'HEADER Message-ID ' . (isset($hdrs['message-id']) ? $hdrs['message-id'] : ''));
                $saved = $index->max();
            }

            $this->store()->delete_message($id, $uid);
            $this->rc->output->command('mail_scheduler_compose', ['uid' => $saved, 'mbox' => $drafts]);
            break;
        }

        $this->rc->output->send();
    }

    protected function split_head($mime)
    {
        require_once __DIR__ . '/lib/mail_scheduler_mailer.php';

        return mail_scheduler_mailer::split_mime($mime);
    }

    protected function send_list_update($filter)
    {
        if (!in_array($filter, ['pending', 'sent', 'all'])) {
            $filter = 'pending';
        }

        $table = new html_table(['cols' => 1]);
        $count = $this->scheduled_rows($table, $filter);
        $html  = preg_replace('!^.*<tbody>|</tbody>.*$!s', '', $table->show());

        if (!$count) {
            $html = '';
        }

        $pending = $this->store()->count_messages($this->rc->user->ID, ['pending', 'sending', 'failed']);

        $this->rc->output->command('mail_scheduler_list', [
            'html'    => $html,
            'filter'  => $filter,
            'count'   => $count,
            'pending' => $pending,
        ]);

        $this->rc->output->send();
    }

    /* ----------------------- Respuestas automáticas ----------------------- */

    public function action_autoreplies()
    {
        $this->include_assets();
        $this->refresh_credentials();

        $this->rc->output->add_label('mail_scheduler.confirmdeleteautoreply', 'mail_scheduler.deleteautoreply');
        $this->register_handler('plugin.autoreplylist', [$this, 'render_autoreply_list']);

        $this->rc->output->set_pagetitle($this->gettext('autoreplies'));
        $this->rc->output->send('mail_scheduler.autoreplies');
    }

    public function render_autoreply_list($attrib)
    {
        $attrib += ['id' => 'autoreply-table'];

        $table = new html_table($attrib + ['cols' => 1]);

        foreach ($this->store()->list_autoreplies($this->rc->user->ID) as $rule) {
            $table->set_row_attribs(['id' => 'rcmrow' . $rule['id'], 'class' => 'ms-ar-' . $this->autoreply_state($rule)]);
            $table->add('name', $this->autoreply_row_html($rule));
        }

        $this->rc->output->add_gui_object('autoreplylist', $attrib['id']);
        $this->rc->output->include_script('list.js');

        return $table->show();
    }

    protected function autoreply_state(array $rule)
    {
        $now = time();

        if (!$rule['enabled']) {
            return 'off';
        }

        if ($rule['date_until'] && $rule['date_until'] <= $now) {
            return 'ended';
        }

        if ($rule['date_from'] && $rule['date_from'] > $now) {
            return 'scheduled';
        }

        return 'active';
    }

    protected function autoreply_row_html(array $rule)
    {
        $state = $this->autoreply_state($rule);
        $name  = $rule['name'] !== '' ? $rule['name'] : $this->gettext('autoreplyunnamed');

        return html::span('ms-row-top',
                html::span('ms-subject', rcube::Q($name))
                . html::span('ms-badges', html::span('ms-badge ms-badge-ar-' . $state, rcube::Q($this->gettext('ar_state_' . $state))))
            )
            . html::span('ms-row-meta', html::span('ms-when', rcube::Q($this->period_text($rule))));
    }

    protected function period_text(array $rule)
    {
        $tz = $rule['timezone'];

        if ($rule['date_from'] && $rule['date_until']) {
            return $this->gettext(['name' => 'period_range', 'vars' => [
                'from'  => $this->format_date($rule['date_from'], $tz, true),
                'until' => $this->format_date($rule['date_until'], $tz, true),
            ]]);
        }

        if ($rule['date_from']) {
            return $this->gettext(['name' => 'period_from', 'vars' => ['from' => $this->format_date($rule['date_from'], $tz, true)]]);
        }

        if ($rule['date_until']) {
            return $this->gettext(['name' => 'period_until', 'vars' => ['until' => $this->format_date($rule['date_until'], $tz, true)]]);
        }

        return $this->gettext('period_always');
    }

    public function action_autoreply_edit()
    {
        $this->include_assets();

        $id = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_GET);

        if ($id) {
            $this->record = $this->store()->get_autoreply($id, $this->rc->user->ID);

            if (!$this->record) {
                $this->rc->output->show_message('mail_scheduler.notfound', 'error');
                $this->rc->output->send('iframe');
            }
        }

        $this->show_autoreply_form();
    }

    protected function show_autoreply_form()
    {
        $this->rc->output->set_env('mail_scheduler_id', !empty($this->record['id']) ? $this->record['id'] : null);
        $this->rc->output->add_label('mail_scheduler.sig_none', 'mail_scheduler.autoreplyunnamed', 'converting', 'editorwarning');
        $this->register_handler('plugin.autoreplyform', [$this, 'render_autoreply_form']);

        $this->rc->output->set_pagetitle($this->gettext(!empty($this->record['id']) ? 'editautoreply' : 'newautoreply'));
        $this->rc->output->send('mail_scheduler.autoreply_edit');
    }

    public function render_autoreply_form($attrib)
    {
        $rule = $this->record ?: [
            'id'            => null,
            'name'          => '',
            'enabled'       => 1,
            'date_from'     => null,
            'date_until'    => null,
            'timezone'      => $this->user_timezone(),
            'identity_id'   => null,
            'signature_id'  => null,
            'subject'       => $this->gettext('defaultarsubject'),
            'body'          => $this->gettext('defaultarbody'),
            'is_html'       => 1,
            'interval_days' => (int) $this->rc->config->get('mail_scheduler_autoreply_interval', 4),
            'options'       => mail_scheduler_store::default_autoreply_options(),
            'reply_count'   => 0,
            'last_error'    => null,
        ];

        $tz   = $this->user_timezone();
        $opts = $rule['options'];

        // Editor HTML de Roundcube (TinyMCE), igual que en las firmas
        if (class_exists('rcmail_action')) {
            rcmail_action::html_editor('identity', 'ms-ar-body');
        }
        else {
            $this->rc->output->include_script('editor.js');
        }

        $identities = $this->rc->user->list_identities(null, true);

        // Identidad desde la que se responde
        $ident = new html_select(['name' => '_identity_id', 'id' => 'ms-ar-identity', 'class' => 'custom-select']);
        foreach ($identities as $i) {
            $ident->add(format_email_recipient($i['email'], $i['name']), $i['identity_id']);
        }

        // Firma: cualquiera de las firmas guardadas en las identidades
        $sig     = new html_select(['name' => '_signature_id', 'id' => 'ms-ar-signature', 'class' => 'custom-select']);
        $sig->add($this->gettext('sig_none'), '');
        $sig_env = [];

        foreach ($identities as $i) {
            if (trim((string) $i['signature']) === '') {
                continue;
            }

            $label = $i['name'] !== '' ? $i['name'] . ' <' . $i['email'] . '>' : $i['email'];
            $sig->add($this->gettext(['name' => 'sig_of', 'vars' => ['name' => $label]]), $i['identity_id']);

            if (!empty($i['html_signature'])) {
                $h2t  = new rcube_html2text($i['signature'], false, false, 0, RCUBE_CHARSET);
                $text = $h2t->get_text();
            }
            else {
                $text = $i['signature'];
            }

            $sig_env[$i['identity_id']] = trim($text);
        }

        $this->rc->output->set_env('mail_scheduler_signatures', $sig_env);

        $field = function ($name, $type, $id, $extra = []) {
            return new html_inputfield(array_merge(['name' => $name, 'id' => $id, 'type' => $type, 'class' => 'form-control'], $extra));
        };

        $local = function ($ts, $fmt) use ($rule) {
            return $ts ? mail_scheduler_time::local($ts, $rule['timezone'], $fmt) : '';
        };

        // --- General ---
        $t1 = new html_table(['cols' => 2, 'class' => 'propform']);

        $t1->add('title', html::label('ms-ar-name', rcube::Q($this->gettext('arname'))));
        $t1->add(null, $field('_name', 'text', 'ms-ar-name', ['size' => 40, 'maxlength' => 128, 'placeholder' => $this->gettext('arnameplaceholder')])->show($rule['name']));

        $enabled = new html_checkbox(['name' => '_enabled', 'id' => 'ms-ar-enabled', 'value' => 1, 'class' => 'form-check-input']);
        $t1->add('title', html::label('ms-ar-enabled', rcube::Q($this->gettext('arenabled'))));
        $t1->add(null, html::div('custom-control custom-switch', $enabled->show($rule['enabled'] ? 1 : 0)));

        // --- Periodo ---
        $t2 = new html_table(['cols' => 2, 'class' => 'propform']);

        $t2->add('title', html::label('ms-ar-from-date', rcube::Q($this->gettext('arfrom'))));
        $t2->add(null, html::div('ms-datetime',
                $field('_from_date', 'date', 'ms-ar-from-date')->show($local($rule['date_from'], 'Y-m-d'))
                . $field('_from_time', 'time', 'ms-ar-from-time')->show($local($rule['date_from'], 'H:i') ?: '00:00')
            ) . html::div('form-text ms-hint', rcube::Q($this->gettext('arfromhint'))));

        $t2->add('title', html::label('ms-ar-until-date', rcube::Q($this->gettext('aruntil'))));
        $t2->add(null, html::div('ms-datetime',
                $field('_until_date', 'date', 'ms-ar-until-date')->show($local($rule['date_until'], 'Y-m-d'))
                . $field('_until_time', 'time', 'ms-ar-until-time')->show($local($rule['date_until'], 'H:i') ?: '23:59')
            ) . html::div('form-text ms-hint', rcube::Q($this->gettext(['name' => 'aruntilhint', 'vars' => ['tz' => $rule['timezone']]]))));

        // --- Mensaje ---
        $t3 = new html_table(['cols' => 2, 'class' => 'propform']);

        $t3->add('title', html::label('ms-ar-identity', rcube::Q($this->gettext('aridentity'))));
        $t3->add(null, $ident->show($rule['identity_id']));

        $t3->add('title', html::label('ms-ar-subject', rcube::Q($this->rc->gettext('subject'))));
        $t3->add(null, $field('_subject', 'text', 'ms-ar-subject', ['size' => 60, 'maxlength' => 250])->show($rule['subject'])
            . html::div('form-text ms-hint', $this->gettext('arvarshint')));

        $body_value = $rule['body'];
        $textarea_attr = [
            'name'             => '_body',
            'id'               => 'ms-ar-body',
            'rows'             => 12,
            'cols'             => 60,
            'class'            => 'form-control' . ($rule['is_html'] ? ' mce_editor' : ''),
            'data-html-editor' => true,
            'spellcheck'       => true,
        ];

        if ($rule['is_html']) {
            $body_value = htmlspecialchars($body_value, ENT_NOQUOTES, RCUBE_CHARSET);
            $textarea_attr['is_escaped'] = true;
        }

        $textarea = new html_textarea($textarea_attr);
        $html_cb  = new html_checkbox([
            'name'    => '_is_html',
            'id'      => 'ms-ar-ishtml',
            'value'   => 1,
            'onclick' => "return rcmail.command('toggle-editor', {id: 'ms-ar-body', html: this.checked}, '', event)",
        ]);

        $t3->add('title', html::label('ms-ar-body', rcube::Q($this->gettext('armessage'))));
        $t3->add(null, $textarea->show($body_value, ['is_escaped' => $rule['is_html'] ? true : null])
            . html::div('ms-html-toggle', html::label(['for' => 'ms-ar-ishtml'], $html_cb->show($rule['is_html'] ? 1 : 0) . ' ' . rcube::Q($this->gettext('arhtml')))));

        $t3->add('title', html::label('ms-ar-signature', rcube::Q($this->gettext('signature'))));
        $t3->add(null, $sig->show($rule['signature_id'] ? (string) $rule['signature_id'] : '')
            . html::div(['class' => 'ms-sig-preview' . ($rule['signature_id'] ? '' : ' hidden'), 'id' => 'ms-ar-sig-preview'], '')
            . (empty($sig_env) ? html::div('form-text ms-hint', rcube::Q($this->gettext('nosignatures'))) : ''));

        // --- Opciones ---
        $t4 = new html_table(['cols' => 2, 'class' => 'propform']);

        $t4->add('title', html::label('ms-ar-interval', rcube::Q($this->gettext('arinterval'))));
        $t4->add(null, html::div('ms-inline', $field('_interval_days', 'number', 'ms-ar-interval', ['min' => 1, 'max' => 365, 'class' => 'form-control ms-number'])->show($rule['interval_days'])
            . html::span('ms-unit', rcube::Q($this->gettext('days'))))
            . html::div('form-text ms-hint', rcube::Q($this->gettext('arintervalhint'))));

        foreach (['only_direct', 'skip_bulk', 'save_sent'] as $opt) {
            $cb = new html_checkbox(['name' => '_opt_' . $opt, 'id' => 'ms-ar-' . $opt, 'value' => 1, 'class' => 'form-check-input']);
            $t4->add('title', html::label('ms-ar-' . $opt, rcube::Q($this->gettext('ar_' . $opt))));
            $t4->add(null, html::div('custom-control custom-switch', $cb->show(!empty($opts[$opt]) ? 1 : 0))
                . html::div('form-text ms-hint', rcube::Q($this->gettext('ar_' . $opt . '_hint'))));
        }

        $domains = new html_textarea(['name' => '_domains', 'id' => 'ms-ar-domains', 'rows' => 2, 'cols' => 40, 'class' => 'form-control', 'placeholder' => 'empresa.com']);
        $t4->add('title', html::label('ms-ar-domains', rcube::Q($this->gettext('ar_domains'))));
        $t4->add(null, $domains->show(implode("\n", (array) $opts['domains'])) . html::div('form-text ms-hint', rcube::Q($this->gettext('ar_domains_hint'))));

        $exclude = new html_textarea(['name' => '_exclude', 'id' => 'ms-ar-exclude', 'rows' => 3, 'cols' => 40, 'class' => 'form-control', 'placeholder' => "jefe@empresa.com\n*@proveedor.com"]);
        $t4->add('title', html::label('ms-ar-exclude', rcube::Q($this->gettext('ar_exclude'))));
        $t4->add(null, $exclude->show(implode("\n", (array) $opts['exclude'])) . html::div('form-text ms-hint', rcube::Q($this->gettext('ar_exclude_hint'))));

        $status = '';
        if (!empty($rule['id'])) {
            $state  = $this->autoreply_state($rule);
            $status = html::div('ms-ar-status',
                html::span('ms-badge ms-badge-ar-' . $state, rcube::Q($this->gettext('ar_state_' . $state)))
                . ' ' . html::span('ms-stats', rcube::Q($this->gettext(['name' => (int) $rule['reply_count'] == 1 ? 'arcount1' : 'arcount', 'vars' => ['n' => (int) $rule['reply_count']]])))
            );

            if (!empty($rule['last_error'])) {
                $status .= html::div('boxerror ms-error', rcube::Q($this->explain_error($rule['last_error'])));
            }

            $overlap = $this->store()->overlapping_autoreplies($this->rc->user->ID, $rule['id'], $rule['date_from'], $rule['date_until']);
            if ($rule['enabled'] && $overlap) {
                $status .= html::div('boxwarning ms-warning', rcube::Q($this->gettext('aroverlap')));
            }
        }

        if (!$this->rc->config->get('mail_scheduler_store_credentials', true)) {
            $status .= html::div('boxwarning ms-warning', rcube::Q($this->gettext('arnocredentials')));
        }

        $fieldset = function ($legend, $content, $class = '') {
            return html::tag('fieldset', ['class' => $class], html::tag('legend', null, rcube::Q($legend)) . $content);
        };

        $hidden = new html_hiddenfield(['name' => '_id', 'value' => (string) $rule['id']]);
        $hidden->add(['name' => '_framed', 'value' => '1']);

        $content = $hidden->show()
            . $status
            . $fieldset($this->gettext('argeneral'), $t1->show())
            . $fieldset($this->gettext('arperiod'), $t2->show())
            . $fieldset($this->gettext('armessagefs'), $t3->show())
            . $fieldset($this->gettext('aroptions'), $t4->show());

        $this->rc->output->add_gui_object('autoreplyform', $attrib['id'] ?? 'ms-ar-form');

        return $this->rc->output->form_tag([
                'id'     => $attrib['id'] ?? 'ms-ar-form',
                'name'   => 'form',
                'method' => 'post',
                'action' => './?_task=settings&_action=plugin.mail_scheduler-autoreply-save&_framed=1',
                'class'  => 'ms-form',
            ], $content);
    }

    public function action_autoreply_save()
    {
        $this->add_texts('localization/');
        $this->include_assets();

        $id   = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $uid  = $this->rc->user->ID;
        $tz   = $this->user_timezone();
        $old  = $id ? $this->store()->get_autoreply($id, $uid) : null;

        if ($id && !$old) {
            $this->rc->output->show_message('mail_scheduler.notfound', 'error');
            $this->rc->output->send('iframe');
        }

        if ($old) {
            $tz = $old['timezone'];
        }

        $in = function ($name, $html = false) {
            return trim((string) rcube_utils::get_input_string($name, rcube_utils::INPUT_POST, $html));
        };

        $is_html = !empty($_POST['_is_html']);
        $body    = $in('_body', true);

        if ($is_html) {
            // Mismo saneado que las firmas HTML de Roundcube
            $body = $this->wash_html($body);
        }

        $from  = null;
        $until = null;
        $error = null;

        if ($in('_from_date') !== '') {
            $from = mail_scheduler_time::parse_local($in('_from_date'), $in('_from_time') ?: '00:00', $tz);
            if ($from === false) {
                $error = 'invalidfrom';
            }
        }

        if ($in('_until_date') !== '') {
            $until = mail_scheduler_time::parse_local($in('_until_date'), $in('_until_time') ?: '23:59', $tz);
            if ($until === false) {
                $error = 'invaliduntil';
            }
        }

        if (!$error && $from && $until && $until <= $from) {
            $error = 'untilbeforefrom';
        }

        $identity_id = (int) $in('_identity_id');
        if (!$identity_id || !$this->rc->user->get_identity($identity_id)) {
            $error = $error ?: 'invalididentity';
        }

        $signature_id = (int) $in('_signature_id') ?: null;
        if ($signature_id && !$this->rc->user->get_identity($signature_id)) {
            $signature_id = null;
        }

        $plain_body = $is_html ? trim(strip_tags($body)) : $body;
        if (!$error && $plain_body === '') {
            $error = 'emptyarbody';
        }

        $lines = function ($name) use ($in) {
            $list = preg_split('/[\s,;]+/', strtolower($in($name)), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique($list));
        };

        $options = [
            'only_direct' => !empty($_POST['_opt_only_direct']),
            'skip_bulk'   => !empty($_POST['_opt_skip_bulk']),
            'save_sent'   => !empty($_POST['_opt_save_sent']),
            'domains'     => array_map(function ($d) { return ltrim($d, '@'); }, $lines('_domains')),
            'exclude'     => $lines('_exclude'),
        ];

        $data = [
            'name'          => mb_substr($in('_name'), 0, 128),
            'enabled'       => !empty($_POST['_enabled']) ? 1 : 0,
            'date_from'     => $from ?: null,
            'date_until'    => $until ?: null,
            'timezone'      => $tz,
            'identity_id'   => $identity_id,
            'signature_id'  => $signature_id,
            'subject'       => mb_substr($in('_subject'), 0, 250),
            'body'          => $body,
            'is_html'       => $is_html ? 1 : 0,
            'interval_days' => max(1, min(365, (int) $in('_interval_days') ?: 4)),
            'options'       => $options,
        ];

        if ($error) {
            $this->rc->output->show_message('mail_scheduler.' . $error, 'error');
            $this->record = array_merge($old ?: [], $data, ['id' => $id ?: null, 'reply_count' => $old ? $old['reply_count'] : 0, 'last_error' => null]);
            return $this->show_autoreply_form();
        }

        // Si cambia el periodo o se reactiva, se vuelve a empezar desde «ahora»
        if (!$old || $old['enabled'] != $data['enabled'] || $old['date_from'] != $data['date_from']) {
            $data['last_uid']    = null;
            $data['uidvalidity'] = null;
        }

        $saved_id = $this->store()->save_autoreply($data, $id ?: null, $uid);

        if (!$saved_id) {
            $this->rc->output->show_message('errorsaving', 'error');
            $this->record = array_merge($data, ['id' => $id ?: null, 'reply_count' => 0, 'last_error' => null]);
            return $this->show_autoreply_form();
        }

        if (!$old || $old['enabled'] != $data['enabled']) {
            $this->store()->autoreply_log_reset($saved_id);
        }

        if ($data['enabled']) {
            $this->save_credentials();
        }

        $this->record = $this->store()->get_autoreply($saved_id, $uid);

        $this->rc->output->show_message('successfullysaved', 'confirmation');
        $this->rc->output->command('parent.mail_scheduler_ar_saved', [
            'id'    => $saved_id,
            'html'  => $this->autoreply_row_html($this->record),
            'state' => $this->autoreply_state($this->record),
            'isnew' => !$old,
        ]);

        $this->show_autoreply_form();
    }

    protected function wash_html($html)
    {
        // rcmail_action_settings_index::wash_html existe desde 1.5
        if (class_exists('rcmail_action_settings_index') && method_exists('rcmail_action_settings_index', 'wash_html')) {
            return rcmail_action_settings_index::wash_html($html);
        }

        $washer = new rcube_washtml(['allow_remote' => 1, 'charset' => RCUBE_CHARSET]);

        return $washer->wash($html);
    }

    public function action_autoreply_ajax()
    {
        $act = rcube_utils::get_input_string('_act', rcube_utils::INPUT_POST);
        $id  = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $uid = $this->rc->user->ID;

        if ($act == 'delete' && $id && $this->store()->delete_autoreply($id, $uid)) {
            $this->rc->output->show_message('mail_scheduler.autoreplydeleted', 'confirmation');
            $this->rc->output->command('mail_scheduler_ar_removed', ['id' => $id]);
        }
        else {
            $this->rc->output->show_message('mail_scheduler.notfound', 'error');
        }

        $this->rc->output->send();
    }

    /* ==================================================================
     * Utilidades
     * ================================================================== */

    /**
     * Mientras el usuario tenga algo pendiente, mantenemos sus credenciales al día.
     */
    protected function refresh_credentials()
    {
        try {
            if ($this->store()->has_credentials($this->rc->user->ID)) {
                $this->save_credentials();
            }
        }
        catch (Exception $e) {
            // sin consecuencias para la interfaz
        }
    }

    /**
     * Fecha legible y localizada, p. ej. «jueves, 5 de noviembre de 2026, 08:00».
     */
    protected function format_date($ts, $tz, $short = false)
    {
        if (!$ts) {
            return '';
        }

        $zone = mail_scheduler_time::zone($tz);

        if (class_exists('IntlDateFormatter')) {
            $locale = $_SESSION['language'] ?? 'en_US';
            $fmt    = new IntlDateFormatter($locale,
                $short ? IntlDateFormatter::MEDIUM : IntlDateFormatter::FULL,
                IntlDateFormatter::SHORT, $zone->getName());

            if ($short) {
                // «jue, 5 nov 2026, 8:00»
                $fmt->setPattern($this->gettext('date_pattern_short'));
            }
            else {
                $fmt->setPattern($this->gettext('date_pattern_long'));
            }

            $out = $fmt->format((int) $ts);

            if ($out !== false) {
                return $out;
            }
        }

        $dt = new DateTime('@' . (int) $ts);
        $dt->setTimezone($zone);

        return $this->rc->format_date($dt, $short ? 'D, j M Y H:i' : 'l, j F Y H:i');
    }
}
