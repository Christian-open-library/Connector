<?php
/**
 * Read-only access to one search build (DESIGN.md sections 5 and 10): library.sqlite through PDO
 * SQLite (passages, works, the Bible, Scripture citations, dictionaries), and the vector files
 * vectors.bin (int8 rows) and ids.bin (uint32 passage ids).
 *
 * The database is opened with SQLITE_OPEN_READONLY and every statement is prepared, with values
 * bound by type (an integer bound as text never equals an integer in SQLite expressions such as
 * rowid / 100000). Must work on SQLite 3.37.2: no ->>, no RIGHT/FULL JOIN, no newer FTS5 options.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** The build cannot be opened or read. Messages never contain user input. */
final class StoreException extends \RuntimeException
{
}

final class Store
{
    /** passage id = work_id * SEQ_MULT + seq (DESIGN.md section 2) */
    public const SEQ_MULT = 100000;

    private const WORK_COLUMNS = 'id, key, author, author_slug, title, full_title, year, type, collection, '
        . 'license, source_url, tcp_id, series, ocr, n_passages, page_first, page_last';
    private const PASSAGE_COLUMNS = 'id, work_id, seq, part, page_start, page_end, section, text, notes, words, vec_pos';
    private const IN_CHUNK = 500;   // values per IN (...) list
    /**
     * Largest decompressed size accepted for a stored BLOB; a larger one reads as empty (fails
     * closed for that row instead of using up the request's memory). The indexer caps passages at
     * 40,000 characters and notes at 40,000, dictionary entries at 200,000 (indexer/corpus.py,
     * indexer/references.py); these leave room for multi-byte text and JSON escapes.
     */
    public const MAX_TEXT_BYTES = 262_144;
    public const MAX_NOTES_BYTES = 262_144;
    public const MAX_DICT_BYTES = 1_048_576;
    public const DICT_EXACT_MAX = 200;      // dictionary rows read for one term
    public const TOPIC_LINKS_MAX = 2000;    // topic links read for one chapter (the most in the KJV: 528)
    private const MAX_VERSE = 999;  // above any verse number: "to the end of the chapter"

    private \PDO $db;
    private string $dir;
    /** @var array<string,string>|null */
    private ?array $meta = null;
    /** @var array<int,array>|null all works by id */
    private ?array $allWorks = null;
    /** @var array<int,array> works fetched one by one */
    private array $workCache = [];
    /** @var array<string,string>|null author name => "dates, note"; null until read ([] for a build without the table) */
    private ?array $authorNotes = null;
    private ?array $sameWriter = null;
    private ?array $authorLives = null;
    /** @var array<string,array{tradition:string,note:string}>|null author name => tradition and note (authors table) */
    private ?array $authorTraditions = null;
    /** @var array<int,array>|null church records by work id (Churches); [] for a build without them */
    private ?array $churchRecords = null;
    /** @var array<string,array>|null church bodies by name */
    private ?array $churchBodies = null;
    /** @var array<string,string>|null tag value => body name */
    private ?array $churchBodyOfTag = null;
    /** @var array<string,array>|null present-day church names */
    private ?array $churchNames = null;
    /** @var array<string,string|false> word => modern spelling (false = none) */
    private array $spellCache = [];
    /** @var array<int,array>|null Bible books by id */
    private ?array $bibleBooks = null;
    private ?bool $vectorsReady = null;
    /** @var resource|null */
    private $vecFile = null;
    /** @var resource|null */
    private $idFile = null;

    /** @throws StoreException if library.sqlite is missing or cannot be opened */
    /** PDO's SQLite open-flags attribute: Pdo\Sqlite's name from PHP 8.4 (the PDO one is deprecated in 8.5). */
    public static function readOnlyFlag(): int
    {
        return defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS') ? \Pdo\Sqlite::ATTR_OPEN_FLAGS : \PDO::SQLITE_ATTR_OPEN_FLAGS;
    }

