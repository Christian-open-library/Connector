# Security review, November 2026

*Reviewed 10 November 2026, code version `dbf6057`. An earlier review of the first version ran on 8–9 November.*

## Summary

**No way was found for someone on the internet to:**
- get past sign-in;
- read anything private;
- change the library;
- run code on the server.

The connector only reads public-domain books. It stores no accounts, documents or conversations.

**The review raised 14 leads. Independent checking kept 13 as weaknesses and rejected one.** Most were about availability: things that could slow the service down or stall the nightly book updates. The rest were safety nets that weren't working as intended.

| Status | Count |
|---|---|
| Fixed and live | 11 |
| Partly fixed, with the remaining risk accepted | 2 |
| Rejected on checking (tightened anyway) | 1 |

None of the 13 was proven by running it, because the review only read the code. They were fixed as if they were real.

## What was reviewed

- **Sign-in:**
  - the email and GitHub sign-in through WorkOS, and how the connector checks each token;
  - personal-link keys and the owner key.
- **Public pages:** the cost card and the sign-in discovery documents.
- **Limits:** per-person limits, shared limits, and the cap on search spending.
- **The MCP protocol layer**, and every tool.
- **What reaches your AI app:** the text the connector returns, including book text and anything you typed.
- **The server:**
  - the sandbox the connector runs in;
  - its outbound proxy;
  - the Cloudflare Tunnel;
  - the restricted account that receives updates.
- **The nightly build** that turns the books repository into the search index, which treats book text as untrusted.

## How it was reviewed

The review was carried out by Claude, Anthropic's AI, following a structured security-audit method for AI reviewers:

- **Mapping:** four agents mapped the code: the product, who can do what, every place outside input comes in, and how it's deployed.
- **Hunting:** six reviewers each took specific boundaries and looked for ways across them.
- **A coverage check** looked for anything the reviewers had missed.
- **Independent checks:** each lead went to a separate reviewer whose job was to disprove it.
- **What was checked:** reviewers also re-checked the leads left open by the first review, against the current code.

Nothing was tested against the live service. The review read the code and configuration. A few points depend on server settings the code can't show; the owner checks those.

## What was found, and what was done

| Issue | Who could do it | Effect | Status |
|---|---|---|---|
| Deploys to the new server had stopped running the check that refuses book text aimed at an AI. | Someone whose text gets merged into the books repository | Planted instructions could reach AI apps without the check | **Fixed** |
| Text you typed (a search, a word to define, a page number) came back as-is, so it could show up as a live link or image in an AI app. | Instructions planted in a conversation | An AI app that displays tool results could load an outside image | **Fixed** |
| Book text could still hold "reference-style" links. | Someone whose text gets merged | A disguised link in a result (needs a click) | **Fixed** |
| Sign-in accepted a token that named no audience. | Someone holding another app's token from the same sign-in service | Use the connector as that person (read-only) | **Fixed.** Tokens must now name this connector. |
| While the sign-in service's key list was unreachable, fake tokens could make every request wait on it. | Anyone | Slow or stalled service during an outage there | **Fixed:** retries are limited to once a minute. |
| Anyone could fill the month's request log, after which errors stopped being recorded. | Anyone | Lost records, not lost service | **Fixed:** errors are always recorded. |
| A blocked GitHub user could lift the block by renaming their account. | A blocked user | Their access returns | **Fixed:** blocks use GitHub's permanent account number. |
| One malformed line in a book could stall the nightly build for hours. | Someone whose text gets merged | New books stop going live | **Fixed** |
| A footnote cited many times was copied without limit. | Someone whose text gets merged | The build could run out of memory | **Fixed:** capped per book. |
| The nightly build trusted whatever branch the books folder was on. | Someone with a pull request, if the folder was left on its branch | Unreviewed text goes live | **Fixed:** builds only from clean, reviewed `main`. |
| The owner key on the new server was the same one the retired first server used. | Anyone who had seen the old server's settings | Owner access, which uses up shared limits | **Fixed:** a new key is installed and the old one no longer works. |
| The account that receives updates could have written outside its upload folder, and could change its own restrictions. | Only someone who already holds the deploy key | An unrestricted login on the server | **Rejected:** the only holder of that key already has full access to the server, so nothing is gained. Tightened anyway: uploads can't follow links, and the account's key and command are locked by the system. |
| Search phrases made of many common words cost a lot to run. | A signed-in user | Slower searches for others | **Partly fixed:** a phrase may hold at most 16 different common words and 24 in all. Per-person limits apply. The cost of the worst phrase still allowed hasn't been measured on the full library. |
| Sign-up is open, so many free accounts could use up the shared daily limits. | Someone making many accounts | Smart search pauses for the day (keyword search keeps working), or calls are refused until the next day | **Partly addressed:** the shared daily call limit was raised tenfold. Free accounts together can use at most 80% of the shared limits. Accounts can be blocked. Search spending stays capped. |

The two partly addressed items are inherent to a free, open service. Spending can't run away, because the daily cap stops the paid search service. The worst case is a degraded day.

## Not yet reviewed

The coverage check named seven areas this review didn't reach. They're listed here so nobody reads silence as a pass. Two were dealt with directly afterwards, as noted:

- **Member sync:** the hourly job that syncs the member and block lists makes one request per account, so many sign-ups could slow it. *Since fixed:* the job now stops after 10 minutes and never overlaps itself, and a run that is cut off leaves the server's list as it was.
- **Stored book text at serve time:** the cost of preparing very unusual stored text for display. One case found here is already fixed.
- **Re-running the server installer** as root over folders the deploy account owns.
- **The installer bundle:** three small files in it come from the owner's other server rather than this repository.
- **Withdrawn dictionaries:** whether entries from a withdrawn dictionary leave the index.
- **Small builds:** how dictionary sources are chosen when a build has far fewer books than usual.
- **Outside inputs to the build:** the Python packages it uses, and the dictionary and Bible source files that don't come through the books repository. *Since done:* the package versions are now recorded in `requirements.txt`, and an audit on 10 November 2026 found no known vulnerabilities in them. The source files are still unreviewed.

## The earlier review

The first review (8–9 November) covered the first version, which ran on a WordPress site.
- **Fixed since then:** 17 of its leads were re-checked against the current code and found fixed. Examples are limits on oversized inputs and books, safer handling of book files and their metadata, and markup removed from book text before it reaches AI apps.
- **No longer apply:** the leads tied to WordPress, because the connector no longer runs there.

## Limits of this review

- It's a review of the source and configuration, not a penetration test, and nothing was run against the live service.
- Some protections are settings outside the code, and the owner checks those. They include Cloudflare's settings, the sign-in service's settings and the server's installed configuration.
- No review finds everything. If you find something, please tell us privately; see [SECURITY.md](../SECURITY.md).
