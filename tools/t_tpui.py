"""Form controls for the two-page options: part switches, Innates (continued), page-2 line lists, cancel."""
import json
from playwright.sync_api import sync_playwright
from lib import *
P = []
def ok(cond, msg): print(("ok   " if cond else "FAIL ") + msg); cond or P.append(msg)
with sync_playwright() as p:
    b = p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
    dialogs = []
    pg, errs = open_page(b, dialogs=dialogs, accept=False)
    d = full_tp(); load(pg, d)
    pg.click('details[data-sec=tpPowers] > summary')
    pg.check('[data-path="twoPage.show.power2"]') if False else pg.uncheck('[data-path="twoPage.show.power2"]')
    st = state(pg)
    ok(st['twoPage']['show']['power2'] is False, "Power 2 switch off saved")
    ok(pg.evaluate("document.querySelector('[data-part=power2]').hidden"), "Power 2 hidden on the sheet")
    ok(not pg.is_visible('[data-path="twoPage.powers.1.name"]'), "Power 2 fields hidden in the form")
    vis_lines = pg.evaluate("[...document.querySelectorAll('[data-path^=\"twoPage.powers.0.lines.\"]')].filter(e=>!e.hidden).length")
    ok(vis_lines == 7, f"Power 1 shows 7 line fields (got {vis_lines})")
    pg.uncheck('[data-path="twoPage.show.totalHeal"]'); pg.uncheck('[data-path="twoPage.show.trueBeliever"]')
    vis_lines = pg.evaluate("[...document.querySelectorAll('[data-path^=\"twoPage.powers.0.lines.\"]')].filter(e=>!e.hidden).length")
    ok(vis_lines == 11, f"Power 1 alone shows 11 line fields (got {vis_lines})")
    pg.click('details[data-sec=innates] > summary')
    ok(pg.is_disabled('[data-path="twoPage.rows.immunities"]'), "line lists disabled while Innates (continued) is off")
    # 60 immunities filled: page 2 drops to 40 rows -> 8 filled rows lost -> asked; cancel
    dialogs.clear(); pg.click('[data-path="twoPage.show.innatesCont"]'); pg.wait_for_timeout(300)
    ok(len(dialogs) == 1 and 'Immunities' in dialogs[0], f"asked before losing Immunities rows: {dialogs}")
    ok(not pg.is_checked('[data-path="twoPage.show.innatesCont"]'), "cancel leaves the switch off")
    ok(not state(pg)['twoPage']['show']['innatesCont'], "cancel leaves the state unchanged")
    # accept
    pg.close(); pg, errs2 = open_page(b, dialogs=dialogs, accept=True); load(pg, d); pg.click('details[data-sec=innates] > summary')
    dialogs.clear(); pg.check('[data-path="twoPage.show.innatesCont"]'); pg.wait_for_timeout(400)
    st = state(pg)
    ok(st['twoPage']['show']['innatesCont'] and len(st["immunities"]) == 54, f"accept: switch on, immunities 54 (got {len(st['immunities'])})")
    ok(pg.is_visible('#sheet-p2 .p2-inn'), "Innates (continued) box on page 2")
    pg.select_option('[data-path="twoPage.rows.immunities"]', '20'); pg.wait_for_timeout(400)
    pg.select_option('[data-path="twoPage.rows.regeneration"]', '2'); pg.wait_for_timeout(400)
    st = state(pg)
    ok(st['twoPage']['rows'] == {"immunities": 20, "regeneration": 2}, f"line counts saved {st['twoPage']['rows']}")
    rows = pg.evaluate("document.querySelectorAll('#sheet-p2-innates .row').length")
    ok(rows == 33, f"Innates (continued) has 33 rows (got {rows})")
    for i in range(5): pg.click('button[data-add=innates]')
    st = state(pg); ok(len(st['innates']) == 36, f"adding innates goes past page 1 ({len(st['innates'])})")
    ok("33 Innates (continued) rows" in pg.text_content('#tp-cont-note'), pg.text_content('#tp-cont-note'))
    ok(not pg.evaluate(GEOM), f"layout ok {pg.evaluate(GEOM)[:3]}")
    # exported file round trip keeps the options
    with pg.expect_download() as dl: pg.click('button[data-action=export]')
    exp = json.load(open(dl.value.path()))['character']['twoPage']
    ok(exp['show']['innatesCont'] and exp['rows']['immunities'] == 20, "export keeps the options")
    pg.locator('#sheet-p2').screenshot(path='/tmp/ui_p2.png'); pg.locator('#sheet').screenshot(path='/tmp/ui_p1.png')
    print("problems:", P or "none", "| js errors:", (errs + errs2) or "none")
    b.close()
