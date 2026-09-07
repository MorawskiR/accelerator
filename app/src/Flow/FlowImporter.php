<?php

declare(strict_types=1);

namespace Flownatic\Flow;

use Flownatic\Salesforce\ApiClient;
use Flownatic\Support\Db;

/**
 * Pobiera inwentarz Flow z org do tabeli flows.
 *
 * Zastepuje reczne wypelnianie arkusza "Flow Inventory" z frameworku.
 *
 * Nazwy pol pochodza z describe wykonanego na playgroundzie 2026-08-31,
 * nie z dokumentacji - patrz Dev/reference/flowdefinitionview.md.
 */
final class FlowImporter
{
    /**
     * Flow z pakietow zarzadzanych i szablony odsiewamy po stronie SOQL.
     * Nie testujemy cudzego kodu, a kazdy niepotrzebny rekord to zmarnowany
     * limit API playgrounda przy pobieraniu metadanych w Fazie 3.
     */
    private const SOQL =
        'SELECT DurableId, ApiName, Label, Description, ProcessType, TriggerType, '
        . 'RecordTriggerType, TriggerObjectOrEventLabel, IsActive, VersionNumber, '
        . 'ActiveVersionId, LatestVersionId, LastModifiedDate, IsTemplate, '
        . 'NamespacePrefix, ManageableState '
        . 'FROM FlowDefinitionView '
        . "WHERE IsTemplate = false AND ManageableState = 'unmanaged' "
        . 'ORDER BY Label';

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * @return array{pobrane:int, dodane:int, zaktualizowane:int, zniklo:int}
     */
    public function import(int $connectionId): array
    {
        $moment  = date('Y-m-d H:i:s');
        $rekordy = $this->pobierzWszystkie();

        $dodane = 0;
        $zaktualizowane = 0;

        foreach ($rekordy as $r) {
            $apiName = (string) ($r['ApiName'] ?? '');

            if ($apiName === '') {
                continue;
            }

            $istnieje = Db::one(
                'SELECT id FROM flows WHERE connection_id = ? AND api_name = ?',
                [$connectionId, $apiName]
            );

            $dane = [
                $connectionId,
                self::txt($r, 'DurableId'),
                $apiName,
                self::txt($r, 'Label'),
                self::txt($r, 'Description'),
                self::txt($r, 'ProcessType'),
                self::txt($r, 'TriggerObjectOrEventLabel'),
                self::txt($r, 'TriggerType'),
                self::txt($r, 'RecordTriggerType'),
                !empty($r['IsActive']) ? 1 : 0,
                self::txt($r, 'ActiveVersionId'),
                self::txt($r, 'LatestVersionId'),
                isset($r['VersionNumber']) ? (int) $r['VersionNumber'] : null,
                $moment,
                self::dataCzas($r['LastModifiedDate'] ?? null),
                self::txt($r, 'NamespacePrefix'),
                self::txt($r, 'ManageableState'),
                !empty($r['IsTemplate']) ? 1 : 0,
            ];

            if ($istnieje === null) {
                Db::query(
                    'INSERT INTO flows
                        (connection_id, durable_id, api_name, label, description, process_type,
                         trigger_object, trigger_type, record_trigger_type, is_active,
                         active_version_id, latest_version_id, version_number, synced_at,
                         last_modified_date, namespace_prefix, manageable_state, is_template)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    $dane
                );
                $dodane++;
            } else {
                $dane[] = (int) $istnieje['id'];
                Db::query(
                    'UPDATE flows SET
                        connection_id = ?, durable_id = ?, api_name = ?, label = ?, description = ?,
                        process_type = ?, trigger_object = ?, trigger_type = ?, record_trigger_type = ?,
                        is_active = ?, active_version_id = ?, latest_version_id = ?, version_number = ?,
                        synced_at = ?, last_modified_date = ?, namespace_prefix = ?,
                        manageable_state = ?, is_template = ?
                     WHERE id = ?',
                    $dane
                );
                $zaktualizowane++;
            }
        }

        // Flow, ktorych nie bylo w tym imporcie, maja starszy synced_at.
        // NIE kasujemy ich: kaskada zabralaby ze soba wygenerowane przypadki
        // testowe. Widok pokaze je jako nieobecne w org.
        $zniklo = (int) (Db::one(
            'SELECT COUNT(*) AS c FROM flows WHERE connection_id = ? AND (synced_at IS NULL OR synced_at < ?)',
            [$connectionId, $moment]
        )['c'] ?? 0);

        return [
            'pobrane'        => count($rekordy),
            'dodane'         => $dodane,
            'zaktualizowane' => $zaktualizowane,
            'zniklo'         => $zniklo,
        ];
    }

    /**
     * Pobiera wszystkie strony wyniku.
     *
     * Salesforce zwraca maksymalnie 2000 rekordow naraz i wskazuje kolejna
     * strone przez nextRecordsUrl. Playground ma ich kilka, ale realna org
     * moze miec setki - bez tego import po cichu urwalby sie na 2000.
     *
     * @return list<array<string,mixed>>
     */
    private function pobierzWszystkie(): array
    {
        $wynik = $this->api->query(self::SOQL);
        $rekordy = $wynik['records'] ?? [];

        while (empty($wynik['done']) && !empty($wynik['nextRecordsUrl'])) {
            $wynik   = $this->api->get((string) $wynik['nextRecordsUrl']);
            $rekordy = array_merge($rekordy, $wynik['records'] ?? []);
        }

        return array_values($rekordy);
    }

    /** @param array<string,mixed> $r */
    private static function txt(array $r, string $klucz): ?string
    {
        $v = $r[$klucz] ?? null;

        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** Salesforce zwraca ISO 8601, MySQL chce Y-m-d H:i:s. */
    private static function dataCzas(mixed $wartosc): ?string
    {
        if (!is_string($wartosc) || $wartosc === '') {
            return null;
        }

        $ts = strtotime($wartosc);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
