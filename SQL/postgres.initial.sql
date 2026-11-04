-- Roundcube Mail Scheduler · esquema inicial (PostgreSQL)
-- Las fechas se guardan como marcas de tiempo Unix (UTC).

CREATE SEQUENCE mail_scheduler_messages_seq
    START WITH 1 INCREMENT BY 1 NO MAXVALUE NO MINVALUE CACHE 1;

CREATE TABLE mail_scheduler_messages (
    id integer DEFAULT nextval('mail_scheduler_messages_seq'::text) PRIMARY KEY,
    user_id integer NOT NULL
        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    identity_id integer DEFAULT NULL,
    status varchar(16) DEFAULT 'pending' NOT NULL,
    send_at bigint NOT NULL,
    timezone varchar(64) DEFAULT 'UTC' NOT NULL,
    recurrence varchar(16) DEFAULT 'none' NOT NULL,
    recurrence_until bigint DEFAULT NULL,
    subject varchar(255) DEFAULT '' NOT NULL,
    mail_from varchar(255) DEFAULT '' NOT NULL,
    recipients text NOT NULL,
    rcpt_display text NOT NULL,
    options text NOT NULL,
    mime text NOT NULL,
    size integer DEFAULT 0 NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    sent_count integer DEFAULT 0 NOT NULL,
    last_error text DEFAULT NULL,
    last_sent_at bigint DEFAULT NULL,
    created bigint NOT NULL,
    changed bigint NOT NULL
);

CREATE INDEX mail_scheduler_messages_user_idx ON mail_scheduler_messages (user_id, status);
CREATE INDEX mail_scheduler_messages_due_idx ON mail_scheduler_messages (status, send_at);

CREATE SEQUENCE mail_scheduler_autoreplies_seq
    START WITH 1 INCREMENT BY 1 NO MAXVALUE NO MINVALUE CACHE 1;

CREATE TABLE mail_scheduler_autoreplies (
    id integer DEFAULT nextval('mail_scheduler_autoreplies_seq'::text) PRIMARY KEY,
    user_id integer NOT NULL
        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    name varchar(128) DEFAULT '' NOT NULL,
    enabled smallint DEFAULT 1 NOT NULL,
    date_from bigint DEFAULT NULL,
    date_until bigint DEFAULT NULL,
    timezone varchar(64) DEFAULT 'UTC' NOT NULL,
    identity_id integer DEFAULT NULL,
    signature_id integer DEFAULT NULL,
    subject varchar(255) DEFAULT '' NOT NULL,
    body text NOT NULL,
    is_html smallint DEFAULT 1 NOT NULL,
    interval_days smallint DEFAULT 4 NOT NULL,
    options text NOT NULL,
    last_uid bigint DEFAULT NULL,
    uidvalidity varchar(32) DEFAULT NULL,
    last_error text DEFAULT NULL,
    reply_count integer DEFAULT 0 NOT NULL,
    created bigint NOT NULL,
    changed bigint NOT NULL
);

CREATE INDEX mail_scheduler_autoreplies_user_idx ON mail_scheduler_autoreplies (user_id, enabled);

CREATE TABLE mail_scheduler_autoreply_log (
    autoreply_id integer NOT NULL
        REFERENCES mail_scheduler_autoreplies (id) ON DELETE CASCADE ON UPDATE CASCADE,
    sender varchar(255) NOT NULL,
    sent_at bigint NOT NULL,
    PRIMARY KEY (autoreply_id, sender)
);

CREATE TABLE mail_scheduler_credentials (
    user_id integer NOT NULL PRIMARY KEY
        REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    username varchar(255) NOT NULL,
    password text NOT NULL,
    host varchar(255) NOT NULL,
    port integer DEFAULT 143 NOT NULL,
    ssl varchar(8) DEFAULT '' NOT NULL,
    changed bigint NOT NULL
);
