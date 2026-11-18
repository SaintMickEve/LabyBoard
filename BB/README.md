# Labyrinthe Battleboard Generator (RC 1.9)

A single page app for filling in a fantasy LARP battleboard, then printing it or saving it as a PDF.
Built for PHP 8.2 on Fasthosts Linux hosting. It works on desktop, tablet and phone and has no
external libraries, CDNs or database.

## What's in the box

```
BB/
├── index.php            The app (editor + live preview of the sheet)
├── api.php              JSON endpoint: validates and cleans imported characters
├── .htaccess            Apache rules (blocks src/, config/, templates/, dotfiles)
├── config/schema.json   Every limit and option (lengths, number ranges, row counts, power types)
├── src/Sanitiser.php    Server-side validation, driven by schema.json
├── src/bootstrap.php    Session, CSRF token, security headers, HTML escaping helper
├── templates/board.php  The A4 battleboard markup
└── assets/              CSS, JavaScript, fonts, favicon
```

## How the code fits together

Each file opens with a plain-English explanation, and the main functions are commented. In short:

1. **`index.php`** builds the page: the editor form (generated from `config/schema.json`) and the
   A4 sheet (`templates/board.php`). Each form field says which piece of character data it edits
   (`data-path`), and each part of the sheet says which piece it shows (`data-bind`).
2. **`assets/js/app.js`** keeps the whole character in one object (`state`). Typing updates it, the
   sheet is redrawn from it, and it is saved in the browser automatically. The file's opening
   comment walks through state, editing, drawing, saving, layouts, and import/export.
3. **`api.php`** is used only when importing. It checks the request (same site, CSRF token, size,
   rate limit), then passes the file to the Sanitiser.
4. **`src/Sanitiser.php`** rebuilds a clean character from any input. `sanitise()` in `app.js` is its
   exact twin, so if you change a rule in one, change it in the other.
5. **`src/bootstrap.php`** holds the site path, the title, HTML escaping, the session and the
   security headers.
6. **`assets/css/board.css`** draws the sheet in millimetres. `app.css` styles the rest of the page.
   `.htaccess` protects the code folders and sets caching.

`config/schema.json` can't contain comments, because JSON doesn't allow them. Its settings are
described in the sections below.

## Deploying to Fasthosts

1. In the Fasthosts control panel, open your hosting package and set the **PHP version to 8.2**
   (Web Hosting → PHP Settings / PHP Version).
2. Upload the `BB` folder into `htdocs/` by SFTP/FTP or File Manager, so the app lives at
   `htdocs/BB/` and is served at `https://yourdomain/BB/`. Upload the hidden `.htaccess`
   files too; some FTP clients hide dotfiles.
3. Enable SSL for the domain, then uncomment the two HTTPS redirect lines in `.htaccess`.
4. Visit `https://yourdomain/BB/`. To check the protection works,
   `https://yourdomain/BB/config/schema.json` and `https://yourdomain/BB/src/Sanitiser.php`
   should both return **403 Forbidden**.

### The /BB/ subdirectory

All links, assets, the API URL and the session cookie use `/BB/`. The path is set in one place,
`BASE_PATH` in `src/bootstrap.php`, plus `RewriteBase /BB/` and the redirect rule in `.htaccess`.
The title link goes to `/BB/`, and unknown URLs inside `/BB/` redirect back to `/BB/`, never to
the server root. If you ever move the app, change those three spots.

Required PHP extensions are `json`, `mbstring` and `session`; these are standard on Fasthosts.
`intl` is used for Unicode normalisation when it's available and is optional.

## How data is handled

- **Storage:** everything is JSON. The board autosaves to the browser's `localStorage`
  (`battleboard.v1`). Nothing is stored on the server.
- **Export** downloads human-readable, indented JSON, for example
  `aldric-thornvale-battleboard.json`. On phones this goes to Downloads or Files.
