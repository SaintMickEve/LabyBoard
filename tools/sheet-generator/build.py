#!/usr/bin/env python3
"""Generates battleboard.html — a printable fantasy LARP battleboard template."""
import base64, pathlib

HERE = pathlib.Path(__file__).parent
def font(name):
    return base64.b64encode((HERE / "fonts" / f"{name}.woff").read_bytes()).decode()

# ---------- helpers ----------
def pips(n=8):
    return '<span class="pips">' + "".join(
        f'<i data-n="{i}"><b>{i}</b></i>' for i in range(1, n + 1)) + "</span>"

def lined_rows(count, field):
    return "".join(f'<div class="row"><span class="fill" data-field="{field}.{i}"></span></div>'
                   for i in range(count))

def pip_rows(count, field):
    return "".join(
        f'<div class="row pip-row" data-field="{field}.{i}"><span class="fill name"></span>{pips()}</div>'
        for i in range(count))

# ---------- paper doll ----------
# All units are millimetres inside an 88 x 140 box.
PIECES = [
    # id, label, path, bbox (x, y, w, h), chip position (x, y) — head chip is centred on x
    # Sized to leave about 1.5 mm between each location and the tables around the doll
    # (Colour Resist and Reductions at the top, at their largest; Resistance, Immunities and the
    # Immunities continuation table at the sides). The arms reach out past the doll's 88 mm box
    # (the SVG draws with overflow visible): the left arm to about 1.5 mm from the POW column, the
    # right arm to about 2 mm from the page border. The legs end about 1.5 mm above the Notes box.
    ("head", "Head", "M28,30.3 L28,6.5 Q28,0.8 33.7,0.8 L54.3,0.8 Q60,0.8 60,6.5 L60,30.3 Q44,32.8 28,30.3 Z", (28, 0.8, 32, 31.4), (44, 2)),
    ("larm", "Left Arm", "M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,101 Q21.5,105 17.5,105 L3.7,105 Q-0.3,105 -0.3,101 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z", (-2.3, 35.7, 24.8, 69.3), (-0.6, 37.7)),
    ("chest", "Chest", "M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,75.2 L26,75.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z", (24.5, 34.2, 39, 41), (27, 36.2)),
    ("rarm", "Right Arm", "M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,101 Q66.5,105 70.5,105 L85.5,105 Q89.5,105 89.5,101 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z", (65.5, 35.7, 26.0, 69.3), (67.2, 37.7)),
    ("abdomen", "Abdomen", "M24.5,77.7 L63.5,77.7 L62,110.2 Q62,112.2 60,112.2 L28,112.2 Q26,112.2 26,110.2 Z", (24.5, 77.7, 39, 34.5), (27, 79.7)),
    ("lleg", "Left Leg", "M24,114.2 L43,114.2 L42,179.8 Q42,183.8 38,183.8 L29,183.8 Q25,183.8 25,179.8 Z", (24, 114.2, 19, 69.6), (25.6, 116.2)),
    ("rleg", "Right Leg", "M45,114.2 L64,114.2 L63,179.8 Q63,183.8 59,183.8 L50,183.8 Q46,183.8 46,179.8 Z", (45, 114.2, 19, 69.6), (46.6, 116.2)),
]

def doll_svg():
    defs = ['<defs>',
            '<pattern id="lines" width="4" height="5" patternUnits="userSpaceOnUse">'
            '<line x1="0" y1="4.9" x2="4" y2="4.9" class="writeline"/></pattern>',
            '<pattern id="hatch" width="1.1" height="1.1" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
            '<line x1="0" y1="0" x2="0" y2="1.1" class="hatchline"/></pattern>']
    body = []
    for pid, label, d, (x, y, w, h), _ in PIECES:
        cx, cy = x + w / 2, y + h / 2
        sx, sy = (w - 2.4) / w, (h - 2.4) / h
        defs.append(f'<clipPath id="clip-{pid}"><path d="{d}"/></clipPath>')
        body.append(f'''
  <g class="piece" id="piece-{pid}">
    <path d="{d}" class="piece-fill"/>
    <g clip-path="url(#clip-{pid})">
      <rect x="{x}" y="{y + 9}" width="{w}" height="{h}" fill="url(#lines)"/>
      <rect x="{x + w - 2.6}" y="{y}" width="2.6" height="{h}" fill="url(#hatch)" class="shade"/>
      <rect x="{x}" y="{y + h - 1.8}" width="{w}" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="{d}" class="stitch" transform="translate({cx} {cy}) scale({sx:.4f} {sy:.4f}) translate({-cx} {-cy})"/>
    <path d="{d}" class="piece-edge"/>
  </g>''')
    defs.append('</defs>')
    return ('<svg class="doll-svg" viewBox="0 0 88 182" width="88mm" height="182mm" aria-hidden="true">'
            + "".join(defs) + "".join(body) + "</svg>")

