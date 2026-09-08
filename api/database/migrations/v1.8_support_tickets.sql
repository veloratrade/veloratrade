-- VELORA migration v1.8 — Phase 9A: Support Inbox foundation
-- Adds the support ticket system (conversations, messages, translation cache).
-- Additive only; no existing table is altered. Rollback: v1.8_support_tickets_rollback.sql

CREATE TABLE IF NOT EXISTS support_conversations (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    subject            VARCHAR(200)    NOT NULL,
    status             ENUM('open','pending','closed','archived') NOT NULL DEFAULT 'open',
    waiting_for        ENUM('admin','user','none') NOT NULL DEFAULT 'admin',
    assigned_admin_id  BIGINT UNSIGNED NULL,
    priority           ENUM('low','normal','high') NULL DEFAULT NULL,
    first_reply_at     DATETIME NULL COMMENT 'sentinel: first admin reply email event (idempotency)',
    last_message_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unread_admin_count INT UNSIGNED NOT NULL DEFAULT 0,
    unread_user_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sc_user (user_id),
    KEY idx_sc_status (status),
    KEY idx_sc_waiting (waiting_for),
    KEY idx_sc_lastmsg (last_message_at),
    KEY idx_sc_admin (assigned_admin_id),
    CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_assigned FOREIGN KEY (assigned_admin_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_messages (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id  BIGINT UNSIGNED NOT NULL,
    sender_type      ENUM('user','admin','system') NOT NULL,
    sender_user_id   BIGINT UNSIGNED NULL,
    body             TEXT NOT NULL,
    message_type     ENUM('text','system_note') NOT NULL DEFAULT 'text',
    metadata_json    TEXT NULL COMMENT 'safe operational metadata; never secrets or full bodies',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at        DATETIME NULL,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_sm_conv (conversation_id, created_at),
    KEY idx_sm_sender (sender_user_id),
    CONSTRAINT fk_sm_conv FOREIGN KEY (conversation_id) REFERENCES support_conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sm_sender FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_message_translations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id      BIGINT UNSIGNED NOT NULL,
    source_language VARCHAR(8)  NOT NULL,
    target_language VARCHAR(8)  NOT NULL,
    translated_body TEXT        NOT NULL,
    provider        VARCHAR(40) NOT NULL DEFAULT 'external',
    model           VARCHAR(80) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_smt_msg (message_id, source_language, target_language),
    CONSTRAINT fk_smt_msg FOREIGN KEY (message_id) REFERENCES support_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB;