- **Import** accepts a `.json` file up to 128 KB. The file is sent to `api.php`, which validates it
  against `schema.json` and returns a clean copy plus a list of anything it had to change. If the
  server can't be reached, the same rules run in the browser.
- **Print / PDF** opens the browser's print dialog. To get a PDF, choose *Save as PDF*.
  Use A4, margins *None* and scale 100%.

## Security measures

- **Input handling:**
  - Character data is never inserted as HTML. The page writes every value with `textContent`,
    and PHP output goes through `htmlspecialchars`.
  - Text is NFC-normalised, then control characters, zero-width characters and bidi-override
    characters are stripped, and the length is limited per field.
  - Numbers must be whole numbers in range. Power type must be one of Mana, Spirits, EP, Mantic Mana or Mantic Spirits.
    Fractions must look like `1/2`. Immunity values must be 0–99, Y or N.
  - Unknown keys are dropped and row counts are capped.
- **API protections:** `api.php` only accepts POST with `application/json`. It also requires:
  - a per-session CSRF token;
  - a same-origin request;
  - a body of 128 KB or less;
  - JSON nested no deeper than 16 levels;
  - no more than 60 requests per minute per session.
- **Headers:** a strict Content-Security-Policy is sent, with no inline scripts or styles and no
  third-party sources. `X-Frame-Options`, `nosniff`, `Referrer-Policy` and a `SameSite=Strict`
  HttpOnly session cookie are also set.

## Changing limits or options

Edit `config/schema.json`; both the PHP and JavaScript validation read it. Examples:

- `text.notes` sets the maximum length of the notes.
- `int.armour` sets the range for the five shields (PAC, DAC, SAC, MAC, NAC), currently 0–36.
- `int.locAc` sets the range for the AC circles on the paper doll, currently 0–36.
- `powerTypes` lists the power options.
- `dollLimits` and `immunitiesCompact.limits` set the most Immunities/Resistances rows for each doll (see below).

Keep row maximums within what fits on the A4 sheet.

## JSON format (export)

```json
{
  "app": "battleboard",
  "version": 1,
  "generator": "Labyrinthe Battleboard Generator (RC 1.9)",
  "exportedAt": "2026-11-14T20:30:00.000Z",
  "character": {
    "layout": "basic",
    "doll": "default",
    "poolsShow": false,
    "consumablesShow": true,
    "immunitiesCompact": false,
    "theme": "blue",
    "watermark": "lightning",
    "name": "Aldric Thornvale",
    "alignment": "GL",
    "classes": "Warrior",
    "races": "Human",
    "tblp": 12,
    "tblpRows": 8,
    "ltm": { "show": true, "value": 345 },
    "castingStam": { "show": true, "value": 120 },
    "tblpLines": ["", "", "", "", "", "", "", ""],
    "power": { "type": "Mana", "value": 8 },
    "powerLines": ["", "", "", "", ""],
    "powB": { "label": "Pow", "value": 15 },
    "pools": [{ "name": "Pool 1", "value": null }, { "name": "Pool 2", "value": null }, { "name": "Pool 3", "value": null }],
    "resChance": 40,
    "innates": [{ "name": "Strike Down", "count": 3 }],
    "consumables": [{ "name": "Healing Draught", "count": 2 }],
    "armour": { "pac": 6, "dac": 2, "sac": null, "mac": 1, "nac": 0 },
    "locations": { "head": { "hits": 3, "ac": 4 }, "...": {} },
    "resist": { "epower": 8, "magic": 8, "neuronic": 8, "physical": 8, "spirits": 8 },
    "reductions": { "numerical": "-2", "percentile": "", "fractional": "1/2" },
    "combatWary": { "show": true, "value": "1/2" },
    "colourResist": [{ "text": "Green", "fraction": "3/4" }],
    "immunities": [{ "text": "Fear", "value": "Y" }, { "text": "Poison", "value": 5 }],
    "notes": "Free text…",
    "social": "Knight of the realm",
    "professional": "Guild smith, journeyman",
    "political": "Loyalist",
    "points": 1250
  }
}
```