def doll_overlays():
    out = []
    for pid, label, _, (x, y, w, h), (chx, chy) in PIECES:
        out.append(
            (f'<div class="chip" style="left:{chx}mm;top:{chy}mm;transform:translateX(-50%)">' if pid == "head" else f'<div class="chip" style="left:{chx}mm;top:{chy}mm">')
            +
            f'<span class="loc-label">loc</span><span class="loc-box fill" data-field="loc.{pid}"></span>'
            f'<span class="loc-ring fill" data-field="ring.{pid}"></span></div>')
        ly = y + h - (6.2 if pid in ("lleg", "rleg") else 5.2)
        out.append(f'<div class="piece-name" style="left:{x}mm;top:{ly}mm;width:{w}mm">{label}</div>')
    return "".join(out)

RES = ["E Power", "Magic", "Neuronic", "Physical", "Spirits"]
def resist_table():
    rows = "".join(f'<tr><th>{r}</th><td class="fill" data-field="resist.{r.lower().replace(" ", "_")}">8</td></tr>'
                   for r in RES)
    return f'<table class="mini resist"><colgroup><col><col class="n"></colgroup><thead><tr><th>Resistance</th><th>LvL</th></tr></thead><tbody>{rows}</tbody></table>'

def colour_table():
    rows = "".join(f'<tr><td class="fill" data-field="colour.{i}.name"></td><td class="fill num" data-field="colour.{i}.lvl"></td></tr>' for i in range(3))
    return f'<table class="mini colour"><colgroup><col><col class="n"></colgroup><thead><tr><th>Colour Resist</th><th></th></tr></thead><tbody>{rows}</tbody></table>'

RED = ["Numerical", "Percentile", "Fractional"]
def reduce_table():
    rows = "".join(f'<tr><th>{r}</th><td class="fill" data-field="reduce.{r.lower()}"></td></tr>' for r in RED)
    return f'<table class="mini reduce"><colgroup><col><col class="n"></colgroup><thead><tr><th>Reductions</th><th></th></tr></thead><tbody>{rows}</tbody></table>'

def immune_table():
    rows = "".join(f'<tr><td class="fill" data-field="immune.{i}.name"></td><td class="fill num" data-field="immune.{i}.lvl"></td></tr>'
                   for i in range(4))
    return f'<table class="mini immune"><colgroup><col><col class="n"></colgroup><thead><tr><th colspan="2">Immunities</th></tr></thead><tbody>{rows}</tbody></table>'

def shields():
    s = []
    for a in ["PAC", "DAC", "SAC", "MAC"]:
        s.append(f'''<div class="shield">
  <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/>
  <path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
  <span class="shield-label">{a}</span><span class="shield-val fill" data-field="armour.{a.lower()}"></span></div>''')
    return "".join(s)

CORNER = ('<svg class="corner {cls}" viewBox="0 0 20 20" aria-hidden="true">'
          '<path d="M1,19 L1,6 Q1,1 6,1 L19,1" class="c1"/>'
          '<path d="M3.5,19 L3.5,7.5 Q3.5,3.5 7.5,3.5 L19,3.5" class="c2"/>'
          '<path d="M6,6 m-2.2,0 a2.2,2.2 0 1,0 4.4,0 a2.2,2.2 0 1,0 -4.4,0" class="c3"/>'
          '<path d="M10,6 Q13,3.5 16,6 Q13,8.5 10,6 Z M6,10 Q3.5,13 6,16 Q8.5,13 6,10 Z" class="c4"/></svg>')

BANNER = '''<svg class="banner" viewBox="0 0 160 22" preserveAspectRatio="none" aria-hidden="true">
  <path class="b-tail" d="M2,6 L16,6 L16,20 L2,20 L7,13 Z"/>
  <path class="b-tail" d="M158,6 L144,6 L144,20 L158,20 L153,13 Z"/>
  <path class="b-fold" d="M16,20 L22,17 L16,17 Z M144,20 L138,17 L144,17 Z"/>
  <path class="b-main" d="M16,2 Q80,-1 144,2 L144,17 Q80,14 16,17 Z"/>
  <path class="b-rule" d="M19,4 Q80,1.2 141,4 M19,15 Q80,12.2 141,15"/>
</svg>'''

