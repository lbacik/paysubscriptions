# PaySubscriptions v1 — konkurencja

Stan weryfikacji: 6 września 2026. Porównanie deklarowanej oferty, nie test działania aplikacji. Źródła: oficjalne strony, dokumentacja i opisy sklepowe wydawców. Ceny dotyczą wskazanej oferty/rynku; nie są gwarancją ceny dla polskiego konta. Brak wzmianki o funkcji w źródle nie dowodzi jej braku w produkcie.

Punkt odniesienia przekazany do badania: darmowe v1 dla osób i gospodarstw domowych, ręczne wprowadzanie danych, nacisk na prywatność, jeden opiekun projektu; bez integracji bankowych, skrzynek pocztowych i dostawców, kont organizacyjnych ani realizowania płatności.

## Potwierdzone fakty

### Bobby — prosty tracker na iPhone

Bobby oferuje katalog setek usług, własne wpisy, podgląd kosztów i nadchodzących rachunków oraz przypomnienia. Oficjalny opis App Store wskazuje aplikację projektowaną dla iPhone; dostępna jest także po polsku. Pobranie jest darmowe, ale są zakupy w aplikacji. Amerykański listing pokazuje m.in. „All-in-one Pack v2” za 2,99 USD oraz odblokowanie limitu subskrypcji za 0,99 USD. Listing nie podaje jasno liczbowego limitu darmowych wpisów — nie zakładam popularnej w zestawieniach liczby. Nie utożsamiam zgodności aplikacji iPhone z komputerami Apple Silicon z dopracowaną aplikacją desktopową. [Opis wydawcy i zakupy w App Store](https://apps.apple.com/us/app/bobby-track-subscriptions/id1059152023), [strona produktu](https://bobbyapp.co/).

**Interpretacja:** Bobby jest dobrym punktem odniesienia dla szybkości dodawania i prostoty. Darmowość PaySubscriptions konkuruje tu z bardzo tanim odblokowaniem, więc sama cena nie tworzy dużej przewagi. Web dostępny z telefonu i komputera daje bardziej konkretny powód wyboru.

### Subby — bezbankowy tracker mobilny

Właściwy produkt to **Subby firmy POCKET IMPLEMENTATION S.R.L., subby.online, Android `com.slapp.subby`**. Nie należy mieszać go z innymi aplikacjami o tej nazwie. Wydawca deklaruje Android i iOS, brak konta i połączenia z bankiem, nielimitowany darmowy rdzeń z reklamami: wpisy, przypomnienia, statystyki i historię płatności. PRO jest zakupem jednorazowym; dodaje import ze zrzutu ekranu, backup w chmurze, widget, zamawianie ikon i usuwa reklamy. Zakup PRO nie przenosi się między sklepami Apple i Google. Nie potwierdzono jednoznacznej publicznej ceny lokalnej. [FAQ](https://subby.online/faq.html), [Google Play](https://play.google.com/store/apps/details?hl=en-US&id=com.slapp.subby), [PRO](https://subby.online/pro.html).

Strona produktu deklaruje cykle od tygodniowych do wieloletnich, przeliczanie 46 walut oraz statystyki „należne/opłacone/razem” i historię płatności. [Funkcje Subby](https://subby.online/).

Prywatność wymaga precyzji: według polityki lista subskrypcji zostaje na urządzeniu, ale wybrany screenshot trafia do przetwarzania AI; bezpłatna wersja wykorzystuje reklamy AdMob, a aplikacja także pomiar instalacji i zdarzeń reklamowych. Hasło „bez banku” nie oznacza zatem „żadne dane nie opuszczają telefonu”. [Polityka prywatności, aktualizacja 12.08.2026](https://subby.online/privacy_policy.html).

**Interpretacja:** To bezpośredni dowód, że „manual + bez banku + darmowe bez limitu” już istnieje. PaySubscriptions potrzebuje czytelnego dodatkowego powodu wyboru: wygodnego webu, transparentnej prywatności, obsługi ważnych dat umowy lub praktycznej użyteczności dla domu. Obecność reklam u konkurenta jest potencjalną przestrzenią do wyróżnienia, o ile własna polityka finansowania to umożliwia.

### TrackMySubs — najbliższy punkt odniesienia dla aplikacji webowej

TrackMySubs świadomie wymaga ręcznego wpisywania danych, uzasadniając to niechęcią do dostępu do banku. Obsługuje także inne płatności cykliczne. Ma domyślne przypomnienia, innych odbiorców alertów, wybór godziny oraz osobną datę końca umowy z przypomnieniem niezależnym od miesięcznych płatności. [Oficjalne FAQ](https://trackmysubs.com/frequently-asked-questions/).

Oferuje import i eksport CSV, foldery, tagi, metody płatności, kalendarz i listę zobowiązań, przeliczenia walut, raporty oraz obsługę próbnych okresów i terminów zwrotu. Ma integrację Zapier. [Opis funkcji](https://trackmysubs.com/how-it-works/).

Cennik: bezpłatnie 10 subskrypcji; Unlimited 10 USD miesięcznie lub wskazane 99,99 USD rocznie; Enterprise 30 USD miesięcznie obejmuje wielu użytkowników. Strona równocześnie pisze o 15% oszczędności rocznej — procent i kwota nie są arytmetycznie spójne, dlatego podaję literalne kwoty zamiast wyliczonego rabatu. [Cennik](https://trackmysubs.com/pricing/).

**Interpretacja:** Darmowy web bez ciasnego limitu może być sensowną przewagą nad tym produktem. Funkcjonalnie sam dashboard i alert przed pobraniem opłaty będą jednak uboższe niż ta oferta. Osobny termin decyzji o wypowiedzeniu umowy jest ważniejszą inspiracją do v1 niż rozbudowane integracje lub plan organizacyjny.

### Wallos — alternatywa dla osób gotowych utrzymywać własny serwer

Wallos to otwartoźródłowa aplikacja webowa do samodzielnego hostowania na GPLv3. Ma widok mobilny, kategorie, statystyki, waluty i konwersję przez Fixer oraz wiele metod powiadomień, w tym email, Telegram, Discord i webhooki. Instrukcja przewiduje konto, ustawienia członków gospodarstwa domowego i uruchomienie aplikacji na własnym serwerze przez Docker lub bezpośrednio. Nie należy interpretować samej wzmianki o członkach gospodarstwa jako potwierdzenia wspólnej edycji przez osobne konta. [Oficjalne repozytorium](https://github.com/ellite/Wallos).

**Interpretacja:** PaySubscriptions może zaoferować podobny porządek bez pracy przy serwerze i aktualizacjach. Nie powinien jednak deklarować silniejszej prywatności wyłącznie na podstawie braku integracji bankowej: model hostowany przez usługodawcę i własny serwer to inne relacje zaufania. Darmowe oprogramowanie Wallos również nie oznacza zerowego kosztu infrastruktury i utrzymania.

### Rocket Money — sąsiednia kategoria, nie lista wymagań v1

Rocket Money wykrywa cykliczne obciążenia po połączeniu rachunków. Darmowy plan umożliwia śledzenie subskrypcji, a Premium obejmuje usługę anulowania w imieniu użytkownika; produkt ma szersze funkcje budżetowe i finansowe. [Oficjalne FAQ](https://www.rocketmoney.com/faq).

Centrum pomocy potwierdza bezpłatną wersję oraz Premium z ceną wybieraną na skali, zmienną w czasie i między platformami. Nie traktuję historycznych widełek z artykułów jako gwarantowanej bieżącej ceny. Dostęp przez przeglądarkę wskazano jako funkcję Premium. [Koszt, aktualizacja 27.07.2026](https://help.rocketmoney.com/en/articles/2217739-how-much-does-rocket-money-cost), [funkcje Premium](https://help.rocketmoney.com/en/articles/2677184-premium-membership-features).

**Interpretacja:** Rocket Money wygrywa wygodą automatycznego odkrywania i wykonania czynności za użytkownika. To inne zadanie i inny koszt operacyjny. Brak tych możliwości w v1 jest rozsądną granicą zakresu, pod warunkiem jasnej obietnicy: PaySubscriptions pomaga pilnować zadeklarowanych zobowiązań, nie odkrywa wszystkich opłat i nie anuluje usług.

### SubTurtle — niezweryfikowana nazwa w tej kategorii

Nie znaleziono wiarygodnego oficjalnego źródła potwierdzającego tracker płatnych subskrypcji o tej nazwie. Znaleziony [subturtle.app](https://subturtle.app/) to nauka języka na podstawie napisów wideo. Nie włączam go do porównania; nie jest to twierdzenie, że żaden inny produkt o tej nazwie nie istnieje.

## Wnioski dla zakresu v1 — ocena autorska

1. **Podstawa kategorii:** sprawne dodawanie, czytelne koszty, daty i przypomnienia. Nie budowałbym argumentu marketingowego na samej liczbie funkcji lub braku banku.
2. **Obietnica produktu powinna dotyczyć decyzji:** „ile zapłacę i do kiedy muszę zdecydować”, nie tylko listy aktywnych usług. W praktyce oznacza to odróżnienie terminu płatności, końca trialu, końca umowy i terminu wypowiedzenia. Dla ograniczonego v1 może wystarczyć data decyzji i link do zarządzania usługą, bez automatycznego anulowania.
3. **Dwie liczby zamiast jednego mylącego kosztu:** miesięczny ekwiwalent pokazuje obciążenie budżetu, a suma faktycznie zaplanowana na dany miesiąc pokazuje gotówkę potrzebną przy rocznych odnowieniach. Subby i TrackMySubs są użytecznymi punktami odniesienia, ale dokładne zachowanie ich obliczeń nie było testowane.
4. **Ręczna obsługa potrzebuje skrótów:** rozsądne wartości domyślne, łatwa aktualizacja ceny, kopiowanie wpisu, pomoc w zebraniu usług podczas pierwszej konfiguracji oraz import/eksport prostego formatu. Koszt wpisania i aktualizowania listy jest realną ceną produktu, nawet gdy korzystanie jest darmowe.
5. **Wiarygodność przypomnień to część funkcji:** test dostarczenia, widoczny stan kanału, strefa czasowa i zachowanie po zmianie daty są bardziej istotne niż kolejny wykres. To rekomendacja jakościowa, nie potwierdzona luka konkretnego konkurenta.
6. **„Dla gospodarstwa” wymaga definicji:** jedna osoba prowadząca listę domowych rachunków to znacznie prostsze v1 niż współdzielony budżet, osobne uprawnienia i rozliczenia między domownikami. Można przetestować etykietę „kto płaci/kto korzysta” przed budowaniem współpracy wielu kont.
7. **Prywatność musi być konkretna:** gdzie są dane, czy są reklamy/analityka, jak pobrać dane i usunąć konto. Bezbankowość sama nie wystarcza jako przewaga nad trackerami przechowującymi listę lokalnie.
8. **Ograniczyłbym rozszerzenia:** banki, pełne zarządzanie finansami, AI i automatyczne anulowanie nie są konieczne do konkurencyjnego startu w wybranej niszy. Najpierw warto zmierzyć ukończenie pierwszej listy, aktywację przypomnień i decyzje podjęte przed niechcianym odnowieniem.

Hipoteza pozycjonowania do sprawdzenia z użytkownikami: **prosty, darmowy organizator domowych opłat działający na telefonie i komputerze, który pokazuje najbliższe wydatki i ostrzega przed terminem decyzji — bez podłączania banku**. To propozycja, nie dowód popytu ani obecna deklaracja PaySubscriptions.
