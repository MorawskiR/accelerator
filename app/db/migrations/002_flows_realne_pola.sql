-- Dopasowanie tabeli flows do realnych pol FlowDefinitionView.
--
-- Powod: 001_init.sql powstal ZANIM zobaczylismy prawdziwy obiekt. Spike OAuth
-- z 2026-08-31 pokazal describe i wyszly trzy rozbieznosci. Pelna lista pol
-- wraz z uzasadnieniem: Dev/reference/flowdefinitionview.md
--
-- Migracja jest bezpieczna, bo tabela flows jest jeszcze pusta - inwentarz
-- pobierzemy dopiero w tej fazie.

-- 1. ActiveVersionId i LatestVersionId to IDENTYFIKATORY (string), nie numery wersji.
--    Numer wersji to osobne pole VersionNumber. W 001_init.sql byly jako INT.
ALTER TABLE flows DROP COLUMN active_version;
ALTER TABLE flows DROP COLUMN latest_version;

ALTER TABLE flows ADD COLUMN active_version_id VARCHAR(18) NULL AFTER is_active;
ALTER TABLE flows ADD COLUMN latest_version_id VARCHAR(18) NULL AFTER active_version_id;
ALTER TABLE flows ADD COLUMN version_number    INT         NULL AFTER latest_version_id;

-- 2. RecordTriggerType - bez niego nie odroznimy Flow uruchamianego przy tworzeniu
--    rekordu od tego przy aktualizacji, a to zupelnie inne przypadki testowe.
ALTER TABLE flows ADD COLUMN record_trigger_type VARCHAR(64) NULL AFTER trigger_type;

-- 3. DurableId daje stabilna tozsamosc miedzy importami, Description zasila prompt
--    w Fazie 4, LastModifiedDate pozwala pomijac niezmienione Flow bez pobierania
--    ich metadanych - czyli oszczedza limit API playgrounda.
ALTER TABLE flows ADD COLUMN durable_id         VARCHAR(64)  NULL AFTER sf_id;
ALTER TABLE flows ADD COLUMN description        TEXT         NULL AFTER label;
ALTER TABLE flows ADD COLUMN last_modified_date DATETIME     NULL AFTER synced_at;

-- 4. Pola do odsiewania Flow, ktorych nie testujemy: z pakietow zarzadzanych
--    i szablonow. Filtrowanie po stronie SOQL, ale zapisujemy, zeby bylo widac
--    dlaczego czegos nie ma na liscie.
ALTER TABLE flows ADD COLUMN namespace_prefix VARCHAR(64) NULL AFTER last_modified_date;
ALTER TABLE flows ADD COLUMN manageable_state VARCHAR(32) NULL AFTER namespace_prefix;
ALTER TABLE flows ADD COLUMN is_template      TINYINT(1)  NOT NULL DEFAULT 0 AFTER manageable_state;

-- 5. DurableId jest naszym kluczem rozpoznawczym przy ponownym imporcie.
CREATE INDEX idx_flows_durable ON flows (durable_id);
