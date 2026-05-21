# gist — Plan & Status

FreeDict bilingual dictionary server: TEI XML → Postgres → LibreTranslate-compatible API + vocabulary classification service.

## Critical bug fixed (session end)

**All 29 processed `*-eng` pairs imported the WRONG file.** `ensureTeiXml` called `findFirst` with `/\.(tei|xml)$/i` which grabbed `freedict-P5.xml` (the DTD schema, ~18K) instead of `deu-eng.tei` (the actual dictionary, ~18MB). Result: 0 Translation rows, only 943K Sense rows from the schema file's incidental matches.

**Fix applied:** `findFirst` now prefers `.tei` extension first, falls back to `.xml`.

**Action needed:** reset all `*-eng` pairs to `new`, delete extracted `tei.xml` files, re-import:
```bash
# Reset markings
php bin/console d:run -- "UPDATE freedict_catalog SET marking='new' WHERE dst='eng'"

# Delete wrong cached tei.xml files so they re-extract correctly
find /mnt/x10/tei -name "tei.xml" -size -100k -delete

# Re-import
php bin/console app:tei:import:all --dst=eng -v
```

## Architecture

**gist is a vocabulary resolution service.** Other apps (md, ssai, zm) send foreign-language keywords and get back `ContentType` slugs + confidence scores. Results cache in `survos/babel-bundle`'s `Str`/`StrTranslation` entities (`text` is nullable — null = known miss, avoid re-querying).

**Flow:**
```
genre_specific: ["photographie"] (lang=fra)
  → babel-bundle: check StrTranslation(fra, photographie, eng, gist) → miss
  → POST gist /resolve {keywords: [...], lang: fra}
  → translate fra→eng via Translation table: "photograph"
  → ContentType::lookupGenreType("photograph") → "photograph"
  → cache StrTranslation, return slug
```

**Why gist beats LibreTranslate for single-word vocab:** FreeDict gives exact lexical translations with POS. MT models hallucinate plausible-but-wrong translations for isolated nouns. Null is the correct answer when a term has no ContentType mapping ("métronome" → "metronome" → null → stay as `object`).

## Done this session