css = r"""
@font-face{font-family:"BB Chorus";src:url(data:font/woff;base64,%(chorus)s) format("woff");}
@font-face{font-family:"BB Pagella";src:url(data:font/woff;base64,%(preg)s) format("woff");font-weight:400;}
@font-face{font-family:"BB Pagella";src:url(data:font/woff;base64,%(pbold)s) format("woff");font-weight:700;}
@font-face{font-family:"BB Pagella";src:url(data:font/woff;base64,%(pital)s) format("woff");font-style:italic;}

:root{
  --ink:#2a1c0f;        /* near-black brown: lines & text      */
  --ink-2:#6a4e2e;      /* secondary ink                       */
  --rule:#b9a27f;       /* faint writing lines                 */
  --tint:#f8f3e7;       /* parchment wash (very light)         */
  --tint-2:#efe4cc;     /* header wash                         */
  --title-font:"BB Chorus","Cinzel Decorative",cursive;
  --label-font:"BB Pagella","Palatino Linotype",Palatino,serif;
}
/* Ink-saver: strips every tint so only line-work prints */
body.ink-saver{--tint:#fff;--tint-2:#fff;}
body.ink-saver .shade{display:none}

*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{background:#8d8273;font-family:var(--label-font);color:var(--ink)}
@page{size:A4;margin:0}

.toolbar{position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;padding:10px;background:#3b2f24}
.toolbar button{font:600 14px var(--label-font);padding:6px 14px;border-radius:4px;border:1px solid #c9b48c;background:#f3e9d2;color:#2a1c0f;cursor:pointer}
@media print{.toolbar{display:none} body{background:none}}

.sheet{width:210mm;height:297mm;margin:12px auto;background:#fff;padding:6mm;position:relative;box-shadow:0 4px 24px rgba(0,0,0,.35)}
@media print{.sheet{margin:0;box-shadow:none}}

.frame{position:relative;height:100%%;border:.6mm solid var(--ink);padding:1mm}
.frame-in{height:100%%;border:.2mm solid var(--ink);background:var(--tint);padding:4mm 5mm 3mm;display:flex;flex-direction:column;gap:3.2mm}
.corner{position:absolute;width:11mm;height:11mm;fill:none;stroke:var(--ink)}
.corner .c1{stroke-width:.9}.corner .c2{stroke-width:.35}.corner .c3{stroke-width:.4;fill:#fff}
.corner .c4{fill:var(--ink);stroke:none}
.corner.tl{top:-.9mm;left:-.9mm}.corner.tr{top:-.9mm;right:-.9mm;transform:scaleX(-1)}
.corner.bl{bottom:-.9mm;left:-.9mm;transform:scaleY(-1)}.corner.br{bottom:-.9mm;right:-.9mm;transform:scale(-1,-1)}

/* ---------- header ---------- */
.head{display:flex;flex-direction:column;align-items:center}
.title-wrap{position:relative;width:158mm;height:21mm}
.banner{position:absolute;inset:0;width:100%%;height:100%%}
.b-main{fill:#fff;stroke:var(--ink);stroke-width:.45}
.b-tail{fill:var(--tint-2);stroke:var(--ink);stroke-width:.45;stroke-linejoin:round}
.b-fold{fill:var(--ink-2);stroke:var(--ink);stroke-width:.3}
.b-rule{fill:none;stroke:var(--ink-2);stroke-width:.15}
.char-name{position:absolute;left:22mm;right:22mm;top:1.2mm;height:15.5mm;display:flex;align-items:center;justify-content:center;
  font:400 12.5mm/1 var(--title-font);white-space:nowrap;overflow:hidden}
.char-name:empty::before{content:"Character Name";color:var(--ink)}
.identity{display:flex;gap:6mm;width:150mm;margin-top:1mm}
.identity label{flex:1;display:flex;align-items:baseline;gap:2mm;border-bottom:.3mm solid var(--ink);padding-bottom:.4mm}
.identity .k{font:700 3.2mm var(--label-font);letter-spacing:.5mm;text-transform:uppercase}
.identity .fill{flex:1;font:italic 4mm var(--label-font);min-height:4.5mm}

/* ---------- body grid ---------- */
.body{flex:1;display:grid;grid-template-columns:78mm 11mm 88.4mm;gap:3.5mm;min-height:0}
.col{display:flex;flex-direction:column;gap:3.4mm;min-height:0}

.panel{border:.35mm solid var(--ink);background:#fff;position:relative}
.panel::after{content:"";position:absolute;inset:.7mm;border:.12mm solid var(--rule);pointer-events:none}
.ph{display:flex;align-items:stretch;height:6.4mm;border-bottom:.35mm solid var(--ink);background:var(--tint-2);position:relative;z-index:1}
.ph .k{display:flex;align-items:center;gap:1.4mm;padding:0 2.4mm;font:700 3.3mm var(--label-font);letter-spacing:.45mm;text-transform:uppercase;white-space:nowrap}
.ph .k::before,.ph .k::after{content:"\25C6";font-size:1.7mm;color:var(--ink-2)}
.ph .box{border-left:.35mm solid var(--ink);background:#fff;flex:0 0 13mm;display:flex;align-items:center;justify-content:center;font:700 3.6mm var(--label-font)}
.ph .box.wide{flex:1}
.ph .spacer{flex:1}

.row{height:6.05mm;border-bottom:.2mm solid var(--rule);display:flex;align-items:center;padding:0 1.8mm;position:relative;z-index:1}
.row:last-child{border-bottom:none}
.row .fill{flex:1;font:italic 3.3mm var(--label-font);white-space:nowrap;overflow:hidden}

.pips{display:flex;gap:.55mm;margin-left:1mm}
.pips i{width:3.9mm;height:3.9mm;border:.25mm solid var(--ink);border-radius:.8mm;background:#fff;position:relative;display:block}
.pips i b{position:absolute;right:.25mm;bottom:-.1mm;font:400 1.75mm/1 var(--label-font);color:var(--ink-2)}
/* boxes beyond a power's uses: light hatch (printer friendly, replaces solid black) */
.pips i.off{background:repeating-linear-gradient(45deg,var(--tint-2,#efe4cc) 0 .28mm,var(--ink-2,#6a4e2e) .28mm .52mm);border-color:var(--ink)}
.pips i.off b{display:none}

.res-row{display:flex;align-items:center;height:7.4mm;padding:0 1.8mm;gap:1mm;z-index:1;position:relative}
.res-row .k{font:700 3.2mm var(--label-font);letter-spacing:.35mm;text-transform:uppercase;flex:1;white-space:nowrap}
.res-row .pct{display:flex;gap:.45mm}
.res-row .pct i{width:4.7mm;height:5mm;border:.25mm solid var(--ink);border-radius:.8mm;background:#fff;font:400 2mm/1 var(--label-font);color:var(--ink-2);
  display:flex;align-items:flex-end;justify-content:center;padding-bottom:.3mm;font-style:normal}
.res-row .pct i.on{background:repeating-linear-gradient(45deg,#fff 0 .45mm,#9b8565 .45mm .6mm)}

/* Pow (B) tower */
.pow{display:flex;flex-direction:column;border:.35mm solid var(--ink);background:#fff;height:100%%}
.pow .ph{height:auto;flex-direction:column;align-items:center;padding:1.2mm 0;gap:0}
.pow .ph .k{padding:0;font-size:3.2mm;letter-spacing:.2mm}
.pow .ph .k::before,.pow .ph .k::after{content:none}
.pow .ph .sub{font:italic 2.8mm var(--label-font)}
.pow .cells{flex:1;display:flex;flex-direction:column;padding:1mm 1.6mm;gap:.6mm}
.pow .cells i{flex:1;border:.22mm solid var(--ink);border-radius:.6mm;display:block;background:#fff}
.pow .cells i:nth-child(5n){border-bottom-width:.6mm}

/* ---------- right column ---------- */
.armour{display:flex;justify-content:space-around;padding:0 2mm}
.shield{position:relative;width:14.5mm;height:16mm}
.shield svg{position:absolute;inset:0;width:100%%;height:100%%}
.shield-fill{fill:var(--tint-2);stroke:var(--ink);stroke-width:.5}
.shield-in{fill:#fff;stroke:var(--ink);stroke-width:.2}
.shield-label{position:absolute;top:2.4mm;left:0;right:0;text-align:center;font:700 2.4mm var(--label-font);letter-spacing:.3mm}
.shield-val{position:absolute;top:5.6mm;left:2.4mm;right:2.4mm;height:5.4mm;border-bottom:.2mm solid var(--rule);
  text-align:center;font:700 4mm/5.4mm var(--label-font)}

.doll{position:relative;width:88mm;height:var(--doll-h,182mm);margin:0 auto;flex:none}
.doll-svg{position:absolute;inset:0;overflow:visible}
.piece-fill{fill:#fff}
.piece-edge{fill:none;stroke:var(--ink);stroke-width:.55;stroke-linejoin:round}
.stitch{fill:none;stroke:var(--ink-2);stroke-width:.18;stroke-dasharray:1 .7}
.writeline{stroke:var(--rule);stroke-width:.2}
.hatchline{stroke:var(--ink-2);stroke-width:.14;opacity:.75}

.chip{position:absolute;display:flex;align-items:center;gap:.55mm;height:4.8mm;padding:0 .7mm;background:#fff;border:.3mm solid var(--ink);border-radius:2.5mm}
.loc-label{font:700 2.6mm var(--label-font);letter-spacing:.2mm}
.loc-box{width:5.2mm;height:3.6mm;border:.22mm solid var(--ink);border-radius:.5mm;font:700 2.8mm/3.4mm var(--label-font);text-align:center}
.loc-ring{width:3.7mm;height:3.7mm;border:.3mm solid var(--ink);border-radius:50%%;font:700 2.2mm/3.4mm var(--label-font);text-align:center}
.piece-name{position:absolute;text-align:center;font:italic 2.7mm var(--label-font);color:var(--ink-2);letter-spacing:.1mm}

.mini{position:absolute;border-collapse:collapse;table-layout:fixed;background:#fff;border:.35mm solid var(--ink);width:22.5mm;font-family:var(--label-font)}
.mini th,.mini td{border:.18mm solid var(--ink);height:5.5mm;padding:0 .9mm}
.mini thead th{background:var(--tint-2);font:700 2.1mm var(--label-font);letter-spacing:0;padding:0 .4mm;text-transform:uppercase;text-align:center}
.mini tbody th{font:400 2.5mm var(--label-font);text-align:right;white-space:nowrap}
.mini td{font:700 3mm var(--label-font);text-align:center}
.mini col.n{width:6mm}
.mini.immune td:first-child{width:auto;text-align:left;font:400 2.7mm var(--label-font)}
.mini.resist{left:0;top:106.5mm}
.mini.immune{right:0;top:106.5mm}
.mini.reduce{right:0;top:.5mm;width:25mm}
.mini.colour{left:0;top:.5mm;width:25mm}
.mini.colour td:first-child{width:auto;text-align:left;font:italic 2.7mm var(--label-font)}

.notes{flex:1;display:flex;flex-direction:column}
.notes .lines{flex:1;position:relative;z-index:1;margin:0 1.8mm;
  background:repeating-linear-gradient(to bottom,transparent 0 5.5mm,var(--rule) 5.5mm 5.75mm)}
.notes .fill{display:block;padding:.6mm 0;font:italic 3.3mm/5.75mm var(--label-font);white-space:pre-wrap}

.foot{display:flex;align-items:center;justify-content:center;gap:3mm;height:8mm;border-top:.35mm solid var(--ink);padding-top:.8mm}
.foot .orn{flex:1;height:0;border-top:.2mm solid var(--ink-2);position:relative}
.foot .name{font:400 7mm/1 var(--title-font)}
.foot .name:empty::before{content:"Character Name"}
.foot .dia{font-size:2.4mm;color:var(--ink-2)}
"""

