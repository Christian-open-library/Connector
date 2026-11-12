# Christian Open Library connector

The AI connector for the [Christian Open Library](https://github.com/Christian-open-library): about 4,800 public-domain works, mostly Christian (the Church Fathers, the Reformers, the Puritans, Spurgeon, Edwards, creeds and confessions, the historic records of more than 80 denominations, verse-by-verse commentaries and Greek and Hebrew study helps, sermons, the Princeton Review, poetry and fiction, and classic philosophy), Bible translations and classic Bible dictionaries, searchable from AI apps such as Claude, ChatGPT and Gemini.

It's a [Model Context Protocol](https://modelcontextprotocol.io) server. Your AI app asks it questions, and it answers with passages quoted word for word from the library's copies of the books, with the author and title, and the year and page where the source gives them, so you can check them.

**Use it:** add `https://mcp.christianopenlibrary.org/mcp` as a connector in your AI app and sign in with your email or GitHub. It's free. Step-by-step instructions are on the [library's front page](https://github.com/Christian-open-library).

This repository is the connector's complete source, published so anyone can see exactly what it does with their questions.

## What it can do

Six read-only tools:

| Tool | What it does |
|---|---|
| `search_library` | Searches by meaning and by keyword together, then reranks the results. There is also an exact-phrase mode for a remembered quotation. Old spellings are matched automatically ("sinne" finds "sin"). |
| `read_passage` | Reads a passage by its reference, or a work by printed page. |
| `find_works` | Lists works by title, author, collection and years. |
| `read_scripture` | Reads Bible passages in seven public-domain translations (Berean Standard Bible by default). |
| `find_scripture_citations` | Finds which historic writers cited a verse, and where. |
| `define_term` | Looks up words in older Bible and theological dictionaries. |
| `check_quote` | Checks a quotation: did this author really write these words? It answers found word for word (with book, year, page and the sentences around it), found but worded differently (the real wording beside the one given, differences marked), found but by someone else (the earliest author in the library who used the words), or not found (which does not prove the quotation is false). |

Each result comes with the text around it, so the AI can quote an author's own position rather than an objection he goes on to answer. If a quotation appears word for word in the library, it ranks first.

Each search result is marked as an exact, close, partial or loose match; "close" means on the subject, not proof of the source, so for who said something the AI is told to use the quote checker. A result that credits its words to another writer ("as Augustine tells us") says so. When the library has nothing close to what was asked, or no such work, the result says NOT FOUND or NO CLOSE MATCH and tells the AI not to quote from memory. Passages from scanned books are marked as uncorrected OCR.

## How it works

- **`indexer/`** (Python) builds the search index from the books repository:
  - an SQLite FTS5 database for keyword search;
  - [Voyage AI](https://www.voyageai.com) embeddings (books indexed with voyage-4-lite, 512 dimensions, int8; searches embedded with voyage-4-large, which shares its embedding space) with an IVF index for meaning search;
  - a Scripture citation index and the dictionaries.

  It runs nightly and embeds only passages that are new. Before a build goes live, a scanner refuses text that looks aimed at an AI.
- **`plugin/col-library-connector/src/`** (PHP 8.1 or later, no dependencies) is the MCP server: Streamable HTTP, protocol versions 2024-11-05 through 2026-07-28, search, reranking (Voyage rerank-3) and formatting. The same code can also run as a WordPress plugin.
- **`server/www/index.php`** is the front controller for a plain PHP-FPM host. It handles the MCP endpoint, the OAuth discovery documents and the public cost card at `/support.svg`.
- **`deploy/`** holds the scripts that push builds and releases to the server over SSH, to an account that can only upload and run a few deploy commands. **`deploy/office/connector/`** is the server's configuration: nginx, PHP-FPM in a locked-down systemd sandbox, an outbound proxy that allows only the two services the connector needs, and the Cloudflare Tunnel.

## Sign-in, privacy and limits

- **Sign-in:** the connector is an OAuth 2.1 resource server. [WorkOS AuthKit](https://workos.com) handles sign-in (a one-time code by email, or GitHub). The connector checks each token's signature and claims itself.
- **No user data:** the connector keeps no copy of conversations or documents. Sign-in accounts are held by WorkOS, not by the connector.
- **Logs:** they record the method, status and timing, with a pseudonymous id per person (part of a hash). Search text isn't logged unless debugging is turned on, and keys are always removed.
- **Your questions:** to search by meaning, the search words are sent to Voyage AI. The library's Voyage account is opted out of data use, so Voyage deletes them after processing. [What happens to your question](https://github.com/Christian-open-library/.github/blob/main/PRIVACY.md) lists everyone who sees it.
- **Limits:** each person has a daily limit, and a daily cap keeps search spending in check. You can see what the connector costs to run each month on the [front page](https://github.com/Christian-open-library).

## Security

See [SECURITY.md](SECURITY.md) for how to report a problem, and [docs/security-review.md](docs/security-review.md) for the latest review.

## How well does search work?

A 75-question test checks whether the right book comes back. Today about 87% of questions find it in the top 8 results. The method, results by kind of question, and the limits of the test are in [docs/evaluation.md](docs/evaluation.md).

## Running the tests

- PHP: run `tools/setup.sh` once (it downloads Node and php-wasm into `tools/`), then `tools/php tests/php/run.php`.
- Python: create a virtual environment with numpy, PyYAML and requests, then run `python -m unittest discover -s indexer/tests -t .`.

## License

MIT (see [LICENSE](LICENSE)). The books themselves are public domain or Creative Commons and live in a separate repository.

The library is curated by [Matt Miller](https://github.com/mattm4444). If it's useful to you, you can [support his work on it](https://github.com/sponsors/mattm4444).
