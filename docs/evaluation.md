# How well does search work?

*Measured on 11 November 2026 against the live library (4,573 works).*

## What is tested

A set of 75 questions (`tests/eval/questions.jsonl`), each with the book or books that should come back. The test asks the connector each question and checks whether an expected book is among the top results. It only checks that the right **book** appears, not that the right passage is on top, and not that an AI app then summarizes it well.

| Kind of question | How many | Example |
|---|---|---|
| Remembered quotation | 19 | "What is the chief end of man?" |
| Idea in your own words | 16 | a paraphrase of a passage |
| Doctrine | 13 | a theological question several authors answer |
| Old spelling | 8 | a phrase as it was printed in 1650 |
| Filtered (by author or work) | 13 | a question limited to one writer |
| Exact phrase | 6 | a half-remembered line, searched word for word |

## Results

The share of questions where an expected book is among the top 8 results (top 3 in brackets):

| Kind | Before the search-model change | After |
|---|---|---|
| **All 75** | **81%** (71%) | **87%** (80%) |
| Quotation | 95% | 100% |
| Idea in your own words | 56% | 75% |
| Doctrine | 77% | 69% |
| Old spelling | 88% | 100% |
| Filtered | 85% | 85% |
| Exact phrase | 100% | 100% |

The change was to understand each search with Voyage AI's larger model while keeping the books indexed as before. Eight questions got better, two got worse (both doctrine questions). The library also grew by about 130 books between the two runs, so one of the two losses may come from new, similar-looking books pushing an older one down.

## What it still misses

About one search in eight. The misses are mostly doctrine questions, where several authors say related things and the "right" book is a judgment call, and a few quotations from books that are not in the library.

## Limits of this test

- It is small: 75 questions. A gain of a few points could be partly luck.
- We wrote the questions ourselves, and we tuned the connector while looking at them. Treat the numbers as a regression check, not an independent benchmark.
- It tests finding the book, not the quality of an AI app's answer. Always check a quote against the page it cites.
- Voyage's rerank model, which orders the final results, is part of the system being measured.

## Run it yourself

```
python3 tests/eval/run_eval.py --url-file <file holding your connector address>
python3 tests/eval/run_eval.py --compare results/a.json results/b.json
```

If it misses a book you expected, tell us: each report becomes a new question in this set.
