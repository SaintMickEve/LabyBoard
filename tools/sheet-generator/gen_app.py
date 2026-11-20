#!/usr/bin/env python3
"""Generates the app's board template (templates/board.php) and board.css from the print design in build.py."""
import pathlib, re, shutil, sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import build  # noqa: E402  (defines PIECES, doll_svg, CORNER, BANNER, css)

# Special Snow(man)flake: chest + abdomen combined into one Torso location (shown instead of them by CSS)
build.PIECES.append(("torso", "Torso",
    "M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,109.2 Q62.5,112.2 59.5,112.2 L28.5,112.2 Q25.5,112.2 25.5,109.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z",
    (24.5, 34.2, 39, 78), (27, 36.2)))

# "2 Page 15k+ nutjobs" format: the doll is 6 mm shorter so a T.B.L.P. box fits under it. Chest and abdomen
# (or the Snow(man)flake torso) are 3 mm shorter each, the arms 6 mm shorter, and the legs move up 6 mm
# (same length). The head is unchanged. These pieces are drawn alongside the normal ones (ids end in
# "-tp") and board.css shows one set or the other from the sheet's data-format.
TP_PIECES = [
    ("larm", "Left Arm", "M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,95 Q21.5,99 17.5,99 L3.7,99 Q-0.3,99 -0.3,95 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z", (-2.3, 35.7, 24.8, 63.3), (-0.6, 37.7)),
    ("chest", "Chest", "M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,72.2 L26,72.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z", (24.5, 34.2, 39, 38), (27, 36.2)),
    ("rarm", "Right Arm", "M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,95 Q66.5,99 70.5,99 L85.5,99 Q89.5,99 89.5,95 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z", (65.5, 35.7, 26.0, 63.3), (67.2, 37.7)),
    ("abdomen", "Abdomen", "M24.5,74.7 L63.5,74.7 L62,104.2 Q62,106.2 60,106.2 L28,106.2 Q26,106.2 26,104.2 Z", (24.5, 74.7, 39, 31.5), (27, 76.7)),
    ("lleg", "Left Leg", "M24,108.2 L43,108.2 L42,173.8 Q42,177.8 38,177.8 L29,177.8 Q25,177.8 25,173.8 Z", (24, 108.2, 19, 69.6), (25.6, 110.2)),
    ("rleg", "Right Leg", "M45,108.2 L64,108.2 L63,173.8 Q63,177.8 59,177.8 L50,177.8 Q46,177.8 46,173.8 Z", (45, 108.2, 19, 69.6), (46.6, 110.2)),
    ("torso", "Torso", "M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,103.2 Q62.5,106.2 59.5,106.2 L28.5,106.2 Q25.5,106.2 25.5,103.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z", (24.5, 34.2, 39, 72), (27, 36.2)),
]

def tp_pieces_svg():
    """The two-page doll pieces, drawn exactly like build.doll_svg() draws the normal ones."""
    out = []
    for pid, label, d, (x, y, w, h), _ in TP_PIECES:
        cx, cy = x + w / 2, y + h / 2
        sx, sy = (w - 2.4) / w, (h - 2.4) / h
        out.append(f'''
  <g class="piece tp" id="piece-{pid}-tp">
    <clipPath id="clip-{pid}-tp"><path d="{d}"/></clipPath>
    <path d="{d}" class="piece-fill"/>
    <g clip-path="url(#clip-{pid}-tp)">
      <rect x="{x}" y="{y + 9}" width="{w}" height="{h}" fill="url(#lines)"/>
      <rect x="{x + w - 2.6}" y="{y}" width="2.6" height="{h}" fill="url(#hatch)" class="shade"/>
      <rect x="{x}" y="{y + h - 1.8}" width="{w}" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="{d}" class="stitch" transform="translate({cx} {cy}) scale({sx:.4f} {sy:.4f}) translate({-cx} {-cy})"/>
    <path d="{d}" class="piece-edge"/>
  </g>''')
    return "".join(out)

APP = pathlib.Path(__file__).resolve().parents[2] / "BB"
# Page-2 row counts come from config/schema.json so the sheet and both sanitisers agree.
import json as _json
TPS = _json.loads((APP / "config/schema.json").read_text())["twoPage"]
P2 = TPS["page2"]
(APP / "templates").mkdir(parents=True, exist_ok=True)
(APP / "assets/css").mkdir(parents=True, exist_ok=True)
(APP / "assets/fonts").mkdir(parents=True, exist_ok=True)
for f in build.HERE.joinpath("fonts").glob("*.woff"):
    shutil.copy(f, APP / "assets/fonts" / f.name)

# ---------------------------------------------------------------- CSS
css = build.css
css = css.replace("%%", "%")
css = re.sub(r'url\(data:font/woff;base64,%\((\w+)\)s\)', lambda m: {
    "chorus": 'url("../fonts/chorus-mediumitalic.woff")', "preg": 'url("../fonts/pagella-regular.woff")',
    "pbold": 'url("../fonts/pagella-bold.woff")', "pital": 'url("../fonts/pagella-italic.woff")'}[m.group(1)], css)