`count` is the number of usable boxes, from 1 to 8; the remaining boxes print hatched.
The **Sort A–Z** button at the top of the Innates section puts the innates into alphabetical order. Case and accents are ignored, numbers sort naturally ("Bolt 2" before "Bolt 10"), and unnamed rows go to the end. Each innate keeps its number of uses.
A `null` value means the box is left blank for pen.

`theme` is the sheet colour: `parchment` (default), `blue`, `red`, `green`, `yellow` or `lavender`.
These are pastel page washes, each with its own ink. Main text contrast is at least 12:1 and the
secondary ink is at least 5.9:1, the same as or better than the original parchment. The palettes
are in `assets/css/board.css`. Ink-saver makes the page white but keeps the theme's line colour.

### Layouts

`layout` picks the arrangement of the left-hand column. Each layout's settings are in
`config/schema.json` under `layouts`: line counts, row heights in mm, row limits and default rows.

| Layout | `layout` value | T.B.L.P. lines | Power lines | Innates | Consumables |
|---|---|---|---|---|---|
| Basic Layout (default) | `basic` | 2–8, user's choice (`tblpRows`) | 5 | 16 by default; up to 25 rows in total with consumables | 4, up to 12 |
| Pool Princess | `poolPrincess` | 3 | 3 | 18 by default; up to 28 rows in total | 5, up to 12 |
| Innately Gifted | `innatelyGifted` | 4 | 3 | compact rows, filled to the space (about 32), up to 35 in total | 3, up to 10 |
| Battlemaster | `battlemaster` | 2–8, user's choice (`tblpRows`) | none: the Power box is removed | 22 by default; up to 32 rows in total | 6, up to 16 |
| Brimful of Asher | `brimful` | 3 | 3 | compact rows, 10, up to 19 | compact rows, filled down to Res Chance (about 27), up to 37 in total |

**Consumables off:** `consumablesShow` (default `true`) can be switched off with the tick box in the
Consumables section. The Consumables table is then left off the sheet, its rows are kept for when it's
switched back on, and innates can use the freed space: up to 29 (Basic), 33 (Pool Princess),
39 (Battlemaster), 38 (Innately Gifted) or 40 (Brimful of Asher, which then fills innates instead of
consumables). These limits are `innatesSolo` in each layout. Switching the table back on trims blank
innate rows, and asks first if filled rows would be lost.

Row heights are 4.8 mm (standard) and 3.9 mm (compact). The text inside the rows stays the same size
at those heights: innate and consumable names are 3.3 mm, and the numbers in the use boxes are about
1.7 mm. When a list nears its limit, the rows squeeze a little closer together to fit the column; the
text only gets smaller in extreme cases, for example a full list plus the optional pools.

- **Pools:** three `pools` sit between the Power and Innates boxes. Each has an editable name (up to
  14 characters), a number from 0 to 999, and three empty lines underneath for pen notes.
  - Pool Princess always shows them.
  - In any other layout they appear when `poolsShow` is `true`, set by the toggle in the Pools section.
    If that layout has no spare room, the innate and consumable rows shrink a little.
- **Battlemaster:** for characters with no power. The Power box isn't printed and everything below it
  moves up. Power values are kept in case you switch layouts.
- **Hidden lines:** lines hidden by a layout keep their text, in case you switch back, and the form
  warns that they won't print.
- **Switching layouts:**
  - Blank rows are trimmed or added as needed.
  - If filled innates or consumables would be lost, the user is asked first.
- **Too many rows:** if they don't fit, rows shrink evenly so the column never runs past Res Chance.

### Paper doll

`doll` is one of:

- **`default`:** separate Chest and Abdomen.
- **`snowflake`** (Special Snow(man)flake): Chest and Abdomen combined into one Torso location, with a
  single loc box and AC circle.
