<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Support\Facades\App;

class LocaleManager
{
    private array $supportedLocales;
    private string $defaultLocale;
    private array $localeData = [];
    
    public function __construct()
    {
        $this->supportedLocales = config('semantic-search.locales.supported', ['en']);
        $this->defaultLocale = config('semantic-search.locales.default', 'en');
        $this->loadLocaleData();
    }
    
    public function getCurrentLocale(): string
    {
        return App::getLocale() ?? $this->defaultLocale;
    }
    
    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supportedLocales, true);
    }
    
    public function getComparatorMapping(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['comparators'] ?? $this->localeData['en']['comparators'];
    }
    
    public function getActionWords(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['actions'] ?? $this->localeData['en']['actions'];
    }
    
    public function getRelationshipWords(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['relationships'] ?? $this->localeData['en']['relationships'];
    }
    
    public function getDatePatterns(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['date_patterns'] ?? $this->localeData['en']['date_patterns'];
    }
    
    public function getQuantifierPatterns(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['quantifiers'] ?? $this->localeData['en']['quantifiers'];
    }
    
    public function getStopWords(string $locale = null): array
    {
        $locale = $locale ?: $this->getCurrentLocale();
        return $this->localeData[$locale]['stop_words'] ?? $this->localeData['en']['stop_words'];
    }
    
    public function normalizeToken(string $token, string $locale = null): string
    {
        $locale = $locale ?: $this->getCurrentLocale();
        
        // Apply locale-specific normalization
        $normalized = strtolower($token);
        
        // Remove diacritics for languages that need it
        if (in_array($locale, ['es', 'fr', 'de', 'pt', 'it'])) {
            $normalized = $this->removeDiacritics($normalized);
        }
        
        return $normalized;
    }
    
    private function removeDiacritics(string $string): string
    {
        return transliterator_transliterate('Any-Latin; Latin-ASCII;', $string);
    }
    
    private function loadLocaleData(): void
    {
        $this->localeData = [
            'en' => [
                'comparators' => [
                    '>' => ['greater than', 'more than', 'over', 'above', 'higher than'],
                    '<' => ['less than', 'under', 'below', 'lower than', 'fewer than'],
                    '>=' => ['at least', 'minimum', 'greater than or equal'],
                    '<=' => ['at most', 'maximum', 'less than or equal'],
                    '=' => ['equals', 'equal to', 'is', 'exactly'],
                    '!=' => ['not equal', 'different from', 'not'],
                    'older' => ['older than', 'greater age than'],
                    'younger' => ['younger than', 'less age than'],
                ],
                'actions' => ['show', 'list', 'display', 'find', 'search', 'get', 'fetch', 'retrieve'],
                'relationships' => ['with', 'and', 'has', 'have', 'contains', 'includes', 'that'],
                'date_patterns' => [
                    'today' => 'today',
                    'yesterday' => 'yesterday',
                    'last_week' => 'last week',
                    'this_week' => 'this week',
                    'last_month' => 'last month',
                    'this_month' => 'this month',
                    'last_year' => 'last year',
                    'this_year' => 'this year',
                    'last_x_days' => '/last\s+(\d+)\s+days?/',
                    'past_x_days' => '/past\s+(\d+)\s+days?/',
                    'in_last_x_days' => '/in\s+the\s+last\s+(\d+)\s+days?/',
                ],
                'quantifiers' => ['all', 'every', 'each', 'any', 'some', 'multiple', 'various'],
                'stop_words' => ['the', 'a', 'an', 'of', 'in', 'on', 'at', 'for', 'to', 'from'],
            ],
            'es' => [
                'comparators' => [
                    '>' => ['mayor que', 'más que', 'superior a', 'encima de'],
                    '<' => ['menor que', 'menos que', 'inferior a', 'debajo de'],
                    '>=' => ['al menos', 'mínimo', 'mayor o igual que'],
                    '<=' => ['como máximo', 'máximo', 'menor o igual que'],
                    '=' => ['igual a', 'es', 'exactamente'],
                    '!=' => ['diferente de', 'no igual'],
                    'older' => ['mayor que', 'de más edad que'],
                    'younger' => ['menor que', 'de menos edad que'],
                ],
                'actions' => ['mostrar', 'listar', 'mostrar', 'buscar', 'encontrar', 'obtener', 'recuperar'],
                'relationships' => ['con', 'y', 'tiene', 'tienen', 'contiene', 'incluye'],
                'date_patterns' => [
                    'today' => 'hoy',
                    'yesterday' => 'ayer',
                    'last_week' => 'la semana pasada',
                    'this_week' => 'esta semana',
                    'last_month' => 'el mes pasado',
                    'this_month' => 'este mes',
                    'last_year' => 'el año pasado',
                    'this_year' => 'este año',
                    'last_x_days' => '/últimos\s+(\d+)\s+días/',
                    'past_x_days' => '/pasados\s+(\d+)\s+días/',
                    'in_last_x_days' => '/en\s+los\s+últimos\s+(\d+)\s+días/',
                ],
                'quantifiers' => ['todos', 'cada', 'todo', 'algunos', 'múltiples', 'varios'],
                'stop_words' => ['el', 'la', 'los', 'las', 'un', 'una', 'de', 'en', 'a', 'para'],
            ],
            'fr' => [
                'comparators' => [
                    '>' => ['supérieur à', 'plus que', 'au-dessus de'],
                    '<' => ['inférieur à', 'moins que', 'en dessous de'],
                    '>=' => ['au moins', 'minimum', 'supérieur ou égal'],
                    '<=' => ['au maximum', 'maximum', 'inférieur ou égal'],
                    '=' => ['égal à', 'est', 'exactement'],
                    '!=' => ['différent de', 'pas égal'],
                    'older' => ['plus âgé que', 'plus vieux que'],
                    'younger' => ['plus jeune que', 'moins âgé que'],
                ],
                'actions' => ['montrer', 'lister', 'afficher', 'trouver', 'chercher', 'obtenir'],
                'relationships' => ['avec', 'et', 'a', 'ont', 'contient', 'inclut'],
                'date_patterns' => [
                    'today' => "aujourd'hui",
                    'yesterday' => 'hier',
                    'last_week' => 'la semaine dernière',
                    'this_week' => 'cette semaine',
                    'last_month' => 'le mois dernier',
                    'this_month' => 'ce mois',
                    'last_year' => "l'année dernière",
                    'this_year' => 'cette année',
                    'last_x_days' => '/derniers\s+(\d+)\s+jours/',
                    'past_x_days' => '/passés\s+(\d+)\s+jours/',
                    'in_last_x_days' => '/depuis\s+les\s+derniers\s+(\d+)\s+jours/',
                ],
                'quantifiers' => ['tous', 'chaque', 'tout', 'certains', 'multiples', 'divers'],
                'stop_words' => ['le', 'la', 'les', 'un', 'une', 'de', 'en', 'à', 'pour'],
            ],
            'de' => [
                'comparators' => [
                    '>' => ['größer als', 'mehr als', 'über', 'oberhalb'],
                    '<' => ['kleiner als', 'weniger als', 'unter', 'unterhalb'],
                    '>=' => ['mindestens', 'minimum', 'größer oder gleich'],
                    '<=' => ['höchstens', 'maximum', 'kleiner oder gleich'],
                    '=' => ['gleich', 'ist', 'genau'],
                    '!=' => ['ungleich', 'nicht gleich'],
                    'older' => ['älter als'],
                    'younger' => ['jünger als'],
                ],
                'actions' => ['zeigen', 'auflisten', 'anzeigen', 'finden', 'suchen', 'holen'],
                'relationships' => ['mit', 'und', 'hat', 'haben', 'enthält', 'beinhaltet'],
                'date_patterns' => [
                    'today' => 'heute',
                    'yesterday' => 'gestern',
                    'last_week' => 'letzte woche',
                    'this_week' => 'diese woche',
                    'last_month' => 'letzter monat',
                    'this_month' => 'dieser monat',
                    'last_year' => 'letztes jahr',
                    'this_year' => 'dieses jahr',
                    'last_x_days' => '/letzten\s+(\d+)\s+tage/',
                    'past_x_days' => '/vergangenen\s+(\d+)\s+tage/',
                    'in_last_x_days' => '/in\s+den\s+letzten\s+(\d+)\s+tagen/',
                ],
                'quantifiers' => ['alle', 'jeder', 'jede', 'einige', 'mehrere', 'verschiedene'],
                'stop_words' => ['der', 'die', 'das', 'ein', 'eine', 'von', 'in', 'zu', 'für'],
            ],
            'pt' => [
                'comparators' => [
                    '>' => ['maior que', 'mais que', 'acima de', 'superior a'],
                    '<' => ['menor que', 'menos que', 'abaixo de', 'inferior a'],
                    '>=' => ['pelo menos', 'mínimo', 'maior ou igual'],
                    '<=' => ['no máximo', 'máximo', 'menor ou igual'],
                    '=' => ['igual a', 'é', 'exatamente'],
                    '!=' => ['diferente de', 'não igual'],
                    'older' => ['mais velho que', 'maior que'],
                    'younger' => ['mais novo que', 'menor que'],
                ],
                'actions' => ['mostrar', 'listar', 'exibir', 'encontrar', 'buscar', 'obter'],
                'relationships' => ['com', 'e', 'tem', 'têm', 'contém', 'inclui'],
                'date_patterns' => [
                    'today' => 'hoje',
                    'yesterday' => 'ontem',
                    'last_week' => 'semana passada',
                    'this_week' => 'esta semana',
                    'last_month' => 'mês passado',
                    'this_month' => 'este mês',
                    'last_year' => 'ano passado',
                    'this_year' => 'este ano',
                    'last_x_days' => '/últimos\s+(\d+)\s+dias/',
                    'past_x_days' => '/passados\s+(\d+)\s+dias/',
                    'in_last_x_days' => '/nos\s+últimos\s+(\d+)\s+dias/',
                ],
                'quantifiers' => ['todos', 'cada', 'todo', 'alguns', 'múltiplos', 'vários'],
                'stop_words' => ['o', 'a', 'os', 'as', 'um', 'uma', 'de', 'em', 'a', 'para'],
            ],
        ];
    }
}
