# FreeDict Translation Server — Fast & Rough

# load catalog (if not already)
bin/console app:load

# import all TEI-capable pairs (limit to 3 pairs while testing)
bin/console app:tei:import:all -M 3 -L 500

# verify counts
open http://127.0.0.1:8000/admin

# try translation using DB (engine=db)
curl -X POST http://127.0.0.1:8000/translate \
-H 'Content-Type: application/json' \
-d '{"q":"hello","source":"eng","target":"spa","mode":"text","engine":"db"}'



A tiny **LibreTranslate‑style** server that uses **FreeDict** dictionaries for **fast word‑by‑word** translation. It favors speed and zero external services over quality. You can later swap in a real MT engine or import the dictionaries into a database for stateless deployments.

- PHP **8.4**, Symfony **7.3**
- Reads **FreeDict** StarDict bundles (WikDict‐based)
- Handles `.tar.xz` (system tar), `.idx.gz`, `.dict.dz` (auto‑gunzip when needed)
- HTTP: `/languages`, `/translate` (`mode=text|rules`)
- UI: **home page** `/` (uses the service directly or the HTTP API toggle)
- CLI: load catalog, browse dictionaries, debug low‑level entries

> ⚠️ Quality is intentionally crude. Unknown tokens remain unchanged.

---

## 1) Requirements

**System**
- Linux/macOS with:
    - `tar` **with xz** support (Debian/Ubuntu: `sudo apt-get install xz-utils`)
    - PHP: `ext-json`, `ext-dom`, `ext-libxml`, `ext-zlib`, `ext-bz2`

**Composer packages**
```bash
composer require symfony/http-client symfony/filesystem symfony/intl skoro/stardict





Can you write up a README about how to install and run?

I'm not sure the dict format gets us what we really need.  Is there a base format that all the dictionaries use, the "source"?  I imagine it's in XML and we can parse it and skip these add-ons.

These are other formats I read about:

freedict-p5 (TEI P5 XML - most detailed)
tei (TEI XML)


Also, add a --format=json to the browse command, and dump ALL the data we know about a record in a json format, so we canfigure out how to deploy (e.g. dictionary files, or import everything into a postgres database).
I was thinking if we moved it to a database, we'd have less parsing to deal with.  Plus, I wouldn't need to set up persistent storage on the server.  Finally, we could add our own API for fetching data from the database.

SOmething's wrong -- a simple English to Spanish 'hello' produces this:

LibreUiController.php on line 79:
"/həˈloʊ/, /həˈloː/, /həˈləʉ/, /həˈləʊ/, /hɛˈloʊ/, /hɛˈloː/, /hɛˈləʊ/, /ˈhɛlo/, /ˈhɛloʊ/, /ˈhɛloː/ interjectionA greeting (salutation) said when meeting someone or acknowledging someone’s arrival or presence.holabuenas tardesbuenos díasqué talA greeting used when answering the telephone.holadígamealódigabuenooigoA call for response if it is not clear if anyone is present or listening, or if a telephone conversation may have been disconnected.aló[[hola|¡Hola!]] [[hay alguien|¿Hay alguien?]] ◀"

