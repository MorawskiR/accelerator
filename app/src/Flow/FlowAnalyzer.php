<?php

declare(strict_types=1);

namespace Flownatic\Flow;

use Flownatic\Support\Db;
use RuntimeException;

/**
 * Spina pobrane metadane z DigestBuilder i RiskScanner, i utrwala wynik.
 *
 * MetadataFetcher zapisuje wylacznie surowe metadane, a przy kazdej zmianie
 * kasuje digest_json i risks_json - bo policzone wczesniej opisywalyby juz
 * nieaktualna strukture. Ta klasa jest druga polowa tej umowy: liczy je
 * na zadanie i zapisuje z powrotem.
 *
 * Liczenie jest leniwe (dopiero przy ogladaniu), a nie przy imporcie, z dwoch
 * powodow. Po pierwsze import ma twardy limit 180 s i kazda sekunda w nim jest
 * droga. Po drugie digest jest tani - to czysty PHP bez wywolan API - wiec nie
 * ma po co liczyc go dla Flow, ktorych nikt nie oglada.
 *
 * Zapis jest mimo to potrzebny: Faza 4 wysyla digest do modelu, a Faza 5
 * eksportuje ryzyka do .xlsx. Obie musza dostac dokladnie to samo, co tester
 * zobaczyl na ekranie.
 */
final class FlowAnalyzer
{
    /** Kolejnosc wyswietlania ryzyk - najgrozniejsze na gorze. */
    private const KOLEJNOSC_WAG = [
        RiskScanner::WAGA_WYSOKA  => 0,
        RiskScanner::WAGA_SREDNIA => 1,
        RiskScanner::WAGA_NISKA   => 2,
    ];

    public function __construct(
        private readonly DigestBuilder $builder = new DigestBuilder(),
        private readonly RiskScanner $scanner = new RiskScanner(),
    ) {
    }

    /**
     * Analiza najnowszej pobranej wersji Flow.
     *
     * Zwraca null, gdy metadanych jeszcze nie pobrano - widok pokazuje wtedy
     * przycisk pobrania zamiast pustej strony.
     *
     * @return array{wersja:array<string,mixed>, digest:array<string,mixed>, ryzyka:list<array<string,mixed>>, podsumowanie:array<string,int>}|null
     */
    public function analiza(int $flowId): ?array
    {
        $wersja = Db::one(
            'SELECT * FROM flow_versions
             WHERE flow_id = ? AND metadata_json IS NOT NULL
             ORDER BY version_number DESC
             LIMIT 1',
            [$flowId]
        );

        if ($wersja === null) {
            return null;
        }

        if (($wersja['digest_json'] ?? null) === null || ($wersja['risks_json'] ?? null) === null) {
            $wersja = $this->przelicz($wersja);
        }

        $digest = json_decode((string) $wersja['digest_json'], true);
        $ryzyka = json_decode((string) $wersja['risks_json'], true);

        $digest = is_array($digest) ? $digest : [];
        $ryzyka = is_array($ryzyka) ? array_values($ryzyka) : [];

        return [
            'wersja'       => $wersja,
            'digest'       => $digest,
            'ryzyka'       => $this->posortuj($ryzyka),
            'podsumowanie' => RiskScanner::podsumuj($ryzyka),
        ];
    }

    /**
     * Liczy digest i ryzyka od nowa, zapisuje przy wersji i zwraca ja uzupelniona.
     *
     * @param array<string,mixed> $wersja wiersz z flow_versions
     * @return array<string,mixed>
     */
    public function przelicz(array $wersja): array
    {
        $meta = json_decode((string) ($wersja['metadata_json'] ?? ''), true);

        if (!is_array($meta)) {
            throw new RuntimeException('Wersja ' . (string) ($wersja['id'] ?? '?') . ' nie ma czytelnych metadanych.');
        }

        $digest = $this->builder->build($meta);
        $ryzyka = $this->scanner->scan($digest);

        $digestJson = (string) json_encode($digest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ryzykaJson = (string) json_encode($ryzyka, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Db::query(
            'UPDATE flow_versions SET digest_json = ?, risks_json = ?, digested_at = NOW() WHERE id = ?',
            [$digestJson, $ryzykaJson, (int) $wersja['id']]
        );

        $wersja['digest_json'] = $digestJson;
        $wersja['risks_json']  = $ryzykaJson;

        return $wersja;
    }

    /**
     * Liczniki ryzyk dla calego inwentarza - do kolumny na liscie Flow.
     *
     * Dolicza brakujace po drodze, bo bez tego lista pokazywalaby "nie
     * przeanalizowany" przy kazdym Flow, dopoki ktos go nie otworzy. Limit
     * jest zabezpieczeniem na wypadek org z setkami Flow: liczenie jest tanie,
     * ale nie darmowe, a strona listy ma sie otworzyc od razu.
     *
     * @return array<int,array{ryzyka:array<string,int>, razem:int}> klucz: flow_id
     */
    public function podsumowania(int $connectionId, int $limitPrzeliczen = 50): array
    {
        $wiersze = Db::all(
            'SELECT v.*
             FROM flow_versions v
             JOIN flows f ON f.id = v.flow_id
             WHERE f.connection_id = ? AND v.metadata_json IS NOT NULL
             ORDER BY v.flow_id, v.version_number DESC',
            [$connectionId]
        );

        $wynik = [];
        $przeliczone = 0;

        foreach ($wiersze as $w) {
            $flowId = (int) $w['flow_id'];

            // Sortowanie malejaco po wersji - pierwszy wiersz to najnowsza.
            if (isset($wynik[$flowId])) {
                continue;
            }

            if (($w['risks_json'] ?? null) === null && $przeliczone < $limitPrzeliczen) {
                try {
                    $w = $this->przelicz($w);
                    $przeliczone++;
                } catch (\Throwable) {
                    // Uszkodzone metadane jednego Flow nie moga wywrocic calej listy.
                    continue;
                }
            }

            $ryzyka = json_decode((string) ($w['risks_json'] ?? ''), true);

            if (!is_array($ryzyka)) {
                continue;
            }

            $wynik[$flowId] = [
                'ryzyka' => RiskScanner::podsumuj($ryzyka),
                'razem'  => count($ryzyka),
            ];
        }

        return $wynik;
    }

    /**
     * @param list<array<string,mixed>> $ryzyka
     * @return list<array<string,mixed>>
     */
    private function posortuj(array $ryzyka): array
    {
        usort($ryzyka, static function (array $a, array $b): int {
            $wa = self::KOLEJNOSC_WAG[(string) ($a['waga'] ?? '')] ?? 9;
            $wb = self::KOLEJNOSC_WAG[(string) ($b['waga'] ?? '')] ?? 9;

            return $wa <=> $wb;
        });

        return $ryzyka;
    }
}
