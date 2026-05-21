# gist — FreeDict Vocabulary Resolution Service

A Symfony 8.1 app that imports [FreeDict](https://freedict.org) bilingual dictionaries (TEI P5 XML) into PostgreSQL and exposes word-level lookup and vocabulary classification as an API.

**Primary use case:** given foreign-language keywords (e.g. French museum genre terms), return English translations and a [`ContentType`](https://github.com/survos/data-contracts) classification slug (`photograph`, `drawing`, `painting`, etc.). Used by md/ssai/zm to classify records that arrive with non-English metadata.

> Single-word dictionary lookup is more accurate than LibreTranslate/Bing/DeepL for isolated vocabulary terms — FreeDict gives exact lexical translations with POS, not sentence-context guesses.

---

## Quick start

```bash
# 1. Install
composer install
php bin/console doctrine:schema:create

# 2. Load the FreeDict catalog (~305 language pairs)
php bin/console app:load

# 3. Import *→English pairs (needed for vocabulary classification)
php bin/console app:tei:import:all --dst=eng -v

# 4. Browse
open https://gist.wip/
```

## Environment

```dotenv
DATABASE_URL=postgresql://...
APP_DATA_DIR=/mnt/x10/gist   # where TEI archives and extracted files are cached
```

`APP_DATA_DIR` should point to persistent storage — TEI archives are large (~10–200MB per pair) and slow to re-download.

---

## Commands

```bash
# Catalog
app:load [--reset]                        # fetch freedict-database.json, upsert catalog

# Import (TEI XML → Postgres)
app:tei:import <pair>                     # single pair, e.g. fra-eng
app:tei:import:all [--dst=eng] [-M 5]    # all pairs; --dst filters by destination language

# Browse (StarDict binary format, for debugging)
app:browse <pair> [--format=json]         # first record from a StarDict pair
app:browse:all [--limit=10]               # loop through all StarDict pairs

# Workflow (via Symfony Messenger)
survos:state:iterate FreeDictCatalog --transition=download   # dispatch downloads
messenger:consume freedictcatalog.download                   # process download queue
messenger:consume freedictcatalog.process                    # process import queue
```

---

## API

### `GET /lookup?word=photographie&lang=fra`
Web UI — shows definitions, English translations, and ContentType classification.

### `GET|POST /translate`
LibreTranslate-compatible endpoint. Drop-in replacement for testing without installing LibreTranslate.

```bash
curl -X POST https://gist.wip/translate \
  -H 'Content-Type: application/json' \
  -d '{"q":"photographie","source":"fra","target":"eng"}'
```

### `POST /resolve` _(coming soon)_
Vocabulary classification endpoint — returns ContentType slug per keyword.

```bash
curl -X POST https://gist.wip/resolve \
  -H 'Content-Type: application/json' \
  -d '{"keywords":["photographie","peinture"],"lang":"fra"}'
# → {"photographie":{"type":"photograph","score":1.0},"peinture":{"type":"painting","score":1.0}}
```

---

## Architecture

```
FreeDict catalog JSON
        ↓  app:load
FreeDictCatalog table (305 pairs)
        ↓  TRANSITION_DOWNLOAD
TEI archive → /APP_DATA_DIR/tei/{pair}/{pair}.tei
        ↓  TRANSITION_PROCESS
Language / Lemma / Sense / Translation tables
        ↓  DbLookupService::resolve()
ContentType slug (via survos/data-contracts)
```

**Workflow states:** `new` → `downloaded` → `processed`

**Translation cache:** results are stored in `survos/babel-bundle`'s `Str`/`StrTranslation` entities in the calling app. `text = null` means "gist was asked and had no answer" — avoids re-querying for unknown terms.

---

## Data model

| Table | Contents |
|---|---|
| `freedict_catalog` | 305 language pairs from freedict.org, with workflow marking |
| `lang` | ISO 639-3 language codes |
| `dictionary` | Imported pair metadata (release, TEI URL) |
| `lemma` | Headwords with POS, gender, normalized form |
| `sense` | Definitions/glosses per lemma |
| `translation` | Directed edges: src lemma → dst lemma |

---

## Re-importing after schema changes

```bash
# Reset markings for a language group
php bin/console d:run -- "UPDATE freedict_catalog SET marking='new' WHERE dst='eng'"

# Delete cached TEI files to force re-extraction
find /APP_DATA_DIR/tei -name "tei.xml" -size -100k -delete

# Re-import
php bin/console app:tei:import:all --dst=eng -v
```

See `PLAN.md` for current status and next steps.
