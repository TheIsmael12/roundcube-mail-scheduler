<?php

/**
 * Mail Scheduler · acceso a datos
 *
 * Toda la persistencia del plugin pasa por esta clase: envíos programados,
 * respuestas automáticas, su registro anti-bucle y las credenciales cifradas
 * que necesita el proceso en segundo plano.
 *
 * Compatible con MySQL/MariaDB, PostgreSQL y SQLite a través de rcube_db.
 *
 * @license GNU GPLv3+
 */
class mail_scheduler_store
{
    const T_MESSAGES    = 'mail_scheduler_messages';
    const T_AUTOREPLIES = 'mail_scheduler_autoreplies';
    const T_LOG         = 'mail_scheduler_autoreply_log';
    const T_CREDENTIALS = 'mail_scheduler_credentials';

    const STATUS_PENDING   = 'pending';
    const STATUS_SENDING   = 'sending';
    const STATUS_SENT      = 'sent';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /** @var rcube_db */
    protected $db;

    /** @var bool */
    protected static $schema_checked = false;

    public function __construct(rcube_db $db)
    {
        $this->db = $db;
    }

    /**
     * Nombre de tabla con el prefijo configurado y entrecomillado.
     */
    public function table($name)
    {
        return $this->db->table_name($name, true);
    }

    protected function q($name)
    {
        return $this->db->quote_identifier($name);
    }

    /**
     * Crea las tablas si no existen (solo si mail_scheduler_auto_install = true).
     */
    public function ensure_schema($plugin_dir)
    {
        if (self::$schema_checked) {
            return true;
        }

        self::$schema_checked = true;

        $tables = (array) $this->db->list_tables();
        $prefix = (string) rcube::get_instance()->config->get('db_prefix');

        if (in_array($prefix . self::T_MESSAGES, $tables)) {
            return true;
        }

        $driver = $this->db->db_provider;
        $file   = rtrim($plugin_dir, '/') . "/SQL/{$driver}.initial.sql";

        if (!is_readable($file)) {
            rcube::raise_error([
                'code'    => 600,
                'file'    => __FILE__,
                'line'    => __LINE__,
                'message' => "mail_scheduler: no hay esquema para el motor '{$driver}'",
            ], true, false);

            return false;
        }

        // exec_script() ya aplica el prefijo de tablas (db_prefix)
        return (bool) $this->db->exec_script(file_get_contents($file));
    }

    /* ------------------------------------------------------------------
     * Envíos programados
     * ------------------------------------------------------------------ */

    public function insert_message(array $data)
    {
        $now  = time();
        $data = array_merge([
            'status'   => self::STATUS_PENDING,
            'attempts' => 0,
            'created'  => $now,
            'changed'  => $now,
        ], $data);

        $cols = array_keys($data);

        $this->db->query(
            'INSERT INTO ' . $this->table(self::T_MESSAGES)
                . ' (' . implode(', ', array_map([$this, 'q'], $cols)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($data)
        );

        if ($this->db->is_error()) {
            return false;
        }

        return $this->db->insert_id(self::T_MESSAGES);
    }

    public function update_message($id, array $data, $user_id = null)
    {
        $data['changed'] = time();

        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->q($col) . ' = ?';
        }

        $args  = array_values($data);
        $args[] = (int) $id;
        $where = 'id = ?';

        if ($user_id !== null) {
            $where .= ' AND user_id = ?';
            $args[] = (int) $user_id;
        }

        $res = $this->db->query(
            'UPDATE ' . $this->table(self::T_MESSAGES) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $args
        );

        return $this->db->affected_rows($res) > 0;
    }

    /**
     * Pasa un mensaje de un estado a otro solo si sigue en el estado esperado
     * (bloqueo optimista para que dos procesos no envíen el mismo correo).
     */
    public function claim_message($id, $from_status, $to_status)
    {
        $res = $this->db->query(
            'UPDATE ' . $this->table(self::T_MESSAGES)
                . ' SET status = ?, changed = ? WHERE id = ? AND status = ?',
            $to_status, time(), (int) $id, $from_status
        );

        return $this->db->affected_rows($res) > 0;
    }

    public function get_message($id, $user_id = null, $with_mime = true)
    {
        $cols = $with_mime ? '*' : $this->message_cols();
        $sql  = "SELECT {$cols} FROM " . $this->table(self::T_MESSAGES) . ' WHERE id = ?';
        $args = [(int) $id];

        if ($user_id !== null) {
            $sql   .= ' AND user_id = ?';
            $args[] = (int) $user_id;
        }

        $res = $this->db->query($sql, $args);
        $row = $this->db->fetch_assoc($res);

        return $row ? $this->decode_message($row) : null;
    }