css = css.replace("body.ink-saver", ".ink-saver")
css = css.replace("#9b8565", "var(--hatch)")
css = css.replace("  --tint-2:#efe4cc;", "  --tint-2:#efe4cc;\n  --hatch:#9b8565;")
# strip page-level rules that belong to app.css
css = re.sub(r"\*\{box-sizing.*?\n", "", css)
css = re.sub(r"body\{background:#8d8273.*?\n", "", css)
css = re.sub(r"@media print\{\.toolbar.*?\n", "", css)
css = re.sub(r"\.toolbar\{.*?\n", "", css)
css = re.sub(r"\.toolbar button\{.*?\n", "", css)
css = re.sub(r"@media print\{\.toolbar.*?\n", "", css)
css = re.sub(r"\.sheet\{width:210mm.*?\n", ".sheet{width:210mm;height:297mm;background:#fff;padding:6mm;position:relative;font-family:var(--label-font);color:var(--ink);overflow:hidden}\n", css)
css = re.sub(r"@media print\{\.sheet\{.*?\n", "", css)
css = css.replace(".char-name:empty::before{content:\"Character Name\";color:var(--ink)}", "")
css = css.replace('.foot .name:empty::before{content:"Character Name"}', "")
css = css.replace(".pow .cells i:nth-child(5n){border-bottom-width:.6mm}", ".pow .cells i:nth-child(5n){border-bottom-width:.6mm}")

positions = []
for pid, label, _, (x, y, w, h), (chx, chy) in build.PIECES:
    if pid == "head":
        positions.append(f"#chip-{pid}{{left:{chx}mm;top:{chy}mm;transform:translateX(-50%)}}")
    else:
        positions.append(f"#chip-{pid}{{left:{chx}mm;top:{chy}mm}}")
    ly = y + h - (6.2 if pid in ("lleg", "rleg") else 5.2)
    positions.append(f"#pname-{pid}{{left:{x}mm;top:{ly}mm;width:{w}mm}}")

tp_ids = ",".join(f"#piece-{pid}" for pid, *_ in TP_PIECES)
for pid, label, _, (x, y, w, h), (chx, chy) in TP_PIECES:
    positions.append(f'.sheet[data-format="twoPage"] #chip-{pid}{{left:{chx}mm;top:{chy}mm}}')
    ly = y + h - (6.2 if pid in ("lleg", "rleg") else 5.2)
    positions.append(f'.sheet[data-format="twoPage"] #pname-{pid}{{left:{x}mm;top:{ly}mm;width:{w}mm}}')
positions.append('.sheet:not([data-format="twoPage"]) .piece.tp{display:none}')
positions.append(f'.sheet[data-format="twoPage"] :is({tp_ids}){{display:none}}')