    public static function readOnlyValue(): int
    {
        return defined('Pdo\\Sqlite::OPEN_READONLY') ? \Pdo\Sqlite::OPEN_READONLY : \PDO::SQLITE_OPEN_READONLY;
    }

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/');
        $path = $this->dir . '/library.sqlite';
        if (!is_file($path) || !is_readable($path)) {
            throw new StoreException('library.sqlite not found in the data directory');
        }
        try {
            $this->db = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                self::readOnlyFlag() => self::readOnlyValue(),
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->db->exec('PRAGMA query_only = 1');
            $this->db->exec('PRAGMA cache_size = -16000');   // 16 MB page cache for this request
        } catch (\PDOException $e) {
            throw new StoreException('library.sqlite could not be opened: ' . $e->getMessage(), 0, $e);
        }
    }

    public function __destruct()
    {
        foreach ([$this->vecFile, $this->idFile] as $fh) {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    // ------------------------------------------------------------------------------------- meta

    /** A value from the build's meta table (loaded once). */
    public function meta(string $key): ?string
    {
        if ($this->meta === null) {
            $this->meta = [];
            foreach ($this->run('SELECT key, value FROM meta')->fetchAll(\PDO::FETCH_NUM) as [$k, $v]) {
                $this->meta[(string) $k] = (string) $v;
            }
        }
        return $this->meta[$key] ?? null;
    }

    /** Dimensions of the stored vectors (0 if the build has none). */
    public function dims(): int
    {
        return (int) ($this->meta('embed_dims') ?? 0);
    }

    /** True if the build has an IVF index and vector files of the size the meta table says. */
    public function vectorsReady(): bool
    {
        if ($this->vectorsReady !== null) {
            return $this->vectorsReady;
        }
        $dims = $this->dims();
        $n = (int) ($this->meta('n_vectors') ?? 0);
        $ok = $dims > 0 && $n > 0 && ($this->meta('embed_dtype') ?? 'int8') === 'int8'
            && @filesize($this->dir . '/vectors.bin') === $n * $dims
            && @filesize($this->dir . '/ids.bin') === $n * 4;
        if ($ok) {
            $ok = (int) $this->run('SELECT count(*) FROM ivf')->fetchColumn() > 0;
        }
        return $this->vectorsReady = $ok;
    }

    // ---------------------------------------------------------------------------------- works

    /** @return array<int,array> every work by id (3,000-odd small rows; loaded once) */
    public function allWorks(): array
    {
        if ($this->allWorks === null) {
            $this->allWorks = [];
            foreach ($this->run('SELECT ' . self::WORK_COLUMNS . ' FROM works ORDER BY id')->fetchAll() as $w) {
                $this->allWorks[(int) $w['id']] = $this->noted(self::workRow($w));
            }
        }
        return $this->allWorks;
    }

    public function work(int $id): ?array
    {
        return $this->works([$id])[$id] ?? null;
    }

    /** @param list<int> $ids  @return array<int,array> works by id (missing ids are left out) */
    public function works(array $ids): array
    {
        $out = [];
        $need = [];
        foreach (array_unique($ids) as $id) {
            if ($this->allWorks !== null) {
                if (isset($this->allWorks[$id])) {
                    $out[$id] = $this->allWorks[$id];
                }
            } elseif (isset($this->workCache[$id])) {
                $out[$id] = $this->workCache[$id];
            } else {
                $need[] = $id;
            }
        }
        foreach (array_chunk($need, self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT ' . self::WORK_COLUMNS . ' FROM works WHERE id IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll() as $w) {
                $w = $this->noted(self::workRow($w));
                $out[$w['id']] = $this->workCache[$w['id']] = $w;
            }
        }
        return $out;
    }

    /** @return list<string> collection codes present in the build */
    public function collections(): array
    {
        $codes = [];
        foreach ($this->allWorks() as $w) {
            $codes[$w['collection']] = true;
        }
        return array_map('strval', array_keys($codes));
    }

    // ------------------------------------------------------------------------------- passages

    public function passage(int $id): ?array
    {
        return $this->passages([$id])[$id] ?? null;
    }

    /**
     * Passages with text decompressed and notes decoded (missing ids are left out).
     * @param list<int> $ids  @return array<int,array> by id, in the order of $ids
     */
    public function passages(array $ids): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT ' . self::PASSAGE_COLUMNS . ' FROM passages WHERE id IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll() as $p) {
                $rows[(int) $p['id']] = self::passageRow($p);
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $out[$id] = $rows[$id];
            }
        }
        return $out;
    }

    /** First and last passage id of a work. @return array{0:int,1:int}|null */
    public function passageRange(int $workId): ?array
    {
        $row = $this->run('SELECT min(id), max(id) FROM passages WHERE work_id = ?', [$workId])->fetch(\PDO::FETCH_NUM);
        return $row && $row[0] !== null ? [(int) $row[0], (int) $row[1]] : null;
    }

    /**
     * Passages of a work just before and after a seq (seq numbers may have gaps). One extra row
     * is fetched on each side, so the caller knows whether more exist.
     * @return array{before: list<array>, after: list<array>} both in reading order
     */
    public function neighbours(int $workId, int $seq, int $before, int $after): array
    {
        $cols = self::PASSAGE_COLUMNS;
        $prev = $this->run("SELECT $cols FROM passages WHERE work_id = ? AND seq < ? ORDER BY seq DESC LIMIT ?",
            [$workId, $seq, $before + 1])->fetchAll();
        $next = $this->run("SELECT $cols FROM passages WHERE work_id = ? AND seq > ? ORDER BY seq ASC LIMIT ?",
            [$workId, $seq, $after + 1])->fetchAll();
        return [
            'before' => array_map([self::class, 'passageRow'], array_reverse($prev)),
            'after' => array_map([self::class, 'passageRow'], $next),
        ];
    }

    /**
     * The passage where a printed page begins.
     * Tries, in order: a passage starting or ending on the label (exact, then case-insensitive);
     * for numbers and roman numerals, the first passage whose page range covers it; then a scan
     * of the work's texts for the "[p. LABEL]" marker.
     * @return array{passage: array, exact: bool}|null exact = the page start was found
     */
    public function findPage(int $workId, string $label, int $scanLimit = 20000): ?array
    {
        $cols = self::PASSAGE_COLUMNS;
        foreach (['', ' COLLATE NOCASE'] as $collate) {
            $row = $this->run("SELECT $cols FROM passages WHERE work_id = ? AND (page_start = ?$collate OR page_end = ?$collate)"
                . ' ORDER BY seq LIMIT 1', [$workId, $label, $label])->fetch();
            if ($row) {
                return ['passage' => self::passageRow($row), 'exact' => true];
            }
        }
        $marker = '[p. ' . $label . ']';
        $target = self::pageNumber($label);
        if ($target !== null) {
            $pages = $this->run('SELECT id, page_start, page_end FROM passages WHERE work_id = ? ORDER BY seq',
                [$workId])->fetchAll(\PDO::FETCH_NUM);
            foreach ($pages as [$id, $start, $end]) {
                $a = $start === null ? null : self::pageNumber((string) $start);
                $b = $end === null ? null : self::pageNumber((string) $end);
                if ($a !== null && $b !== null && $a[1] === $target[1] && $b[1] === $target[1]
                    && $a[0] <= $target[0] && $target[0] <= $b[0]) {
                    $p = $this->passage((int) $id);
                    if ($p !== null) {
                        return ['passage' => $p, 'exact' => str_contains($p['text'], $marker)];
                    }
                }
            }
        }
        // Last resort: look for the marker in the text (labels such as "14a" or "ii.546").
        $st = $this->run("SELECT $cols FROM passages WHERE work_id = ? ORDER BY seq LIMIT ?", [$workId, $scanLimit]);
        while ($row = $st->fetch()) {
            $text = self::inflate($row['text'], self::MAX_TEXT_BYTES);
            if ($text !== '' && str_contains($text, $marker)) {
                $st->closeCursor();
                return ['passage' => self::passageRow($row), 'exact' => true];
            }
        }
        return null;
    }

    /**
     * Numeric value of a page label: [value, 'arabic'] for "23", [value, 'roman'] for "xiv".
     * @return array{0:int,1:string}|null
     */
    public static function pageNumber(string $label): ?array
    {
        $label = trim($label);
        if (preg_match('/^\d{1,6}$/D', $label)) {
            return [(int) $label, 'arabic'];
        }
        if (!preg_match('/^[ivxlcdm]{1,12}$/iD', $label)) {
            return null;
        }
        $vals = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100, 'd' => 500, 'm' => 1000];
        $chars = str_split(strtolower($label));
        $total = 0;
        foreach ($chars as $i => $ch) {
            $v = $vals[$ch];
            $next = isset($chars[$i + 1]) ? $vals[$chars[$i + 1]] : 0;
            $total += $v < $next ? -$v : $v;
        }
        return $total > 0 ? [$total, 'roman'] : null;
    }

    // ---------------------------------------------------------------------------- full text

    /**
     * Ranked keyword search over passages_fts (bm25 weights body 1.0, notes 0.3, header 0.6).
     * @param list<int>|null $workIds restrict to these works (null = all)
     * @return array<int,float> passage id => bm25 score (lower is better), best first
     */
    public function keywordSearch(string $match, ?array $workIds, int $limit): array
    {
        [$cond, $params] = $this->workCondition('rowid', $workIds);
        $st = $this->run('SELECT rowid, bm25(passages_fts, 1.0, 0.3, 0.6) AS score FROM passages_fts '
            . "WHERE passages_fts MATCH ?$cond ORDER BY score, rowid LIMIT ?",
            array_merge([$match], $params, [$limit]));
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $score]) {
            $out[(int) $id] = (float) $score;
        }
        return $out;
    }

    /**
     * Passage ids matching an FTS5 query, in id (book) order, with paging.
     * @param list<int>|null $workIds  @return list<int>
     */
    public function matchInOrder(string $match, ?array $workIds, int $limit, int $offset): array
    {
        [$cond, $params] = $this->workCondition('rowid', $workIds);
        $st = $this->run("SELECT rowid FROM passages_fts WHERE passages_fts MATCH ?$cond ORDER BY rowid LIMIT ? OFFSET ?",
            array_merge([$match], $params, [$limit, $offset]));
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Number of passages matching an FTS5 query, counting at most $cap. @param list<int>|null $workIds */
    public function countMatches(string $match, ?array $workIds, int $cap): int
    {
        [$cond, $params] = $this->workCondition('rowid', $workIds);
        $st = $this->run("SELECT count(*) FROM (SELECT rowid FROM passages_fts WHERE passages_fts MATCH ?$cond LIMIT ?)",
            array_merge([$match], $params, [$cap]));
        return (int) $st->fetchColumn();
    }

    /**
     * Ranked search over works_fts (author, title, full title).
     * @return array<int,float> work id => bm25 score, best first
     */
    public function workSearch(string $match, int $limit): array
    {
        $st = $this->run('SELECT rowid, bm25(works_fts, 1.0, 1.0, 0.5) AS score FROM works_fts '
            . 'WHERE works_fts MATCH ? ORDER BY score, rowid LIMIT ?', [$match, $limit]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $score]) {
            $out[(int) $id] = (float) $score;
        }
        return $out;
    }

    /**
     * Modern spellings of words from the build's spelling table (one IN query per 500 words).
     * @param list<string> $words lower-case  @return array<string,string> variant => standard
     */
    public function spellings(array $words): array
    {
        $need = [];
        foreach ($words as $w) {
            if (!isset($this->spellCache[$w]) && preg_match('/\p{L}/u', $w)) {
                $need[$w] = true;
            }
        }
        $need = array_map('strval', array_keys($need));
        foreach (array_chunk($need, self::IN_CHUNK) as $chunk) {
            foreach ($chunk as $w) {
                $this->spellCache[$w] = false;
            }
            $st = $this->run('SELECT variant, standard FROM spelling WHERE variant IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$variant, $standard]) {
                $this->spellCache[(string) $variant] = (string) $standard;
            }
        }
        $out = [];
        foreach ($words as $w) {
            if (($this->spellCache[$w] ?? false) !== false) {
                $out[$w] = $this->spellCache[$w];
            }
        }
        return $out;
    }

    /**
     * Old spellings recorded for modern words (reverse lookup; scans the spelling table, so it is
     * used only for the small works index).
     * @param list<string> $standards  @return array<string,list<string>> standard => variants
     */
    public function variantsOf(array $standards, int $perWord = 12): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($standards)), self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT variant, standard FROM spelling WHERE standard IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$variant, $standard]) {
                if (count($out[$standard] ?? []) < $perWord) {
                    $out[(string) $standard][] = (string) $variant;
                }
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------------- vectors

    /** @return list<array{cluster:int, start:int, count:int, centroid:string}> float32 LE centroids */
    public function centroids(): array
    {
        $out = [];
        foreach ($this->run('SELECT cluster, start, count, centroid FROM ivf ORDER BY cluster')->fetchAll(\PDO::FETCH_NUM)
                 as [$cluster, $start, $count, $centroid]) {
            $out[] = ['cluster' => (int) $cluster, 'start' => (int) $start, 'count' => (int) $count,
                      'centroid' => (string) $centroid];
        }
        return $out;
    }

    /** Rows [$start, $start + $count) of vectors.bin as raw int8 bytes (one read). */
    public function readRows(int $start, int $count): string
    {
        $dims = $this->dims();
        $this->vecFile ??= $this->openBinary('vectors.bin');
        return self::readAt($this->vecFile, $start * $dims, $count * $dims);
    }

    /** Passage ids of rows [$start, $start + $count). @return list<int> */
    public function readIds(int $start, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $this->idFile ??= $this->openBinary('ids.bin');
        $ids = unpack('V*', self::readAt($this->idFile, $start * 4, $count * 4));
        return $ids === false ? [] : array_values($ids);
    }

    /**
     * Vector rows of the passages of some works, for an exact scan.
     * @param list<int> $workIds  @return array<int,int> row position in vectors.bin => passage id, by position
     */
    public function vectorPositions(array $workIds): array
    {
        $out = [];
        foreach (array_chunk($workIds, self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT vec_pos, id FROM passages WHERE work_id IN (' . self::marks($chunk) . ') '
                . 'AND vec_pos IS NOT NULL', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$pos, $id]) {
                $out[(int) $pos] = (int) $id;
            }
        }
        ksort($out);
        return $out;
    }

    // ----------------------------------------------------------------------- cross-references

    /** True if the build has all these tables (the cross-reference tables came after the first builds). */
    /**
     * Which of these words are headwords of the library's dictionaries (one of them, Wilson 1661, is a general
     * English dictionary): how check_quote tells a real word from a scan's misreading. Empty for a build
     * without the dictionary table.
     * @param list<string> $words lower-case  @return array<string,true>
     */
    public function headwords(array $words): array
    {
        $words = array_values(array_unique(array_map('strval', $words)));
        if ($words === [] || !$this->hasTables('dictionary')) {
            return [];
        }
        $out = [];
        foreach (array_chunk($words, self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT DISTINCT norm FROM dictionary WHERE norm IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $norm) {
                $out[(string) $norm] = true;
            }
        }
        return $out;
    }

    public function hasTables(string ...$names): bool
    {
        $names = array_values(array_unique($names));
        if ($names === []) {
            return true;
        }
        $st = $this->run("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name IN (" . self::marks($names) . ')',
            $names);
        return (int) $st->fetchColumn() === count($names);
    }

    /**
     * The Bible's books (bible_books; DESIGN.md section 10), loaded once.
     * @return array<int,array{id:int, name:string, short:string, aliases:list<string>, chapters:int}> by id
     */
    public function bibleBooks(): array
    {
        if ($this->bibleBooks === null) {
            $this->bibleBooks = [];
            foreach ($this->run('SELECT id, name, short, aliases, chapters FROM bible_books ORDER BY id')->fetchAll() as $b) {
                $aliases = json_decode((string) $b['aliases'], true);
                $this->bibleBooks[(int) $b['id']] = [
                    'id' => (int) $b['id'],
                    'name' => (string) $b['name'],
                    'short' => (string) ($b['short'] ?? ''),
                    'aliases' => is_array($aliases) ? array_values(array_filter($aliases, 'is_string')) : [],
                    'chapters' => (int) ($b['chapters'] ?? 0),
                ];
            }
        }
        return $this->bibleBooks;
    }

    /** Last chapter number of a book in a translation (0 if the translation does not have the book). */
    public function bibleLastChapter(string $translation, int $book): int
    {
        return (int) $this->run('SELECT max(chapter) FROM bible WHERE translation = ? AND book = ?',
            [$translation, $book])->fetchColumn();
    }

    /**
     * Verses of one translation whose text matches a LIKE pattern, in Bible order (a scan of that translation:
     * about 31,000 short rows). The caller builds the pattern from letters, digits and "%" only.
     * @return list<array{book:int, chapter:int, verse:int, text:string}>
     */
    public function bibleLike(string $translation, string $pattern, int $limit): array
    {
        $out = [];
        foreach ($this->run('SELECT book, chapter, verse, text FROM bible WHERE translation = ? AND text LIKE ? '
            . 'ORDER BY book, chapter, verse LIMIT ?', [$translation, $pattern, $limit])->fetchAll(\PDO::FETCH_NUM) as $r) {
            $out[] = ['book' => (int) $r[0], 'chapter' => (int) $r[1], 'verse' => (int) $r[2], 'text' => (string) $r[3]];
        }
        return $out;
    }

    /**
     * Verses, in any translation, that have at least two of up to three given words (one scan of the bible table).
     * The caller passes words of letters and digits only.
     * @param list<string> $words 2 or 3 words
     * @return list<array{translation:string, book:int, chapter:int, verse:int, text:string}>
     */
    public function bibleWithWords(array $words, int $limit): array
    {
        $words = array_values($words);
        $pairs = count($words) >= 3 ? [[0, 1], [0, 2], [1, 2]] : [[0, 1]];
        $sql = [];
        $params = [];
        foreach ($pairs as [$i, $j]) {
            $sql[] = '(text LIKE ? AND text LIKE ?)';
            $params[] = '%' . $words[$i] . '%';
            $params[] = '%' . $words[$j] . '%';
        }
        $params[] = $limit;
        $out = [];
        foreach ($this->run('SELECT translation, book, chapter, verse, text FROM bible WHERE ' . implode(' OR ', $sql)
            . ' LIMIT ?', $params)->fetchAll(\PDO::FETCH_NUM) as $r) {
            $out[] = ['translation' => (string) $r[0], 'book' => (int) $r[1], 'chapter' => (int) $r[2],
                      'verse' => (int) $r[3], 'text' => (string) $r[4]];
        }
        return $out;
    }

    /** Last verse number of a chapter in a translation (0 if the translation does not have the chapter). */
    public function bibleLastVerse(string $translation, int $book, int $chapter): int
    {
        return (int) $this->run('SELECT max(verse) FROM bible WHERE translation = ? AND book = ? AND chapter = ?',
            [$translation, $book, $chapter])->fetchColumn();
    }

    /**
     * Verses $c1:$v1 to $c2:$v2 (inclusive) of a book in one translation, in order, at most $limit.
     * @return list<array{chapter:int, verse:int, text:string}>
     */
    public function bibleVerses(string $translation, int $book, int $c1, int $v1, int $c2, int $v2, int $limit): array
    {
        if ($c1 === $c2) {
            $st = $this->run('SELECT chapter, verse, text FROM bible WHERE translation = ? AND book = ? AND chapter = ? '
                . 'AND verse BETWEEN ? AND ? ORDER BY chapter, verse LIMIT ?', [$translation, $book, $c1, $v1, $v2, $limit]);
        } else {
            $st = $this->run('SELECT chapter, verse, text FROM bible WHERE translation = ? AND book = ? '
                . 'AND chapter BETWEEN ? AND ? AND (chapter > ? OR verse >= ?) AND (chapter < ? OR verse <= ?) '
                . 'ORDER BY chapter, verse LIMIT ?', [$translation, $book, $c1, $c2, $c1, $v1, $c2, $v2, $limit]);
        }
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$chapter, $verse, $text]) {
            $out[] = ['chapter' => (int) $chapter, 'verse' => (int) $verse, 'text' => (string) $text];
        }
        return $out;
    }

    /**
     * Scripture citations in one chapter (scripture_refs; verse_start NULL = the whole chapter), in
     * verse order: with $from, the citations whose verses overlap $from-$to ($to null = to the end
     * of the chapter); with $from null, every citation of a verse of the chapter; then, if
     * $chapterRefs, those of the whole chapter. Each query reads one range of the scripture_refs_bcv
     * index (book, chapter, verse_start, ...; in builds made after indexer/references.py widened it
     * to every column read here, the table itself is not read).
     * @param list<int>|null $workIds only these works (null = all)
     * @return list<array{0:int,1:int,2:int,3:?int,4:?int,5:int}>
     *         [chapter, passage_id, work_id, verse_start, verse_end, in_note]
     */
    public function citations(int $book, int $chapter, ?int $from, ?int $to, bool $chapterRefs, ?array $workIds,
                              int $limit): array
    {
        $works = '';
        $workParams = [];
        if ($workIds !== null) {
            if ($workIds === []) {
                return [];
            }
            $works = ' AND work_id IN (' . self::marks($workIds) . ')';
            $workParams = array_values($workIds);
        }
        $base = 'SELECT passage_id, work_id, verse_start, verse_end, in_note FROM scripture_refs WHERE book = ? AND chapter = ?';
        // the verses first, so that a cut (at $limit) drops the least specific citations
        $queries = [[$base . ($from === null ? ' AND verse_start IS NOT NULL'
            : ' AND verse_start <= ? AND coalesce(verse_end, verse_start) >= ?') . $works . ' ORDER BY verse_start',
            $from === null ? [] : [$to ?? self::MAX_VERSE, $from]]];
        if ($chapterRefs) {
            $queries[] = [$base . ' AND verse_start IS NULL' . $works, []];
        }
        $out = [];
        foreach ($queries as [$sql, $params]) {
            if (count($out) >= $limit) {
                break;
            }
            $st = $this->run($sql . ' LIMIT ?', array_merge([$book, $chapter], $params, $workParams, [$limit - count($out)]));
            while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                $out[] = [$chapter, (int) $r[0], (int) $r[1], $r[2] === null ? null : (int) $r[2],
                          $r[3] === null ? null : (int) $r[3], (int) $r[4]];
            }
        }
        return $out;
    }

    /**
     * Section heading of each passage (id => heading, '' when none), for Subject; only the short column is read.
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function passageSections(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT id, section FROM passages WHERE id IN (' . self::marks($chunk) . ')', $chunk);
            while (($r = $st->fetch(\PDO::FETCH_NUM)) !== false) {
                $out[(int) $r[0]] = (string) ($r[1] ?? '');
            }
        }
        return $out;
    }

    /**
     * Nave's and Torrey's topic links (topic_refs) covering any of verses $from-$to of a chapter,
     * or the whole chapter, with the topic's headword.
     * @return list<array{id:int, source:string, headword:string, norm:string, verse_start:?int, verse_end:?int}>
     *         id = the topic's dictionary id
     */
    public function topicLinks(int $book, int $chapter, int $from, int $to): array
    {
        $st = $this->run('SELECT d.id, d.source, d.headword, d.norm, t.verse_start, t.verse_end FROM topic_refs t '
            . 'JOIN dictionary d ON d.id = t.dictionary_id WHERE t.book = ? AND t.chapter = ? '
            . 'AND (t.verse_start IS NULL OR (t.verse_start <= ? AND coalesce(t.verse_end, t.verse_start) >= ?)) '
            . 'ORDER BY t.dictionary_id LIMIT ?', [$book, $chapter, $to, $from, self::TOPIC_LINKS_MAX]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $source, $head, $norm, $vs, $ve]) {
            $out[] = ['id' => (int) $id, 'source' => (string) $source, 'headword' => (string) $head, 'norm' => (string) $norm,
                      'verse_start' => $vs === null ? null : (int) $vs, 'verse_end' => $ve === null ? null : (int) $ve];
        }
        return $out;
    }

    /**
     * Number of verse links of each topic (a small topic is a specific one).
     * @param list<int> $dictionaryIds  @return array<int,int> by dictionary id
     */
    public function topicSizes(array $dictionaryIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($dictionaryIds)), self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT dictionary_id, count(*) FROM topic_refs WHERE dictionary_id IN (' . self::marks($chunk)
                . ') GROUP BY dictionary_id', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $n]) {
                $out[(int) $id] = (int) $n;
            }
        }
        return $out;
    }

    /**
     * Dictionary entries (without their text) whose norm is one of $norms: in the order of $order
     * (source codes; others last), then by id, at most $limit (DICT_EXACT_MAX by default, so a
     * headword with thousands of entries costs no more than one with a few hundred).
     * @param list<string> $norms  @param list<string>|null $sources only these sources (null = all)
     * @param list<string> $order sources in display order
     * @return list<array{id:int, source:string, work_id:?int, headword:string, norm:string, page:?string}>
     */
    public function dictionaryExact(array $norms, ?array $sources, array $order = [], int $limit = self::DICT_EXACT_MAX): array
    {
        if ($norms === [] || $sources === [] || $limit <= 0) {
            return [];
        }
        $rank = '';
        if ($order !== []) {
            $rank = 'CASE source';
            foreach (array_keys($order) as $i) {
                $rank .= " WHEN ? THEN $i";
            }
            $rank .= ' ELSE ' . count($order) . ' END, ';
        }
        [$cond, $params] = self::sourceCondition($sources);
        $st = $this->run('SELECT id, source, work_id, headword, norm, page FROM dictionary WHERE norm IN ('
            . self::marks($norms) . ")$cond ORDER BY {$rank}id LIMIT ?",
            array_merge(array_values($norms), $params, array_values($order), [min($limit, self::DICT_EXACT_MAX)]));
        return array_map([self::class, 'dictionaryRow'], $st->fetchAll());
    }

    /**
     * Dictionary entries (without their text) whose norm begins with $prefix and is longer: in the
     * order of $order (source codes), then shortest first, at most $limit. The prefix is read as a
     * range of the dictionary_norm index and checked again with LIKE (%, _ and \ escaped).
     * @param list<string> $order sources in display order  @param list<string>|null $sources only these
     * @return list<array> rows as dictionaryExact()
     */
    public function dictionaryPrefix(string $prefix, array $order, ?array $sources, int $limit): array
    {
        if ($prefix === '' || $sources === [] || $limit <= 0) {
            return [];
        }
        $rank = 'CASE source';
        foreach (array_keys($order) as $i) {
            $rank .= " WHEN ? THEN $i";
        }
        $rank .= ' ELSE ' . count($order) . ' END';
        [$cond, $params] = self::sourceCondition($sources);
        $st = $this->run('SELECT id, source, work_id, headword, norm, page FROM dictionary '
            . "WHERE norm > ? AND norm < ? AND norm LIKE ? ESCAPE '\\'$cond "
            . "ORDER BY $rank, length(norm), norm, id LIMIT ?",
            array_merge([$prefix, $prefix . "\u{10FFFF}", self::likePrefix($prefix)], $params, array_values($order),
                [$limit]));
        return array_map([self::class, 'dictionaryRow'], $st->fetchAll());
    }

    /**
     * Texts of dictionary entries (zlib-compressed like passage text).
     * @param list<int> $ids  @return array<int,string> by id
     */
    public function dictionaryTexts(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::IN_CHUNK) as $chunk) {
            $st = $this->run('SELECT id, text FROM dictionary WHERE id IN (' . self::marks($chunk) . ')', $chunk);
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$id, $blob]) {
                $out[(int) $id] = self::inflate($blob, self::MAX_DICT_BYTES);
            }
        }
        return $out;
    }

    /** A LIKE pattern for strings that begin with $prefix: %, _ and \ escaped (use with ESCAPE '\'). */
    public static function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
    }

    // -------------------------------------------------------------------------------- helpers

    /** @param list<string>|null $sources  @return array{0:string,1:list<string>} */
    private static function sourceCondition(?array $sources): array
    {
        return $sources === null ? ['', []] : [' AND source IN (' . self::marks($sources) . ')', array_values($sources)];
    }

    private static function dictionaryRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'source' => (string) $r['source'],
                'work_id' => $r['work_id'] === null ? null : (int) $r['work_id'], 'headword' => (string) $r['headword'],
                'norm' => (string) $r['norm'],
                'page' => $r['page'] === null || $r['page'] === '' ? null : (string) $r['page']];
    }

    /**
     * SQL condition limiting an id column (passage ids) to some works, and its parameters: the id
     * range of the works, plus an IN or NOT IN list (whichever is shorter) when the range also
     * holds other works. An empty list matches nothing.
     * @param list<int>|null $workIds
     * @return array{0:string,1:list<int>}
     */
    private function workCondition(string $column, ?array $workIds): array
    {
        if ($workIds === null) {
            return ['', []];
        }
        if ($workIds === []) {
            return [' AND 0', []];
        }
        $min = min($workIds);
        $max = max($workIds);
        $sql = " AND $column BETWEEN ? AND ?";
        $params = [$min * self::SEQ_MULT, ($max + 1) * self::SEQ_MULT - 1];
        if ($min === $max) {
            return [$sql, $params];
        }
        $allowed = array_flip($workIds);
        $excluded = [];
        foreach (array_keys($this->allWorks()) as $id) {
            if ($id > $min && $id < $max && !isset($allowed[$id])) {
                $excluded[] = $id;
            }
        }
        if ($excluded === []) {
            return [$sql, $params];
        }
        $byWork = "($column / " . self::SEQ_MULT . ')';
        if (count($excluded) < count($workIds)) {
            return [$sql . " AND $byWork NOT IN (" . self::marks($excluded) . ')', array_merge($params, $excluded)];
        }
        $ids = array_values(array_unique($workIds));
        return [$sql . " AND $byWork IN (" . self::marks($ids) . ')', array_merge($params, $ids)];
    }

    /** Prepares and runs a statement, binding integers as integers. */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $i = 0;
        foreach ($params as $value) {
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $st->bindValue(++$i, $value, $type);
        }
        $st->execute();
        return $st;
    }

    /**
     * The work row with 'author_note': who the author was ("1798–1870, American Presbyterian minister"), or '';
     * and 'church': for a church record, its denomination, tradition, doc_kind, status and body (Churches), or null.
     */
    private function noted(array $w): array
    {
        $note = $this->authorNotes()[$w['author']] ?? '';
        $w['author_note'] = $note !== '' && self::noteFits($note, $w['year']) ? $note : '';
        $church = $this->churchRecords()[$w['id']] ?? null;
        if ($church !== null) {
            $church['body'] = $this->churchBodies()[$this->churchBodyOfTag()[$church['denomination']] ?? ''] ?? null;
        }
        $w['church'] = $church;
        return $w;
    }

    // ------------------------------------------------------------------------- church records

    /** True if the build has the church tables (builds before them do not). */
    public function hasChurchRecords(): bool
    {
        return $this->churchRecords() !== [] || $this->hasTables('church_records', 'church_bodies', 'church_names');
    }

    /** @return array<int,array{denomination:string, tradition:string, doc_kind:string, status:string}> by work id */
    public function churchRecords(): array
    {
        if ($this->churchRecords === null) {
            $this->churchRecords = [];
            try {
                if ($this->hasTables('church_records')) {
                    foreach ($this->run('SELECT work_id, denomination, tradition, doc_kind, status FROM church_records')
                                 ->fetchAll() as $r) {
                        $this->churchRecords[(int) $r['work_id']] = [
                            'denomination' => (string) $r['denomination'], 'tradition' => (string) $r['tradition'],
                            'doc_kind' => (string) $r['doc_kind'],
                            'status' => in_array($r['status'], ['current', 'record'], true) ? $r['status'] : 'historical'];
                    }
                }
            } catch (\PDOException $e) {
                $this->churchRecords = [];   // unreadable table: no labels, and the filters say they are unavailable
            }
        }
        return $this->churchRecords;
    }

    /**
     * Church bodies whose records the library holds, by name, with the tag values of their records and their
     * other names. A tag value no body lists becomes a body of its own (named after it), so every record has one.
     * @return array<string,array{name:string, tradition:string, founded:?int, ended:?int, tags:list<string>,
     *                             aliases:list<string>, verified:bool}>
     */
    public function churchBodies(): array
    {
        if ($this->churchBodies === null) {
            $this->churchBodies = [];
            try {
                if ($this->hasTables('church_bodies')) {
                    foreach ($this->run('SELECT name, tradition, founded, ended, tags, aliases, verified FROM church_bodies '
                                 . 'ORDER BY name')->fetchAll() as $b) {
                        $name = (string) $b['name'];
                        $this->churchBodies[$name] = ['name' => $name, 'tradition' => (string) $b['tradition'],
                            'founded' => $b['founded'] === null ? null : (int) $b['founded'],
                            'ended' => $b['ended'] === null ? null : (int) $b['ended'],
                            'tags' => self::stringList($b['tags']), 'aliases' => self::stringList($b['aliases']),
                            'verified' => (int) $b['verified'] === 1];
                    }
                }
            } catch (\PDOException $e) {
                $this->churchBodies = [];
            }
            $listed = [];
            foreach ($this->churchBodies as $name => $b) {
                foreach ($b['tags'] as $t) {
                    $listed[$t] = true;
                }
            }
            foreach ($this->churchRecords() as $r) {
                $t = $r['denomination'];
                if (!isset($listed[$t]) && !isset($this->churchBodies[$t])) {
                    $this->churchBodies[$t] = ['name' => $t, 'tradition' => $r['tradition'], 'founded' => null,
                        'ended' => null, 'tags' => [$t], 'aliases' => [], 'verified' => false];
                }
            }
        }
        return $this->churchBodies;
    }

    /** @return array<string,string> tag value (a record's denomination) => body name */
    public function churchBodyOfTag(): array
    {
        if ($this->churchBodyOfTag === null) {
            $this->churchBodyOfTag = [];
            foreach ($this->churchBodies() as $name => $b) {
                foreach ($b['tags'] as $t) {
                    $this->churchBodyOfTag[$t] ??= $name;
                }
            }
        }
        return $this->churchBodyOfTag;
    }

    /**
     * Present-day bodies and other common names, with the bodies they descend from.
     * @return array<string,array{name:string, aliases:list<string>, tradition:string,
     *                             ancestors:list<array{0:string,1:?int,2:?int}>, verified:bool}>
     */
    public function churchNames(): array
    {
        if ($this->churchNames === null) {
            $this->churchNames = [];
            try {
                if ($this->hasTables('church_names')) {
                    foreach ($this->run('SELECT name, aliases, tradition, ancestors, verified FROM church_names ORDER BY name')
                                 ->fetchAll() as $c) {
                        $ancestors = [];
                        $raw = json_decode((string) $c['ancestors'], true, 4);
                        foreach (is_array($raw) ? $raw : [] as $a) {
                            if (is_array($a) && count($a) === 3 && is_string($a[0])
                                && ($a[1] === null || is_int($a[1])) && ($a[2] === null || is_int($a[2]))) {
                                $ancestors[] = [$a[0], $a[1], $a[2]];
                            }
                        }
                        $this->churchNames[(string) $c['name']] = ['name' => (string) $c['name'],
                            'aliases' => self::stringList($c['aliases']), 'tradition' => (string) $c['tradition'],
                            'ancestors' => $ancestors, 'verified' => (int) $c['verified'] === 1];
                    }
                }
            } catch (\PDOException $e) {
                $this->churchNames = [];
            }
        }
        return $this->churchNames;
    }

    /** @return list<string> the strings of a JSON list ([] if it is not one) */
    private static function stringList(mixed $json): array
    {
        $v = json_decode((string) $json, true, 3);
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /**
     * False when a work cannot be by the author the note describes: the catalogue sometimes files two
     * people under one name, so a work printed before the noted author was ten years old gets no note.
     * Notes without a plain birth year ("d. 1617", "fl. 1650s", "c. 428–348 BC") always fit.
     */
    public static function noteFits(string $note, ?int $workYear): bool
    {
        if ($workYear === null || str_contains($note, 'BC')
            || preg_match('/^(?:c\. )?(\d{3,4})\s*–/u', $note, $m) !== 1) {
            return true;
        }
        return $workYear >= (int) $m[1] + 10;
    }

    /**
     * Author name => dates and a short description, from the build's authors table (indexer: the books
     * repository's authors.csv). Builds made before that table existed have none: every note is ''.
     * @return array<string,string>
     */
    private function authorNotes(): array
    {
        if ($this->authorNotes === null) {
            $this->authorNotes = [];
            try {
                if ($this->hasTables('authors')) {
                    foreach ($this->run('SELECT name, dates, note FROM authors')->fetchAll() as $a) {
                        $text = implode(', ', array_filter([trim((string) $a['dates']), trim((string) $a['note'])], 'strlen'));
                        if ($text !== '') {
                            $this->authorNotes[(string) $a['name']] = $text;
                        }
                    }
                }
            } catch (\PDOException $e) {
                $this->authorNotes = [];   // unreadable table: results simply carry no author notes
            }
        }
        return $this->authorNotes;
    }

    /**
     * Author name => the other names the same writer is filed under in the works table: names with the same
     * surname (last word) and the same dates in the authors table, e.g. "John Calvin" => ["Jean Calvin"] (the
     * EEBO translations of the 1500s keep "Jean"), "Ulrich Zwingli" => ["Huldreich Zwingli"]. Names without
     * dates are never joined. Builds without the authors table have none.
     * @return array<string, list<string>>
     */
    public function sameWriter(): array
    {
        if ($this->sameWriter === null) {
            $this->sameWriter = [];
            try {
                if ($this->hasTables('authors')) {
                    $this->sameWriter = self::sameWriterGroups($this->run('SELECT name, dates FROM authors')->fetchAll());
                }
            } catch (\PDOException $e) {
                $this->sameWriter = [];
            }
        }
        return $this->sameWriter;
    }

    /**
     * Author name => [first year of writing, year of death] from the authors table's dates: "1616–1683" ->
     * [1636, 1683] (20 years after birth), "c. 347–407" -> [367, 407], "fl. 366–384" -> [366, 384]. Dates before
     * Christ, single years and empty dates are left out. Used by year filters (Filters::resolve).
     * @return array<string, array{0: int, 1: int}>
     */
    public function authorLives(): array
    {
        if ($this->authorLives === null) {
            $this->authorLives = [];
            try {
                if ($this->hasTables('authors')) {
                    foreach ($this->run('SELECT name, dates FROM authors')->fetchAll() as $a) {
                        $life = self::lifeOf((string) $a['dates']);
                        if ($life !== null) {
                            $this->authorLives[(string) $a['name']] = $life;
                        }
                    }
                }
            } catch (\PDOException $e) {
                $this->authorLives = [];
            }
        }
        return $this->authorLives;
    }

    /**
     * Author name => the tradition and note of the authors table (the books repository's authors.csv), for the
     * tradition filter (Churches::writerTraditions). Builds without the table have none.
     * @return array<string, array{tradition: string, note: string}>
     */
    public function authorTraditions(): array
    {
        if ($this->authorTraditions === null) {
            $this->authorTraditions = [];
            try {
                if ($this->hasTables('authors')) {
                    foreach ($this->run('SELECT name, tradition, note FROM authors')->fetchAll() as $a) {
                        $this->authorTraditions[(string) $a['name']] = ['tradition' => trim((string) $a['tradition']),
                            'note' => (string) $a['note']];
                    }
                }
            } catch (\PDOException $e) {
                $this->authorTraditions = [];
            }
        }
        return $this->authorTraditions;
    }

    /** @return array{0: int, 1: int}|null see authorLives */
    public static function lifeOf(string $dates): ?array
    {
        if (preg_match('/^(fl\. )?(?:c\. )?(\d{2,4})–(?:c\. )?(\d{2,4})$/u', trim($dates), $m) !== 1) {
            return null;
        }
        [$from, $to] = [(int) $m[2], (int) $m[3]];
        if ($to < $from || $to - $from > 110) {
            return null;
        }
        return [$m[1] !== '' ? $from : min($from + 20, $to), $to];
    }

    /**
     * Store::sameWriter from authors rows (name, dates).
     * @param list<array{name: string, dates: string}> $rows  @return array<string, list<string>>
     */
    public static function sameWriterGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $a) {
            $name = (string) $a['name'];
            $dates = trim((string) $a['dates']);
            $words = explode(' ', Text::looseKey($name));
            $last = (string) end($words);
            if ($last === '' || preg_match('/\d{3}/', $dates) !== 1) {
                continue;
            }
            $groups[$last . '|' . $dates][] = $name;
        }
        $out = [];
        foreach ($groups as $names) {
            $names = array_values(array_unique($names));
            if (count($names) > 1 && count($names) <= 4) {
                foreach ($names as $n) {
                    $out[$n] = array_values(array_diff($names, [$n]));
                }
            }
        }
        return $out;
    }

    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private static function workRow(array $w): array
    {
        foreach (['id', 'n_passages', 'ocr'] as $k) {
            $w[$k] = (int) $w[$k];
        }
        $w['year'] = $w['year'] === null ? null : (int) $w['year'];
        foreach (['key', 'author', 'author_slug', 'title', 'full_title', 'type', 'collection', 'license',
                  'source_url', 'tcp_id', 'series'] as $k) {
            $w[$k] = (string) ($w[$k] ?? '');
        }
        return $w;
    }

    private static function passageRow(array $p): array
    {
        foreach (['id', 'work_id', 'seq'] as $k) {
            $p[$k] = (int) $p[$k];
        }
        $p['part'] = $p['part'] === null ? null : (int) $p['part'];
        $p['vec_pos'] = $p['vec_pos'] === null ? null : (int) $p['vec_pos'];
        $p['section'] = (string) ($p['section'] ?? '');
        $p['page_start'] = $p['page_start'] === null ? null : (string) $p['page_start'];
        $p['page_end'] = $p['page_end'] === null ? null : (string) $p['page_end'];
        $p['text'] = self::inflate($p['text'], self::MAX_TEXT_BYTES);
        $notes = [];
        if ($p['notes'] !== null) {
            $json = self::inflate($p['notes'], self::MAX_NOTES_BYTES);
            $list = $json !== '' ? json_decode($json, true, 4) : null;
            if (is_array($list)) {
                $notes = array_values(array_filter($list, 'is_string'));
            }
        }
        $p['notes'] = $notes;
        return $p;
    }

    /** A zlib BLOB decompressed, or '' if it is not one or would be larger than $max bytes. */
    public static function inflate(mixed $blob, int $max): string
    {
        $text = @gzuncompress((string) $blob, $max);   // zlib may overshoot $max by a buffer
        return is_string($text) && strlen($text) <= $max ? $text : '';
    }

    /** @return resource */
    private function openBinary(string $name)
    {
        $fh = @fopen($this->dir . '/' . $name, 'rb');
        if ($fh === false) {
            throw new StoreException("$name could not be opened");
        }
        stream_set_read_buffer($fh, 0);   // large reads go straight to the file
        return $fh;
    }

    /** @param resource $fh */
    private static function readAt($fh, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (fseek($fh, $offset) !== 0) {
            throw new StoreException('seek past the end of a vector file');
        }
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = fread($fh, $length - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new StoreException('a vector file is shorter than the index says');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
