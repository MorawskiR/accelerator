<?php

declare(strict_types=1);

namespace Flownatic\Flow;

use Flownatic\Salesforce\ApiClient;
use Flownatic\Support\Db;
use RuntimeException;

/**
 * Pobiera metadane Flow z Tooling API.
 *
 * Dwa twarde ograniczenia ksztaltuja ta klase i zadne z nich nie jest
 * optymalizacja - obu nie da sie obejsc:
 *
 * 1. Salesforce zwraca pole Metadata TYLKO gdy zapytanie daje maksymalnie
 *    jeden rekord. Potwierdzone komunikatem MALFORMED_QUERY. Stad N+1 wywolan.
 * 2. max_execution_time na produkcji to 180 s. Import wszystkiego jednym
 *    zadaniem urwalby sie w polowie i zostawil baze w stanie czesciowym.
 *    Stad partie i wznawialnosc.
 *
 * Struktura metadanych: Dev/reference/flow-metadata.md
 */
final class MetadataFetcher
{
    /** Ile Flow na jedno zadanie. Ostroznie, bo kazdy to osobne wywolanie API. */
    public const DOMYSLNA_PARTIA = 5;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Lista wersji Flow - BEZ pola Metadata, wiec wolno pytac zbiorczo.
     *
     * @return array<string,array<string,mixed>> klucz: DefinitionId
     */
    public function listaWersji(): array
    {
        $w = $this->api->queryTooling(
            'SELECT Id, DefinitionId, MasterLabel, VersionNumber, Status, ProcessType '
            . 'FROM Flow ORDER BY DefinitionId, VersionNumber DESC'
        );

        $wynik = [];

        foreach ($w['records'] ?? [] as $r) {
            $def = (string) ($r['DefinitionId'] ?? '');

            if ($def === '') {
                continue;
            }

            // Pierwsza napotkana to najwyzsza wersja - sortujemy malejaco.
            $wynik[$def] ??= $r;
        }

        return $wynik;
    }

    /**
     * Metadane jednego Flow. Tylko tak, jak pozwala API.
     *
     * @return array<string,mixed>
     */
    public function metadaneJednego(string $flowId): array
    {
        if (preg_match('/^[a-zA-Z0-9]{15,18}$/', $flowId) !== 1) {
            throw new RuntimeException('Nieprawidlowy identyfikator Flow: ' . $flowId);
        }

        $w = $this->api->queryTooling(
            'SELECT Id, MasterLabel, Metadata FROM Flow WHERE Id = ' . chr(39) . $flowId . chr(39)
        );

        $meta = $w['records'][0]['Metadata'] ?? null;

        if (!is_array($meta)) {
            throw new RuntimeException('Salesforce nie zwrocil metadanych dla ' . $flowId);
        }

        return $meta;
    }

    /**
     * Flow czekajace na pobranie metadanych.
     *
     * Kryterium: brak zapisanej wersji albo Flow zmienil sie w org po ostatnim
     * pobraniu. Porownujemy LastModifiedDate z FlowDefinitionView, ktory mamy
     * juz w bazie - to oszczedza wywolanie API na kazdym niezmienionym Flow.
     *
     * @return list<array<string,mixed>>
     */
    public function oczekujace(int $connectionId, ?int $limit = null): array
    {
        $sql =
            'SELECT f.id, f.durable_id, f.api_name, f.label, f.version_number, f.last_modified_date
             FROM flows f
             LEFT JOIN flow_versions v
                    ON v.flow_id = f.id AND v.version_number = f.version_number
             WHERE f.connection_id = ?
               AND (v.id IS NULL
                    OR v.fetched_at IS NULL
                    OR (f.last_modified_date IS NOT NULL AND f.last_modified_date > v.fetched_at))
             ORDER BY f.label';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return Db::all($sql, [$connectionId]);
    }

    public function ileOczekuje(int $connectionId): int
    {
        return count($this->oczekujace($connectionId));
    }

    /**
     * Pobiera metadane dla jednej partii.
     *
     * @return array{pobrane:int, bez_zmian:int, bledy:list<string>, pozostalo:int}
     */
    public function pobierzPartie(int $connectionId, int $partia = self::DOMYSLNA_PARTIA): array
    {
        $wersje = $this->listaWersji();
        $pobrane = 0;
        $bezZmian = 0;
        $bledy = [];

        foreach ($this->oczekujace($connectionId, $partia) as $flow) {
            $def = (string) ($flow['durable_id'] ?? '');
            $rec = $wersje[$def] ?? null;

            if ($rec === null) {
                // Flow jest w inwentarzu, ale Tooling go nie widzi - np. typ
                // nieobslugiwany przez obiekt Flow. Zapisujemy pusta wersje,
                // zeby nie probowac go w kolko przy kazdej partii.
                $this->zapisz((int) $flow['id'], (int) ($flow['version_number'] ?? 0), null, null, null);
                $bledy[] = (string) $flow['label'] . ': brak w Tooling API';
                continue;
            }

            try {
                $meta = $this->metadaneJednego((string) $rec['Id']);
            } catch (\Throwable $e) {
                $bledy[] = (string) $flow['label'] . ': ' . $e->getMessage();
                continue;
            }

            $json = (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $json);

            $stara = Db::one(
                'SELECT id, metadata_hash FROM flow_versions WHERE flow_id = ? AND version_number = ?',
                [(int) $flow['id'], (int) $rec['VersionNumber']]
            );

            if ($stara !== null && $stara['metadata_hash'] === $hash) {
                // Nic sie nie zmienilo - tylko odswiezamy znacznik czasu,
                // zeby ten Flow nie wracal w kolejnych partiach.
                Db::query('UPDATE flow_versions SET fetched_at = NOW() WHERE id = ?', [(int) $stara['id']]);
                $bezZmian++;
                continue;
            }

            $this->zapisz(
                (int) $flow['id'],
                (int) $rec['VersionNumber'],
                (string) ($rec['Status'] ?? ''),
                $json,
                $hash
            );
            $pobrane++;
        }

        return [
            'pobrane'    => $pobrane,
            'bez_zmian'  => $bezZmian,
            'bledy'      => $bledy,
            'pozostalo'  => $this->ileOczekuje($connectionId),
        ];
    }

    private function zapisz(int $flowId, int $wersja, ?string $status, ?string $json, ?string $hash): void
    {
        $istnieje = Db::one(
            'SELECT id FROM flow_versions WHERE flow_id = ? AND version_number = ?',
            [$flowId, $wersja]
        );

        if ($istnieje === null) {
            Db::query(
                'INSERT INTO flow_versions (flow_id, version_number, status, metadata_json, metadata_hash, fetched_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$flowId, $wersja, $status, $json, $hash]
            );
        } else {
            Db::query(
                'UPDATE flow_versions SET status = ?, metadata_json = ?, metadata_hash = ?, fetched_at = NOW(),
                    digest_json = NULL, risks_json = NULL, digested_at = NULL
                 WHERE id = ?',
                [$status, $json, $hash, (int) $istnieje['id']]
            );
        }
    }
}
