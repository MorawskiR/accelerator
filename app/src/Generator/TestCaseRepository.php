<?php

declare(strict_types=1);

namespace Flownatic\Generator;

use Flownatic\Support\Db;

/**
 * Zapis i odczyt przypadkow testowych dla wersji Flow.
 *
 * Jedna zasada, ktora warto znac przed czytaniem kodu: **ponowne generowanie
 * nadpisuje wylacznie przypadki z tego samego zrodla.** Wygenerowane regulami
 * kasujemy i zapisujemy od nowa, bo powstaja z metadanych i maja byc ich
 * wiernym odbiciem. Ale przypadkow dopisanych recznie przez testera
 * (source = manual) nie ruszamy nigdy - to jego praca, nie nasza.
 *
 * Bez tego rozroznienia kazde klikniecie "Generuj testy" albo duplikowaloby
 * liste, albo kasowalo dopiski. Oba warianty konczylyby sie utrata zaufania
 * do narzedzia szybciej niz jakikolwiek blad w regulach.
 */
final class TestCaseRepository
{
    public const ZRODLO_REGULY   = 'reguly';
    public const ZRODLO_WKLEJONE = 'wklejone';
    public const ZRODLO_RECZNE   = 'manual';

    /**
     * Stan przegladu przypadku - nie mylic ze statusem wykonania testu.
     *
     * Ten tutaj mowi, czy tester zaakceptowal przypadek do wykonania.
     * Status wykonania (Pass/Fail/Blocked) zyje w wyeksportowanym arkuszu,
     * bo to tam tester pracuje.
     */
    public const STATUS_ROBOCZY     = 'draft';
    public const STATUS_ZAAKCEPTOWANY = 'zaakceptowany';
    public const STATUS_ODRZUCONY   = 'odrzucony';

    /** @var list<string> */
    public const STATUSY = [self::STATUS_ROBOCZY, self::STATUS_ZAAKCEPTOWANY, self::STATUS_ODRZUCONY];

    /**
     * Podmienia przypadki z danego zrodla na nowy komplet.
     *
     * @param list<array<string,mixed>> $przypadki
     * @return int ile zapisano
     */
    public function zapisz(int $flowVersionId, array $przypadki, string $zrodlo): int
    {
        $pdo = Db::conn();
        $pdo->beginTransaction();

        try {
            Db::query(
                'DELETE FROM test_cases WHERE flow_version_id = ? AND source = ?',
                [$flowVersionId, $zrodlo]
            );

            foreach ($przypadki as $tc) {
                Db::query(
                    'INSERT INTO test_cases
                        (flow_version_id, tc_code, checklist_ref, category, title,
                         preconditions, steps, expected, priority, source, status, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $flowVersionId,
                        (string) ($tc['tc_code'] ?? ''),
                        (string) ($tc['checklist_ref'] ?? ''),
                        (string) ($tc['category'] ?? ''),
                        (string) ($tc['title'] ?? ''),
                        $tc['preconditions'] ?? null,
                        (string) ($tc['steps'] ?? ''),
                        (string) ($tc['expected'] ?? ''),
                        (string) ($tc['priority'] ?? Framework::PRIORYTET_KLUCZOWY),
                        $zrodlo,
                        'draft',
                        (int) ($tc['sort_order'] ?? 0),
                    ]
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            // Bez wycofania zostalaby polowa kompletu - gorsza niz brak.
            $pdo->rollBack();

            throw $e;
        }

        return count($przypadki);
    }

    /**
     * Wszystkie przypadki wersji, w kolejnosci do wyswietlenia.
     *
     * @return list<array<string,mixed>>
     */
    public function dla(int $flowVersionId): array
    {
        return Db::all(
            'SELECT * FROM test_cases WHERE flow_version_id = ? ORDER BY sort_order, id',
            [$flowVersionId]
        );
    }

    /**
     * Liczniki wedlug zrodla - do pokazania nad lista.
     *
     * @return array<string,int>
     */
    public function podsumowanie(int $flowVersionId): array
    {
        $wiersze = Db::all(
            'SELECT source, COUNT(*) AS ile FROM test_cases WHERE flow_version_id = ? GROUP BY source',
            [$flowVersionId]
        );

        $wynik = [];

        foreach ($wiersze as $w) {
            $wynik[(string) $w['source']] = (int) $w['ile'];
        }

        return $wynik;
    }

    /**
     * Jeden przypadek wraz ze sprawdzeniem, czy nalezy do tej wersji Flow.
     *
     * Identyfikator przychodzi z adresu URL, wiec sama zgodnosc id nie
     * wystarcza - inaczej podmiana numeru pozwalalaby edytowac cudzy wpis.
     *
     * @return array<string,mixed>|null
     */
    public function jeden(int $id, int $flowVersionId): ?array
    {
        return Db::one(
            'SELECT * FROM test_cases WHERE id = ? AND flow_version_id = ?',
            [$id, $flowVersionId]
        );
    }

    /**
     * Zapisuje zmiany wprowadzone recznie przez testera.
     *
     * Zmienione pola zostaja przy swoim zrodle - przypadek wygenerowany
     * z regul i poprawiony recznie **nadal jest z regul**, wiec ponowne
     * generowanie go nadpisze. To jest zamierzone: gdyby edycja zmieniala
     * zrodlo na manual, jedna literowka zamrazalaby przypadek na zawsze
     * i lista przestalaby odzwierciedlac metadane. Kto chce trwalej wersji,
     * dopisuje wlasny przypadek.
     *
     * @param array<string,mixed> $pola
     */
    public function zapiszJeden(int $id, array $pola): void
    {
        Db::query(
            'UPDATE test_cases
                SET title = ?, preconditions = ?, steps = ?, expected = ?, priority = ?, updated_at = NOW()
              WHERE id = ?',
            [
                trim((string) ($pola['title'] ?? '')),
                trim((string) ($pola['preconditions'] ?? '')) !== '' ? trim((string) $pola['preconditions']) : null,
                trim((string) ($pola['steps'] ?? '')),
                trim((string) ($pola['expected'] ?? '')),
                (string) ($pola['priority'] ?? Framework::PRIORYTET_KLUCZOWY),
                $id,
            ]
        );
    }

    public function zmienStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSY, true)) {
            throw new \InvalidArgumentException('Nieznany status przypadku: ' . $status);
        }

