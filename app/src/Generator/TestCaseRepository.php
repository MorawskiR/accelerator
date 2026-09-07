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

    public function policz(int $flowVersionId): int
    {
        return (int) (Db::one(
            'SELECT COUNT(*) AS ile FROM test_cases WHERE flow_version_id = ?',
            [$flowVersionId]
        )['ile'] ?? 0);
    }
}