extra = r"""
/* ---------- app additions ---------- */
.sheet .fit{display:block;overflow:hidden;white-space:nowrap;min-width:0}
.char-name .fit{flex:0 1 auto;min-width:0;max-width:100%;padding:0 1.5mm}
.char-name .align{flex:none;font:400 8.5mm/1 var(--title-font);white-space:nowrap;margin-left:2.5mm;padding-right:1mm;letter-spacing:.3mm}
.foot .align{flex:none;font:400 5.6mm/1 var(--title-font);white-space:nowrap;margin-left:-1mm;letter-spacing:.2mm}
.foot .name{display:block;max-width:120mm;min-width:0}
.char-name.is-empty{color:var(--ink)}
/* Class(es)/Race(s) wrap onto up to two lines; the header keeps a fixed height and the banner rides up into the top margin */
.head{flex:none;height:28mm;justify-content:flex-end}
.title-wrap{flex:none}
.identity{align-items:flex-end;flex:none;align-self:stretch;width:auto}
.identity label{align-items:flex-end;min-width:0}
.identity label:first-child{flex:2 1 0}
.identity label:last-child{flex:1 1 0}
.identity .k{flex:none;white-space:nowrap;line-height:4.6mm}
.identity .fill{min-width:0;white-space:normal;overflow-wrap:anywhere;line-height:4.6mm;max-height:9.2mm;overflow:hidden}
.ph .box .fit{text-align:center;width:100%}
.ph .ptype{display:inline-block;min-width:8mm;text-align:center;letter-spacing:.2mm}
.ph .k.power-k::after{margin-left:.6mm}
.pip-row{height:var(--row-h,6.05mm)}
/* pip rows scale with their list's row height (set per list by app.js for each layout) */
.pip-row .pips i{width:min(3.9mm, calc(var(--row-h,6.05mm) - 1.15mm));height:min(3.9mm, calc(var(--row-h,6.05mm) - 1.15mm));border-radius:.6mm}
.pip-row .pips i b{font-size:min(1.75mm, calc((var(--row-h,6.05mm) - 1.15mm) * .62))}
.pip-row .pips{gap:min(.55mm, calc(var(--row-h,6.05mm) * .09))}
/* the left column has a fixed height: rows shrink to fit rather than pushing the page */
.body{grid-template-rows:minmax(0,1fr)}
/* when a layout hides lines, the last visible line shouldn't double up with the box edge */
.row:has(+ .row[hidden]){border-bottom:none}
.body > .col:first-child{overflow:hidden}
/* Pool Princess: three named pools under the Power box */
.pools{display:none;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.8mm;flex:none}
.sheet[data-pools="on"] .pools{display:grid}
/* Battlemaster: no Power box */
.sheet[data-power="off"] #sheet-power{display:none}
.pool{display:flex;flex-direction:column;border:.35mm solid var(--ink);background:#fff;border-radius:.8mm;overflow:hidden}
.pool-head{display:flex;align-items:center;gap:1mm;height:7.6mm;padding:0 .9mm 0 1.6mm;background:var(--tint-2);border-bottom:.35mm solid var(--ink)}
.pool-line{height:5.2mm;border-bottom:.15mm solid var(--rule)}
.pool-line:last-child{border-bottom:none}
.pool .pn{flex:1;min-width:0;font:700 2.6mm var(--label-font);letter-spacing:.2mm;text-transform:uppercase}
.pool .pv{flex:0 0 7.5mm;height:5.6mm;border:.25mm solid var(--ink);border-radius:.6mm;background:#fff;display:flex;align-items:center;justify-content:center;font:700 3.2mm var(--label-font);padding:0 .4mm}
.pool .pv .fit{text-align:center;width:100%}
/* paper doll variants */
.sheet[data-doll="snowflake"] :is(#piece-chest,#piece-abdomen,#piece-chest-tp,#piece-abdomen-tp,#chip-chest,#chip-abdomen,#pname-chest,#pname-abdomen){display:none}
.sheet:not([data-doll="snowflake"]) :is(#piece-torso,#piece-torso-tp,#chip-torso,#pname-torso){display:none}
/* "Woo I'm a ghost": no locations, no T.B.L.P. box; one ghost area; tables rearranged for more rows */
.sheet:not([data-doll="ghost"]) #piece-ghost{display:none}
.sheet[data-doll="ghost"] #sheet-tblp,
.sheet[data-doll="ghost"] .doll .piece:not(#piece-ghost),
.sheet[data-doll="ghost"] .doll .chip,
.sheet[data-doll="ghost"] .doll .piece-name{display:none}
/* T.B.L.P. (and optional LTM) moves into a small box in the ghost's bottom-left, above the skirt */
.ghost-stats{display:none;position:absolute;left:3.6mm;bottom:72.6mm;flex-direction:column;border:.35mm solid var(--ink);border-radius:.8mm;background:#fff;overflow:hidden;z-index:2}
.sheet[data-doll="ghost"] .ghost-stats{display:flex}
.gs-row{display:flex;align-items:stretch;height:5.6mm}
.gs-row + .gs-row{border-top:.25mm solid var(--ink)}
/* Casting Stam: optional labelled bottom line of the T.B.L.P. box */
.cs-row{padding:0;border-top:.2mm solid var(--rule)}
.cs-row[hidden]{display:none}
.cs-k{display:flex;align-items:center;align-self:stretch;padding:0 2.2mm;background:var(--tint-2);border-right:.25mm solid var(--ink);font:700 2.8mm var(--label-font);letter-spacing:.35mm;text-transform:uppercase;white-space:nowrap}
.cs-v{flex:0 0 13mm;align-self:stretch;display:flex;align-items:center;justify-content:center;border-right:.25mm solid var(--ink);background:#fff;font:700 3.4mm var(--label-font)}
.cs-v .fit{text-align:center;width:100%}

.gs-k{width:24.5mm;display:flex;align-items:center;padding:0 1.4mm;background:var(--tint-2);border-right:.25mm solid var(--ink);font:700 2.5mm var(--label-font);letter-spacing:.3mm;text-transform:uppercase;white-space:nowrap}
.gs-v{width:11mm;display:flex;align-items:center;justify-content:center;font:700 3.2mm var(--label-font);padding:0 .5mm}
.gs-v .fit{text-align:center;width:100%}
.sheet[data-doll="ghost"] .mini.colour{left:0;top:124mm;width:60mm}
.sheet[data-doll="ghost"] .mini.colour col.n{width:10mm}
.sheet[data-doll="ghost"] .mini.resist{left:auto;right:0;width:25mm}
.sheet[data-doll="ghost"] .mini.immune{width:25mm}
.pip-row .name{flex:1;font:italic min(3.3mm, calc(var(--row-h,6.05mm) * .85)) var(--label-font)}
.res-panel{margin-top:auto}
.chip .loc-box,.chip .loc-ring{display:flex;align-items:center;justify-content:center;overflow:hidden;white-space:nowrap}
.loc-box{font:700 2.5mm/1 var(--label-font)}
.loc-ring{font:700 2.1mm/1 var(--label-font)}
.loc-ring.is-empty::before{content:"AC";font:400 1.45mm/1 var(--label-font);color:var(--ink-2);letter-spacing:-.05mm}
.shield-val .fit{text-align:center}
.mini td .fit,.mini th .fit{text-align:inherit}
.mini td{overflow:hidden}
.mini.reduce col.n{width:8mm}
.mini.reduce td{font-size:2.6mm}
.mini.colour td:first-child{font:italic 2.3mm var(--label-font);text-align:left}
.mini.immune td:first-child{font:400 2.3mm var(--label-font);text-align:left} /* upright, never italic */
/* Colour Resist rows are a little shorter than the other side tables, so up to 6 fit above the left arm */
.mini.colour tbody td{height:4.6mm}
/* Guilds off: the Social / Professional / Political boxes go and the notes lines take their space */
.sheet[data-guilds="off"] .standing{display:none}
.mini.colour td.num,.mini.immune td.num{font:700 2.5mm var(--label-font)}
.notes .fill{font:3mm/1.25 var(--label-font);white-space:pre-wrap;overflow:hidden;height:100%;padding:1mm 0}
.notes .lines{display:flex;flex-direction:column;min-height:0}
.panel.notes{min-height:0;overflow:hidden}
.notes.has-text .lines{background:none}
.pow .ph .k{display:block;align-self:stretch;text-align:center;padding:0 .5mm}
.pow .ph .sub{display:flex;align-items:center;justify-content:center;max-width:100%}
.pow-val{display:inline-block;min-width:4mm;max-width:8mm;overflow:hidden;white-space:nowrap;text-align:center;font:700 2.8mm var(--label-font);font-style:normal}
.res-box{flex:0 0 18mm;height:5.2mm;border:.25mm solid var(--ink);border-radius:.8mm;background:#fff;display:flex;align-items:center;justify-content:center;font:700 3.4mm var(--label-font);padding:0 .8mm}
.res-box .fit{text-align:center;width:100%}
.mini.immune thead th{white-space:normal;height:auto;line-height:1.15;padding:.6mm .4mm}
.notes{min-height:12mm}
.mini.immune thead th{white-space:normal;line-height:1.15;height:auto;padding:.7mm .4mm}
.armour{padding:0}
/* ---------- sheet colour themes (pastel washes; ink chosen for >= 12:1 contrast, secondary ink >= 5.9:1) ---------- */
.sheet[data-theme="parchment"]{--wm:#e3dacb;--tint:#f8f3e7;--tint-2:#efe4cc;--ink:#2a1c0f;--ink-2:#6a4e2e;--rule:#b9a27f;--hatch:#9b8565}
.sheet[data-theme="blue"]{--wm:#d3dce8;--tint:#eef4fb;--tint-2:#d8e5f4;--ink:#10213a;--ink-2:#3a5679;--rule:#9db2cc;--hatch:#7f97b6}
.sheet[data-theme="red"]{--wm:#e9d4d3;--tint:#fbf0ef;--tint-2:#f3dad8;--ink:#3a1212;--ink-2:#863636;--rule:#d0a3a0;--hatch:#b98480}
.sheet[data-theme="green"]{--wm:#d3dfd3;--tint:#eff7ee;--tint-2:#d8ebd6;--ink:#122a15;--ink-2:#345a39;--rule:#a1c3a3;--hatch:#7fa883}
.sheet[data-theme="yellow"]{--wm:#e4dfc2;--tint:#fcf8e2;--tint-2:#f4eabd;--ink:#2a2406;--ink-2:#5d4f0f;--rule:#c8b971;--hatch:#a99a55}
.sheet[data-theme="lavender"]{--wm:#ded7e7;--tint:#f5f1fa;--tint-2:#e5dbf2;--ink:#23143a;--ink-2:#5a417e;--rule:#b6a4ce;--hatch:#9584b0}
/* ---------- watermark layer: behind every box, visible only where the page colour shows ---------- */
.frame-in{position:relative}
.frame-in > :not(.wm){position:relative;z-index:1}
.wm{position:absolute;inset:0;width:100%;height:100%;z-index:0;color:var(--wm,#e2d9c9);pointer-events:none}
.ink-saver .wm{display:none}
/* ink-saver wins over any theme: white page, theme ink kept */
.ink-saver .sheet[data-theme]{--tint:#fff;--tint-2:#fff}
.sheet [hidden]{display:none !important}
.ph .opt-group{display:flex;align-items:stretch}
.ph .k.sep{border-left:.35mm solid var(--ink)}
.ph .box + .spacer{border-left:.35mm solid var(--ink)}
.ph .spacer + .k.sep{border-left:.35mm solid var(--ink)}
.ph .k.pts-k{font-size:2.8mm;letter-spacing:.3mm;padding:0 1.6mm}
.ph .k.pts-k::before,.ph .k.pts-k::after{content:none}
.ph .box.pts{flex:0 0 17mm}
/* Social / Professional / Political: three equal boxes; long text wraps, shrinks a little, then clips */
.standing{flex:none;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.4mm;margin:1.2mm 1.8mm .4mm;position:relative;z-index:1}
.sbox{height:6.3mm;border:.25mm solid var(--ink);border-radius:.8mm;background:#fff;padding:.35mm .9mm;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.sbox .sk{flex:none;font:700 1.8mm/1.1 var(--label-font);letter-spacing:.25mm;text-transform:uppercase;color:var(--ink-2)}
.sbox.filled .sk{display:none} /* the label is a placeholder: hidden once the box has text */
.sbox .sv{flex:1;min-height:0;font:italic 1.95mm/1.3 var(--label-font);overflow:hidden;overflow-wrap:anywhere;white-space:normal}
[data-section]{cursor:pointer}
@media print{[data-section]{cursor:auto}}
"""
css = css.strip() + "\n\n/* ---------- paper-doll positions ---------- */\n" + "\n".join(positions) + "\n" + extra
(APP / "assets/css/board.css").write_text("""/*
 * Generated from the battleboard print design.
 *
 * Styles for the printable A4 sheet (templates/board.php). Everything is sized in real millimetres
 * so the sheet prints at exactly A4 (210 x 297 mm). The page colours come from CSS variables
 * (--ink, --tint...) that each sheet theme overrides.
 *
 * app.js sets attributes on the sheet, and rules here react to them:
 *   data-theme      sheet colour palette        data-layout   board layout (row heights, lines)
 *   data-doll       which paper doll is drawn   data-pools    whether the three pools show
 *   data-power      Power box shown or not      data-imm      normal or compact Immunities rows
 * The [hidden] attribute (used by data-show) always hides an element on the sheet.
 *
 * This file is generated by the build script; change the generator and regenerate rather than
 * hand-editing, or the change will be lost on the next build.
 */
:where(.sheet, .sheet *){box-sizing:border-box;margin:0;padding:0}
""" + css + """

/* Immunities/Resistances continuation table: sits under the Resistance table (top is set by app.js) */
.mini.immune-over{left:0;right:auto}
/* Compact Immunities/Resistances rows (optional): shorter lines, same text size */
.sheet[data-imm="compact"] .mini.immune tbody td,.sheet[data-imm="compact"] .mini.immune tbody th{height:4mm}

/* =====================================================================================
 * "2 Page 15k+ nutjobs" format (data-format="twoPage" on both sheets). Page 1 keeps the normal header,
 * footer, shields, doll and side tables, but:
 *   left column   Powers box (two named powers, Total Heal Innate, True Believer; parts can be
 *                 switched off and the rest share its fixed height), optional pools, then Innates
 *                 down to Res Chance, which also shows Points; no Power box or Consumables
 *   right column  smaller shields, a 6 mm shorter doll (TP_PIECES), and the T.B.L.P. box under the
 *                 doll (app.js moves it there); no Notes box, no Guilds
 * Page 2 (#sheet-p2) has the name banner and footer, Immunities/Resistances (continued),
 * optional Innates (continued), Regeneration Powers, Damage Grades, Notes, Damage Reductions and
 * Additional Resistances.
 * ===================================================================================== */
.sheet[hidden]{display:none !important}
.sheet:not([data-format="twoPage"]) .tp-only{display:none !important}
.sheet[data-format="twoPage"] :is(#sheet-power,[data-section="consumables"],#sheet-notes,.immune-over,.ghost-stats){display:none !important}
.sheet[data-format="twoPage"] .armour .shield{zoom:.72}
.sheet[data-format="twoPage"]:not([data-doll="ghost"]) .doll{--doll-h:176mm}
.sheet[data-format="twoPage"]:not([data-doll="ghost"]) :is(.mini.resist,.mini.immune){top:100.5mm}
.sheet[data-format="twoPage"] #sheet-tblp{display:block;flex:1 1 auto;min-height:0;overflow:hidden}
.sheet[data-format="twoPage"] #sheet-tblp .row{height:4.4mm}
/* Powers box: each power is a heading row (name | value | free space) with its own lines */
.tp-powers{flex:none;box-sizing:border-box;display:flex;flex-direction:column;height:59.7mm}
.tp-powers .tp-part{display:contents}
.tp-powers .tp-part[hidden],.tp-powers .row[hidden]{display:none}
.tp-powers .ph{height:5.6mm;flex:none;margin-top:0}
.tp-powers .ph + .row,.tp-powers .row + .row{margin-top:0}
.tp-powers .tp-part ~ .tp-part > .ph{border-top:.35mm solid var(--ink)}
.tp-powers .ph .k{flex:0 0 49mm;min-width:0;justify-content:flex-start;overflow:hidden;font-size:3.1mm;letter-spacing:.3mm}
.tp-powers .ph .k .fit{flex:0 1 auto;min-width:0}
.tp-powers .row{height:auto;flex:1 0 4.6mm;min-height:0}
.tp-powers .row .fill{font:italic 3mm var(--label-font)}
.tp-powers .ph .box .fit{font-size:3.4mm}
/* Res Chance + Points share the bottom box */
.res-row .tp-only.k{margin-left:auto}
.sheet[data-format="twoPage"] .res-row .res-box{flex:0 0 14mm}
/* "Page 1 of 2" / "Page 2 of 2" at the right of the footer */
.foot{position:relative}
.foot .pg{position:absolute;right:2mm;bottom:.6mm;font:600 2.3mm var(--label-font);letter-spacing:.3mm;text-transform:uppercase;color:var(--ink-2)}
/* ---------- page 2 ---------- */
.preview-scale .sheet + .sheet{margin-top:8mm}
.sheet-p2 .head{height:21mm}
.sheet-p2 .body{grid-template-columns:92mm minmax(0,1fr);gap:3.5mm}
.sheet-p2 .col{gap:3.4mm}
.sheet-p2 .panel{flex:none;overflow:hidden}
.sheet-p2 .p2-row{height:4mm;padding:0 1.6mm}
.sheet-p2 .p2-row .fill{font:3mm var(--label-font);min-width:0}
.sheet-p2 .vrow{padding-right:0}
.sheet-p2 .vrow .vb{flex:0 0 9mm;align-self:stretch;border-left:.25mm solid var(--ink);display:flex;align-items:center;justify-content:center;font:700 2.8mm var(--label-font);min-width:0}
.sheet-p2 .vrow .vb .fit{text-align:center;width:100%}
.sheet-p2 .p2-imm .ph .k.sep{flex:0 0 9mm;justify-content:center;padding:0;letter-spacing:.3mm}
.sheet-p2 .p2-imm .ph .k.sep::before,.sheet-p2 .p2-imm .ph .k.sep::after{content:none}
.sheet-p2 .p2-notes .p2-lines{position:relative;margin:0 1.6mm;background:repeating-linear-gradient(to bottom,transparent 0 3.8mm,var(--rule) 3.8mm 4mm)}
.sheet-p2 .p2-notes .fill{display:block;height:100%;overflow:hidden;white-space:pre-wrap;font:3mm/4mm var(--label-font);padding-top:.5mm}
.sheet-p2 .p2-fill{flex:1 1 auto}
.sheet-p2 .panel[hidden],.sheet-p2 .row[hidden]{display:none}
.sheet-p2[data-cont="on"] .p2-imm{flex:none}
.sheet-p2[data-cont="on"] .p2-inn{flex:1 1 auto}
.sheet-p2 #sheet-p2-innates{--row-h:4mm}
""" + f".sheet-p2 .p2-notes .p2-lines{{height:{P2['notes'] * 4}mm}}\n", encoding="utf-8")

