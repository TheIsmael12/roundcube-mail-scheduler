-- Roundcube Mail Scheduler · esquema inicial (SQLite)
-- Las fechas se guardan como marcas de tiempo Unix (UTC).

CREATE TABLE IF NOT EXISTS mail_scheduler_messages (
  id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL
    REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  identity_id integer DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  send_at integer NOT NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  recurrence varchar(16) NOT NULL DEFAULT 'none',
  recurrence_until integer DEFAULT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  mail_from varchar(255) NOT NULL DEFAULT '',
  recipients text NOT NULL,
  rcpt_display text NOT NULL,
  options text NOT NULL,
  mime text NOT NULL,
  size integer NOT NULL DEFAULT 0,
  attempts integer NOT NULL DEFAULT 0,
  sent_count integer NOT NULL DEFAULT 0,
  last_error text DEFAULT NULL,
  last_sent_at integer DEFAULT NULL,
  created integer NOT NULL,
  changed integer NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_mail_scheduler_messages_user ON mail_scheduler_messages (user_id, status);
CREATE INDEX IF NOT EXISTS ix_mail_scheduler_messages_due ON mail_scheduler_messages (status, send_at);

CREATE TABLE IF NOT EXISTS mail_scheduler_autoreplies (
  id integer NOT NULL PRIMARY KEY,
  user_id integer NOT NULL
    REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  name varchar(128) NOT NULL DEFAULT '',
  enabled tinyint NOT NULL DEFAULT 1,
  date_from integer DEFAULT NULL,
  date_until integer DEFAULT NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  identity_id integer DEFAULT NULL,
  signature_id integer DEFAULT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  body text NOT NULL,
  is_html tinyint NOT NULL DEFAULT 1,
  interval_days integer NOT NULL DEFAULT 4,
  options text NOT NULL,
  last_uid integer DEFAULT NULL,
  uidvalidity varchar(32) DEFAULT NULL,
  last_error text DEFAULT NULL,
  reply_count integer NOT NULL DEFAULT 0,
  created integer NOT NULL,
  changed integer NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_mail_scheduler_autoreplies_user ON mail_scheduler_autoreplies (user_id, enabled);

CREATE TABLE IF NOT EXISTS mail_scheduler_autoreply_log (
  autoreply_id integer NOT NULL
    REFERENCES mail_scheduler_autoreplies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  sender varchar(255) NOT NULL,
  sent_at integer NOT NULL,
  PRIMARY KEY (autoreply_id, sender)
);

CREATE TABLE IF NOT EXISTS mail_scheduler_credentials (
  user_id integer NOT NULL PRIMARY KEY
    REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
  username varchar(255) NOT NULL,
  password text NOT NULL,
  host varchar(255) NOT NULL,
  port integer NOT NULL DEFAULT 143,
  ssl varchar(8) NOT NULL DEFAULT '',
  changed integer NOT NULL
);