### Upgrades & setup
- Symfony 8.1, survos/* ^2.4, EasyAdmin 5, Doctrine DBAL 4.4, migrations bundle 4
- Replaced `survos/core-bundle` with `survos/field-bundle` + `survos/tabler-bundle`
- Added API Platform, `survos/search-bundle`, `mezcalito/ux-search`, `survos/data-contracts`
- Fixed `SurvosGraphVizDumper` for Symfony 8.1 (3 method signature mismatches)
- `APP_DATA_DIR` via `services.yaml` param `app.data_dir`; `.env.local` = `/mnt/x10/gist`
  - **Shell profile still has `APP_DATA_DIR=/mnt/x10` — update to `/mnt/x10/gist`**
  - Currently files land at `/mnt/x10/tei/` (not `/mnt/x10/gist/tei/`)

### Architecture
- Commands moved into services as `#[AsCommand]` methods (no standalone command classes)
  - `FreeDictService`: `app:load`, `app:browse`, `app:browse:all`
  - `TeiImportService`: `app:tei:import` (single), `app:tei:import:all` (batch, `--dst=eng` filter)
- `FreeDictCatalogWorkflow` properly split:
  - `TRANSITION_DOWNLOAD` → `downloadTei()` (fetch archive, extract to disk)
  - `TRANSITION_PROCESS` → `processTei()` (parse `.tei` XML, persist to DB)
  - Guard on `TRANSITION_PROCESS`: blocks if `tei.xml` missing
  - `onCompleted` → `em->flush()` (saves marking after transition)
- Messenger transports: `freedictcatalog.download` / `freedictcatalog.process`
- Doctrine `messenger` connection (same URL as `default`)
- `LemmaRepository::upsert` has in-memory cache keyed `code3|headword|pos` (prevents within-batch duplicates)
- Headwords truncated at 250 chars, `Lemma.pos` is `varchar(64)`
- Composite index `(language_id, norm_headword)` added for resolve query

### UI
- `base.html.twig` extends `@SurvosTabler/layout/base.html.twig`
- `AppMenuListener` with navbar + sidebar menus
- Homepage (`/`) — stat cards, lookup form, import pipeline progress, →eng pairs table
- Lookup UI (`/lookup`) — word + language → lemma details, senses, English translations, ContentType badge. English translation badges are clickable (look up in reverse)
- `DbLookupService::resolve()` — full resolution: lemma(s), senses, translations→eng, ContentType via `ContentType::lookupGenreType()`

### Import pipeline fixes
- `em->clear()` removed (was detaching catalog entity, breaking marking persistence)
- `safeFlush()` wraps errors with `[pair-name]` prefix for debugging
- `refreshManagers()` only resets when EM is closed (not on every batch)
- Batch flush every 1000 entries — clean, no UniqueConstraintViolation handling needed (cache prevents dups)

## Next steps (priority order)

### 1. Re-import all *-eng pairs (IMMEDIATE)
See "Critical bug fixed" section above. Run after resetting markings and deleting bad cached files.

### 2. Progress logging with totals
In `onProcess` / `importAll`, use `$catalog->headwords` as the known total:
```
[fra-eng] 3000 of 8505 entries (35%)…
```

### 3. `/resolve` API endpoint
Build `POST /resolve {keywords: string[], lang: string}` → per-term `{type, score, via[]}`.
Same service as the lookup UI (`DbLookupService::resolve()`), just JSON output.
gist needs to depend on `data-contracts` for `ContentType::GENRE_SPECIFIC_MAP`.

### 4. BM25 / PostgreSQL FTS for fuzzy lookup
Add GIN index: `to_tsvector('simple', headword)` on lemma table.
Use language-specific PG dictionaries (french, german, spanish…) when available.
Replaces `MorphHelper` + `wamania/php-stemmer` — DB handles morphology natively.

### 5. Shell profile fix
Set `APP_DATA_DIR=/mnt/x10/gist` in `~/.bashrc`/`~/.zshrc`.

### 6. Search UI
- Menu links to `survos_entity_ux_search` for Lemma and FreeDictCatalog
- Hit templates: `templates/search/hits/lemma.html.twig`

### 7. TranslateCommand → service
`src/Command/TranslateCommand.php` still standalone. Move to `DbLookupService` or new `TranslationService`.

### 8. JSONL refactor (longer term)
TEI→DB direct import is fragile. Better: TEI → JSONL (per pair or per language) → DB.
Look at `data-bundle` and `harvest` project for the pattern.
Language-organized JSONL (`/mnt/x10/gist/jsonl/eng/lemmas.jsonl`) interesting for cross-dictionary merging.

## Key files

| File | Purpose |
|---|---|
| `src/Service/FreeDictService.php` | Catalog load, StarDict browse commands |
| `src/Service/TeiImportService.php` | TEI download, parse, DB import, workflow commands |
| `src/Service/DbLookupService.php` | Word lookup, resolve(), available languages |
| `src/Workflow/IFreeDictCatalogWorkflow.php` | Workflow definition |
| `src/Workflow/FreeDictCatalogWorkflow.php` | Transition listeners, guard, onCompleted flush |
| `src/Repository/LemmaRepository.php` | upsert with in-memory cache |
| `src/Controller/HomepageController.php` | Stats homepage |
| `src/Controller/LookupController.php` | Word lookup UI |
| `src/EventListener/AppMenuListener.php` | Navbar + sidebar menus |
| `config/packages/doctrine.yaml` | Two DBAL connections: `default` + `messenger` |
| `config/services.yaml` | `app.data_dir` parameter |

## DB state (end of session)

- 305 catalog entries loaded
- 29 `*-eng` pairs `processed` (but with WRONG TEI — need re-import)
- 1,040,286 lemmas (from non-eng pairs + bad eng imports)
- 943,723 senses
- 0 translations (bug: wrong file imported)