        Db::query('UPDATE test_cases SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
    }

    public function usun(int $id): void
    {
        Db::query('DELETE FROM test_cases WHERE id = ?', [$id]);
    }

    /**
     * Dopisuje wlasny przypadek testera.
     *
     * Kod nadajemy dalej rosnaco w obrebie wersji, zeby lista czytala sie
     * jak jedna calosc, a nie dwie osobne numeracje.
     *
     * @param array<string,mixed> $pola
     */
    public function dodajReczny(int $flowVersionId, array $pola, string $prefiks): int
    {
        $kod = $prefiks . '-' . str_pad((string) $this->nastepnyNumer($flowVersionId, $prefiks), 3, '0', STR_PAD_LEFT);
        $ref = (string) ($pola['checklist_ref'] ?? '');

        if (!Framework::znany($ref)) {
            $ref = 'TC-001';
        }

        Db::query(
            'INSERT INTO test_cases
                (flow_version_id, tc_code, checklist_ref, category, title,
                 preconditions, steps, expected, priority, source, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $flowVersionId,
                $kod,
                $ref,
                Framework::kategoria($ref) ?? 'Przypadki per typ Flow',
                trim((string) ($pola['title'] ?? '')),
                trim((string) ($pola['preconditions'] ?? '')) !== '' ? trim((string) $pola['preconditions']) : null,
                trim((string) ($pola['steps'] ?? '')),
                trim((string) ($pola['expected'] ?? '')),
                (string) ($pola['priority'] ?? Framework::PRIORYTET_KLUCZOWY),
                self::ZRODLO_RECZNE,
                self::STATUS_ZAAKCEPTOWANY,   // wlasny przypadek nie wymaga akceptacji
                $this->nastepnaKolejnosc($flowVersionId),
            ]
        );

        return (int) Db::conn()->lastInsertId();
    }