# ---------------------------------------------------------------- ghost doll ("Woo I'm a ghost")
GHOST_D = ("M0,121 L0,30.5 A30,30 0 0 1 60,30.5 L60,121 L52.5,113.5 L45,121 L37.5,113.5 L30,121 "
           "L22.5,113.5 L15,121 L7.5,113.5 Z")
def ghost_svg():
    cx, cy, sx, sy = 30, 60.75, (60 - 2.4) / 60, (120.5 - 2.4) / 120.5
    return f'''
  <g class="piece" id="piece-ghost">
    <clipPath id="clip-ghost"><path d="{GHOST_D}"/></clipPath>
    <path d="{GHOST_D}" class="piece-fill"/>
    <g clip-path="url(#clip-ghost)">
      <rect x="0" y="46" width="60" height="76" fill="url(#lines)"/>
      <rect x="57.4" y="0" width="2.6" height="122" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="{GHOST_D}" class="stitch" transform="translate({cx} {cy}) scale({sx:.4f} {sy:.4f}) translate({-cx} {-cy})"/>
    <path d="{GHOST_D}" class="piece-edge"/>
  </g>'''

# ---------------------------------------------------------------- template
def lined(count, key):
    return "".join(f'<div class="row"><span class="fill fit" data-bind="{key}.{i}"></span></div>' for i in range(count))