- **`ghost`** ("Woo I'm a ghost"): for characters with no locations.
  - **Sheet changes:**
    - The T.B.L.P. box is removed, and the boxes below move up. A small box in the ghost's
      bottom-left corner shows the T.B.L.P. value, plus LTM when it's switched on.
    - The doll becomes one pacman-style ghost area, about two thirds of the doll's height, with lines
      for writing.
    - Reductions, Resistance and Immunities/Resistances stack down the right-hand side.
    - Colour Resist moves under the ghost and is wider.
  - **Row limits:** Colour Resist rises from 5 to 9 rows, and Immunities/Resistances from 12 to 20.
    Both fill with blank rows when you switch to the ghost. They are set in `dollLimits` in
    `config/schema.json`.
  - **Switching back:** blank rows are trimmed. If filled rows would be lost, the user is asked first.

Location and T.B.L.P. values are always kept when switching dolls; only the parts the chosen doll uses
are shown. Layouts that fill rows (Innately Gifted, Brimful of Asher) refill automatically when the
doll or the pools toggle changes the space available.

`watermark` is a faint background design: `none` (default), `fire`, `water`, `nature`, `angels`,
`demons`, `mind`, `combat`, `lightning` or `stone`.

- **How they're drawn:** they're vector drawings in `assets/watermarks.svg`, drawn in `currentColor`,
  so each design takes on the colour of the chosen sheet theme. There's no separate image per theme.
- **Faintness:** the watermark colour sits only slightly darker than the page. Even the darkest parts
  are about 1.25:1 against it, and most of each design is fainter still.
- **Placement:** they sit behind every box, so they show only in the page-coloured gaps, and they
  print as part of the page. Ink-saver turns them off.
- **Loading:** the file is about 550 KB, roughly 150 KB compressed. It's downloaded once and cached.
  `.htaccess` turns on compression for it when the server supports it.

**Immunities/Resistances rows.** On the Default and Snow(man)flake dolls, rows that don't fit in
the main table (beside the right leg) continue in a second table, headed "Imms (cont.)", in the
space below the Resistance table, beside the left leg. It only appears when it's needed. The ghost doll
has no space there, as its Resistance table is on the right, but its Immunities column already runs
the full height of the doll.

`immunitiesCompact` (the "Compact rows" tick box in the Immunities/Resistances section) shortens the
rows so more fit, without changing the text size. Row limits:

| Doll | Normal rows | Compact rows |
|---|---|---|
| Default, Snow(man)flake | 18 (12 in the main table, 6 below Resistance) | 26 (17 + 9) |
| Woo I'm a ghost | 20 | 28 |

The limits are `dollLimits` (normal) and `immunitiesCompact.limits` (compact) in `config/schema.json`,
and `immunitiesMainRows` says how many rows the main table holds before the rest continue below
Resistance. The ghost's column refills with blank rows when compact rows are switched on or off.
Switching back to normal rows trims blank rows, and asks first if filled rows would be lost. Older
files import with compact rows switched off.

`combatWary.show` adds an optional Combat Wary row under Fractional in the Reductions box. Its value is
up to 4 characters, like the other reductions, and typing a value switches the row on.

`castingStam.show` turns the bottom line of the T.B.L.P. box into a **Casting Stam** line: a label,
a number box (`castingStam.value`, 0–999) and the rest of the line left blank. It replaces the bottom
line rather than adding one, so the box keeps its height, and any text on that line is kept in case
you switch it off. Typing a value switches it on. On the ghost doll it appears in the small
T.B.L.P. box inside the ghost. Older files without it import with it switched off.

`ltm.show` controls whether the optional LTM box appears on the T.B.L.P. heading. Entering an LTM
value switches it on automatically. `points` (0–99999) is shown beside the Notes heading.

`social`, `professional` and `political` (up to 50 characters, two short lines, each) print in three small boxes under
the Notes heading. Each box's label shows only while it is empty. Long text shrinks slightly, then is cut at the last whole word that fits, with "…".
Only the printed sheet is shortened; the saved and exported text is kept in full.

