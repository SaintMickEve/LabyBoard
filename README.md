# Labyrinthe Battleboard Generator

Fill in a Labyrinthe LARP battleboard in your browser and print it or save it as a PDF. Characters can be exported and imported as JSON files.

- Website: https://labynoob.info/BB
- Windows app: download it from this repository's **Releases** page.

## What's in this repository

| Folder | What it is |
|---|---|
| `BB/` | The website: a PHP 8.2 single page app with no external libraries. Upload this folder to any PHP host. See [`BB/README.md`](BB/README.md) for deployment, security, the JSON format and the layouts. |
| `desktop/` | The Windows desktop app (Tauri), which shows the same web app in its own window. See [`desktop/README-desktop.md`](desktop/README-desktop.md) to build it. |
| `tools/sheet-generator/` | Generates `BB/templates/board.php` and `BB/assets/css/board.css` from the print design. Run `python3 tools/sheet-generator/gen_app.py` after changing the sheet's design; don't edit those two files by hand. |
| `tools/sanitiser-tests/` | Checks that the PHP and JavaScript sanitisers give identical results. Run `python3 gen.py`, then `php fuzz.php` and `node fuzz.js`, from that folder. |

## Running it locally

From the repository root:

```
php -S 127.0.0.1:8080
```

Then open http://127.0.0.1:8080/BB/.

## Fonts

The fantasy and serif fonts are TeX Gyre Chorus and TeX Gyre Pagella, both from GUST and released under the [GUST Font License](https://www.gust.org.pl/projects/e-foundry/licenses).