def shields():
    out = []
    for a in ["pac", "dac", "sac", "mac", "nac"]:
        out.append(f'''<div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">{a.upper()}</span><span class="shield-val"><span class="fit" data-bind="armour.{a}"></span></span>
        </div>''')
    return "\n        ".join(out)

def chips():
    out = []
    for pid, label, *_ in build.PIECES:
        out.append(f'<div class="chip" id="chip-{pid}"><span class="loc-label">loc</span>'
                   f'<span class="loc-box" data-bind="locations.{pid}.hits"></span>'
                   f'<span class="loc-ring" data-bind="locations.{pid}.ac" data-empty-class="is-empty"></span></div>')
        out.append(f'<div class="piece-name" id="pname-{pid}"><?= e($schema[\'locations\'][\'{pid}\']) ?></div>')
    return "\n        ".join(out)

resist_rows = "".join(
    f'<tr><th><?= e($label) ?></th><td><span class="fit" data-bind="resist.<?= e($key) ?>"></span></td></tr>')
reduce_rows = "".join(
    f'<tr><th><?= e($label) ?></th><td><span class="fit" data-bind="reductions.<?= e($key) ?>"></span></td></tr>')

corners = "".join(build.CORNER.format(cls=c) for c in ("tl", "tr", "bl", "br"))

