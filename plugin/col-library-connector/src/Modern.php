<?php
/**
 * Writers still in copyright: the commonly quoted Christian writers who died in 1956 or later, or are living
 * (ModernList, from tools/modern/writers.tsv). Their books can't be in the library, so a quotation credited to
 * one of them that the library doesn't hold is answered with that, not with a bare "not found".
 *
 * A name given is matched to the list, ignoring case, accents, punctuation and titles ("Rev.", "Dr.", "Jr."):
 * the whole name or one of its other names ("C.S. Lewis", "CS Lewis", "Clive Staples Lewis"), the same with
 * middle initials left out ("John R. W. Stott"), or the surname alone where the list says it means that writer
 * ("Tozer"). Nothing else: a name not on the list is not guessed at.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Modern
{
    private const DROP = ['rev', 'reverend', 'dr', 'pastor', 'jr', 'sr', 'mr', 'mrs', 'bishop', 'archbishop'];

    /** @var array<string,int>|null name key => index in ModernList::WRITERS */
    private static ?array $index = null;

    /**
     * The writer still in copyright that a credited name means, or null.
     * @return array{name:string, died:?int}|null
     */
    public static function find(string $name): ?array
    {
        $index = self::index();
        [$whole, $short] = self::keys($name);
        foreach (array_filter([$whole, $short]) as $k) {
            if (isset($index[$k])) {
                $w = ModernList::WRITERS[$index[$k]];
                return ['name' => $w[0], 'died' => $w[1]];
            }
        }
        return null;
    }

    /**
     * The name's words to look for among the library's authors (Quote::byAuthor): its long words, with its
     * initials when it has any, and none of the looser tries of Quote::authorKeysFor ("Packer" is not a
     * misspelt "Parker", "A. W. Tozer" is not Henry Tozer). @return list<string>
     */
    public static function authorKeys(string $name): array
    {
        $all = Quote::authorWords($name);
        $initials = array_values(array_filter(explode(' ', Text::looseKey($name)),
            static fn (string $w): bool => strlen($w) === 1));
        return $initials !== [] && array_diff($all, $initials) === $all ? array_merge($initials, $all) : $all;
    }

    /**
     * The name as a key (initials run together: "c s lewis" is "cs lewis"), and the same without its initials
     * when two or more words are left ("john rw stott" is "john stott"), else null.
     * @return array{0:string, 1:?string}
     */
    private static function keys(string $name): array
    {
        $words = array_values(array_filter(explode(' ', Text::looseKey($name)),
            static fn (string $w): bool => $w !== '' && !in_array($w, self::DROP, true)));
        $tokens = [];
        $initial = [];
        foreach ($words as $w) {
            $n = count($tokens);
            if (strlen($w) === 1 && $n > 0 && $initial[$n - 1]) {
                $tokens[$n - 1] .= $w;
            } else {
                $tokens[] = $w;
                $initial[] = strlen($w) === 1;
            }
        }
        $rest = array_values(array_filter($tokens, static fn (string $t, int $i): bool => !$initial[$i]
            && !preg_match('/^(?:ii|iii|iv)$/', $t), ARRAY_FILTER_USE_BOTH));
        $whole = implode(' ', $tokens);
        $short = count($rest) >= 2 && count($rest) < count($tokens) ? implode(' ', $rest) : null;
        return [$whole, $short];
    }

    /** @return array<string,int> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $index = [];
        foreach (ModernList::WRITERS as $i => $w) {
            foreach (array_merge([$w[0]], $w[2]) as $n) {
                [$whole] = self::keys($n);
                $index[$whole] ??= $i;
            }
            if ($w[3] !== '') {
                $index[self::keys($w[3])[0]] ??= $i;
            }
        }
        return self::$index = $index;
    }
}