    /**
     * Lista los envíos del usuario sin el cuerpo MIME.
     *
     * @param string $filter 'pending' (pendientes y con error), 'sent' o 'all'
     */
    public function list_messages($user_id, $filter = 'pending', $limit = 200)
    {
        $sql  = 'SELECT ' . $this->message_cols() . ' FROM ' . $this->table(self::T_MESSAGES) . ' WHERE user_id = ?';
        $args = [(int) $user_id];

        if ($filter == 'pending') {
            $sql .= " AND status IN ('pending', 'sending', 'failed')";
            $sql .= ' ORDER BY send_at ASC';
        }
        else if ($filter == 'sent') {
            $sql .= " AND (status = 'sent' OR sent_count > 0)";
            $sql .= ' ORDER BY last_sent_at DESC';
        }
        else {
            $sql .= " AND status <> 'cancelled'";
            $sql .= ' ORDER BY send_at DESC';
        }

        $res  = $this->db->limitquery($sql, 0, (int) $limit, ...$args);
        $list = [];

        while ($res && ($row = $this->db->fetch_assoc($res))) {
            $list[] = $this->decode_message($row);
        }

        return $list;
    }

    public function count_messages($user_id, array $statuses)
    {
        $in  = implode(', ', array_map([$this->db, 'quote'], $statuses));
        $res = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->table(self::T_MESSAGES)
                . " WHERE user_id = ? AND status IN ({$in})",
            (int) $user_id
        );

        $row = $this->db->fetch_assoc($res);