def tp_powers():
    """Page-1 Powers box for the two-page format. Names, values and lines bind to state.twoPage.
    Each part can be switched off (data-show); the box keeps its height and app.js shows as many
    lines under each power as then fit (schema twoPage.powerBox), so the box always stays full."""
    out = []
    for n in range(2):
        show = ' data-show="twoPage.show.power2"' if n == 1 else ''
        out.append(f'<div class="tp-part" data-part="power{n + 1}"{show}><div class="ph"><span class="k"><span class="fit" data-bind="twoPage.powers.{n}.name" data-placeholder="Power {n + 1}"></span></span>'
                   f'<span class="box"><span class="fit" data-bind="twoPage.powers.{n}.value"></span></span><span class="spacer"></span></div>'
                   + "".join(f'<div class="row"><span class="fill fit" data-bind="twoPage.powers.{n}.lines.{i}"></span></div>' for i in range(TPS["powerLines"])) + '</div>')
    for key, label in (("totalHeal", "Total Heal Innate"), ("trueBeliever", "True Believer")):
        out.append(f'<div class="tp-part" data-part="{key}" data-show="twoPage.show.{key}"><div class="ph"><span class="k">{label}</span><span class="box"><span class="fit" data-bind="twoPage.{key}.value"></span></span><span class="spacer"></span></div>'
                   f'<div class="row"><span class="fill fit" data-bind="twoPage.{key}.line"></span></div></div>')
    return "\n        ".join(out)


