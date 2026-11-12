<?php
/**
 * Plain-text (Markdown) tool results (PLUGIN-SPEC.md "Result text format"). Passage text is
 * printed verbatim, except that every field that comes from the library (text, notes, labels,
 * metadata, verses, dictionary entries) goes through Text::inert(), so no Markdown image or link
 * and no HTML tag in the books can become live in a client that renders the result; every passage
 * is headed by
 *   [n] P<id> · Author, *Title* (Year) · pp. X–Y · Section
 * A whole result stays under MAX_CHARS: when it would not, the longest passage texts are cut
 * to an equal share of the room (marked with "…") and the result says so.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Format
{
    public const MAX_CHARS = 120000;
    private const NOTES_MAX = 3000;     // characters of footnotes printed per passage
    private const MIN_TEXT = 300;       // a shortened passage keeps at least this much

    /** Collection codes (as stored in works.collection) and their labels (indexer/corpus.py). */
    public const COLLECTIONS = [
        'eebo-tcp' => 'EEBO-TCP (original 1500s-1700s editions)',
        'evans-tcp' => 'Evans-TCP (early American imprints)',
        'ecco-tcp' => 'ECCO-TCP (1700s editions)',
        'ccel-schaff' => 'Church Fathers (Schaff, CCEL)',
        'ccel' => 'CCEL',
        'internet-archive' => 'Internet Archive (OCR)',
        'project-gutenberg' => 'Project Gutenberg',
        'standard-ebooks' => 'Standard Ebooks',
        'docsouth' => 'Documenting the American South',
        'creeds-json' => 'Creeds and confessions',
        'contributed' => 'Contributed',
    ];

    // ---------------------------------------------------------------------------- search

    public static function search(SearchResult $r): string
    {
        $q = Text::echo($r->query, 200);
        $notes = $r->notes;
        if ($r->mode === 'exact') {
            $first = $r->offset + 1;
            $last = $r->offset + count($r->hits);
            $of = $r->totalCapped ? 'more than ' . number_format((int) $r->total) : number_format((int) $r->total);
            $top = "Results $first-$last of $of for the exact phrase \"$q\" (in book order)";
            if ($r->total !== null && ($r->totalCapped || $last < $r->total)) {
                $notes[] = "More passages contain this phrase: repeat the search with offset $last for the next ones.";
            }
        } else {
            $top = 'Results 1-' . count($r->hits) . " for \"$q\" ({$r->method})";
        }
        if ($r->hits !== []) {
            $top .= "\n" . self::CONTEXT_RULE;
        }
        $matches = array_filter(array_column($r->hits, 'match'));
        if ($matches !== []) {
            $top .= "\n" . self::MATCH_RULE;
            if (array_intersect($matches, ['exact', 'close']) === []) {
                $top .= "\n" . (in_array('partial', $matches, true) ? self::NO_CLOSE_MATCH : self::NO_MATCH);
            }
        }
        $blocks = [];
        foreach ($r->hits as $i => $p) {
            $head = self::passageHeader($p, $r->offset + $i + 1) . self::matchLabel($p['match'] ?? null)
                . self::authorLine($p['work']);
            if (isset($p['context_before'])) {
                $head .= "\n" . self::contextLine($p['context_before'], 'before');
            }
            $tail = self::notesLine($p);
            if (isset($p['context_after'])) {
                $tail .= "\n" . self::contextLine($p['context_after'], 'after');
            }
            $tail .= self::scanLine($p['work']) . self::sourceLine($p['work'])
                . self::pageLinkLine($p['work'], $p['page_start'] ?? null);
            if ($p['also'] !== []) {
                $also = [];
                foreach ($p['also'] as $a) {
                    $also[] = self::workLabel($a['work']) . ', P' . $a['id'];
                }
                $tail .= "\nAlso in: " . implode('; ', $also);
            }
            $blocks[] = ['head' => $head, 'text' => Text::inert($p['text']), 'tail' => $tail];
        }
        return self::assemble($top, $blocks, self::notesFooter($notes));
    }

    /** Shown above every list of search results. */
    public const CONTEXT_RULE = 'Each result shows the text just before and after it ("Before"/"After"), or the '
        . 'whole neighbouring passage when the result opens with an objection, a question or an answer. Quote the '
        . 'author\'s own position, not an objection he answers; read_passage gives more context by ref.';

    /** Shown above search results that carry a match strength. */
    public const MATCH_RULE = 'Each result is marked with how well it matches: "exact words" (it holds the query '
        . 'word for word), "close" (it is about what was asked), "partial" (related) or "loose" (only loosely '
        . 'related; do not rely on it).';

    /** Shown when no result is an exact or close match but some are partial. */
    public const NO_CLOSE_MATCH = 'NO CLOSE MATCH: these passages are related to the query but may not answer it. '
        . 'If they do not, tell the user the library may not have it; do not stretch a passage to fit.';

    /** Shown when every result is only loosely related. */
    public const NO_MATCH = 'NOT FOUND: nothing in the library matches this query; the passages below are only '
        . 'loosely related. Tell the user the library does not appear to have it. ' . self::FROM_MEMORY_RULE;

    /** What to do about something the library does not have. */
    public const FROM_MEMORY_RULE = 'Do not quote or cite it from memory as though it came from the library.';

    /** Shown with every passage of a work whose text is uncorrected OCR. */
    public const SCAN_NOTE = 'Text: uncorrected OCR of a scanned book, so some letters and words are misread; '
        . 'check the wording against the scan before quoting it.';

    /** Words of a neighbouring passage shown before or after a result (the whole passage for a Q&A pair). */
    public const CONTEXT_WORDS = 45;
    public const CONTEXT_FULL_CHARS = 1500;

    /**
     * How a passage opens: 'objection', 'question' or 'answer' (Obj., Obiect., Quest., Q., Answ., Resp.,
     * Sol. and the like), or null. Its other half is in the passage after (objection, question) or
     * before (answer).
     */
    public static function opening(string $text): ?string
    {
        $start = (string) preg_replace('/^(?:\s|\[p\. [^\]]{1,20}\]|[#>*_]+)+/u', '', mb_substr($text, 0, 300));
        if (preg_match('/^(?:ob[ij]ect(?:ions?)?\b|ob[ij]\.|it (?:is|will be|may be) ob[ij]ected\b)/iu', $start)) {
            return 'objection';
        }
        if (preg_match('/^(?:quest(?:ion)?\.|qu\.|q\.\s)/iu', $start)) {
            return 'question';
        }
        if (preg_match('/^(?:answ?(?:er)?\.|resp\.|sol(?:ution)?\.)/iu', $start)) {
            return 'answer';
        }
        return null;
    }

    /**
     * The neighbour of a result as shown with it: the last (before) or first (after) words, or the
     * whole passage (clipped) when $full.
     * @return array{id: int, pages: string, text: string, full: bool, label: string}
     */
    public static function contextOf(array $neighbour, string $side, bool $full, string $label = ''): array
    {
        $text = (string) $neighbour['text'];
        if ($full) {
            $text = Text::clip($text, self::CONTEXT_FULL_CHARS);
        } else {
            $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) > self::CONTEXT_WORDS) {
                $text = $side === 'before'
                    ? '…' . implode(' ', array_slice($words, -self::CONTEXT_WORDS))
                    : implode(' ', array_slice($words, 0, self::CONTEXT_WORDS)) . '…';
            } else {
                $text = implode(' ', $words);
            }
        }
        return ['id' => (int) $neighbour['id'], 'pages' => self::pages($neighbour['page_start'], $neighbour['page_end']),
                'text' => $text, 'full' => $full, 'label' => $label];
    }

    /** "Before (P122 · p. 22): …" or a labelled whole passage. */
    private static function contextLine(array $c, string $side): string
    {
        $where = 'P' . $c['id'] . ($c['pages'] !== '' ? ' · ' . $c['pages'] : '');
        $label = $c['label'] !== '' ? $c['label'] : ($side === 'before' ? 'Before' : 'After');
        return "$label ($where): " . Text::inert(Text::oneLine($c['text'], self::CONTEXT_FULL_CHARS + 10));
    }

    /** Text for a search that found nothing (returned with isError, per the spec). */
    public static function noResults(SearchResult $r): string
    {
        $q = Text::echo($r->query, 200);
        if ($r->mode === 'exact') {
            if ($r->total !== null && $r->total > 0) {
                $text = "Only {$r->total} passage" . ($r->total === 1 ? ' contains' : 's contain')
                    . " the exact phrase \"$q\"; use an offset below {$r->total}.";
            } else {
                $text = "NOT FOUND: no passage in the library contains the exact phrase \"$q\". Old spellings are "
                    . 'matched automatically; try fewer or different words, or search in mode "best". If it is still '
                    . 'not found, the wording may differ, the work may not be in the library, or the quotation may be '
                    . 'misattributed: say the library could not confirm it. ' . self::FROM_MEMORY_RULE;
            }
        } else {
            $text = "NOT FOUND: no passages found for \"$q\". Try other words or a shorter query"
                . (self::hasFilterNote($r->notes) ? ', or loosen the filters' : '') . '. If nothing is found, tell the '
                . 'user the library does not appear to have it. ' . self::FROM_MEMORY_RULE;
        }
        return $text . self::notesFooter($r->notes);
    }

    // ------------------------------------------------------------------------- read_passage

    /**
     * @param array $work work row
     * @param list<array> $passages in reading order
     * @param list<string> $notes extra lines for the end (page lookup notes)
     */
    public static function readPassage(array $work, array $passages, int $targetId, ?int $previousId,
                                       ?int $nextId, array $notes = []): string
    {
        $top = self::workLabel($work) . ' · work_id ' . $work['id'] . ' · ' . self::collectionLabel($work['collection']);
        if ($work['full_title'] !== '' && $work['full_title'] !== $work['title']) {
            $top .= "\nFull title: " . self::field($work['full_title'], 400);
        }
        $where = array_filter([$work['source_url'] !== '' ? 'Copied from: ' . self::field($work['source_url'], 500) : '',
                               $work['license'] !== '' ? 'License: ' . self::field($work['license'], 200) : '']);
        $top .= self::authorLine($work);
        $top .= $where !== [] ? "\n" . implode(' · ', $where) : '';
        $top .= self::scanLine($work);
        $blocks = [];
        foreach ($passages as $p) {
            $head = 'P' . $p['id'];
            $pages = self::pages($p['page_start'], $p['page_end']);
            $head .= ($pages !== '' ? " · $pages" : '') . ($p['section'] !== '' ? ' · ' . self::field($p['section'], 200) : '');
            if ($p['id'] === $targetId) {
                $head .= ' · (requested passage)';
            }
            $blocks[] = ['head' => $head, 'text' => Text::inert($p['text']),
                         'tail' => rtrim(self::notesLine($p) . self::pageLinkLine($work, $p['page_start'] ?? null))];
        }
        $foot = [];
        $foot[] = $previousId !== null ? "Earlier text: ref P$previousId" : 'This is the beginning of the work.';
        $foot[] = $nextId !== null ? "Later text: ref P$nextId" : 'This is the end of the work.';
        $bottom = implode(' · ', $foot) . ($notes !== [] ? "\n" . implode("\n", $notes) : '');
        return self::assemble($top, $blocks, $bottom);
    }

    // ---------------------------------------------------------------------------- find_works

    /**
     * @param list<array> $works  @param list<string> $notes
     * @param string $lead a line printed first (when no work matched every word of the query)
     */
    public static function works(array $works, int $total, string $heading, array $notes = [], string $lead = ''): string
    {
        $top = ($lead !== '' ? $lead . "\n\n" : '')
            . 'Works 1-' . count($works) . ' of ' . number_format($total) . ($heading !== '' ? " $heading" : '');
        $blocks = [];
        foreach ($works as $i => $w) {
            $lines = [];
            if ($w['full_title'] !== '' && $w['full_title'] !== $w['title']) {
                $lines[] = 'Full title: ' . self::field($w['full_title'], 400);
            }
            $facts = [$w['type'] !== '' ? self::field($w['type'], 60) : 'work', self::collectionLabel($w['collection']),
                      number_format($w['n_passages']) . ' passage' . ($w['n_passages'] === 1 ? '' : 's')];
            $range = self::pages($w['page_first'] ?? null, $w['page_last'] ?? null);
            if ($range !== '') {
                $facts[] = $range;
            }
            if ($w['license'] !== '') {
                $facts[] = 'License: ' . self::field($w['license'], 200);
            }
            $lines[] = implode(' · ', $facts);
            if (($w['author_note'] ?? '') !== '') {
                $lines[] = ltrim(self::authorLine($w));
            }
            if ($w['source_url'] !== '') {
                $lines[] = 'Copied from: ' . self::field($w['source_url'], 500);
            }
            $blocks[] = ['head' => '[' . ($i + 1) . '] work_id ' . $w['id'] . ' · ' . self::workLabel($w),
                         'text' => implode("\n", $lines), 'tail' => ''];
        }
        if ($total > count($works)) {
            $notes[] = 'More works match: narrow the query or the filters, or raise the limit (at most 50).';
        }
        return self::assemble($top, $blocks, self::notesFooter($notes));
    }

    // ------------------------------------------------------------------------ read_scripture

    /**
     * "John 3:16-18 (BSB)", then one verse per line ("16 For God so loved…"; "9:1 …" when the
     * passage crosses chapters), notes, the topics of a short passage and a pointer to its citations.
     */
    public static function scripture(BibleText $t): string
    {
        $lines = [$t->label . ' (' . $t->translation . ')'];
        foreach ($t->verses as $v) {
            $lines[] = ($t->withChapter ? $v['chapter'] . ':' : '') . $v['verse'] . ' ' . self::field($v['text'], 4000);
        }
        $topics = array_map(static fn (string $n): string => self::field($n, 200), $t->topics);
        $out = implode("\n", $lines) . ($t->notes !== [] ? "\n\n" . implode("\n", $t->notes) : '') . "\n\n"
            . ($topics !== [] ? "Topics (Nave's, Torrey's): " . implode('; ', $topics) . "\n" : '')
            . 'For passages in the library that cite this, use find_scripture_citations'
            . ($t->citeAs !== null ? " with the KJV's numbering: \"{$t->citeAs}\"." : '.');
        return Text::clip($out, self::MAX_CHARS);
    }

    // -------------------------------------------------------------- find_scripture_citations

    /**
     * The reference and its text (BSB and KJV), how often it is cited, then the citing passages in
     * the search result format, each marked with what it cites and whether only in a margin note.
     */
    public static function citations(CitationResult $r): string
    {
        $top = "Citations of {$r->label} in the library" . ($r->heading !== '' ? " ({$r->heading})" : '');
        foreach ($r->texts as $t) {
            $top .= "\n\n" . $t['label'] . "\n" . implode("\n", array_map([Text::class, 'inert'], $t['lines']))
                . ($t['more'] ? "\n…" : '');
        }
        $first = $r->offset + 1;
        $last = $r->offset + count($r->hits);
        $inNotes = ($r->inNotes > 0 ? number_format($r->inNotes) : 'none') . ' of them in margin notes';
        $verses = $r->passages - $r->chapterOnly;
        $one = $r->chapterOnly === 1;
        if ($r->chapterOnly > 0 && $r->label !== $r->chapterLabel) {
            // the count for verses leaves out the passages that cite only their chapter
            $top .= "\n\n" . ($verses > 0
                ? 'Cited in ' . self::plural($verses, 'passage') . '; ' . number_format($r->chapterOnly) . ' more '
                    . ($one ? 'cites' : 'cite') . " {$r->chapterLabel} as a whole and " . ($one ? 'comes' : 'come')
                    . ' after them (' . self::plural($r->passages, 'passage') . ' across ' . self::plural($r->works, 'work')
                    . " in all, $inNotes)"
                : "No passage cites {$r->label} itself; " . self::plural($r->chapterOnly, 'passage') . ' across '
                    . self::plural($r->works, 'work') . ' ' . ($one ? 'cites' : 'cite') . " {$r->chapterLabel} as a whole"
                    . " ($inNotes)");
        } else {
            $top .= "\n\nCited in " . self::plural($r->passages, 'passage') . ' across ' . self::plural($r->works, 'work')
                . " ($inNotes)";
            if ($r->chapterOnly === $r->passages) {
                $top .= '; ' . ($r->passages === 1 ? 'it cites' : 'all of them cite') . " {$r->chapterLabel} as a whole";
            } elseif ($r->chapterOnly > 0) {
                $top .= '; ' . number_format($r->chapterOnly) . ' of them ' . ($one ? 'cites' : 'cite')
                    . " only {$r->chapterLabel} as a whole and " . ($one ? 'comes' : 'come') . ' after the others';
            }
        }
        $top .= ". Passages $first-$last, earliest works first, one passage per work in turn:";
        $blocks = [];
        foreach ($r->hits as $i => $p) {
            $blocks[] = ['head' => self::passageHeader($p, $r->offset + $i + 1) . " · (cites {$p['cites']})"
                             . ($p['in_note'] ? ' (in a margin note)' : '') . self::authorLine($p['work']),
                         'text' => Text::inert($p['text']), 'tail' => self::notesLine($p) . self::scanLine($p['work']) . self::sourceLine($p['work'])
                             . self::pageLinkLine($p['work'], $p['page_start'] ?? null)];
        }
        $notes = $r->notes;
        if ($last < $r->passages) {
            array_unshift($notes, $last <= Citations::MAX_OFFSET
                ? "More passages cite {$r->label}: repeat with offset $last for the next ones."
                : "More passages cite {$r->label}, beyond the largest offset (" . number_format(Citations::MAX_OFFSET)
                    . '): narrow the reference to fewer verses, or filter by author, work_id, collection or years.');
        }
        return self::assemble($top, $blocks, self::notesFooter($notes));
    }

    /** Text for a reference no passage cites (returned with isError). */
    public static function noCitations(CitationResult $r, bool $chapterRefs): string
    {
        $verses = $r->label !== $r->chapterLabel;
        $try = $verses ? ["the whole chapter (\"{$r->chapterLabel}\")", 'a nearby verse'] : ['a nearby chapter'];
        if (!$chapterRefs) {
            $try[] = 'include_chapter_refs true';
        }
        if ($r->heading !== '') {
            $try[] = 'looser filters';
        }
        $try[] = 'or a search for the words of the ' . ($verses ? 'verse' : 'passage');
        return "No passage in the library cites {$r->label}" . ($r->heading !== '' ? " ({$r->heading})" : '') . '. '
            . 'Citations are found in the usual styles ("Rom. 8. 28", "Rom. viii. 28", "Romans 8:28"), in the text and '
            . 'in margin notes, but not a verse given only by its number ("ver. 30"). Try ' . implode(', ', $try) . '.'
            . self::notesFooter($r->notes);
    }

    // --------------------------------------------------------------------------- define_term

    /** Dictionary entries: headword, source and year, page, the dictionary's work_id, then the text. */
    public static function definitions(DictionaryResult $r): string
    {
        $exact = count(array_filter($r->entries, static fn (array $e): bool => $e['exact'] && !$e['indexed']));
        $spelled = count(array_filter($r->entries, static fn (array $e): bool => $e['exact'] && $e['indexed']));
        $begins = count($r->entries) - $exact - $spelled;
        $term = Text::echo($r->term, 100);
        $top = "Dictionary entries for \"$term\"" . ($r->source !== null ? ' in ' . Dictionary::SOURCES[$r->source] : '')
            . ($r->norm !== Dictionary::norm($r->term) ? " (as \"{$r->norm}\")" : '') . ': '
            . ($exact > 0 ? "$exact with this headword" : 'none with exactly this headword')
            . ($spelled > 0 ? "; $spelled with another headword indexed under it (check the meaning)" : '')
            . ($begins > 0 ? ($exact + $spelled > 0 ? ', then ' : '; ') . "$begins whose headword begins with it" : '');
        $cap = count($r->entries) === 1 ? Dictionary::ENTRY_ALONE_CHARS : Dictionary::ENTRY_CHARS;
        $blocks = [];
        foreach ($r->entries as $i => $e) {
            $head = '[' . ($i + 1) . '] ' . self::field($e['headword'], 200) . ' · '
                . (Dictionary::SOURCES[$e['source']] ?? self::field($e['source'], 40))
                . ($e['page'] !== null ? ' · p. ' . self::field($e['page'], 40) : '')
                . ($e['work_id'] !== null ? ' · work_id ' . $e['work_id'] : '')
                . ($e['indexed'] ? ' · indexed as "' . self::field($e['norm'], 100) . '"' : '');
            // a single entry is shown whole: how to ask for this one alone (Dictionary::lookup)
            $whole = '';
            if ($e['first'] && count($r->entries) > 1) {
                $whole = '; to read all of it, repeat with ' . ($r->source === null ? "source \"{$e['source']}\" and " : '')
                    . 'limit 1';
            } elseif ($e['alone']) {
                $whole = '; to read all of it, look up "' . self::field($e['headword'], 100) . "\" with source "
                    . "\"{$e['source']}\" and limit 1";
            }
            $tail = $e['cut'] ? "\n(Entry shortened to " . number_format($cap) . ' of its ' . number_format($e['length'])
                . " characters$whole.)" : '';
            $blocks[] = ['head' => $head, 'text' => Text::inert($e['text']), 'tail' => $tail];
        }
        $notes = $r->notes;
        if ($r->more) {
            $notes[] = 'More entries match: raise the limit (at most 20)'
                . ($r->source === null ? ' or choose a source' : '') . '.';
        }
        return self::assemble($top, $blocks, self::notesFooter($notes));
    }

    /** Text for a term no dictionary has (returned with isError). */
    public static function noDefinitions(DictionaryResult $r): string
    {
        $term = Text::echo($r->term, 100);
        return "No dictionary entry for \"$term\"" . ($r->source !== null ? ' in ' . Dictionary::SOURCES[$r->source] : '')
            . '. ' . ($r->notes !== [] ? implode(' ', $r->notes) . ' ' : '')
            . 'Try another spelling or form of the word (old spellings such as "sinne" are matched automatically, and '
            . '"justif" finds every headword that begins with it), or a related word.';
    }

    // ------------------------------------------------------------------------------ check_quote

    /** What every quote check that finds nothing must say. */
    public const QUOTE_NOT_FOUND_RULE = 'This does not show that the quotation is false: the library holds about 4,600 '
        . 'works, not everything ever written or every edition, so it can only say that it cannot confirm it. Tell the '
        . 'user so plainly. ' . self::FROM_MEMORY_RULE;

    public static function quoteCheck(QuoteResult $r): string
    {
        $who = $r->author !== null ? Text::echo($r->author, 100) : null;
        $top = 'Quote check: "' . Text::echo($r->quote, 600) . '"' . ($who !== null ? " (credited to $who)" : '');
        $p = $r->primary;
        $e = $r->earliest;
        $lines = [];
        if ($p === null) {
            $lines[] = 'NOT FOUND: no passage in the library has these words or wording close to them. '
                . self::QUOTE_NOT_FOUND_RULE;
            if ($who !== null) {
                $lines[] = $r->authorWorks > 0
                    ? 'The library has ' . self::plural($r->authorWorks, 'work') . " by $who; it holds only some of this author's writings."
                    : "The library has no works by $who.";
            }
            foreach ($r->notes as $n) {
                $lines[] = $n;
            }
            return $top . "\n" . implode("\n", $lines);
        }
        $where = self::quoteWhere($p);
        $credit = $p['credit'] !== null ? self::field($p['credit'], 80) : null;
        if ($r->verdict === QuoteResult::OTHER_AUTHOR && $credit !== null
            && Quote::byAuthor($p['work'], Quote::authorWords((string) $r->author))) {
            $lines[] = "FOUND, BUT BY SOMEONE ELSE: the words are in $where, but there $who is quoting $credit: a credit "
                . "line, \"— $credit\", follows them. Tell the user the words are $credit's as quoted by $who"
                . ($p['edits'] > 0 ? ' (worded a little differently; see below)' : '') . '.';
        } elseif ($r->verdict === QuoteResult::OTHER_AUTHOR) {
            $lines[] = "FOUND, BUT NOT BY $who: the earliest work in the library with these words is $where"
                . ($p['edits'] > 0 ? ' (worded a little differently; see below)' : '') . '. '
                . ($r->authorWorks > 0
                    ? 'None of the library\'s ' . self::plural($r->authorWorks, 'work') . " by $who has them (the library "
                        . 'holds only some of this author\'s writings, so this does not prove the words are not theirs).'
                    : "The library has no works by $who, so it cannot say whether he also used these words.");
            $lines[] = 'Tell the user who the library shows using these words first, and that the credit to ' . $who
                . ' is not confirmed.';
        } elseif ($r->verdict === QuoteResult::EXACT) {
            $lines[] = "FOUND WORD FOR WORD: these words are in $where. Spelling, capitals and punctuation were not "
                . 'compared; quote the library\'s wording below.';
        } else {
            $lines[] = "FOUND, BUT WORDED DIFFERENTLY: the closest wording is in $where: {$p['same']} of the {$p['words']} "
                . 'words given are there in the same order. The real wording and the one given are shown side by side '
                . 'below, with the words that differ in bold. Quote the library\'s wording, not the version given.';
        }
        if ($r->verdict !== QuoteResult::OTHER_AUTHOR && $e !== null && $e['work']['id'] !== $p['work']['id']
            && $e['when'] < $p['when'] && Text::looseKey($e['work']['author']) !== Text::looseKey($p['work']['author'])) {
            $lines[] = 'EARLIER SOURCE: ' . ($e['edits'] === 0 ? 'the same words are' : 'similar words are')
                . ' in an earlier work, ' . self::quoteWhere($e) . '. '
                . ($who !== null ? "$who may have been quoting or echoing it; say so." : 'The later writer may have been quoting it.');
        }
        if ($r->phraseWorks > Quote::COMMON_WORKS) {
            array_unshift($lines, 'COMMON WORDING: ' . number_format($r->phraseWorks) . ' or more works in the library use these exact '
                . 'words, so they may be a Bible verse, a proverb or a stock phrase; the earliest holder is not necessarily '
                . 'their author.');
        }
        if ($credit !== null && $r->verdict !== QuoteResult::OTHER_AUTHOR) {
            $lines[] = "NOTE: in this work a credit line, \"— $credit\", follows the words: the writer is quoting $credit.";
        } elseif ($credit === null && $p['quoting']) {
            $lines[] = 'NOTE: just before these words the writer seems to be quoting someone (a quotation mark, or a word '
                . 'such as "saith" or "says"): read the sentences before them to see whose words they are.';
        }
        foreach ($r->notes as $n) {
            $lines[] = $n;
        }
        $out = $top . "\n" . implode("\n", $lines) . "\n\n" . self::quoteBlock($p);
        $others = array_values(array_filter($r->matches, static fn (array $m): bool => $m['work']['id'] !== $p['work']['id']));
        if ($others !== []) {
            $out .= "\n\nOther works in the library with these words (earliest first"
                . (count($others) > 8 ? ', 8 of ' . count($others) : '') . '):';
            foreach (array_slice($others, 0, 8) as $m) {
                $out .= "\n- " . self::quoteWhere($m) . ' · P' . $m['passage']['id'] . ' · ' . self::quoteMatchLabel($m)
                    . ($m['credit'] !== null ? ' · credited there to ' . self::field($m['credit'], 80)
                        : ($m['quoting'] ? ' · seems to be quoting someone' : ''));
            }
        }
        return mb_strlen($out) > self::MAX_CHARS ? Text::clip($out, self::MAX_CHARS) : $out;
    }

    /** "John Owen (1616–1683), *Of the Mortification of Sin* (1668), p. 11" */
    private static function quoteWhere(array $m): string
    {
        $w = $m['work'];
        $note = (string) ($w['author_note'] ?? '');
        $dates = $note !== '' ? trim(explode(',', $note, 2)[0]) : '';
        $title = $w['title'] !== '' ? $w['title'] : 'Untitled';
        $page = $m['page'] !== null && $m['page'] !== '' ? $m['page'] : null;
        $pageEnd = $m['page_end'] !== null && $m['page_end'] !== '' ? $m['page_end'] : null;
        return ($w['author'] !== '' ? self::field($w['author'], 200) . ($dates !== '' ? ' (' . self::field($dates, 60) . ')' : '')
                . ', ' : '')
            . '*' . self::field($title, 300) . '*' . ($w['year'] !== null ? ' (' . $w['year'] . ')' : ' (year not known)')
            . ($page !== null ? ', ' . self::pages($page, $pageEnd ?? $page) : '');
    }

    private static function quoteMatchLabel(array $m): string
    {
        return $m['edits'] === 0 ? 'word for word' : "{$m['same']} of {$m['words']} words the same";
    }

    /** The passage block: header, author, the wording (side by side when it differs), context, links. */
    private static function quoteBlock(array $m): string
    {
        $p = $m['passage'];
        $w = $m['work'];
        $out = self::passageHeader($p) . ' · Match: ' . self::quoteMatchLabel($m) . self::authorLine($w);
        $text = Text::inert(Text::oneLine(Text::plain($m['text']), 3000));
        if ($m['edits'] === 0) {
            $out .= "\nThe library's wording: \"" . $text . '"';
        } else {
            $out .= "\nCompared word by word (spelling, capitals and punctuation ignored; differing words in bold):"
                . "\nThe library's wording: \"" . Text::inert(Text::oneLine(self::plainKeepBold($m['lib_marked']), 3000)) . '"'
                . "\nThe wording given:     \"" . Text::inert(Text::oneLine($m['quote_marked'], 3000)) . '"';
            if ($m['changes'] !== []) {
                $out .= "\nDifferences (given vs library): " . Text::inert(Text::oneLine(implode('; ', $m['changes']), 1500));
            }
        }
        $out .= "\nIn context: \"" . Text::inert(Text::oneLine($m['before'], 600)) . ($m['before'] !== '' ? ' ' : '')
            . '**' . $text . '**' . (preg_match('/^\s/u', $m['after']) === 1 ? ' ' : '')
            . Text::inert(Text::oneLine($m['after'], 600)) . '"'
            . self::scanLine($w) . self::sourceLine($w) . self::pageLinkLine($w, $m['page']);
        return $out;
    }

    /** Text::plain() that keeps the **bold** marks added by the quote checker. */
    private static function plainKeepBold(string $text): string
    {
        $text = str_replace('**', "\u{E000}", $text);
        return str_replace("\u{E000}", '**', Text::plain($text));
    }

    // ------------------------------------------------------------------------------- pieces

    /** "[n] P<id> · Author, *Title* (Year) · pp. X–Y · Section" */
    public static function passageHeader(array $p, ?int $n = null): string
    {
        $parts = [($n !== null ? "[$n] " : '') . 'P' . $p['id'], self::workLabel($p['work'])];
        $pages = self::pages($p['page_start'], $p['page_end']);
        if ($pages !== '') {
            $parts[] = $pages;
        }
        if ($p['section'] !== '') {
            $parts[] = self::field($p['section'], 200);
        }
        return implode(' · ', $parts);
    }

    /** "Author, *Title* (Year)"; the year is left out when unknown. */
    public static function workLabel(array $w): string
    {
        $title = $w['title'] !== '' ? $w['title'] : 'Untitled';
        return ($w['author'] !== '' ? self::field($w['author'], 200) . ', ' : '') . '*' . self::field($title, 300) . '*'
            . ($w['year'] !== null ? ' (' . $w['year'] . ')' : '');
    }

    /** "p. 23" when start and end agree, "pp. 23–24" when not, "" when unknown. */
    public static function pages(?string $start, ?string $end): string
    {
        $start = $start === null || $start === '' ? null : self::field($start, 40);
        $end = $end === null || $end === '' ? null : self::field($end, 40);
        if ($start !== null && $end !== null && $start !== $end) {
            return "pp. {$start}–{$end}";
        }
        $one = $start ?? $end;
        return $one === null ? '' : "p. $one";
    }

    public static function collectionLabel(string $code): string
    {
        return self::COLLECTIONS[$code] ?? self::field($code, 60);
    }

    /** A library field on one line, at most $max characters, made inert (Text::inert). */
    public static function field(string $text, int $max): string
    {
        return Text::inert(Text::oneLine($text, $max));
    }

    private static function notesLine(array $p): string
    {
        if ($p['notes'] === []) {
            return '';
        }
        return "\nNotes: " . Text::inert(Text::clip(implode(' | ', array_map(static fn (string $n): string
            => Text::oneLine($n, self::NOTES_MAX), $p['notes'])), self::NOTES_MAX));
    }

    /**
     * "\nScan page: <url>" for a passage of a work copied from an Internet Archive scan whose first page is a plain
     * printed page number: archive.org opens its viewer at the page with that printed label (".../page/100").
     * Other sources have no page-level address.
     */
    private static function pageLinkLine(array $w, ?string $pageStart): string
    {
        $id = self::archiveId((string) ($w['source_url'] ?? ''));
        if ($id === null || $pageStart === null || !preg_match('/^[1-9][0-9]{0,4}$/', trim($pageStart))) {
            return '';
        }
        return "\nScan page: https://archive.org/details/$id/page/" . trim($pageStart) . '/mode/1up';
    }

    /** The Internet Archive item name of a "https://archive.org/details/<id>" address, or null. */
    public static function archiveId(string $url): ?string
    {
        return preg_match('~^https://archive\.org/details/([A-Za-z0-9][A-Za-z0-9._-]{0,150})/?$~', trim($url), $m) === 1 ? $m[1] : null;
    }

    /** "\nText: uncorrected OCR…" for a work whose text is OCR of a scan, else "". */
    private static function scanLine(array $w): string
    {
        return (int) ($w['ocr'] ?? 0) === 1 ? "\n" . self::SCAN_NOTE : '';
    }

    /**
     * "\nAuthor: Albert Barnes (1798–1870, American Presbyterian minister, New School)" when the library
     * has a note on the author, else "". It says who is speaking; it does not judge him.
     */
    private static function authorLine(array $w): string
    {
        $note = (string) ($w['author_note'] ?? '');
        return $note === '' || $w['author'] === '' ? ''
            : "\nAuthor: " . self::field($w['author'], 200) . ' (' . self::field($note, 300) . ')';
    }

    /** " · Match: close" for a search result that carries a match strength, else "". */
    private static function matchLabel(?string $match): string
    {
        return $match === null ? '' : ' · Match: ' . ($match === 'exact' ? 'exact words' : $match);
    }

    private static function sourceLine(array $w): string
    {
        return $w['source_url'] !== '' ? "\nCopied from: " . self::field($w['source_url'], 500) : '';
    }

    /** "1 passage", "1,234 passages" */
    private static function plural(int $n, string $noun): string
    {
        return number_format($n) . " $noun" . ($n === 1 ? '' : 's');
    }

    /** @param list<string> $notes */
    private static function notesFooter(array $notes): string
    {
        $notes = array_values(array_unique(array_filter($notes, static fn (string $n): bool => $n !== '')));
        return $notes === [] ? '' : "\n\nSearch notes: " . implode(' ', $notes);
    }

    private static function hasFilterNote(array $notes): bool
    {
        foreach ($notes as $n) {
            if (str_starts_with($n, 'Filters:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Joins a header line, blocks (head line, text, tail lines) and a footer, cutting the texts
     * when the whole would be longer than MAX_CHARS: short texts stay whole, longer ones are cut
     * to an equal share of the remaining room.
     * @param list<array{head:string, text:string, tail:string}> $blocks
     */
    private static function assemble(string $top, array $blocks, string $bottom): string
    {
        $fixed = mb_strlen($top) + mb_strlen($bottom) + 400;   // 400: separators and the cut note
        $lengths = [];
        foreach ($blocks as $i => $b) {
            $fixed += mb_strlen($b['head']) + mb_strlen($b['tail']) + 4;
            $lengths[$i] = mb_strlen($b['text']);
        }
        $room = self::MAX_CHARS - $fixed;
        $cut = false;
        if (array_sum($lengths) > $room) {
            $cap = max(self::MIN_TEXT, self::share($lengths, max(0, $room)));
            foreach ($blocks as $i => $b) {
                if ($lengths[$i] > $cap) {
                    $blocks[$i]['text'] = Text::clip($b['text'], $cap);
                    $cut = true;
                }
            }
        }
        $out = $top;
        foreach ($blocks as $b) {
            $out .= "\n\n" . $b['head'] . ($b['text'] !== '' ? "\n" . $b['text'] : '') . $b['tail'];
        }
        if ($cut) {
            $out .= "\n\nSome passages were shortened (ending in …) to keep this result under "
                . number_format(self::MAX_CHARS) . ' characters; read them in full by ref.';
        }
        $out .= $bottom === '' ? '' : (str_starts_with($bottom, "\n") ? $bottom : "\n\n" . $bottom);
        if (mb_strlen($out) > self::MAX_CHARS) {   // only if headers alone are huge
            $out = Text::clip($out, self::MAX_CHARS);
        }
        return $out;
    }

    /** Largest per-item length L such that the sum of min(length, L) fits in $room. @param list<int> $lengths */
    private static function share(array $lengths, int $room): int
    {
        sort($lengths);
        $n = count($lengths);
        $used = 0;
        foreach ($lengths as $i => $len) {
            $cap = intdiv($room - $used, $n - $i);
            if ($len > $cap) {
                return $cap;
            }
            $used += $len;
        }
        return PHP_INT_MAX;
    }
}
