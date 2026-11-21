"""Two-page board options: Powers box parts on/off, pools, Innates (continued) with page-2 line counts."""
import itertools, json
from playwright.sync_api import sync_playwright
from lib import *
S = json.load(open('/home/claude/app/BB/config/schema.json')); TP = S['twoPage']
def cont_rows(imm, regen):
    c = TP['page2Col']; panels = 1; used = c['panelMm10']
    if imm: panels += 1; used += c['panelMm10'] + imm * c['rowMm10']
    if regen: panels += 1; used += c['panelMm10'] + regen * c['rowMm10']
    used += (panels - 1) * c['gapMm10']
    return max(0, (c['heightMm10'] - used) // c['rowMm10'])
cases = [(parts, pools, None, "default") for parts in itertools.product([False, True], repeat=3) for pools in (False, True)]
cases += [((True, True, True), pools, rows, doll) for rows in [(40, 7), (0, 0), (50, 7), (42, 7), (10, 0), (0, 3), (25, 5)] for pools in (False, True) for doll in ("default", "ghost")]
fails = 0
with sync_playwright() as p:
    b = p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
    pg, errs = open_page(b)
    for parts, pools, rows, doll in cases:
        d = full_tp(doll); c = d['character']
        c['poolsShow'] = pools; c['pools'] = [{"name": "Faith", "value": 7}, {"name": "Chi", "value": 1}, {"name": "Rage", "value": 3}]
        c['innates'] = [{"name": f"Innate {i}", "count": (i % 8) + 1} for i in range(100)]
        c['immunities'] = [{"text": f"Imm {i}", "value": "Y"} for i in range(100)]
        c['twoPage']['powers'][0]['lines'] = [f"A{i}" for i in range(11)]
        c['twoPage']['show'] = {"power2": parts[0], "totalHeal": parts[1], "trueBeliever": parts[2], "innatesCont": rows is not None}
        if rows: c['twoPage']['rows'] = {"immunities": rows[0], "regeneration": rows[1]}
        load(pg, d); pg.wait_for_timeout(150)
        st = state(pg)
        probs = pg.evaluate(GEOM)
        imm, regen = rows if rows else (TP['page2']['immunities'], TP['page2']['regeneration'])
        cr = cont_rows(imm, regen) if rows else 0
        if len(st['innates']) != 31 + cr: probs.append(f"innates {len(st['innates'])} expected {31 + cr}")
        main = TP['ghostImmunities']['normal'] if doll == 'ghost' else S['immunitiesMainRows']['normal']
        if len(st['immunities']) != main + imm: probs.append(f"immunities {len(st['immunities'])} expected {main + imm}")
        g = pg.evaluate("""() => {
          const vis = (e) => !e.closest('[hidden]') && e.offsetParent !== null;
          const q = (s) => [...document.querySelectorAll(s)];
          const lines = {}; q('#sheet-tp-powers .tp-part').forEach(p => lines[p.dataset.part] = !p.hidden ? q(`#sheet-tp-powers [data-part=${p.dataset.part}] .row`).filter(vis).length : 0);
          return { lines, p1inn: q('#sheet-innates .row').length, p2inn: q('#sheet-p2-innates .row').filter(vis).length,
            p2innLast: q('#sheet-p2-innates .row .name').map(e => e.textContent).filter(Boolean).pop() || null,
            p2imm: q('#sheet-p2-immunities .row').filter(vis).length, regen: q('#sheet-p2-regen .row').filter(vis).length,
            pools: q('#sheet .pools').filter(vis).length, box: document.querySelector('#sheet-tp-powers').getBoundingClientRect().height / (document.querySelector('#sheet').getBoundingClientRect().width / 210) }
        }""")
        exp = pg.evaluate("tpPowerLinesForTest") if False else None
        if g['p1inn'] != 31: probs.append(f"page-1 innate rows {g['p1inn']}")
        if g['p2inn'] != cr: probs.append(f"page-2 innate rows {g['p2inn']} expected {cr}")
        if cr and g['p2innLast'] != st['innates'][-1]['name']: probs.append(f"last innate {g['p2innLast']}")
        if g['p2imm'] != imm: probs.append(f"page-2 immunities {g['p2imm']} expected {imm}")
        if g['regen'] != regen: probs.append(f"regen rows {g['regen']} expected {regen}")
        if g['pools'] != (1 if pools else 0): probs.append("pools visibility")
        if abs(g['box'] - 59.7) > 0.3: probs.append(f"powers box {g['box']:.1f} mm")
        heads = 1 + sum(parts); n = (592 - heads * 56) // 46 - parts[1] - parts[2]
        e2 = n // 2 if parts[0] else 0
        want = {"power1": min(11, n - e2), "power2": min(11, e2), "totalHeal": int(parts[1]), "trueBeliever": int(parts[2])}
        if g['lines'] != want: probs.append(f"power lines {g['lines']} expected {want}")
        tag = f"parts={parts} pools={pools} rows={rows} doll={doll}"
        if probs: fails += 1; print("FAIL", tag, probs[:6])
    print(f"two-page option combinations: {len(cases)}, failing: {fails}, js errors: {errs or 'none'}")
    b.close()