        return $row ? (int) $row['cnt'] : 0;
    }

    /**
     * Mensajes que ya toca enviar (de todos los usuarios), sin el MIME.
     */
    public function due_messages($now, $limit = 50)
    {
        $res = $this->db->limitquery(
            'SELECT ' . $this->message_cols() . ' FROM ' . $this->table(self::T_MESSAGES)
                . ' WHERE status = ? AND send_at <= ? ORDER BY send_at ASC',
            0, (int) $limit, self::STATUS_PENDING, (int) $now
        );

        $list = [];
        while ($res && ($row = $this->db->fetch_assoc($res))) {
            $list[] = $this->decode_message($row);
        }

        return $list;
    }

    /**
     * Mensajes que se quedaron en «enviando» (proceso interrumpido).
     */
    public function stale_sending($older_than)
    {
        $res = $this->db->query(
            'SELECT id FROM ' . $this->table(self::T_MESSAGES) . ' WHERE status = ? AND changed < ?',
            self::STATUS_SENDING, (int) $older_than
        );

        $ids = [];
        while ($res && ($row = $this->db->fetch_assoc($res))) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function delete_message($id, $user_id)
    {
        $res = $this->db->query(
            'DELETE FROM ' . $this->table(self::T_MESSAGES) . ' WHERE id = ? AND user_id = ?',
            (int) $id, (int) $user_id
        );

        return $this->db->affected_rows($res) > 0;
    }

    /**
     * Borra el historial de envíos completados más antiguo que $before.
     */
    public function purge_messages($before)
    {
        $this->db->query(
            'DELETE FROM ' . $this->table(self::T_MESSAGES)
                . " WHERE status IN ('sent', 'cancelled') AND changed < ?",
            (int) $before
        );
    }

    protected function message_cols()
    {
        $cols = ['id', 'user_id', 'identity_id', 'status', 'send_at', 'timezone', 'recurrence',
            'recurrence_until', 'subject', 'mail_from', 'recipients', 'rcpt_display', 'options',
            'size', 'attempts', 'sent_count', 'last_error', 'last_sent_at', 'created', 'changed'];

        return implode(', ', array_map([$this, 'q'], $cols));
    }

    protected function decode_message(array $row)
    {
        foreach (['id', 'user_id', 'send_at', 'size', 'attempts', 'sent_count', 'created', 'changed'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }

        foreach (['identity_id', 'recurrence_until', 'last_sent_at'] as $col) {
            $row[$col] = isset($row[$col]) && $row[$col] !== '' ? (int) $row[$col] : null;
        }

        $row['recipients'] = self::json_decode($row['recipients'] ?? '');
        $row['options']    = self::json_decode($row['options'] ?? '');

        if (isset($row['mime'])) {
            $row['mime'] = (string) base64_decode($row['mime']);
        }

        return $row;
    }

    /* ------------------------------------------------------------------
     * Respuestas automáticas
     * ------------------------------------------------------------------ */

    public function list_autoreplies($user_id)
    {
        $res = $this->db->query(
            'SELECT * FROM ' . $this->table(self::T_AUTOREPLIES) . ' WHERE user_id = ? ORDER BY created ASC',
            (int) $user_id
        );

        $list = [];
        while ($res && ($row = $this->db->fetch_assoc($res))) {
            $list[] = $this->decode_autoreply($row);
        }

        return $list;
    }

    public function get_autoreply($id, $user_id = null)
    {
        $sql  = 'SELECT * FROM ' . $this->table(self::T_AUTOREPLIES) . ' WHERE id = ?';
        $args = [(int) $id];

        if ($user_id !== null) {
            $sql   .= ' AND user_id = ?';
            $args[] = (int) $user_id;
        }

        $res = $this->db->query($sql, $args);
        $row = $this->db->fetch_assoc($res);

        return $row ? $this->decode_autoreply($row) : null;
    }

    public function save_autoreply(array $data, $id = null, $user_id = null)
    {
        $now = time();
        $data['changed'] = $now;

        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = json_encode($data['options']);
        }

        if ($id) {
            $sets = [];
            foreach (array_keys($data) as $col) {
                $sets[] = $this->q($col) . ' = ?';
            }

            $args   = array_values($data);
            $args[] = (int) $id;
            $where  = 'id = ?';

            if ($user_id !== null) {
                $where .= ' AND user_id = ?';
                $args[] = (int) $user_id;
            }

            $this->db->query(
                'UPDATE ' . $this->table(self::T_AUTOREPLIES) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
                $args
            );

            return $this->db->is_error() ? false : (int) $id;
        }

        $data['created'] = $now;
        $data['user_id'] = (int) $user_id;
        $cols = array_keys($data);

        $this->db->query(
            'INSERT INTO ' . $this->table(self::T_AUTOREPLIES)
                . ' (' . implode(', ', array_map([$this, 'q'], $cols)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($data)
        );

        return $this->db->is_error() ? false : (int) $this->db->insert_id(self::T_AUTOREPLIES);
    }

    public function delete_autoreply($id, $user_id)
    {
        $this->db->query('DELETE FROM ' . $this->table(self::T_LOG) . ' WHERE autoreply_id = ?', (int) $id);

        $res = $this->db->query(
            'DELETE FROM ' . $this->table(self::T_AUTOREPLIES) . ' WHERE id = ? AND user_id = ?',
            (int) $id, (int) $user_id
        );

        return $this->db->affected_rows($res) > 0;
    }

    /**
     * Respuestas automáticas activas en este momento (de todos los usuarios).
     */
    public function active_autoreplies($now)
    {
        $res = $this->db->query(
            'SELECT * FROM ' . $this->table(self::T_AUTOREPLIES)
                . ' WHERE enabled = 1'
                . ' AND (date_from IS NULL OR date_from <= ?)'
                . ' AND (date_until IS NULL OR date_until > ?)'
                . ' ORDER BY user_id, id',
            (int) $now, (int) $now
        );

        $list = [];
        while ($res && ($row = $this->db->fetch_assoc($res))) {
            $list[] = $this->decode_autoreply($row);
        }

        return $list;
    }

    /**
     * ¿Hay alguna otra regla activa ahora mismo para el usuario (para avisar
     * de solapamientos)?
     */
    public function overlapping_autoreplies($user_id, $exclude_id, $from, $until)
    {
        $list = [];

        foreach ($this->list_autoreplies($user_id) as $rule) {
            if (!$rule['enabled'] || $rule['id'] == $exclude_id) {
                continue;
            }

            $r_from  = $rule['date_from'] ?: 0;
            $r_until = $rule['date_until'] ?: PHP_INT_MAX;
            $from_   = $from ?: 0;
            $until_  = $until ?: PHP_INT_MAX;

            if ($from_ < $r_until && $r_from < $until_) {
                $list[] = $rule;
            }
        }

        return $list;
    }

    public function update_autoreply_state($id, array $data)
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->q($col) . ' = ?';
        }

        $args   = array_values($data);
        $args[] = (int) $id;

        $this->db->query(
            'UPDATE ' . $this->table(self::T_AUTOREPLIES) . ' SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $args
        );
    }

    public function autoreply_recently_sent($autoreply_id, $sender, $since)
    {
        $res = $this->db->query(
            'SELECT sent_at FROM ' . $this->table(self::T_LOG) . ' WHERE autoreply_id = ? AND sender = ?',
            (int) $autoreply_id, $sender
        );

        $row = $this->db->fetch_assoc($res);

        return $row && (int) $row['sent_at'] >= $since;
    }

    public function autoreply_log($autoreply_id, $sender, $time)
    {
        $this->db->query(
            'DELETE FROM ' . $this->table(self::T_LOG) . ' WHERE autoreply_id = ? AND sender = ?',
            (int) $autoreply_id, $sender
        );

        $this->db->query(
            'INSERT INTO ' . $this->table(self::T_LOG) . ' (autoreply_id, sender, sent_at) VALUES (?, ?, ?)',
            (int) $autoreply_id, $sender, (int) $time
        );
    }

    public function autoreply_log_reset($autoreply_id)
    {
        $this->db->query('DELETE FROM ' . $this->table(self::T_LOG) . ' WHERE autoreply_id = ?', (int) $autoreply_id);
    }

    protected function decode_autoreply(array $row)
    {
        foreach (['id', 'user_id', 'enabled', 'is_html', 'interval_days', 'reply_count', 'created', 'changed'] as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }

        foreach (['date_from', 'date_until', 'identity_id', 'signature_id', 'last_uid'] as $col) {
            $row[$col] = isset($row[$col]) && $row[$col] !== '' ? (int) $row[$col] : null;
        }

        $row['options'] = array_merge(self::default_autoreply_options(), self::json_decode($row['options'] ?? ''));

        return $row;
    }

    public static function default_autoreply_options()
    {
        return [
            'only_direct'  => true,   // solo si mi dirección está en Para/CC
            'skip_bulk'    => true,   // no responder a listas ni a mensajes automáticos
            'save_sent'    => false,  // guardar copia en Enviados
            'domains'      => [],     // solo responder a estos dominios (vacío = todos)
            'exclude'      => [],     // nunca responder a estas direcciones / *@dominio
        ];
    }

    /* ------------------------------------------------------------------
     * Credenciales para el proceso en segundo plano
     * ------------------------------------------------------------------ */

    public function save_credentials($user_id, $username, $encrypted_password, $host, $port, $ssl)
    {
        $this->db->query('DELETE FROM ' . $this->table(self::T_CREDENTIALS) . ' WHERE user_id = ?', (int) $user_id);

        $this->db->query(
            'INSERT INTO ' . $this->table(self::T_CREDENTIALS)
                . ' (user_id, username, password, host, port, ' . $this->q('ssl') . ', changed)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
            (int) $user_id, (string) $username, (string) $encrypted_password,
            (string) $host, (int) $port, (string) $ssl, time()
        );

        return !$this->db->is_error();
    }

    public function has_credentials($user_id)
    {
        $res = $this->db->query(
            'SELECT user_id FROM ' . $this->table(self::T_CREDENTIALS) . ' WHERE user_id = ?',
            (int) $user_id
        );

        return (bool) $this->db->fetch_assoc($res);
    }

    public function get_credentials($user_id)
    {
        $res = $this->db->query(
            'SELECT * FROM ' . $this->table(self::T_CREDENTIALS) . ' WHERE user_id = ?',
            (int) $user_id
        );

        $row = $this->db->fetch_assoc($res);

        return $row ?: null;
    }

    /**
     * Elimina las credenciales de usuarios que ya no tienen nada pendiente.
     */
    public function purge_unused_credentials()
    {
        $this->db->query(
            'DELETE FROM ' . $this->table(self::T_CREDENTIALS)
                . ' WHERE user_id NOT IN (SELECT user_id FROM ' . $this->table(self::T_MESSAGES)
                . " WHERE status IN ('pending', 'sending', 'failed'))"
                . ' AND user_id NOT IN (SELECT user_id FROM ' . $this->table(self::T_AUTOREPLIES)
                . ' WHERE enabled = 1)'
        );
    }

    public function delete_credentials($user_id)
    {
        $this->db->query('DELETE FROM ' . $this->table(self::T_CREDENTIALS) . ' WHERE user_id = ?', (int) $user_id);
    }

    /* ------------------------------------------------------------------ */

    public static function json_decode($str)
    {
        if (is_array($str)) {
            return $str;
        }

        $data = $str !== '' && $str !== null ? json_decode($str, true) : null;

        return is_array($data) ? $data : [];
    }
}