def page2():
    """The second A4 page of the "2 Page 15k+ nutjobs" format (hidden unless that format is chosen)."""
    def lines_panel(sec, title, key, count):
        rows = "".join(f'<div class="row p2-row"><span class="fill fit" data-bind="twoPage.{key}.{i}"></span></div>' for i in range(count))
        return (f'<div class="panel" data-section="{sec}"><div class="ph"><span class="k">{title}</span><span class="spacer"></span></div>{rows}</div>')
    regen = "".join(f'<div class="row p2-row vrow"><span class="fill fit" data-bind="twoPage.regeneration.{i}.text"></span>'
                    f'<span class="vb"><span class="fit" data-bind="twoPage.regeneration.{i}.value"></span></span></div>' for i in range(P2["regeneration"]))
    return f"""<?php /* PAGE 2 ("2 Page 15k+ nutjobs" format only): same banner and footer, then the extra tables. Rows are fixed;
       the Immunities/Resistances rows that don't fit on page 1 continue here (app.js builds them). */ ?>
<div class="sheet sheet-p2" id="sheet-p2" data-theme="parchment" aria-label="Battleboard page 2 preview" hidden>
 <div class="frame">
  {corners}
  <div class="frame-in">
   <svg class="wm" id="sheet-wm-2" viewBox="0 0 194 281" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false" hidden><use width="194" height="281"></use></svg>
   <header class="head">
     <div class="title-wrap" data-section="identity">{build.BANNER}<h1 class="char-name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span></h1></div>
   </header>
   <main class="body">
    <section class="col">
      <div class="panel p2-imm p2-fill" data-section="immunities"><div class="ph"><span class="k">Immunities / Resistances</span><span class="spacer"></span><span class="k sep">Lvl</span></div><div id="sheet-p2-immunities"></div></div>
      <div class="panel p2-inn" data-section="innates" data-show="twoPage.show.innatesCont"><div class="ph"><span class="k">Innates (continued)</span><span class="box wide"></span></div><div id="sheet-p2-innates"></div></div>
      <div class="panel" data-section="tpRegen" id="sheet-p2-regen"><div class="ph"><span class="k">Regeneration Powers</span><span class="spacer"></span></div>{regen}</div>
    </section>
    <section class="col">
      {lines_panel("tpDamageGrades", "Damage Grades", "damageGrades", P2["damageGrades"])}
      <div class="panel p2-notes" data-section="notes"><div class="ph"><span class="k">Notes</span><span class="spacer"></span></div><div class="p2-lines" id="sheet-p2-notes"><span class="fill" data-bind="notes"></span></div></div>
      {lines_panel("tpDamageReductions", "Damage Reductions", "damageReductions", P2["damageReductions"])}
      {lines_panel("tpAdditional", "Additional Resistances", "additionalResistances", P2["additionalResistances"])}
    </section>
   </main>
   <footer class="foot"><span class="orn"></span><span class="dia">&#9670;</span>
     <span class="name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span><span class="dia">&#9670;</span><span class="orn"></span><span class="pg">Page 2 of 2</span></footer>
  </div>
 </div>
</div>"""

