import unittest

from indexer.modernize import DATA, Modernizer, load_lexicon, pre_markdown


@unittest.skipUnless((DATA / 'lexicon.tsv').exists(), 'build data/lexicon.tsv first')
class Modernize(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.m = Modernizer(load_lexicon())

    def test_known_pairs(self):
        pairs = {'iesvs': 'jesus', 'sinne': 'sin', 'sinnes': 'sins', 'witnesse': 'witness', 'eternall': 'eternal',
                 'publicke': 'public', 'beleeue': 'believe', 'haue': 'have', 'vnto': 'unto', 'euery': 'every',
                 'reioyce': 'rejoice', 'mercie': 'mercy', 'onely': 'only', 'iustice': 'justice', 'iust': 'just',
                 'souldier': 'soldier', 'cōmon': 'common', 'honour': 'honor', 'hath': 'has', 'loveth': 'loves',
                 'vs': 'us', 'dyed': 'died', 'againe': 'again', 'yeeld': 'yield'}
        for old, new in pairs.items():
            self.assertEqual(self.m.standard(old), new, old)

    def test_modern_words_untouched(self):
        for w in ['then', 'than', 'inn', 'cane', 'mane', 'hope', 'vain', 'joy', 'theme', 'borne', 'sound', 'an',
                  'fall', 'whether', 'four', 'hour', 'devour', 'deus', 'gratia', 'fide', 'spake']:
            self.assertEqual(self.m.standard(w), w, w)

    def test_text_keeps_case_and_punctuation(self):
        out = self.m.modernize_text(pre_markdown('Y*e* Lord hath made it ſelfe knowne; ſaith IESVS.'))
        self.assertEqual(out, 'The Lord has made itself known; says JESUS.')


    def test_a_words_form_does_not_depend_on_what_was_looked_up_before(self):
        # 'deel' -> 'del' -> 'deal': once 'del' was cached, 'deel' used to stop at 'del' (builds not reproducible)
        lex = load_lexicon()
        warmed = Modernizer(lex, external=(self.m.vep, self.m.ma, self.m.macron, self.m.latin))
        words = ['deel', 'iesvs', 'beleeue', 'sinnes', 'haue', 'loveth']
        warmed.standard('del')                       # the middle of the chain looked up first
        for w in reversed(words):
            self.assertEqual(Modernizer(lex, external=(self.m.vep, self.m.ma, self.m.macron, self.m.latin)).standard(w),
                             warmed.standard(w), w)


    def test_ties_do_not_depend_on_the_hash_seed(self):
        import os, subprocess, sys
        code = ('from indexer.modernize import Modernizer, load_lexicon; m = Modernizer(load_lexicon()); '
                "print(' '.join(m.standard(w) for w in ['versie', 'terie', 'deel', 'iesvs']))")
        outs = {subprocess.run([sys.executable, '-c', code], capture_output=True, text=True, check=True,
                               env={**os.environ, 'PYTHONHASHSEED': str(seed)}).stdout for seed in range(1, 7)}
        self.assertEqual(len(outs), 1, outs)


if __name__ == '__main__':
    unittest.main()
