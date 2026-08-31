-- Flownatic - schemat poczatkowy.
--
-- Pisane w przenosnym SQL-u: lokalnie MySQL 8.4, na produkcji MariaDB 10.6.
-- Zadnej skladni specyficznej dla jednego z silnikow.
--
-- metadata_json / digest_json / risks_json sa typu LONGTEXT, nie JSON.
-- Powod: MySQL ma natywny typ JSON, MariaDB traktuje go jako alias na LONGTEXT
-- z inna semantyka. My te dane tylko zapisujemy i odczytujemy w calosci,
-- wiec LONGTEXT zachowuje sie identycznie w obu.

-- ── Konto ────────────────────────────────────────────────────────
-- POC ma jednego uzytkownika i jedna org, ale tabela istnieje, zeby
-- sesja i logowanie mialy sie o co oprzec.
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Polaczenie z org ─────────────────────────────────────────────
-- Tokeny trzymane wylacznie zaszyfrowane (AES-256-GCM, Support\Crypto).
-- Kolumny sa TEXT, bo szyfrogram w base64 jest dluzszy niz token.
CREATE TABLE sf_connections (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    org_id            VARCHAR(32)  NULL,
    instance_url      VARCHAR(255) NOT NULL,
    access_token_enc  TEXT         NOT NULL,
    refresh_token_enc TEXT         NULL,
    issued_at         DATETIME     NULL,
    expires_at        DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_sf_connections_user (user_id),
    CONSTRAINT fk_sf_connections_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Inwentarz Flow ───────────────────────────────────────────────
-- Odpowiednik arkusza "Flow Inventory" z frameworku, tylko wypelniany
-- automatycznie z FlowDefinitionView zamiast recznie.
CREATE TABLE flows (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    connection_id  INT UNSIGNED NOT NULL,
    sf_id          VARCHAR(18)  NULL,
    api_name       VARCHAR(191) NOT NULL,
    label          VARCHAR(255) NULL,
    process_type   VARCHAR(64)  NULL,
    trigger_object VARCHAR(128) NULL,
    trigger_type   VARCHAR(64)  NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 0,
    active_version INT          NULL,
    latest_version INT          NULL,
    synced_at      DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flows_conn_api (connection_id, api_name),
    KEY idx_flows_process_type (process_type),
    KEY idx_flows_active (is_active),
    CONSTRAINT fk_flows_connection
        FOREIGN KEY (connection_id) REFERENCES sf_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Wersje Flow: metadane, digest, ryzyka ────────────────────────
-- metadata_hash jest sercem oszczedzania limitu API: jesli hash sie nie
-- zmienil, nie odpytujemy Salesforce o te wersje ponownie.
CREATE TABLE flow_versions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    flow_id        INT UNSIGNED NOT NULL,
    version_number INT          NOT NULL,
    status         VARCHAR(32)  NULL,
    metadata_json  LONGTEXT     NULL,
    metadata_hash  CHAR(64)     NULL,
    digest_json    LONGTEXT     NULL,
    risks_json     LONGTEXT     NULL,
    fetched_at     DATETIME     NULL,
    digested_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_flow_versions_flow_version (flow_id, version_number),
    KEY idx_flow_versions_hash (metadata_hash),
    CONSTRAINT fk_flow_versions_flow
        FOREIGN KEY (flow_id) REFERENCES flows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Przypadki testowe ────────────────────────────────────────────
-- source rozroznia wygenerowane przez model od dopisanych recznie -
-- w Fazie 6 to bedzie podstawa do policzenia, ile TC bylo trafionych.
CREATE TABLE test_cases (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    flow_version_id INT UNSIGNED NOT NULL,
    tc_code         VARCHAR(32)  NOT NULL,
    checklist_ref   VARCHAR(16)  NULL,
    category        VARCHAR(64)  NULL,
    title           VARCHAR(500) NOT NULL,
    preconditions   TEXT         NULL,
    steps           TEXT         NULL,
    expected        TEXT         NULL,
    priority        VARCHAR(16)  NULL,
    source          VARCHAR(16)  NOT NULL DEFAULT 'ai',
    status          VARCHAR(16)  NOT NULL DEFAULT 'draft',
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_test_cases_version (flow_version_id),
    KEY idx_test_cases_checklist (checklist_ref),
    KEY idx_test_cases_source (source),
    CONSTRAINT fk_test_cases_version
        FOREIGN KEY (flow_version_id) REFERENCES flow_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Koszt wywolan modelu ─────────────────────────────────────────
-- Koszt ma byc widoczny od pierwszego dnia, a nie odkrywany na fakturze.
-- DECIMAL, nie FLOAT: kwoty sumujemy, a float po drodze gubi grosze.
CREATE TABLE generation_runs (
    id                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    flow_version_id   INT UNSIGNED   NULL,
    model             VARCHAR(64)    NOT NULL,
    input_tokens      INT UNSIGNED   NOT NULL DEFAULT 0,
    output_tokens     INT UNSIGNED   NOT NULL DEFAULT 0,
    cache_read_tokens INT UNSIGNED   NOT NULL DEFAULT 0,
    cost_usd          DECIMAL(10, 6) NOT NULL DEFAULT 0,
    stop_reason       VARCHAR(32)    NULL,
    succeeded         TINYINT(1)     NOT NULL DEFAULT 1,
    error_message     TEXT           NULL,
    created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_generation_runs_version (flow_version_id),
    KEY idx_generation_runs_created (created_at),
    CONSTRAINT fk_generation_runs_version
        FOREIGN KEY (flow_version_id) REFERENCES flow_versions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