template = f"""<?php
/**
 * Battleboard sheet markup. Static structure only — all character data is written by app.js using textContent.
 *
 * HOW THE SHEET WORKS
 * This is the printable A4 page, laid out in real millimetres (see assets/css/board.css).
 * It is included by index.php and contains no character data. app.js fills it in using:
 *   data-bind="path"         the element's text is set to that value from the character
 *   data-placeholder="..."   text shown when the value is empty (e.g. "Character Name")
 *   data-show="path"         the element is only shown while that on/off setting is true
 *   data-section="name"      clicking it opens the matching section of the editor
 *   class="fit"/"fit-lines"  text that is shrunk to fit its box (one line / up to two lines)
 * The innate/consumable rows and the two small tables (Colour Resist, Immunities/Resistances)
 * are empty containers here; app.js builds their rows.
 * The data-layout, data-doll, data-pools... attributes app.js sets on #sheet let board.css switch
 * between board layouts and paper dolls without changing this markup.
 *
 * This file is generated (with board.css) by the build script from the print design, so to change
 * the structure, change the generator and regenerate, rather than hand-editing.
 */
declare(strict_types=1);
use function Battleboard\\e;
?>
<div class="sheet" id="sheet" data-theme="parchment" aria-label="Battleboard preview">
 <div class="frame">
  <?php /* Decorative double border and corner flourishes, then the watermark behind everything. */ ?>
  {corners}
  <div class="frame-in">
   <svg class="wm" id="sheet-wm" viewBox="0 0 194 281" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false" hidden><use width="194" height="281"></use></svg>
   <?php /* HEADER: name banner with alignment, then the Class(es) and Race(s) lines. */ ?>
   <header class="head">
     <div class="title-wrap" data-section="identity">{build.BANNER}<h1 class="char-name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span></h1></div>
     <div class="identity" data-section="identity">
       <label><span class="k">Class(es)</span><span class="fill fit-lines" data-bind="classes"></span></label>
       <label><span class="k">Race(s)</span><span class="fill fit-lines" data-bind="races"></span></label>
     </div>
   </header>

   <?php /* BODY: three columns. Left: T.B.L.P., Power, optional pools, Innates, Consumables, Res Chance. */ ?>
   <main class="body">
    <section class="col">
      <div class="panel" data-section="tblp" id="sheet-tblp">
        <div class="ph"><span class="k"><span class="tp-only">Life &#183;</span>T.B.L.P.</span><span class="box"><span class="fit" data-bind="tblp"></span></span><span class="opt-group" data-show="ltm.show"><span class="k sep">LTM</span><span class="box"><span class="fit" data-bind="ltm.value"></span></span></span><span class="spacer"></span></div>
        {lined(8, "tblpLines")}<div class="row cs-row" data-show="castingStam.show"><span class="cs-k">Casting Stam</span><span class="cs-v"><span class="fit" data-bind="castingStam.value"></span></span><span class="fill"></span></div>
      </div>
      <?php /* 2 Page 15k+ nutjobs only: the Powers box (two named powers with their lines, then Total Heal Innate and True Believer; app.js shows the lines that fit). */ ?>
      <div class="panel tp-powers tp-only" id="sheet-tp-powers" data-section="tpPowers">
        {tp_powers()}
      </div>
      <div class="panel" data-section="power" id="sheet-power">
        <div class="ph"><span class="k power-k">Power (<span class="ptype" data-bind="power.type"></span>)</span><span class="box"><span class="fit" data-bind="power.value"></span></span><span class="spacer"></span></div>
        {lined(5, "powerLines")}
      </div>
      <div class="pools" data-section="pools">
        {"".join(f'<div class="pool"><div class="pool-head"><span class="pn fit" data-bind="pools.{n}.name"></span><span class="pv"><span class="fit" data-bind="pools.{n}.value"></span></span></div><div class="pool-line"></div><div class="pool-line"></div><div class="pool-line"></div></div>' for n in range(3))}
      </div>
      <div class="panel" data-section="innates">
        <div class="ph"><span class="k">Innates</span><span class="box wide"></span></div>
        <div id="sheet-innates"></div>
      </div>
      <div class="panel" data-section="consumables" data-show="consumablesShow">
        <div class="ph"><span class="k">Consumables</span><span class="spacer"></span></div>
        <div id="sheet-consumables"></div>
      </div>
      <div class="panel res-panel" data-section="resChance">
        <div class="res-row"><span class="k">Res Chance</span>
          <span class="res-box"><span class="fit" data-bind="resChance"></span></span><span class="k tp-only">Points</span><span class="res-box tp-only"><span class="fit" data-bind="points"></span></span></div>
      </div>
    </section>

    <?php /* MIDDLE: the editable Pow column, a tall strip of boxes to tick off. */ ?>
    <section class="pow">
      <div class="ph" data-section="powB"><span class="k"><span class="fit" data-bind="powB.label"></span></span><span class="sub">(<span class="pow-val" data-bind="powB.value"></span>)</span></div>
      <div class="cells">{"<i></i>" * 38}</div>
    </section>

    <section class="col">
      <?php /* RIGHT: the five shields, then the paper doll with its location boxes and side tables, then Notes. */ ?>
      <div class="armour" data-section="armour">
        {shields()}
      </div>
      <?php /* PAPER DOLL: one SVG holds every body piece for all three dolls; CSS shows the pieces for the chosen doll. Each chip is a location's loc box and AC circle. */ ?>
      <div class="doll">
        <div data-section="locations">
        {build.doll_svg().replace("</svg>", tp_pieces_svg() + ghost_svg() + "</svg>")}
        {chips()}
        </div>
        <?php /* Ghost doll only: small T.B.L.P. / LTM / Casting Stam box inside the ghost (the normal T.B.L.P. box is hidden). */ ?>
        <div class="ghost-stats" data-section="tblp">
          <div class="gs-row"><span class="gs-k">T.B.L.P.</span><span class="gs-v"><span class="fit" data-bind="tblp"></span></span></div>
          <div class="gs-row" data-show="ltm.show"><span class="gs-k">LTM</span><span class="gs-v"><span class="fit" data-bind="ltm.value"></span></span></div>
          <div class="gs-row" data-show="castingStam.show"><span class="gs-k">Casting Stam</span><span class="gs-v"><span class="fit" data-bind="castingStam.value"></span></span></div>
        </div>
        <?php /* Side tables around the doll. Their positions change with the doll (see board.css and positionGhostTables() in app.js). */ ?>
        <table class="mini colour" data-section="colourResist"><colgroup><col><col class="n"></colgroup>
          <thead><tr><th>Colour Resist</th><th></th></tr></thead><tbody id="sheet-colour"></tbody></table>
        <table class="mini reduce" data-section="reductions"><colgroup><col><col class="n"></colgroup>
          <thead><tr><th>Reductions</th><th></th></tr></thead><tbody>
          <?php foreach ($schema['reductions'] as $key => $label): ?>{reduce_rows}<?php endforeach; ?>
          <tr data-show="combatWary.show"><th>Combat Wary</th><td><span class="fit" data-bind="combatWary.value"></span></td></tr>
          </tbody></table>
        <table class="mini resist" data-section="resist"><colgroup><col><col class="n"></colgroup>
          <thead><tr><th>Resistance</th><th>LvL</th></tr></thead><tbody>
          <?php foreach ($schema['resistances'] as $key => $label): ?>{resist_rows}<?php endforeach; ?>
          </tbody></table>
        <table class="mini immune" data-section="immunities"><colgroup><col><col class="n"></colgroup>
          <thead><tr><th colspan="2">Immunities/<wbr>Resistances</th></tr></thead><tbody id="sheet-immunities"></tbody></table>
        <?php /* Continuation of Immunities/Resistances (Default and Snow(man)flake dolls): rows beyond what the main table holds flow into the space under the Resistance table. */ ?>
        <table class="mini immune immune-over" data-section="immunities" hidden><colgroup><col><col class="n"></colgroup>
          <thead><tr><th colspan="2">Imms (cont.)</th></tr></thead><tbody id="sheet-immunities-over"></tbody></table>
      </div>
      <?php /* NOTES: heading with Points, the three small Social / Professional / Political boxes, then free notes. */ ?>
      <div class="panel notes" id="sheet-notes" data-section="notes">
        <div class="ph"><span class="k">Notes</span><span class="spacer"></span><span class="k sep pts-k">Points</span><span class="box pts"><span class="fit" data-bind="points"></span></span></div>
        <div class="standing">
          <div class="sbox"><span class="sk">Social</span><span class="sv fit-lines" data-bind="social"></span></div>
          <div class="sbox"><span class="sk">Professional</span><span class="sv fit-lines" data-bind="professional"></span></div>
          <div class="sbox"><span class="sk">Political</span><span class="sv fit-lines" data-bind="political"></span></div>
        </div>
        <div class="lines"><span class="fill" data-bind="notes"></span></div>
      </div>
    </section>
   </main>

   <?php /* FOOTER: the character's name and alignment again, at the bottom of the page. */ ?>
   <footer class="foot"><span class="orn"></span><span class="dia">&#9670;</span>
     <span class="name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span><span class="dia">&#9670;</span><span class="orn"></span><span class="pg tp-only">Page 1 of 2</span></footer>
  </div>
 </div>
</div>
{page2()}
"""
(APP / "templates/board.php").write_text(template, encoding="utf-8")
print("generated")