html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Battleboard</title>
<style>{css % {"chorus": font("chorus-mediumitalic"), "preg": font("pagella-regular"),
                "pbold": font("pagella-bold"), "pital": font("pagella-italic")}}</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Print battleboard</button>
  <button type="button" onclick="document.body.classList.toggle('ink-saver')">Toggle ink-saver</button>
</div>

<div class="sheet">
 <div class="frame">
  {CORNER.format(cls="tl")}{CORNER.format(cls="tr")}{CORNER.format(cls="bl")}{CORNER.format(cls="br")}
  <div class="frame-in">

   <header class="head">
     <div class="title-wrap">{BANNER}<h1 class="char-name fill" data-field="name"></h1></div>
     <div class="identity">
       <label><span class="k">Class</span><span class="fill" data-field="class"></span></label>
       <label><span class="k">Race</span><span class="fill" data-field="race"></span></label>
     </div>
   </header>

   <main class="body">
    <section class="col">
      <div class="panel">
        <div class="ph"><span class="k">T.B.L.P.</span><span class="box fill" data-field="tblp"></span><span class="spacer"></span></div>
        {lined_rows(8, "tblp_line")}
      </div>
      <div class="panel">
        <div class="ph"><span class="k">Power (A)</span><span class="box fill" data-field="power_a.0"></span><span class="box wide fill" data-field="power_a.1"></span></div>
        {lined_rows(5, "power_line")}
      </div>
      <div class="panel">
        <div class="ph"><span class="k">Innates</span><span class="box wide fill" data-field="innates_note"></span></div>
        {pip_rows(13, "innate")}
      </div>
      <div class="panel">
        <div class="ph"><span class="k">Consumables</span><span class="spacer"></span></div>
        {pip_rows(3, "consumable")}
      </div>
      <div class="panel">
        <div class="res-row"><span class="k">Res Chance</span>
          <span class="pct" data-field="res_chance">{"".join(f"<i>{v}</i>" for v in range(0, 90, 10))}</span></div>
      </div>
    </section>

    <section class="pow">
      <div class="ph"><span class="k">Pow</span><span class="sub">(B)</span></div>
      <div class="cells" data-field="pow_b">{"<i></i>" * 38}</div>
    </section>

    <section class="col">
      <div class="armour">{shields()}</div>
      <div class="doll">
        {doll_svg()}
        {doll_overlays()}
        {colour_table()}
        {reduce_table()}
        {resist_table()}
        {immune_table()}
      </div>
      <div class="panel notes">
        <div class="ph"><span class="k">Notes</span><span class="spacer"></span></div>
        <div class="lines"><span class="fill" data-field="notes"></span></div>
      </div>
    </section>
   </main>

   <footer class="foot"><span class="orn"></span><span class="dia">&#9670;</span>
     <span class="name fill" data-field="name"></span><span class="dia">&#9670;</span><span class="orn"></span></footer>
  </div>
 </div>
