#!/usr/bin/env python3
"""Check death years of the modern writers list against Wikidata (one SPARQL query).

    python3 -I tools/modern/wikidata.py CANDIDATES.tsv > OUT.tsv

CANDIDATES.tsv: name, died (year or -), aliases (;), surname alone. Each name (and alias) is looked up as an
English label or alias of a human; of the people found, the one with the most Wikipedia articles is taken. A
death year that differs from the list's, or a person who doesn't look like the writer meant, is checked by hand
before the list is changed.
"""
import json, sys, urllib.parse, urllib.request

rows = [l.rstrip('\n').split('\t') for l in open(sys.argv[1], encoding='utf-8') if l.strip()]
labels = {}
for r in rows:
    for n in [r[0]] + [a for a in (r[2].split(';') if len(r) > 2 else []) if a]:
        labels.setdefault(n, r[0])
values = ' '.join(json.dumps(n) + '@en' for n in labels)
q = f'''SELECT ?label ?item ?desc ?born ?died ?links WHERE {{
  VALUES ?label {{ {values} }}
  {{ ?item rdfs:label ?label }} UNION {{ ?item skos:altLabel ?label }}
  ?item wdt:P31 wd:Q5 ; wikibase:sitelinks ?links .
  OPTIONAL {{ ?item wdt:P569 ?b . BIND(YEAR(?b) AS ?born) }}
  OPTIONAL {{ ?item wdt:P570 ?d . BIND(YEAR(?d) AS ?died) }}
  OPTIONAL {{ ?item schema:description ?desc FILTER(LANG(?desc) = "en") }}
}}'''
req = urllib.request.Request('https://query.wikidata.org/sparql',
                             data=urllib.parse.urlencode({'query': q, 'format': 'json'}).encode(),
                             headers={'User-Agent': 'col-modern-writers/1.0 (list check)',
                                      'Content-Type': 'application/x-www-form-urlencoded'})
res = json.load(urllib.request.urlopen(req, timeout=120))['results']['bindings']
best = {}
for b in res:
    name = labels[b['label']['value']]
    v = {k: b[k]['value'] for k in b}
    links = int(v['links'])
    cur = best.get(name)
    if cur is None or links > int(cur['links']) or (v['item'] == cur['item'] and 'died' in v and 'died' not in cur):
        if cur is not None and v['item'] == cur['item'] and 'died' in cur and 'died' in v:
            v['died'] = str(min(int(cur['died']), int(v['died']))) + ('/' + str(max(int(cur['died']), int(v['died'])))
                                                                        if cur['died'] != v['died'] else '')
        best[name] = v
for r in rows:
    v = best.get(r[0])
    if v is None:
        print(f'{r[0]}\t{r[1]}\tNONE')
        continue
    wd = v.get('died', '-')
    print(f"{r[0]}\t{r[1]}\t{wd}\t{'' if wd == r[1] else 'DIFF'}\t{v['item'].rsplit('/', 1)[1]}\tb.{v.get('born', '?')}"
          f"\t{v['links']} links\t{v.get('desc', '')}")
