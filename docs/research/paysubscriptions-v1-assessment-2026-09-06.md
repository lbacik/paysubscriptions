# Ocena zakresu PaySubscriptions v1

Stan analizy: 2026-09-06. Materiał doradczy, nie zatwierdzona zmiana zakresu.

## Wniosek

Kierunek jest rozsądny dla jednej osoby utrzymującej usługę: darmowy, ręczny tracker wydatków cyklicznych, bez banków i skanowania poczty. Jednak lista subskrypcji z wykresami daje słaby powód, by użytkownik regularnie aktualizował dane. Proponuję oprzeć v1 na trzech rezultatach: znam koszt swoich zobowiązań, wiem, co niedługo się odnowi, mam czas zareagować przed odnowieniem.

Samo „bez banku” nie jest unikalną przewagą. Porównanie i źródła: [raport konkurencyjny](paysubscriptions-v1-competitors-2026-09-06.md). Wyróżnik powinien wynikać z wygody całego procesu, dostępności przez WWW i wiarygodnych zasad przetwarzania danych. To hipoteza pozycjonowania wymagająca walidacji z użytkownikami.

## Co rzeczywiście zawiera mapa

[Mapa #19](https://github.com/lbacik/paysubscriptions/issues/19) i jej dziewięć otwartych zadań #20–#28 opisują proces planowania. Sekcja „Decisions so far” pozostaje pusta. Zakres funkcji dopiero ma zostać wybrany w [#23](https://github.com/lbacik/paysubscriptions/issues/23). Nie należy więc traktować poniższych luk jako odrzuconych funkcji ani przypisywać planom statusu wdrożenia.

Mocne strony mapy:

- Jasne ograniczenie integracji, płatności i zastosowań firmowych.
- Oparcie zakresu na bieżącym produkcie i rynku przed tworzeniem stron promocyjnych.
- Osobne kryteria jakości wydania w [#24](https://github.com/lbacik/paysubscriptions/issues/24).
- Publiczna roadmapa bez obietnic terminowych.

Brakuje wyraźnego miejsca na walidację z użytkownikami, prototyp głównego procesu w aplikacji i ekonomię utrzymania darmowej usługi. Wspomniane w mapie przyszłe pomiary i rollout nie zastępują sprawdzenia, czy ktoś zechce ręcznie utrzymywać swoją listę. To rekomendacja uzupełnienia procesu, nie twierdzenie, że mapa zakazuje tych działań.

## Punkt wyjścia w repozytorium

Analiza statyczna lokalnego `main`, commit `fee9892`; bez testowania zalogowanej produkcji. Stan wdrożenia może różnić się od tej wersji.

| Obszar | Potwierdzony zakres / ograniczenie | Źródło |
|---|---|---|
| Rejestr subskrypcji | Dodawanie, edycja i usuwanie; nazwa, pierwsza płatność, kwota miesięczna albo roczna | [formularz](../../src/Form/SubscriptionType.php), [kontroler](../../src/Controller/SubscriptionController.php) |
| Podsumowania | Przeliczenie miesiąc/rok i wykresy; brak rejestru potwierdzonych transakcji w modelu | [model](../../src/Entity/Subscription.php), [wykresy](../../src/Service/ChartService.php) |
| Harmonogram | Formularz wymaga daty pierwszej płatności; w tabeli widnieje „from”, nie termin następnego odnowienia | [wiersz tabeli](../../templates/dashboard/_row.html.twig) |
| Waluty i cykl życia | Model nie ma waluty, końca triala, daty zakończenia, archiwizacji ani historii cen | [model](../../src/Entity/Subscription.php) |
| Limit | Domyślnie 30 pozycji; taki zakres opisuje lokalny cennik | [limity](../../src/Entity/Limits.php), [cennik](../../templates/pricing/index.html.twig) |
| Przypomnienia | Nie znalazłem implementacji przypomnień o odnowieniu w przejrzanych ścieżkach aplikacji | `src/`, `templates/`, konfiguracja; to ustalenie lokalne, nie test dostarczania wiadomości |

Publiczny odczyt [homepage](https://paysubscriptions.com/) zawiera „Customizable Notifications”; lokalny szablon ma tę funkcję zakomentowaną. [About](https://paysubscriptions.com/about) obejmuje też zastosowania biznesowe, podczas gdy #19 je wyklucza. Źródło internetowe mogło pochodzić z indeksu; przed zmianami trzeba potwierdzić aktualnie wdrożoną treść. Rozbieżności są powodem do uporządkowania komunikacji w #20/#26/#27, nie dowodem aktualnej awarii produkcyjnej.

## Rekomendowany rdzeń v1

| Priorytet | Zakres | Minimalna użyteczna wersja i uzasadnienie |
|---|---|---|
| v1 | Szybkie dodawanie | Nazwa, kwota, waluta, cykl, najbliższa płatność. Użytkownik zwykle łatwiej zna kolejną datę niż historyczny początek umowy. Możliwość szybkiego dodania następnej pozycji. |
| v1 | Poprawne cykle | Kwota „co N tygodni/miesięcy/lat”, z określoną obsługą końca miesiąca. To obejmuje np. abonament 4-tygodniowy i kwartalny bez osobnych funkcji dla każdego rodzaju usługi. |
| v1 | Nadchodzące obciążenia | Lista 7/30 dni i najbliższe duże odnowienia roczne. Priorytet przed rozbudową wykresów. |
| v1 | Przypomnienia | Jeden kanał, np. email, prosty wybór wyprzedzenia; łatwe wyłączenie; obsługa błędów wysyłki i unikanie duplikatów. Wysyłanie przypomnień nie oznacza skanowania skrzynki i mieści się w granicach #19. |
| v1 | Trial i decyzja o odnowieniu | Data końca triala, znana przyszła cena, opcjonalny termin rezygnacji oraz własny link/notatka. Termin wypowiedzenia może poprzedzać datę pobrania opłaty. |
| v1 | Zakończenie i archiwum | Zakończenie od określonej daty, wyłączenie dalszych przypomnień i przyszłych kosztów bez usuwania rekordu. Oznaczenie w trackerze nie anuluje umowy u dostawcy. |
| v1 | Czytelne koszty | Oddzielić średnią miesięczną, planowane obciążenia w wybranym okresie i roczny ekwiwalent. 600 zł rocznie to średnio 50 zł/mies., ale 600 zł do zapłaty w miesiącu odnowienia. |
| v1 | Waluty | Waluta na pozycji, sumy osobno dla każdej waluty. Automatyczne kursy można odłożyć; nie wolno prezentować PLN+EUR jako jednej sumy. |
| v1 | Kontrola nad danymi | Eksport CSV, usunięcie konta/danych i zrozumiały opis ich przechowywania. To konkretna treść obietnicy prywatności, nie analiza wymogów prawnych. |
| v1 | Obsługa na telefonie | Wygodne dodanie/edycja i otwarcie przypomnienia prowadzącego do właściwej pozycji. Nie wymaga osobnych aplikacji mobilnych. |
| Następnie | CSV import, szablony, kategorie | Priorytet zależny od obserwacji onboardingu. Przy użytkownikach migrujących z arkusza prosty import jednego formatu może wejść do v1; ogólny importer dowolnych plików powinien poczekać. |
| Następnie | Historia zmian ceny, pauza, kalendarz | Użyteczne rozszerzenia, ale bez potrzeby odtwarzania księgowej historii transakcji w pierwszym wydaniu. |
| Później | Wspólny dostęp domowników | Wymaga decyzji o uprawnieniach, zaproszeniach, właścicielu danych i odejściu z gospodarstwa. Nie jest to mały dodatek do pola „domownik”. |

Do czasu zebrania dowodów popytu utrzymałbym wykluczenie integracji bankowych, skanowania poczty, automatycznego anulowania, rozliczeń między osobami, AI i zaawansowanych analiz.

## Najważniejsze rozstrzygnięcia produktowe

1. **Odbiorca:** jedna osoba zarządzająca własnymi i domowymi opłatami jest spójna z obecnym modelem. Obietnica wspólnego rodzinnego panelu wymaga szerszego zakresu. Dla v1 rekomenduję pierwszą opcję.
2. **Powód powrotu:** przypomnienia mają wracać do użytkownika, gdy może jeszcze podjąć decyzję. Codzienne wizyty nie muszą być dobrą miarą skuteczności tego produktu.
3. **Przewaga nad arkuszem i kalendarzem:** mniej pracy przy utrzymaniu cykli, automatyczne terminy oraz jedno miejsce do działania. To ważniejszy substytut niż kolejny rozbudowany produkt finansowy.
4. **Rynek/język:** jawnie ustalić język pierwszej grupy użytkowników i zakres walut. Nie zakładać polskiego rynku tylko na podstawie języka tej rozmowy.
5. **Darmowy zakres:** 30 pozycji jest rozsądną hipotezą startową, ale nie dowodem dopasowania do gospodarstw. Wyjaśnić limit przed rejestracją; nie zwiększać go wyłącznie w celu porównania tabelki konkurencji.
6. **Koszt utrzymania:** określić akceptowalny koszt miesięczny, koszt przypomnień, ograniczanie nadużyć i czas obsługi. Darmowość dla użytkownika nie usuwa tych kosztów.

## Co dopisać do mapy

- Do [#21](https://github.com/lbacik/paysubscriptions/issues/21): poza konkurencją 5–8 rozmów z osobami z wybranego segmentu; jak pilnują opłat, co przeoczyli i dlaczego porzucili poprzednie narzędzia. Liczba jest propozycją jakościowej próby, nie reprezentatywnym badaniem rynku.
- Do [#22](https://github.com/lbacik/paysubscriptions/issues/22): jedna główna obietnica, konkretny segment, znaczenie „household”, granice prywatności i darmowego zakresu.
- Do [#23](https://github.com/lbacik/paysubscriptions/issues/23): powyższa macierz oraz prototyp scenariusza „dodaj kilka opłat → zobacz nadchodzące odnowienie → otrzymaj przypomnienie → oznacz zakończenie”. Prototyp aplikacji powinien poprzedzać zatwierdzenie pakietu, obok istniejących prototypów homepage/About.
- Do [#24](https://github.com/lbacik/paysubscriptions/issues/24): konkretne przykłady dat i kosztów; przypomnienie zgodne ze strefą czasową, pojedyncze mimo ponowienia zadania, niewysyłane po wyłączeniu; izolacja danych użytkowników; odtworzenie kopii zapasowej i usuwanie danych.
- Do [#25](https://github.com/lbacik/paysubscriptions/issues/25): krótki pilotaż i sposób oceny aktywacji, utrzymania aktualnych danych, skuteczności przypomnień i kosztu obsługi. Przykładowy cel do walidacji: trzy pozycje i pierwsze przypomnienie skonfigurowane w kilka minut. Nie zbierać nazw/kwot subskrypcji do analityki, jeśli nie są potrzebne.
- Do [#28](https://github.com/lbacik/paysubscriptions/issues/28): pakiet ma zawierać również wynik sprawdzenia głównego procesu z użytkownikami, nie tylko spójność dokumentów i stron promocyjnych.

Nie trzeba tworzyć osobnego zgłoszenia dla każdego punktu. Większość uzupełnia istniejące zadania; nowy element o największej wartości to sprawdzenie głównego procesu w aplikacji z użytkownikami.

## Konkretna uwaga o gotowości

W lokalnym [SubscriptionController](../../src/Controller/SubscriptionController.php) edycja (od linii 63) i usuwanie (od linii 91) przyjmują rekord po ID, bez widocznego sprawdzenia, czy należy do zalogowanej osoby. W przeszukanych usługach, repozytorium i konfiguracji nie znalazłem uzupełniającej kontroli właściciela. Wymaga to pilnego potwierdzenia i zamknięcia w kryteriach #24. Jest to mocna przesłanka z analizy statycznej, nie wynik próby dostępu do cudzych danych ani potwierdzenie stanu wdrożenia produkcyjnego.

Nie zmieniano kodu aplikacji, mapy, statusów ani treści zgłoszeń GitHub. Analiza konkurencji opisuje deklaracje dostawców; nie obejmuje testów wszystkich aplikacji ani pomiaru ich udziałów rynkowych.
