<?php

declare(strict_types=1);

namespace Flownatic\Generator;

/**
 * Zrodlo przypadkow testowych dla jednego Flow.
 *
 * Istnieje po to, zeby sposob powstawania TC dalo sie wymienic bez ruszania
 * reszty aplikacji. Dzis sa dwa zrodla i oba kosztuja zero:
 *
 * - TemplateGenerator - reguly deterministyczne, domyslny,
 * - ClipboardImporter - wynik wklejony z Claude.ai, oplacony subskrypcja.
 *
 * Trzecie, ApiGenerator, wolaloby platne /v1/messages. Nie budujemy go
 * (decyzja z 2026-09-07: projekt ma dzialac bez kosztow), ale interfejs
 * zostawia na nie miejsce - gdyby kiedys pojawily sie kredyty, dochodzi
 * jedna klasa i nic wiecej.
 */
interface TestCaseSource
{
    /**
     * @param array<string,mixed>       $digest wynik DigestBuilder
     * @param list<array<string,mixed>> $ryzyka wynik RiskScanner
     * @return list<array<string,mixed>> przypadki gotowe do zapisu w test_cases
     */
    public function generuj(array $digest, array $ryzyka): array;

    /** Wartosc do kolumny test_cases.source. */
    public function zrodlo(): string;
}