    /** Najwyzszy uzyty numer w kodach o tym prefiksie, powiekszony o jeden. */
    private function nastepnyNumer(int $flowVersionId, string $prefiks): int
    {
        $kody = Db::all(
            'SELECT tc_code FROM test_cases WHERE flow_version_id = ? AND tc_code LIKE ?',
            [$flowVersionId, $prefiks . '-%']
        );

        $max = 0;

        foreach ($kody as $w) {
            if (preg_match('/-(\d+)$/', (string) $w['tc_code'], $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    private function nastepnaKolejnosc(int $flowVersionId): int
    {
        return (int) (Db::one(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS nastepna FROM test_cases WHERE flow_version_id = ?',
            [$flowVersionId]
        )['nastepna'] ?? 1);
    }

    /**
     * Przypadki do eksportu - **bez odrzuconych**.
     *
     * To jest cala roznica miedzy akceptacja, ktora cos znaczy, a przyciskiem
     * bez konsekwencji: odrzucony przypadek zostaje na ekranie, zeby bylo
     * widac decyzje, ale nie trafia do pliku oddawanego klientowi.
     *
     * @return list<array<string,mixed>>
     */
    public function doEksportu(int $flowVersionId): array
    {
        return Db::all(
            'SELECT * FROM test_cases
              WHERE flow_version_id = ? AND status <> ?
              ORDER BY sort_order, id',
            [$flowVersionId, self::STATUS_ODRZUCONY]
        );
    }

    /**
     * Czy przypadki opisuja juz nieaktualna strukture Flow.
     *
     * Problem, ktory to rozwiazuje: gdy Flow zmieni sie w org, MetadataFetcher
     * nadpisuje metadane i zeruje digest_json oraz risks_json, wiec struktura
     * i ryzyka przeliczaja sie same. Ale przypadki testowe **zostaja** - opisuja
     * poprzednia wersje i nic o tym nie mowia. Nazwy elementow zwykle sie nie
     * zmieniaja, wiec na oko wszystko wyglada dobrze.
     *
     * Sygnalem jest digested_at, a NIE fetched_at: fetched_at odswieza sie
     * takze wtedy, gdy metadane byly bez zmian, wiec kazde ponowne pobranie
     * falszywie unieważniałoby liste. digested_at zmienia sie wylacznie po
     * faktycznej zmianie metadanych, bo tylko wtedy digest jest liczony od nowa.
     *
     * Nie kasujemy przypadkow po cichu - tester ma zobaczyc ostrzezenie
     * i sam zdecydowac. Ciche kasowanie wyglada jak zgubienie pracy.
     *
     * @return array{nieaktualne:bool, wygenerowano:?string}
     */
    public function stanAktualnosci(int $flowVersionId, ?string $przeliczono): array
    {
        $wygenerowano = Db::one(
            'SELECT MAX(created_at) AS ostatni FROM test_cases WHERE flow_version_id = ? AND source = ?',
            [$flowVersionId, self::ZRODLO_REGULY]
        )['ostatni'] ?? null;

        return [
            'nieaktualne'  => self::czyPrzeterminowane(
                $wygenerowano === null ? null : (string) $wygenerowano,
                $przeliczono
            ),
            'wygenerowano' => $wygenerowano === null ? null : (string) $wygenerowano,
        ];
    }

    /**
     * Czyste porownanie dwoch znacznikow czasu - wydzielone, zeby dalo sie
     * je sprawdzic testem bez bazy.
     *
     * Rownosc traktujemy jako "aktualne". Oba znaczniki stawia MySQL z NOW(),
     * a DATETIME ma dokladnosc do sekundy, wiec wygenerowanie i przeliczenie
     * w tej samej sekundzie jest mozliwe. Wtedy przypadki powstaly z digestu,
     * ktory wlasnie policzono - a nie przed nim.
     */
    public static function czyPrzeterminowane(?string $wygenerowano, ?string $przeliczono): bool
    {
        // Nie ma przypadkow albo nie ma digestu - nie ma czego uniewazniac.
        if ($wygenerowano === null || $przeliczono === null) {
            return false;
        }

        $a = strtotime($wygenerowano);
        $b = strtotime($przeliczono);

        if ($a === false || $b === false) {
            return false;
        }

        return $a < $b;
    }

    public function policz(int $flowVersionId): int
    {
        return (int) (Db::one(
            'SELECT COUNT(*) AS ile FROM test_cases WHERE flow_version_id = ?',
            [$flowVersionId]
        )['ile'] ?? 0);
    }
}