`alignment` is one of `GL, GN, GC, NL, NN, NC, EL, EN, EC`, or `""` when not chosen. It's shown in
brackets after the name, at the top and bottom of the sheet.

## Compatibility between versions

Exported files are meant to keep working as the site evolves.

- **Older files in a newer site:** any field the file doesn't have gets its default. RC 1.0 files,
  which have no alignment, NAC, Pow column or Res Chance, import without any warnings.
- **Newer files in an older site:**
  - Fields the site doesn't know are ignored, at the top level and nested.
  - The user sees a note listing the ignored fields.
  - If the file's `version` is higher than the site's, the user is told the file came from a newer
    version.
- **Never an error for valid JSON:** anything wrong in a file, such as wrong types, out-of-range
  values or too many rows, is corrected and listed rather than rejected. The only refusals are
  invalid JSON, files over 128 KB or nested more than 64 levels deep, and an expired session.
- **Testing:** the PHP and JavaScript sanitisers were fuzz-tested with 1,800 random JSON shapes. They
  never threw, always gave identical output, and re-importing a sanitised file gives no further
  changes.

- **Text encodings:** files can be UTF-8, with or without the byte-order mark Windows Notepad adds,
  or UTF-16, which is Notepad's "Unicode" option. Windows line endings and minified JSON are also
  fine. A bare character object without the `{app, version, character}` wrapper also imports.
- **Offline or expired session:** if the server can't be reached, or the session has expired, the
  import is checked in the browser instead, using the same rules.
- **Upgrading the site:** a board saved in the browser by an older version loads in the new one with
  every field intact.

Compatibility tests for RC 1.9:

- **Older versions:** files exported from RC 1.0 to RC 1.4 import with every field preserved. The only
  exception is a Social/Professional/Political entry longer than today's 50-character limit, which
  is shortened with a note.
- **Server vs browser:** results are identical either way.
- **Round trips:** 40 random boards across every layout and paper-doll combination survive export,
  import and export again unchanged.
- **Largest board:** the biggest possible board exports at about 22 KB, well under the 128 KB limit.

When adding features in future versions:

1. **Only add new fields,** each with a default, in `config/schema.json`, `src/Sanitiser.php` and
   the `sanitise()` function in `assets/js/app.js`.
2. **Don't rename or reuse an existing key.** If you have to, bump `version` in `schema.json` and
   translate the old key to the new one in both sanitisers before the other rules run.
3. **Don't narrow a range** without accepting that older files with values outside it will have
   those values cleared, with a note.

## Windows desktop app

The generator is also available as a Windows app that opens in its own window, with no browser,
no command window and no PHP.

- **Downloads:** `LabyrintheBattleboard-Setup-1.9.0.exe` is an installer that needs no
  administrator rights and adds Start-menu and desktop shortcuts and an uninstaller. A portable
  zip is also available: keep `LabyrintheBattleboard.exe` and `WebView2Loader.dll` together.
- **How it works:** the app is built with Tauri. It shows this web app in a native window using
  Microsoft Edge WebView2, which is built into Windows 10 and 11. The page is rendered once from
  `index.php` and compiled into the .exe with `assets/`. Imports are checked by `sanitise()` in the
  page, exactly as on the website. Export uses the Windows "Save As" dialog: `app.js` calls the
  app's `save_export` command when `window.__TAURI__` exists, and downloads as usual otherwise.
- **Saved boards:** they are kept by the app on the computer, and messages say "on this computer"
  instead of "in this browser".
- **Source and build instructions:** see `README-desktop.md` in the desktop source package. Rebuild
  after every web release so the two stay identical.

The earlier Go-based desktop edition, which opened the generator in a browser tab with a command
window, has been replaced by this app.

## Local testing

Run this from the folder that *contains* `BB`, so that the `/BB/` paths resolve:

```
php -S 127.0.0.1:8080
```

Open http://127.0.0.1:8080/BB/. The PHP built-in server ignores `.htaccess`.
