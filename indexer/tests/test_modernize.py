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


if __name__ == '__main__':
    unittest.main()