</div>

<script>
/*
 * Fill the board from a plain object. Everything is written with textContent,
 * so user-supplied strings can never inject markup.
 *   fillBoard({{ name:"Aldric", class:"Warrior", innate:[{{name:"Heal", uses:3}}], res_chance:40, ... }})
 * Flat keys match data-field attributes (e.g. "loc.head", "armour.pac", "resist.magic").
 */
function fillBoard(d) {{
  const set = (key, val) => document.querySelectorAll('[data-field="' + key + '"]')
      .forEach(el => {{ el.textContent = val == null ? '' : String(val); }});
  const flat = (obj, pre) => Object.entries(obj).forEach(([k, v]) => {{
    const key = pre ? pre + '.' + k : k;
    if (['innate', 'consumable'].includes(key) && Array.isArray(v)) {{
      v.forEach((item, i) => {{
        const row = document.querySelector('[data-field="' + key + '.' + i + '"]');
        if (!row) return;
        row.querySelector('.name').textContent = item.name || '';
        const uses = Number.isInteger(item.uses) ? item.uses : 8;
        row.querySelectorAll('.pips i').forEach((p, n) => p.classList.toggle('off', n >= uses));
      }});
    }} else if (key === 'res_chance') {{
      document.querySelectorAll('[data-field="res_chance"] i')
        .forEach((p, n) => p.classList.toggle('on', n * 10 <= Number(v)));
    }} else if (key === 'pow_b') {{
      document.querySelectorAll('[data-field="pow_b"] i')
        .forEach((p, n) => p.style.visibility = n < Number(v) ? '' : 'hidden');
    }} else if (Array.isArray(v)) {{
      v.forEach((x, i) => (x && typeof x === 'object') ? flat(x, key + '.' + i) : set(key + '.' + i, x));
    }} else if (v && typeof v === 'object') {{
      flat(v, key);
    }} else {{
      set(key, v);
    }}
  }});
  flat(d, '');
}}
if (window.BATTLEBOARD_DATA) fillBoard(window.BATTLEBOARD_DATA);
</script>
</body>
</html>
"""
(HERE / "battleboard.html").write_text(html, encoding="utf-8")
print("ok", len(html))
