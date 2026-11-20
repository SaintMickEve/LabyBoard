/*!
 * Battleboard — single page editor for a printable LARP battleboard.
 * No dependencies. All character data is written to the page with textContent,
 * never innerHTML, and every value is sanitised against config/schema.json here
 * and again on the server (src/Sanitiser.php) when importing.
 *
 * HOW THE APP WORKS (the big picture)
 *
 *  1. STATE. The whole character is one plain object, `state`, shaped exactly like the
 *     "character" part of an exported JSON file. It is the single source of truth: the form
 *     and the sheet are both drawn from it.
 *
 *  2. EDITING. Every form field has data-path="some.path" (see index.php). When the user types,
 *     onFieldInput() cleans the value, writes it into `state` at that path, then calls changed().
 *     The repeatable lists (innates, consumables, Colour Resist, Immunities) are built from row
 *     templates and update their row objects in `state` directly.
 *
 *  3. DRAWING THE SHEET. changed() asks for one redraw on the next animation frame (so typing
 *     quickly doesn't redraw for every key). renderSheet() writes each value into the matching
 *     data-bind element of the sheet with textContent, shows or hides optional parts, then
 *     fitAll() shrinks any text that would overflow its box.
 *
 *  4. SAVING. scheduleSave() stores `state` in the browser's localStorage 0.4 s after the last
 *     change. Nothing is sent to the server except during an import.
 *
 *  5. LAYOUTS AND DOLLS. Board layouts, paper dolls and optional tables change how many rows fit.
 *     The apply...() functions switch them safely: they trim only blank rows, and ask before
 *     removing any row with something in it. "Fill" layouts top up blank rows to the space left.
 *
 *  6. IMPORT / EXPORT. Export downloads `state` as pretty JSON. Import reads a file, sends it to
 *     api.php to be cleaned (or cleans it here if the server can't be reached), then replaces
 *     `state` and redraws everything.
 *
 * The code is wrapped in one function so nothing leaks into the global scope.
 */
(() => {
  'use strict';

  /* ---- Settings read from the page (put there by index.php) ---- */
  // The schema: every limit and option, identical to the copy the server uses.
  const schema = JSON.parse(document.getElementById('bb-schema').textContent);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const API_URL = document.querySelector('meta[name="bb-api"]').content;
  const WM_URL = document.querySelector('meta[name="bb-watermarks"]').content; // cached SVG file of all designs
  // Where the board is saved, in words: the Windows desktop app (Tauri) keeps it on the computer.
  const SAVED_WHERE = window.__TAURI__ ? 'on this computer' : 'in this browser';

  // localStorage keys: the saved board, and the ink-saver preference.
  const STORE_KEY = 'battleboard.v1';
  const INK_KEY = 'battleboard.inkSaver';
  const MAX_IMPORT_BYTES = 131072;
  const PIP_LIST_KEYS = ['innates', 'consumables'];
  // Kit and Equipment, on the optional last page: pip rows like Consumables, in their own space.
  const KIT_KEYS = ['kit', 'equipment'];
  const isPipList = (key) => PIP_LIST_KEYS.includes(key) || KIT_KEYS.includes(key);
  const COL_GAP_MM = 3.4; // gap between panels in the left column

  /* ---- Tiny DOM helpers ---- */
  // $ finds one element, $$ finds all as an array, el() creates an element with a class and text.
  // el() only ever sets text with textContent, so character data can never become HTML.
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  };

  /* ===================================================================
   * Sanitiser (mirrors src/Sanitiser.php)
   * =================================================================== */
  // Same invisible/control characters the PHP sanitiser removes (see src/Sanitiser.php STRIP).
  const STRIP = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F​-‏‪-‮⁠-⁩﻿]/g;
  const FRACTION = new RegExp(schema.fractionPattern);
  const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
  // Length and slicing by Unicode characters (code points), as PHP's mb_ functions count them,
  // so emoji and accented letters count as one character in both places.
  const cpLength = (s) => Array.from(s).length;
  const cpSlice = (s, n) => Array.from(s).slice(0, n).join('');
  /**
   * Rows in the Innates (continued) box on page 2: what is left of the left column after the shown
   * Immunities/Resistances and Regeneration Powers rows (schema twoPage.page2Col, tenths of a mm).
   * A table with 0 rows isn't shown, so its heading and gap are freed too. Same sums in PHP.
   */
  /** Kit and Equipment rows that fit under a Damage Sheet table of `rows` rows (schema damageSheet.kitFit). Same sum in PHP. */
  const kitMax = (rows) => {
    const f = schema.damageSheet.kitFit;
    return Math.max(0, Math.floor((f.heightMm10 - rows * f.dmgRowMm10) / f.kitRowMm10));
  };
  const innatesContRows = (TP, imm, regen) => {
    const c = TP.page2Col;
    let panels = 1;
    let used = c.panelMm10;
    if (imm > 0) { panels++; used += c.panelMm10 + imm * c.rowMm10; }
    if (regen > 0) { panels++; used += c.panelMm10 + regen * c.rowMm10; }
    used += (panels - 1) * c.gapMm10;
    return Math.max(0, Math.floor((c.heightMm10 - used) / c.rowMm10));
  };

  /**
   * Cleans a character (from an imported file, from localStorage, or before export).
   * Returns { data, warnings }: a complete, valid character, plus human-readable notes about
   * anything that had to be changed. This is a line-for-line twin of Sanitiser::sanitise() in
   * src/Sanitiser.php, so results are identical whichever side does the cleaning. Keep them in step.
   * The small helpers below (text, int, oneOf, rows...) each clean one kind of value; the
   * character is then rebuilt from scratch using only them.
   */
  function sanitise(input) {
    const warnings = [];
    const warn = (m) => { if (warnings.length < 50) warnings.push(m); };
    const T = schema.text, I = schema.int, R = schema.rows;

    const text = (value, max, label, multiline = false) => {
      if (Number.isSafeInteger(value)) value = String(value);
      if (typeof value !== 'string') {
        if (value != null) warn(`${label}: expected text, so it was cleared.`);
        return '';
      }
      let v = value.normalize('NFC').replace(/\r\n?/g, '\n').replace(/\t/g, ' ').replace(STRIP, '');
      v = multiline ? v.replace(/\n{3,}/g, '\n\n') : v.replace(/\s+/g, ' ');
      v = v.trim();
      if (cpLength(v) > max) {
        v = cpSlice(v, max).trimEnd();
        warn(`${label}: shortened to ${max} characters.`);
      }
      return v;
    };
    const int = (value, [min, max], label) => {
      if (value === null || value === undefined || value === '') return null;
      if (typeof value === 'string' && /^\s*\d{1,6}\s*$/.test(value)) value = parseInt(value, 10);
      if (!Number.isInteger(value) || value < min || value > max) {
        warn(`${label}: must be a whole number from ${min} to ${max}, so it was cleared.`);
        return null;
      }
      return value;
    };
    const oneOf = (value, allowed, label) => {
      if (value === '' || value == null) return '';
      const hit = typeof value === 'string' && allowed.find((o) => o.toLowerCase() === value.trim().toLowerCase());
      if (!hit) warn(`${label}: must be one of ${allowed.join(', ')}, so it was cleared.`);
      return hit || '';
    };
    // PHP decodes JSON objects and arrays alike, so treat an object's values as a list here too.
    const asList = (v) => (Array.isArray(v) ? v : isObj(v) ? Object.values(v) : []);
    const fixedLines = (lines, count, max, label) => {
      lines = asList(lines);
      if (lines.length > count) warn(`${label}s: only ${count} lines fit, extra lines were dropped.`);
      return Array.from({ length: count }, (_, n) => text(lines[n] ?? '', max, `${label} ${n + 1}`));
    };
    const rows = (list, max, label) => {
      list = asList(list).filter((v) => v !== null && typeof v === 'object'); // [] and {} both count as a row, as in PHP
      if (list.length > max) {
        warn(`${label} rows: only ${max} fit on the sheet, extra rows were dropped.`);
        list = list.slice(0, max);
      }
      return list;
    };
    const pipRows = (list, max, nameMax, label) => rows(list, max, label).map((row, n) => ({
      name: text(row.name ?? '', nameMax, `${label} ${n + 1} name`),
      count: int(row.count ?? null, I.pipCount, `${label} ${n + 1} uses`),
    }));

    // Exported files ({app, version, character}) or a bare character. Newer files are accepted:
    // unknown fields are ignored and missing ones get defaults.
    if (isObj(input) && input.character !== null && typeof input.character === 'object') {
      if (Number.isInteger(input.version) && input.version > schema.version) {
        warn('This file was made with a newer version of the generator; anything this version does not support was ignored.');
      }
      input = input.character;
    }
    if (Array.isArray(input) && input.length === 0) input = {}; // PHP can't tell [] from {}
    if (!isObj(input)) {
      warn('The file did not contain a character, so a blank board was loaded.');
      input = {};
    }
    // sub('ltm') returns a nested object from the input, or {} if it's missing or not an object.
    const sub = (k) => (isObj(input[k]) ? input[k] : {});

    // Layout and doll first: they decide how many rows the other lists may keep.

    // Board format: "normal" (one page) or "twoPage" (2 Page 15k+ nutjobs). The two-page board has its own
    // left column (schema.twoPage), so it sets the innate limit; the one-page layout is kept for switching back.
    const format = oneOf(input.format ?? schema.formatDefault, Object.keys(schema.formats), 'Board format') || schema.formatDefault;
    const TP = schema.twoPage;
    const twoPage = format === 'twoPage';
    const layout = oneOf(input.layout ?? schema.layoutDefault, Object.keys(schema.layouts), 'Layout') || schema.layoutDefault;
    const L = schema.layouts[layout];
    const doll = oneOf(input.doll ?? schema.dollDefault, Object.keys(schema.dolls), 'Paper doll') || schema.dollDefault;
    const maxColour = schema.dollLimits[doll]?.colourResist ?? R.colourResist[1];
    const immCompact = [true, 1, '1', 'true'].includes(input.immunitiesCompact);
    const conShow = [true, 1, '1', 'true'].includes(input.consumablesShow ?? true);
    // Two-page board switches and page-2 line counts (read now: they decide the innate and Immunities limits).
    const tpLayout = twoPageLayout(sub('twoPage'), TP);
    let dmg; // the Damage Sheet table (set in data below; Kit and Equipment's limits follow its rows)
    let maxImmune = immCompact
      ? (schema.immunitiesCompact.limits[doll] ?? R.immunities[1])
      : (schema.dollLimits[doll]?.immunities ?? R.immunities[1]);
    if (twoPage) {
      // Page 1 holds the doll's main table (the ghost's whole column); the rest continue on page 2.
      const page1 = doll === 'ghost' ? TP.ghostImmunities[immCompact ? 'compact' : 'normal'] : schema.immunitiesMainRows[immCompact ? 'compact' : 'normal'];
      maxImmune = page1 + tpLayout.p2Imm;
    }
    const innatesMax = twoPage ? TP.innates[1] + tpLayout.contRows : (conShow ? L.innates[1] : L.innatesSolo);
    const poolsIn = asList(input.pools);
    const data = {
      format,
      layout,
      doll,
      theme: oneOf(input.theme ?? schema.themeDefault, Object.keys(schema.themes), 'Sheet colour') || schema.themeDefault,
      watermark: oneOf(input.watermark ?? schema.watermarkDefault, Object.keys(schema.watermarks), 'Watermark') || schema.watermarkDefault,
      name: text(input.name ?? '', T.name, 'Character name'),
      alignment: oneOf(input.alignment ?? '', schema.alignments, 'Alignment'),
      classes: text(input.classes ?? '', T.classes, 'Class(es)'),
      races: text(input.races ?? '', T.races, 'Race(s)'),
      tblp: int(input.tblp ?? null, I.tblp, 'T.B.L.P.'),
      ltm: {
        show: [true, 1, '1', 'true'].includes(sub('ltm').show),
        value: int(sub('ltm').value ?? null, I.ltm, 'LTM'),
      },
      castingStam: {
        show: [true, 1, '1', 'true'].includes(sub('castingStam').show),
        value: int(sub('castingStam').value ?? null, I.castingStam, 'Casting Stam'),
      },
      tblpRows: int(input.tblpRows ?? schema.tblpRowsDefault, I.tblpRows, 'T.B.L.P. lines') ?? schema.tblpRowsDefault,
      tblpLines: fixedLines(input.tblpLines, R.tblpLines, T.tblpLine, 'T.B.L.P. line'),
      power: {
        type: oneOf(sub('power').type ?? '', schema.powerTypes, 'Power type'),
        value: int(sub('power').value ?? null, I.powerValue, 'Power value'),
      },
      powerLines: fixedLines(input.powerLines, R.powerLines, T.powerLine, 'Power line'),
      powB: {
        label: text(sub('powB').label ?? schema.powLabelDefault, T.powLabel, 'Pow column heading'),
        value: int(sub('powB').value ?? null, I.powValue, 'Pow column value'),
      },
      resChance: int(input.resChance ?? null, I.resChance, 'Res Chance'),
      poolsShow: [true, 1, '1', 'true'].includes(input.poolsShow),
      consumablesShow: conShow,
      immunitiesCompact: immCompact,
      pools: [0, 1, 2].map((n) => {
        const row = isObj(poolsIn[n]) || Array.isArray(poolsIn[n]) ? poolsIn[n] : {};
        return {
          name: text(row.name ?? `Pool ${n + 1}`, T.poolName, `Pool ${n + 1} name`),
          value: int(row.value ?? null, I.poolValue, `Pool ${n + 1} value`),
        };
      }),
      innates: pipRows(input.innates, innatesMax, T.innateName, 'Innate'),
      consumables: pipRows(input.consumables, L.consumables[1], T.consumableName, 'Consumable'),
      armour: {}, locations: {}, resist: {}, reductions: {},
      combatWary: {
        show: [true, 1, '1', 'true'].includes(sub('combatWary').show),
        value: text(sub('combatWary').value ?? '', T.reduction, 'Combat Wary reduction'),
      },
      colourResist: [], immunities: [],
      notes: text(input.notes ?? '', T.notes, 'Notes', true),
      // Guilds: the Social / Professional / Political boxes under Notes can be switched off (older files: on).
      guildsShow: [true, 1, '1', 'true'].includes(input.guildsShow ?? true),
      social: text(input.social ?? '', T.standing, 'Social'),
      professional: text(input.professional ?? '', T.standing, 'Professional'),
      political: text(input.political ?? '', T.standing, 'Political'),
      points: int(input.points ?? null, I.points, 'Points'),
      twoPage: twoPageData(sub('twoPage'), tpLayout),
      damageSheet: (dmg = damageSheetData(sub('damageSheet'))),
      // Kit and Equipment (on the Damage Sheet page): as many rows as fit under its table.
      kit: pipRows(input.kit, kitMax(dmg.rows.length), T.kitName, 'Kit'),
      equipment: pipRows(input.equipment, kitMax(dmg.rows.length), T.kitName, 'Equipment'),
    };
    // (Consumables aren't on the two-page board, so they don't share its innate column.)
    if (!twoPage && conShow && data.innates.length + data.consumables.length > L.pipRowsCombined) {
      data.consumables = data.consumables.slice(0, Math.max(0, L.pipRowsCombined - data.innates.length));
      warn(`Innates and consumables are limited to ${L.pipRowsCombined} rows in total; extra rows were dropped.`);
    }
    for (const k of schema.armour) data.armour[k] = int(sub('armour')[k] ?? null, I.armour, k.toUpperCase());
    for (const [k, label] of Object.entries(schema.locations)) {
      const loc = isObj(sub('locations')[k]) ? sub('locations')[k] : {};
      data.locations[k] = { hits: int(loc.hits ?? null, I.locHits, `${label} loc`), ac: int(loc.ac ?? null, I.locAc, `${label} AC`) };
    }
    for (const [k, label] of Object.entries(schema.resistances)) {
      data.resist[k] = int(sub('resist')[k] ?? schema.resistDefault, I.resist, `${label} resistance`);
    }
    for (const [k, label] of Object.entries(schema.reductions)) {
      data.reductions[k] = text(sub('reductions')[k] ?? '', T.reduction, `${label} reduction`);
    }
    rows(input.colourResist, maxColour, 'Colour resist').forEach((row, n) => {
      let fraction = text(row.fraction ?? '', 5, 'Colour resist fraction').replace(/ /g, '');
      if (fraction && !FRACTION.test(fraction)) {
        warn(`Colour resist row ${n + 1}: "${fraction}" is not a fraction like 1/2, so it was cleared.`);
        fraction = '';
      }
      data.colourResist.push({ text: text(row.text ?? '', T.colourText, 'Colour resist text'), fraction });
    });
    rows(input.immunities, maxImmune, 'Immunities/Resistances').forEach((row, n) => {
      const rowText = text(row.text ?? '', T.immunityText, 'Immunity text'); // text first, as in PHP (same warning order)
      let value = row.value ?? '';
      if (typeof value === 'string' && ['Y', 'N'].includes(value.trim().toUpperCase())) value = value.trim().toUpperCase();
      else if (value !== '') value = int(value, I.immunityNumber, `Immunity row ${n + 1} value`) ?? '';
      data.immunities.push({ text: rowText, value });
    });
    /** The parts only the two-page board uses (kept, unprinted, while the one-page board is shown). */
    /**
     * The two-page board's on/off switches (twoPage.show) and page-2 line counts (twoPage.rows), and what
     * they leave: page 2's Immunities/Resistances and Regeneration rows (the full tables unless the
     * Innates (continued) box is on) and how many rows that box holds. Matches twoPageLayout() in PHP.
     */
    function twoPageLayout(tp, TP) {
      const obj = (v) => (isObj(v) || Array.isArray(v) ? v : {});
      const showIn = obj(tp.show);
      const rowsIn = obj(tp.rows);
      const show = {};
      for (const [k, d] of Object.entries(TP.showDefault)) show[k] = [true, 1, '1', 'true'].includes(showIn[k] ?? d);
      const D = TP.p2RowsDefault;
      const rows = {
        immunities: int(rowsIn.immunities ?? D.immunities, I.tpImmRows, 'Page 2 Immunities/Resistances lines') ?? D.immunities,
        regeneration: int(rowsIn.regeneration ?? D.regeneration, I.tpRegenRows, 'Page 2 Regeneration Powers lines') ?? D.regeneration,
      };
      const cont = show.innatesCont;
      const p2Imm = cont ? rows.immunities : TP.page2.immunities;
      const p2Regen = cont ? rows.regeneration : TP.page2.regeneration;
      return { show, rows, p2Imm, p2Regen, contRows: cont ? innatesContRows(TP, p2Imm, p2Regen) : 0 };
    }

    function twoPageData(tp, tpLayout) {
      const obj = (v) => (isObj(v) || Array.isArray(v) ? v : {});
      const powersIn = asList(tp.powers);
      const regenIn = asList(tp.regeneration);
      if (regenIn.length > TP.page2.regeneration) warn(`Regeneration Powers rows: only ${TP.page2.regeneration} fit, extra rows were dropped.`);
      const extra = (key, label) => ({
        value: int(obj(tp[key]).value ?? null, I.poolValue, `${label} value`),
        line: text(obj(tp[key]).line ?? '', T.powerLine, `${label} line`),
      });
      return {
        show: tpLayout.show,
        rows: tpLayout.rows,
        powers: Array.from({ length: TP.powers }, (_, n) => {
          const row = obj(powersIn[n]);
          return {
            name: text(row.name ?? `Power ${n + 1}`, T.tpPowerName, `Power ${n + 1} name`),
            value: int(row.value ?? null, I.poolValue, `Power ${n + 1} value`),
            lines: fixedLines(row.lines, TP.powerLines, T.powerLine, `Power ${n + 1} line`),
          };
        }),
        totalHeal: extra('totalHeal', 'Total Heal Innate'),
        trueBeliever: extra('trueBeliever', 'True Believer'),
        regeneration: Array.from({ length: TP.page2.regeneration }, (_, n) => {
          const row = obj(regenIn[n]);
          return {
            text: text(row.text ?? '', T.tpLine, `Regeneration Powers ${n + 1}`),
            value: text(row.value ?? '', T.tpRegenValue, `Regeneration Powers ${n + 1} value`),
          };
        }),
        damageGrades: fixedLines(tp.damageGrades, TP.page2.damageGrades, T.tpLine, 'Damage Grades line'),
        damageReductions: fixedLines(tp.damageReductions, TP.page2.damageReductions, T.tpLine, 'Damage Reductions line'),
        additionalResistances: fixedLines(tp.additionalResistances, TP.page2.additionalResistances, T.tpLine, 'Additional Resistances line'),
      };
    }
    /**
     * The optional last page's Damage Sheet table: a corner heading, 1–8 column headings (bold, up to
     * 40 characters) and 1–13 rows, each a bold heading (up to 15 characters) and one 0–99 value per
     * column. Older files and files without it get the example table (switched off). Matches PHP.
     */
    function damageSheetData(ds) {
      const D = schema.damageSheet;
      const listOrNull = (v) => (Array.isArray(v) || isObj(v) ? asList(v) : null);
      let cols = listOrNull(ds.columns) ?? D.columnsDefault;
      if (cols.length > D.columns[1]) {
        warn(`Damage Sheet columns: only ${D.columns[1]} fit, extra columns were dropped.`);
        cols = cols.slice(0, D.columns[1]);
      }
      const corner = text(ds.corner ?? D.corner, T.dmgLabel, 'Damage Sheet corner heading');
      const columns = cols.map((c, n) => text(c ?? '', T.dmgHead, `Damage Sheet column ${n + 1} heading`));
      if (!columns.length) columns.push('');
      const rowsIn = listOrNull(ds.rows) === null ? D.rowsDefault.map((label) => ({ label })) : rows(ds.rows, D.rows[1], 'Damage Sheet');
      const out = rowsIn.map((row, n) => {
        const label = text(row.label ?? '', T.dmgLabel, `Damage Sheet row ${n + 1} heading`);
        const vals = asList(row.values);
        if (vals.length > columns.length) warn(`Damage Sheet row ${n + 1}: values beyond column ${columns.length} were dropped.`);
        return { label, values: columns.map((_, c) => int(vals[c] ?? null, I.dmgValue, `Damage Sheet row ${n + 1}, column ${c + 1}`)) };
      });
      if (!out.length) out.push({ label: '', values: columns.map(() => null) });
      return { show: [true, 1, '1', 'true'].includes(ds.show ?? false), corner, columns, rows: out };
    }
    const unknown = Object.keys(input).filter((k) => !Object.prototype.hasOwnProperty.call(data, k));
    if (unknown.length) {
      const names = unknown.slice(0, 10).map((k) => cpSlice(k.replace(STRIP, ''), 30));
      warn(`Ignored ${unknown.length} field(s) this version does not use: ${names.join(', ')}${unknown.length > 10 ? ', …' : ''}.`);
    }
    return { data, warnings };
  }

  /** A fresh, empty board: the default layout with its default number of blank rows. */
  function blankCharacter() {
    const d = schema.defaultRows;
    const L = schema.layouts[schema.layoutDefault];
    const blanks = (n) => Array.from({ length: n }, () => ({}));
    return sanitise({
      innates: blanks(L.defaultInnates), consumables: blanks(L.defaultConsumables),
      colourResist: blanks(d.colourResist), immunities: blanks(d.immunities),
    }).data;
  }

  /* ===================================================================
   * State, paths and storage
   * =================================================================== */
  // The character being edited (see "STATE" at the top). Starts from the board saved in this
  // browser, if there is one, otherwise a blank board.
  let state = loadStored() || blankCharacter();

  /** Reads a value by its dotted path, e.g. getPath(state, 'ltm.value'). Missing parts give undefined. */
  function getPath(obj, path) {
    return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
  }
  /** Writes a value by its dotted path, e.g. setPath(state, 'armour.pac', 6). */
  function setPath(obj, path, value) {
    const keys = path.split('.');
    const last = keys.pop();
    const target = keys.reduce((o, k) => o[k], obj);
    target[last] = value;
  }

  /**
   * Loads the board saved in this browser. It goes through sanitise() like an import, so a board
   * saved by an older version of the site is upgraded automatically. Returns null if there's none.
   */
  function loadStored() {
    try {
      const raw = localStorage.getItem(STORE_KEY);
      return raw ? sanitise(JSON.parse(raw)).data : null;
    } catch { return null; }
  }
  /**
   * Saves the board to localStorage 0.4 s after the last change (so it isn't rewritten on every
   * keystroke) and shows "Saved in this browser at…". If storage is full or blocked (some private
   * browsing modes), the status line suggests using Export instead.
   */
  let saveTimer = 0;
  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try {
        localStorage.setItem(STORE_KEY, JSON.stringify({ app: 'battleboard', version: schema.version, character: state }));
        setStatus(`Saved ${SAVED_WHERE} at ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`);
      } catch {
        setStatus(`Could not save ${SAVED_WHERE} — use Export to keep a copy.`);
      }
    }, 400);
  }

  /* ===================================================================
   * UI helpers
   * =================================================================== */
  const statusEl = $('#status');
  const setStatus = (msg) => { statusEl.textContent = msg; };

  /**
   * Shows a pop-up message, optionally with a bulleted list (e.g. import warnings).
   * Plain messages disappear after 5 seconds; messages with a list stay until dismissed.
   */
  const toastEl = $('#toast');
  let toastTimer = 0;
  function toast(message, list = []) {
    clearTimeout(toastTimer);
    toastEl.replaceChildren();
    const close = el('button', 'close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', () => { toastEl.hidden = true; });
    toastEl.append(close, el('span', '', message));
    if (list.length) {
      const ul = el('ul');
      list.forEach((item) => ul.append(el('li', '', item)));
      toastEl.append(ul);
    } else {
      toastTimer = setTimeout(() => { toastEl.hidden = true; }, 5000);
    }
    toastEl.hidden = false;
  }

  /**
   * Shows (or clears, with an empty message) a validation message under a field. It is linked
   * to the field with aria-describedby so screen readers announce it.
   */
  function setError(input, message) {
    const holder = input.closest('.field, .item-main, .loc-table, .stack') || input.parentElement;
    const id = input.dataset.errId || (input.dataset.errId = `err-${Math.random().toString(36).slice(2, 9)}`);
    let err = document.getElementById(id);
    if (message) {
      input.setAttribute('aria-invalid', 'true');
      if (!err) {
        err = el('p', 'err');
        err.id = id;
        if (holder.classList.contains('loc-table')) input.after(err); else holder.append(err);
      }
      err.textContent = message;
      input.setAttribute('aria-describedby', id);
    } else {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
      err?.remove();
    }
  }

  /** Cleans text while typing: no trimming, so spaces can be typed. */
  function liveText(value, max, multiline) {
    let v = value.replace(STRIP, '').replace(/\t/g, ' ');
    if (!multiline) v = v.replace(/[\r\n]+/g, ' ');
    return cpLength(v) > max ? cpSlice(v, max) : v;
  }
  /** Cleans text when the user leaves the field: the same rules as sanitise(), including trimming. */
  function finalText(value, max, multiline) {
    let v = value.normalize('NFC').replace(/\r\n?/g, '\n').replace(STRIP, '').replace(/\t/g, ' ');
    v = multiline ? v.replace(/\n{3,}/g, '\n\n') : v.replace(/\s+/g, ' ');
    v = v.trim();
    return cpLength(v) > max ? cpSlice(v, max).trimEnd() : v;
  }
  /** Returns {value, error} for an integer field; strips non-digits as you type. */
  function readInt(input, min, max) {
    const digits = input.value.replace(/\D+/g, '');
    if (digits !== input.value) input.value = digits;
    if (digits === '') return { value: null, error: '' };
    const n = parseInt(digits, 10);
    if (n < min || n > max) return { value: null, error: `Enter a number from ${min} to ${max}.` };
    return { value: n, error: '' };
  }

  /* ===================================================================
   * Static form fields  (data-path)
   * =================================================================== */
  const editor = $('#editor');

  /** A text field's character limit, taken from its maxlength attribute. */
  function maxFor(input) {
    return parseInt(input.getAttribute('maxlength'), 10) || 100;
  }

  /**
   * Handles typing in any data-path field. `final` is false while typing ("input" event) and
   * true when the field is left ("change" event), when text is trimmed and errors are shown.
   * Each kind of field is read differently (see data-kind in index.php); switches that change the
   * layout of the sheet (pools, consumables, compact rows, layout, doll) hand over to their own
   * apply...() function, which may need to ask the user before removing rows.
   */
  function onFieldInput(input, final) {
    const path = input.dataset.path;
    const kind = input.dataset.kind;
    if (kind === 'tprows') { applyTpLayout({ rows: { [path.split('.').pop()]: parseInt(input.value, 10) } }, input); return; }
    if (kind === 'bool') {
      if (path === 'twoPage.show.innatesCont') { applyTpLayout({ show: { innatesCont: input.checked } }, input); return; }
      setPath(state, path, input.checked);
      if (path.startsWith('twoPage.show.')) { updateLayoutForm(); renderSheet(); scheduleSave(); return; }
      if (path === 'consumablesShow') { setPath(state, path, !input.checked); applyConsumablesShow(input.checked, input); return; }
      if (path === 'immunitiesCompact') { setPath(state, path, !input.checked); applyImmunitiesCompact(input.checked, input); return; }
      if (path === 'poolsShow') { updateLayoutForm(); renderSheet(); refill(); scheduleSave(); return; }
      if (path === 'damageSheet.show') { applyDamageSheetShow(input.checked); return; }
    } else if (kind === 'int') {
      const { value, error } = readInt(input, +input.dataset.min, +input.dataset.max);
      setError(input, error);
      setPath(state, path, value);

    } else if (kind === 'enum' && path === 'layout') {
      if (input.checked) applyLayout(input.value, input);
      return;
    } else if (kind === 'enum' && path === 'doll') {
      if (input.checked) applyDoll(input.value, input);
      return;
    } else if (kind === 'enum') {
      const opts = schema[input.dataset.options] || [];
      const options = Array.isArray(opts) ? opts : Object.keys(opts);
      setPath(state, path, options.includes(input.value) ? input.value : '');
    } else {
      const multiline = kind === 'multiline';
      const v = final ? finalText(input.value, maxFor(input), multiline) : liveText(input.value, maxFor(input), multiline);
      if (final && v !== input.value) input.value = v;
      setPath(state, path, v);
      if (path === 'notes') updateNotesCount();
    }
    if (path === 'tblpRows' || path === 'doll' || path === 'castingStam.show') updateLayoutForm();
    // Typing a value into an optional field (LTM, Combat Wary) switches its row on.
    const autoShow = input.dataset.autoshow;
    const current = getPath(state, path);
    if (autoShow && current !== null && current !== '' && getPath(state, autoShow) !== true) {
      setPath(state, autoShow, true);
      $(`[data-path="${autoShow}"]`, editor).checked = true;
      if (autoShow === 'castingStam.show') updateLayoutForm();
    }
    changed();
  }

  // One listener for the whole form ("event delegation") instead of one per field.
  editor.addEventListener('input', (e) => {
    const t = e.target;
    if (t.matches('[data-path]')) onFieldInput(t, false);
  });
  editor.addEventListener('change', (e) => {
    const t = e.target;
    if (t.matches('[data-path]')) onFieldInput(t, true);
  });

  /** Copies every value from `state` into the form fields (after loading, importing or New). */
  function fillForm() {
    $$('[data-path]', editor).forEach((input) => {
      const v = getPath(state, input.dataset.path);
      if (input.dataset.kind === 'bool') input.checked = v === true;
      else if (input.type === 'radio') input.checked = input.value === v;
      else input.value = v == null ? '' : String(v);
      setError(input, '');
    });
    updateNotesCount();
  }
  function updateNotesCount() {
    $('#notes-count').textContent = `${cpLength(state.notes)} / ${schema.text.notes} characters`;
  }

  /* ===================================================================
   * Repeatable lists
   * =================================================================== */
  /*
   * The board layout decides how many T.B.L.P./Power lines show and how many innate and consumable
   * rows fit. Innates and consumables share one column, so as well as their own limits they share
   * a combined limit (pipRowsCombined in the schema). These helpers answer "can another row be added?".
   */
  /** True while the "2 Page 15k+ nutjobs" (two-page) board is chosen with the toggle in the top bar. */
  const isTP = () => state.format === 'twoPage';
  /** Page 2's Immunities/Resistances and Regeneration rows and the Innates (continued) rows, as sanitise() works them out. */
  const tpLayoutOf = (c = state) => {
    const TP = schema.twoPage;
    const cont = c.twoPage.show.innatesCont;
    const p2Imm = cont ? c.twoPage.rows.immunities : TP.page2.immunities;
    const p2Regen = cont ? c.twoPage.rows.regeneration : TP.page2.regeneration;
    return { p2Imm, p2Regen, contRows: cont ? innatesContRows(TP, p2Imm, p2Regen) : 0 };
  };
  /**
   * Lines under each part of the two-page Powers box. The box keeps its height (schema twoPage.powerBox):
   * Total Heal Innate and True Believer keep one line each, and the shown powers share the rest
   * (Power 1 takes any odd one), so switching a part off gives the others its room.
   */
  const tpPowerLines = (c = state) => {
    const TP = schema.twoPage;
    const b = TP.powerBox;
    const sh = c.twoPage.show;
    const heads = 1 + [sh.power2, sh.totalHeal, sh.trueBeliever].filter(Boolean).length;
    let lines = Math.floor((b.heightMm10 - heads * b.headMm10) / b.lineMm10);
    const out = { power1: 0, power2: 0, totalHeal: sh.totalHeal ? 1 : 0, trueBeliever: sh.trueBeliever ? 1 : 0 };
    lines -= out.totalHeal + out.trueBeliever;
    const each = sh.power2 ? Math.floor(lines / 2) : 0;
    out.power2 = Math.min(TP.powerLines, each);
    out.power1 = Math.min(TP.powerLines, lines - each);
    return out;
  };
  /**
   * The active board layout's settings (row counts, row heights, limits). The two-page board has its
   * own fixed left column (schema.twoPage); state.layout is the one-page layout, kept for switching back.
   * Pass a layout key to read that one-page layout's settings instead.
   */
  const layoutCfg = (key) => (key
    ? schema.layouts[key] || schema.layouts[schema.layoutDefault]
    : isTP() ? schema.twoPage : schema.layouts[state.layout] || schema.layouts[schema.layoutDefault]);
  /** Consumables take space on the sheet only on the one-page board, while their table is switched on. */
  const conShown = () => state.consumablesShow && !isTP();
  // Two-page board: the T.B.L.P. box sits under the doll, with a fixed number of lines for each doll.
  const tblpRowCount = () => (isTP() ? schema.twoPage.tblpRows[state.doll] : layoutCfg().tblpRows ?? state.tblpRows);
  /** T.B.L.P. lines left for writing: the bottom line becomes Casting Stam when that is switched on. */
  const tblpTextRows = () => tblpRowCount() - (state.castingStam.show ? 1 : 0);
  const listLimits = {
    get innates() { return innateMax(); },
    get consumables() { return layoutCfg().consumables[1]; },
    get colourResist() { return tableLimit('colourResist'); },
    get immunities() { return tableLimit('immunities'); },
    get kit() { return kitMax(state.damageSheet.rows.length); },
    get equipment() { return kitMax(state.damageSheet.rows.length); },
  };
  /** Innates allowed: more when the Consumables table is switched off. */
  const innateMax = (cfg = layoutCfg(), conShow = conShown()) => (cfg === schema.twoPage
    ? cfg.innates[1] + tpLayoutOf().contRows
    : conShow ? cfg.innates[1] : cfg.innatesSolo);
  /** Rows sharing the column: hidden consumables take no space. */
  const pipCombined = () => state.innates.length + (conShown() ? state.consumables.length : 0);
  const pipFull = () => conShown() && pipCombined() >= layoutCfg().pipRowsCombined;
  /** True if another row can be added to the list `key` (its own limit and any shared limit). */
  function canAdd(key) {
    if (state[key].length >= listLimits[key]) return false;
    if (key === 'consumables' && !conShown()) return false;
    if (PIP_LIST_KEYS.includes(key) && pipFull()) return false;
    return true;
  }
  /** A new empty row of the right shape for each list. */
  function blankRow(key) {
    if (isPipList(key)) return { name: '', count: null };
    if (key === 'colourResist') return { text: '', fraction: '' };
    return { text: '', value: '' };
  }

  /** Updates each list's "+ Add" button and its "12 / 25" counter. */
  function updateListControls() {
    for (const key of Object.keys(listLimits)) {
      const btn = $(`[data-add="${key}"]`);
      btn.disabled = !canAdd(key);
      let note = `${state[key].length} / ${listLimits[key]}`;
      if (PIP_LIST_KEYS.includes(key) && pipFull() && state[key].length < listLimits[key]) {
        note += ' · sheet full';
      }
      if (key === 'consumables' && !conShown()) note += isTP() ? ' · not on the two-page board' : ' · hidden';
      if (KIT_KEYS.includes(key) && !state.damageSheet.show) note += ' · page off';
      $(`[data-count="${key}"]`).textContent = note;
    }
  }

  /** Rebuilds the editor rows for one list from `state`. */
  function renderList(key) {
    const ol = $(`[data-list="${key}"]`);
    ol.replaceChildren();
    state[key].forEach((row, i) => ol.append(buildItem(key, row, i)));
    updateListControls();
  }

  /**
   * Builds one editor row from its <template> in index.php and wires it to its row object in
   * `state`: typing updates the row, the × button removes it. Innate/consumable rows also get the
   * 1–8 "uses" buttons; Colour Resist checks the fraction; Immunities choose Y, N or a number.
   */
  function buildItem(key, row, i) {
    const tplId = isPipList(key) ? 'tpl-pip-row' : key === 'colourResist' ? 'tpl-colour-row' : 'tpl-immunity-row';
    const li = $(`#${tplId}`).content.firstElementChild.cloneNode(true);
    const label = { innates: 'Innate', consumables: 'Consumable', colourResist: 'Colour resist', immunities: 'Immunity/resistance', kit: 'Kit item', equipment: 'Equipment item' }[key];
    li.querySelector('.remove').setAttribute('aria-label', `Remove ${label.toLowerCase()} ${i + 1}`);

    if (isPipList(key)) {
      const name = li.querySelector('[data-field="name"]');
      const max = key === 'innates' ? schema.text.innateName : KIT_KEYS.includes(key) ? schema.text.kitName : schema.text.consumableName;
      name.maxLength = max;
      name.placeholder = `${label} name`;
      name.setAttribute('aria-label', `${label} ${i + 1} name`);
      name.value = row.name;
      name.addEventListener('input', () => { row.name = liveText(name.value, max, false); changed(); });
      name.addEventListener('change', () => { row.name = name.value = finalText(name.value, max, false); changed(); });

      const pick = li.querySelector('.pip-pick');
      pick.setAttribute('aria-label', `${label} ${i + 1}: number of uses`);
      for (let n = 1; n <= 8; n++) {
        const b = el('button', '', String(n));
        b.type = 'button';
        b.setAttribute('aria-label', `${n} use${n > 1 ? 's' : ''}`);
        b.addEventListener('click', () => {
          row.count = row.count === n ? null : n;
          paintPips(pick, row.count);
          changed();
        });
        pick.append(b);
      }
      paintPips(pick, row.count);
    } else if (key === 'colourResist') {
      const txt = li.querySelector('[data-field="text"]');
      const frac = li.querySelector('[data-field="fraction"]');
      txt.value = row.text;
      frac.value = row.fraction;
      txt.addEventListener('input', () => { row.text = liveText(txt.value, schema.text.colourText, false); changed(); });
      txt.addEventListener('change', () => { row.text = txt.value = finalText(txt.value, schema.text.colourText, false); changed(); });
      const checkFrac = (final) => {
        // Accept the slash look-alikes phone keyboards can produce (fraction slash, division slash, ÷, \).
        const cleaned = frac.value.replace(/[\u2044\u2215\u00F7\\]/g, '/').replace(/[^0-9/]/g, '').slice(0, 5);
        if (cleaned !== frac.value) frac.value = cleaned;
        const ok = cleaned === '' || FRACTION.test(cleaned);
        row.fraction = ok ? cleaned : '';
        setError(frac, !ok && final ? 'Use a fraction such as 1/2 or 3/4.' : '');
        changed();
      };
      frac.addEventListener('input', () => checkFrac(false));
      frac.addEventListener('change', () => checkFrac(true));
    } else {
      const txt = li.querySelector('[data-field="text"]');
      const mode = li.querySelector('[data-field="mode"]');
      const num = li.querySelector('[data-field="number"]');
      txt.value = row.text;
      mode.value = row.value === '' ? '' : typeof row.value === 'number' ? '#' : row.value;
      num.value = typeof row.value === 'number' ? String(row.value) : '';
      num.hidden = mode.value !== '#';
      txt.addEventListener('input', () => { row.text = liveText(txt.value, schema.text.immunityText, false); changed(); });
      txt.addEventListener('change', () => { row.text = txt.value = finalText(txt.value, schema.text.immunityText, false); changed(); });
      const sync = () => {
        num.hidden = mode.value !== '#';
        if (mode.value === '#') {
          const [min, max] = schema.int.immunityNumber;
          const { value, error } = readInt(num, min, max);
          setError(num, error);
          row.value = value ?? '';
        } else {
          setError(num, '');
          row.value = mode.value === 'Y' || mode.value === 'N' ? mode.value : '';
        }
        changed();
      };
      mode.addEventListener('change', () => { sync(); if (mode.value === '#') num.focus(); });
      num.addEventListener('input', sync);
    }

    li.querySelector('.remove').addEventListener('click', () => {
      const idx = state[key].indexOf(row);
      if (idx < 0) return;
      state[key].splice(idx, 1);
      renderList(key);
      changed();
      const items = $$(`[data-list="${key}"] .remove`);
      (items[Math.min(idx, items.length - 1)] || $(`[data-add="${key}"]`)).focus();
    });
    return li;
  }

  /** Highlights the 1–8 uses buttons in the editor to match a row's count. */
  function paintPips(pick, count) {
    $$('button', pick).forEach((b, n) => {
      const on = count != null && n < count;
      b.classList.toggle('on', on);
      b.classList.toggle('off', count != null && n >= count);
      b.setAttribute('aria-pressed', String(count === n + 1));
    });
  }

  /*
   * "Sort A–Z" buttons: put a list's rows into alphabetical order by name. Upper and lower case and
   * accents are ignored ("élan" sorts with "Elan"), numbers sort naturally ("Bolt 2" before
   * "Bolt 10"), and rows without a name go to the end. Each row keeps its number of uses.
   */
  const nameOrder = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });
  $$('[data-sort]').forEach((btn) => btn.addEventListener('click', () => {
    const key = btn.dataset.sort;
    const list = state[key];
    if (list.length < 2) return;
    const before = list.slice();
    list.sort((a, b) => {
      if (!a.name || !b.name) return (a.name ? 0 : 1) - (b.name ? 0 : 1); // blanks last
      return nameOrder.compare(a.name, b.name);
    });
    if (list.every((row, i) => row === before[i])) { toast('Already in alphabetical order.'); return; }
    renderList(key);
    changed();
    toast('Innates sorted A\u2013Z.');
  }));

  // "+ Add" buttons: append a blank row, redraw that list and move the cursor into the new row.
  $$('[data-add]').forEach((btn) => btn.addEventListener('click', () => {
    const key = btn.dataset.add;
    if (!canAdd(key)) return;
    state[key].push(blankRow(key));
    renderList(key);
    changed();
    $(`[data-list="${key}"] .item:last-child .in`)?.focus();
  }));

  /* ===================================================================
   * Sheet rendering
   * =================================================================== */
  // The A4 sheet element, and the CSS conversion from millimetres to pixels (96 px per inch).
  // The sheet is laid out in real millimetres so it prints at exactly A4 size.
  const sheet = $('#sheet');
  // Page 2 of the "2 Page 15k+ nutjobs" board (hidden on the one-page board). Both pages share data-bind.
  const sheet2 = $('#sheet-p2');
  // The optional last page, "Damage Sheet, Kit and Equipment" (damageSheet.show), after either board.
  const sheet3 = $('#sheet-dmg');
  const pages = [sheet, sheet2, sheet3];
  /** How many A4 pages print: 1 or 2 for the board, plus the Damage Sheet page when it is on. */
  const pageCount = () => (isTP() ? 2 : 1) + (state.damageSheet.show ? 1 : 0);
  const pxPerMm = 96 / 25.4;

  /**
   * Redraws the whole sheet from `state`:
   *  - fills every data-bind element with its value as plain text (with a placeholder if empty),
   *  - applies the colour theme and watermark,
   *  - shows or hides optional parts (data-show), layout lines, pools and the Power box,
   *  - rebuilds the innate/consumable rows and the Colour Resist and Immunities tables,
   * then, on the next frame, runs fitAll() to shrink anything that overflows.
   * The data-* attributes set on the sheet (layout, doll, pools...) let board.css switch layouts.
   */
  function renderSheet() {
    const tp = isTP();
    sheet2.hidden = !tp;
    sheet3.hidden = !state.damageSheet.show;
    // Page numbers in the footers: none on a single page; otherwise "Page n of N" on every page.
    const total = pageCount();
    const pg1 = $('.foot .pg', sheet);
    pg1.hidden = total === 1;
    pg1.textContent = `Page 1 of ${total}`;
    $('.foot .pg', sheet2).textContent = `Page 2 of ${total}`;
    $('.foot .pg', sheet3).textContent = `Page ${total} of ${total}`;
    renderDamageTable();
    pages.flatMap((page) => $$('[data-bind]', page)).forEach((node) => {
      const v = getPath(state, node.dataset.bind);
      let text = v == null ? '' : String(v);
      node.classList.toggle('is-empty', text === '');
      delete node.dataset.full;
      if (text !== '' && node.dataset.wrap === 'brackets') text = `(${text})`;
      if (text === '' && node.dataset.placeholder) text = node.dataset.placeholder;
      node.textContent = text;
    });

    // Colour, watermark, format, doll and row settings apply to both pages.
    pages.forEach((page) => {
      page.dataset.theme = state.theme;
      page.dataset.format = state.format;
      page.dataset.doll = state.doll;
      page.dataset.imm = state.immunitiesCompact ? 'compact' : 'normal';
      const wm = $('.wm', page);
      wm.toggleAttribute('hidden', state.watermark === 'none'); // SVG elements have no .hidden property
      const use = wm.querySelector('use');
      if (state.watermark === 'none') use.removeAttribute('href');
      else use.setAttribute('href', `${WM_URL}#wm-${state.watermark}`);
      $$('[data-show]', page).forEach((node) => { node.hidden = getPath(state, node.dataset.show) !== true; });
    });
    $$('.sbox', sheet).forEach((box) => box.classList.toggle('filled', box.querySelector('.sv').textContent !== ''));
    // Two-page board: the T.B.L.P. box moves from the top of the left column to under the paper doll.
    const tblpBox = $('#sheet-tblp', sheet);
    if (tp && tblpBox.previousElementSibling !== $('.doll', sheet)) $('.doll', sheet).after(tblpBox);
    if (!tp && tblpBox.parentElement.firstElementChild !== tblpBox) $('.body > .col', sheet).prepend(tblpBox);

    // On the two-page board page 1 holds the first innates; the rest go in Innates (continued) on page 2.
    const p1Innates = schema.twoPage.innates[1];
    const pipLists = PIP_LIST_KEYS.map((key) => [key, `#sheet-${key}`, key === 'innates' && tp ? state.innates.slice(0, p1Innates) : state[key]]);
    if (tp) {
      const { contRows } = tpLayoutOf();
      const rest = state.innates.slice(p1Innates);
      pipLists.push(['innates', '#sheet-p2-innates', Array.from({ length: Math.max(contRows, rest.length) }, (_, n) => rest[n] || { name: '', count: null })]);
    }
    pipLists.push(['kit', '#sheet-kit', state.kit], ['equipment', '#sheet-equipment', state.equipment]);
    for (const [, sel, rows] of pipLists) {
      const holder = $(sel);
      holder.replaceChildren(...rows.map((row) => {
        const r = el('div', 'row pip-row');
        r.append(el('span', 'fill name fit', row.name));
        const pips = el('span', 'pips');
        for (let n = 1; n <= 8; n++) {
          const i = el('i');
          if (row.count != null && n > row.count) i.className = 'off';
          i.append(el('b', '', String(n)));
          pips.append(i);
        }
        r.append(pips);
        return r;
      }));
    }
    sheet.dataset.layout = state.layout;
    sheet.dataset.pools = layoutCfg().pools || state.poolsShow ? 'on' : 'off';
    // Guilds off hides the three small boxes under the Notes heading (board.css), so the notes get their room.
    sheet.dataset.guilds = state.guildsShow ? 'on' : 'off';
    $('#guild-fields').hidden = !state.guildsShow; // the form hides their inputs too (the text is kept)
    sheet.dataset.power = layoutCfg().power === false ? 'off' : 'on';
    // Casting Stam (optional) takes the place of the bottom T.B.L.P. line.
    $$('#sheet-tblp .row:not(.cs-row)', sheet).forEach((r, i) => { r.hidden = i >= tblpTextRows(); });
    $$('#sheet-power .row', sheet).forEach((r, i) => { r.hidden = i >= layoutCfg().powerRows; });
    if (tp) {
      // Powers box: the parts left on share its fixed height, so the powers get more lines.
      const lines = tpPowerLines();
      $$('#sheet-tp-powers .tp-part', sheet).forEach((part) => {
        const n = lines[part.dataset.part] ?? 1;
        $$('.row', part).forEach((r, i) => { r.hidden = i >= n; });
      });
      // Page 2: Innates (continued) takes the room freed from Immunities/Resistances and Regeneration.
      const lay = tpLayoutOf();
      sheet2.dataset.cont = state.twoPage.show.innatesCont ? 'on' : 'off';
      $('.p2-imm', sheet2).hidden = lay.p2Imm === 0;
      $('.p2-inn', sheet2).hidden = lay.contRows === 0; // (switched on, but the other two tables leave it no room)
      $('#sheet-p2-regen').hidden = lay.p2Regen === 0;
      $$('#sheet-p2-regen .row', sheet2).forEach((r, i) => { r.hidden = i >= lay.p2Regen; });
    }
    sizePipRows();

    // Builds <tr> rows for the two small tables: text on the left, fraction/value on the right.
    const tableRows = (rows, left, right) => rows.map((row) => {
      const tr = el('tr');
      const a = el('td'); a.append(el('span', 'fit', row[left]));
      const b = el('td', 'num'); b.append(el('span', 'fit', row[right] === '' || row[right] == null ? '' : String(row[right])));
      tr.append(a, b);
      return tr;
    });
    $('#sheet-colour').replaceChildren(...tableRows(state.colourResist, 'text', 'fraction'));
    // Immunities/Resistances: on the Default and Snow(man)flake dolls, rows beyond what the main table
    // holds continue in a second table under the Resistance table (the ghost's column holds them all).
    const immMain = immunitiesMainRows();
    $('#sheet-immunities').replaceChildren(...tableRows(state.immunities.slice(0, immMain), 'text', 'value'));
    const immOver = state.immunities.slice(immMain);
    $('#sheet-immunities-over').replaceChildren(...tableRows(tp ? [] : immOver, 'text', 'value'));
    $('#sheet-immunities-over').closest('table').hidden = tp || immOver.length === 0;
    // Two-page board: the rows page 1 can't hold continue on page 2, which is ruled down to the bottom.
    if (tp) {
      const p2 = Array.from({ length: tpLayoutOf().p2Imm }, (_, n) => immOver[n] || { text: '', value: '' });
      $('#sheet-p2-immunities').replaceChildren(...p2.map((row) => {
        const r = el('div', 'row p2-row vrow');
        r.append(el('span', 'fill fit', row.text));
        const v = el('span', 'vb');
        v.append(el('span', 'fit', row.value === '' || row.value == null ? '' : String(row.value)));
        r.append(v);
        return r;
      }));
    }

    $('#sheet-notes').classList.toggle('has-text', state.notes.trim() !== '');
    requestAnimationFrame(fitAll);
  }

  /**
   * Gives innate/consumable rows their layout's height, then shrinks them evenly if the left
   * column would overflow, so the column never pushes past the page (Res Chance stays at the bottom).
   */
  function sizePipRows() {
    const cfg = layoutCfg();
    const lists = [['innates', '#sheet-innates', cfg.innateRowMm], ['consumables', '#sheet-consumables', cfg.consumableRowMm]];
    lists.forEach(([, sel, mm]) => $(sel, sheet).style.setProperty('--row-h', `${mm}mm`));
    const col = $('.body > .col', sheet);
    const over = col.scrollHeight - col.clientHeight;
    if (over > 0.5) {
      const shown = (key) => (key === 'consumables' && !conShown() ? 0
        : key === 'innates' && isTP() ? Math.min(state.innates.length, schema.twoPage.innates[1]) : state[key].length);
      const total = lists.reduce((sum, [key, , mm]) => sum + shown(key) * mm * pxPerMm, 0);
      if (total > 0) {
        const scale = Math.max(0.5, (total - over) / total);
        lists.forEach(([, sel, mm]) => $(sel, sheet).style.setProperty('--row-h', `${(mm * scale).toFixed(3)}mm`));
      }
    }
  }

  /** Empty height (px) left in the left column above the Res Chance box. */
  function freeColumnSpace() {
    const res = $('.res-panel', sheet);
    let prev = res.previousElementSibling;
    while (prev && prev.offsetHeight === 0) prev = prev.previousElementSibling;
    if (!prev) return 0;
    return res.offsetTop - (prev.offsetTop + prev.offsetHeight) - COL_GAP_MM * pxPerMm;
  }

  /* ---------------- layouts ---------------- */
  /** True for an innate/consumable row with no name and no count: safe to remove automatically. */
  const isBlankRow = (r) => r.name === '' && r.count == null;

  /**
   * Switches board layout. Works out the rows the new layout would keep: blank rows are added or
   * trimmed to its defaults, and only if rows with content wouldn't fit is the user asked first.
   * `radio` is the layout option that was clicked; it is set back if the user cancels.
   */
  function applyLayout(key, radio) {
    const cfg = layoutCfg(key);
    if (!schema.layouts[key] || key === state.layout) return;
    // Work out the rows the new layout would have, dropping only blank rows where possible.
    const plan = (list, target, max) => {
      const rows = list.slice();
      while (rows.length > target && isBlankRow(rows[rows.length - 1])) rows.pop();
      while (rows.length < target) rows.push({ name: '', count: null });
      const kept = rows.slice(0, max);
      return { kept, lost: rows.slice(max).filter((r) => !isBlankRow(r)).length };
    };
    const inn = plan(state.innates, cfg.defaultInnates, innateMax(cfg));
    const con = plan(state.consumables, cfg.defaultConsumables, cfg.consumables[1]);
    let lost = inn.lost + con.lost;
    // Hidden consumables are kept but take no room on the sheet.
    let room = state.consumablesShow ? cfg.pipRowsCombined - inn.kept.length : Infinity;
    while (con.kept.length > room && con.kept.length && isBlankRow(con.kept[con.kept.length - 1])) con.kept.pop();
    if (con.kept.length > room) {
      lost += con.kept.slice(Math.max(0, room)).filter((r) => !isBlankRow(r)).length;
      con.kept = con.kept.slice(0, Math.max(0, room));
    }
    if (lost > 0 && !window.confirm(`Switching to ${cfg.label} leaves room for fewer innate/consumable rows, so ${lost} filled row${lost > 1 ? 's' : ''} would be removed. Switch anyway?`)) {
      if (radio) $(`input[name="layout"][value="${state.layout}"]`).checked = true;
      return;
    }
    state.layout = key;
    state.innates = inn.kept;
    state.consumables = con.kept;
    renderAll();
    refill();
    scheduleSave();
    toast(`Layout: ${cfg.label}.`);
  }

  /**
   * Switches the Consumables table on or off. Off: its rows are kept (they reappear when switched back
   * on) and innates may use the freed space. On: extra blank innate rows are trimmed; if filled rows
   * wouldn't fit, the user is asked first.
   */
  function applyConsumablesShow(on, input) {
    if (on === state.consumablesShow) return;
    const cfg = layoutCfg();
    let innates = state.innates.slice();
    let consumables = state.consumables.slice();
    let lost = 0;
    if (on) {
      const max = cfg.innates[1];
      while (innates.length > Math.min(max, cfg.defaultInnates) && isBlankRow(innates[innates.length - 1])) innates.pop();
      lost += innates.slice(max).filter((r) => !isBlankRow(r)).length;
      innates = innates.slice(0, max);
      const room = Math.max(0, cfg.pipRowsCombined - innates.length);
      while (consumables.length > room && consumables.length && isBlankRow(consumables[consumables.length - 1])) consumables.pop();
      if (consumables.length < cfg.defaultConsumables && room > consumables.length) {
        while (consumables.length < Math.min(cfg.defaultConsumables, room)) consumables.push({ name: '', count: null });
      }
      lost += consumables.slice(room).filter((r) => !isBlankRow(r)).length;
      consumables = consumables.slice(0, room);
      if (lost > 0 && !window.confirm(`Showing the Consumables table leaves room for ${cfg.pipRowsCombined} innate and consumable rows in total, so ${lost} filled row${lost > 1 ? 's' : ''} would be removed. Show it anyway?`)) {
        input.checked = false;
        return;
      }
    }
    state.consumablesShow = on;
    state.innates = innates;
    state.consumables = consumables;
    renderAll();
    refill();
    scheduleSave();
    toast(on ? 'Consumables table shown.' : `Consumables table hidden: up to ${cfg.innatesSolo} innates. Consumable rows are kept for if you switch it back on.`);
  }

  /** Runs fn with the sheet laid out (off-screen if the Preview tab is hidden on a phone). */
  function measurable(fn) {
    const offscreen = preview.offsetParent === null;
    if (offscreen) preview.classList.add('measuring');
    try { return fn(); } finally { if (offscreen) preview.classList.remove('measuring'); }
  }

  /**
   * For layouts that "fill" (Innately Gifted, Brimful of Asher): after anything changes the space
   * in the left column, trim the automatic blank rows and add as many as now fit.
   */
  function refill() {
    const cfg = layoutCfg();
    if (!cfg.fill) return;
    // Brimful of Asher fills consumables; with the Consumables table off, it fills innates instead.
    const key = cfg.fill === 'consumables' && !state.consumablesShow ? 'innates' : cfg.fill;
    const base = key === 'innates' ? cfg.defaultInnates : cfg.defaultConsumables;
    const list = state[key];
    while (list.length > base && isBlankRow(list[list.length - 1])) list.pop();
    renderSheet();
    measurable(() => {
      sizePipRows();
      const rowPx = (key === 'innates' ? cfg.innateRowMm : cfg.consumableRowMm) * pxPerMm;
      let extra = Math.floor((freeColumnSpace() + 0.5) / rowPx);
      // (Two-page board: blank rows only fill page 1; Innates (continued) on page 2 is ruled anyway.)
      const cap = isTP() ? schema.twoPage.innates[1] : Infinity;
      while (extra-- > 0 && canAdd(key) && list.length < cap) list.push({ name: '', count: null });
    });
    renderAll();
  }

  /* ---------------- paper doll ---------------- */
  /**
   * How many Immunities/Resistances rows the doll's main table holds before the rest continue: under
   * Resistance on the one-page board (the ghost's column holds them all), or on page 2 of the two-page board.
   */
  const immunitiesMainRows = (doll = state.doll, compact = state.immunitiesCompact, format = state.format) => {
    // (On the two-page board the T.B.L.P. box sits under the ghost, so its column holds a little less.)
    if (doll === 'ghost') return format === 'twoPage' ? schema.twoPage.ghostImmunities[compact ? 'compact' : 'normal'] : Infinity;
    return schema.immunitiesMainRows[compact ? 'compact' : 'normal'];
  };

  /**
   * Most Colour Resist or Immunities rows allowed, for a doll, the compact-rows setting and the board
   * format (the two-page board adds page 2's rows to what page 1 holds). Matches sanitise().
   */
  function tableLimit(key, doll = state.doll, compact = state.immunitiesCompact, format = state.format) {
    const base = key === 'immunities' && compact
      ? schema.immunitiesCompact.limits[doll] ?? schema.rows[key][1]
      : schema.dollLimits[doll]?.[key] ?? schema.rows[key][1];
    if (key !== 'immunities' || format !== 'twoPage') return base;
    return immunitiesMainRows(doll, compact, format) + tpLayoutOf().p2Imm;
  }
  /** True for a Colour Resist / Immunities row with nothing in it. */
  const isBlankTableRow = (key, r) => (key === 'colourResist' ? r.text === '' && r.fraction === '' : r.text === '' && r.value === '');

  /** Ghost doll: fill the tall Immunities/Resistances column with blank rows down to the bottom of the doll. */
  function fillGhostImmunities() {
    measurable(() => {
      const doll = $('.doll', sheet);
      const table = $('#sheet-immunities', sheet).closest('table');
      renderSheet();
      positionGhostTables();
      while (canAdd('immunities') && state.immunities.length < immunitiesMainRows()) {
        state.immunities.push({ text: '', value: '' });
        renderSheet();
        positionGhostTables();
        if (table.offsetTop + table.offsetHeight > doll.offsetHeight) { state.immunities.pop(); break; }
      }
    });
    renderAll();
  }

  /** Compact Immunities/Resistances rows: shorter lines, so more of them fit. */
  /**
   * Two-page board: switches the Innates (continued) box on page 2 on or off, or changes how many
   * Immunities/Resistances or Regeneration Powers lines page 2 shows (which sets the box's size).
   * Blank innate/Immunities rows that no longer fit are trimmed; if filled ones wouldn't fit, the user
   * is asked first. `input` is the checkbox or list that changed; it is set back if the user cancels.
   */
  function applyTpLayout(change, input) {
    const next = JSON.parse(JSON.stringify(state));
    Object.assign(next.twoPage.show, change.show || {});
    Object.assign(next.twoPage.rows, change.rows || {});
    const lay = tpLayoutOf(next);
    const fit = (list, max, blank) => {
      const rows = list.slice();
      while (rows.length > max && blank(rows[rows.length - 1])) rows.pop();
      return { kept: rows.slice(0, max), lost: rows.slice(max).filter((r) => !blank(r)).length };
    };
    const inn = fit(next.innates, schema.twoPage.innates[1] + lay.contRows, isBlankRow);
    const immMax = immunitiesMainRows(next.doll, next.immunitiesCompact, 'twoPage') + lay.p2Imm;
    const imm = fit(next.immunities, immMax, (r) => isBlankTableRow('immunities', r));
    if (isTP()) {
      const parts = [];
      if (inn.lost) parts.push(`${inn.lost} filled innate row${inn.lost > 1 ? 's' : ''}`);
      if (imm.lost) parts.push(`${imm.lost} filled Immunities/Resistances row${imm.lost > 1 ? 's' : ''}`);
      if (parts.length && !window.confirm(`That leaves less room on page 2, so ${parts.join(' and ')} would be removed. Go ahead?`)) {
        const v = getPath(state, input.dataset.path);
        if (input.type === 'checkbox') input.checked = v === true; else input.value = String(v);
        return;
      }
      next.innates = inn.kept;
      next.immunities = imm.kept;
    }
    state = sanitise(next).data;
    renderAll();
    refill();
    scheduleSave();
    if (isTP() && change.show) {
      toast(change.show.innatesCont ? `Innates (continued) on page 2: room for ${lay.contRows} more innate${lay.contRows === 1 ? '' : 's'}.` : 'Innates (continued) box removed from page 2.');
    }
  }

  function applyImmunitiesCompact(on, input) {
    if (on === state.immunitiesCompact) return;
    const max = tableLimit('immunities', state.doll, on);
    const rows = state.immunities.slice();
    // Going compact on the ghost: drop the automatic blank rows, then refill to the new height.
    const target = on && state.doll === 'ghost' ? schema.defaultRows.immunities : max;
    while (rows.length > target && isBlankTableRow('immunities', rows[rows.length - 1])) rows.pop();
    const lost = rows.slice(max).filter((r) => !isBlankTableRow('immunities', r)).length;
    if (lost > 0 && !window.confirm(`Normal-height rows leave room for ${max} Immunities/Resistances rows, so ${lost} filled row${lost > 1 ? 's' : ''} would be removed. Switch anyway?`)) {
      input.checked = state.immunitiesCompact;
      return;
    }
    state.immunitiesCompact = on;
    state.immunities = rows.slice(0, max);
    renderAll();
    if (state.doll === 'ghost') fillGhostImmunities();
    scheduleSave();
    toast(on ? `Compact Immunities/Resistances rows: up to ${max} rows.` : `Normal Immunities/Resistances rows: up to ${max} rows.`);
  }

  /**
   * Switches paper doll (Default, Special Snow(man)flake, Woo I'm a ghost). The ghost has room for
   * more Colour Resist and Immunities rows, so switching to it adds blank rows and switching away
   * trims them; the user is asked before any filled row is removed.
   */
  function applyDoll(key, radio) {
    if (!schema.dolls[key] || key === state.doll) return;
    const ghost = key === 'ghost';
    const plans = {};
    let lost = 0;
    for (const k of ['colourResist', 'immunities']) {
      const rows = state[k].slice();
      const max = tableLimit(k, key);
      const target = ghost && k === 'colourResist' ? max : schema.defaultRows[k];
      while (rows.length > target && isBlankTableRow(k, rows[rows.length - 1])) rows.pop();
      if (ghost && k === 'colourResist') while (rows.length < target) rows.push({ text: '', fraction: '' });
      lost += rows.slice(max).filter((r) => !isBlankTableRow(k, r)).length;
      plans[k] = rows.slice(0, max);
    }
    if (lost > 0 && !window.confirm(`The ${schema.dolls[key]} paper doll has room for fewer Colour Resist / Immunities rows, so ${lost} filled row${lost > 1 ? 's' : ''} would be removed. Switch anyway?`)) {
      if (radio) $(`input[name="doll"][value="${state.doll}"]`).checked = true;
      return;
    }
    state.doll = key;
    state.colourResist = plans.colourResist;
    state.immunities = plans.immunities;
    renderAll();
    if (ghost) fillGhostImmunities();
    refill();
    scheduleSave();
    toast(`Paper doll: ${schema.dolls[key]}.`);
  }

  /* ---------------- board format: one page / "2 Page 15k+ nutjobs" ---------------- */
  // Settings that aren't "content": changing them alone doesn't need a warning when switching.
  const SETTING_KEYS = ['format', 'layout', 'doll', 'theme', 'watermark', 'tblpRows', 'poolsShow', 'consumablesShow', 'immunitiesCompact', 'guildsShow'];
  /** A comparable summary of everything the user has typed: settings and blank rows are left out. */
  function contentSignature(c) {
    const copy = JSON.parse(JSON.stringify(c));
    SETTING_KEYS.forEach((k) => delete copy[k]);
    ['ltm', 'castingStam', 'combatWary'].forEach((k) => delete copy[k].show);
    copy.innates = copy.innates.filter((r) => !isBlankRow(r));
    copy.consumables = copy.consumables.filter((r) => !isBlankRow(r));
    copy.colourResist = copy.colourResist.filter((r) => !isBlankTableRow('colourResist', r));
    copy.immunities = copy.immunities.filter((r) => !isBlankTableRow('immunities', r));
    return JSON.stringify(copy);
  }
  /** True if the board has anything typed into it (compared with a blank board). */
  const hasContent = () => contentSignature(state) !== contentSignature(blankCharacter());

  const blankLines = (lines) => lines.every((l) => l === '');
  /** True if the one-page Power box has anything in it. */
  const powerUsed = (c) => c.power.type !== '' || c.power.value != null || !blankLines(c.powerLines);
  /** True if a two-page power has been filled in (a name other than its default "Power n" counts). */
  const tpPowerUsed = (p, n) => (p.name !== '' && p.name !== `Power ${n + 1}`) || p.value != null || !blankLines(p.lines);

  /**
   * Switches between the one-page board and the "2 Page 15k+ nutjobs" board (the toggle in the top bar).
   * Shared fields (name, T.B.L.P., innates, pools, the doll, shields, resistances, Colour Resist,
   * Immunities, notes, points...) carry straight across. Matching fields are copied where the other
   * board has an empty place for them:
   *   one page -> two pages  Power (type, value, lines) -> Power 1
   *   two pages -> one page  Power 1 -> Power (its name becomes the type if it is one; first 5 lines)
   * Anything the other board doesn't show (Consumables, Guilds, the page-2 tables...) is kept, unprinted,
   * so switching back brings it back. If the board has content, the user is asked first, with a list
   * of what changes, including any filled rows that don't fit and would be removed.
   */
  function applyFormat(target) {
    if (!schema.formats[target] || target === state.format) return;
    const toTP = target === 'twoPage';
    const label = toTP ? schema.twoPage.label : 'one-page';
    const next = JSON.parse(JSON.stringify(state));
    next.format = target;
    const notes = [];

    // Carry matching fields into empty places on the other board.
    const tp = next.twoPage;
    if (toTP) {
      if (powerUsed(next) && !tpPowerUsed(tp.powers[0], 0)) {
        tp.powers[0].name = next.power.type ? `${next.power.type}` : tp.powers[0].name;
        tp.powers[0].value = next.power.value != null && next.power.value <= schema.int.poolValue[1] ? next.power.value : tp.powers[0].value;
        tp.powers[0].lines = next.powerLines.slice(0, schema.twoPage.powerLines);
        notes.push('Your Power box is copied into Power 1.');
      }
      // Pools carry straight across (shown under the Powers box if they were on this board).
      if (schema.layouts[next.layout].pools && !next.poolsShow) next.poolsShow = true;
      const hidden = [];
      const p1Lines = tpPowerLines(next).power1;
      if (next.powerLines.slice(p1Lines).some((l) => l !== '')) hidden.push(`Power lines after line ${p1Lines} (switch Power 2, Total Heal Innate or True Believer off to make room)`);
      if (next.consumables.some((r) => !isBlankRow(r))) hidden.push('Consumables');
      if (next.guildsShow && [next.social, next.professional, next.political].some((v) => v !== '')) hidden.push('Social / Professional / Political');
      if (hidden.length) notes.push(`Not shown on the two-page board (kept for if you switch back): ${hidden.join(', ')}.`);
      if (next.notes.trim()) notes.push('Notes move to page 2.');
    } else {
      if (tpPowerUsed(tp.powers[0], 0) && !powerUsed(next)) {
        const type = schema.powerTypes.find((t) => t.toLowerCase() === tp.powers[0].name.trim().toLowerCase());
        next.power.type = type || '';
        const [, maxPower] = schema.int.powerValue;
        next.power.value = tp.powers[0].value != null && tp.powers[0].value <= maxPower ? tp.powers[0].value : null;
        tp.powers[0].lines.slice(0, schema.rows.powerLines).forEach((l, i) => { next.powerLines[i] = l; });
        notes.push('Power 1 is copied into the Power box.');
      }
      const hidden = [];
      if (tpPowerUsed(tp.powers[1], 1)) hidden.push('Power 2');
      if (tp.powers[0].lines.slice(schema.rows.powerLines).some((l) => l !== '')) hidden.push(`Power 1 lines after line ${schema.rows.powerLines}`);
      if (tp.totalHeal.value != null || tp.totalHeal.line || tp.trueBeliever.value != null || tp.trueBeliever.line) hidden.push('Total Heal Innate / True Believer');
      const p2Used = tp.regeneration.some((r) => r.text || r.value) || [tp.damageGrades, tp.damageReductions, tp.additionalResistances].some((l) => !blankLines(l));
      if (p2Used) hidden.push('the page 2 tables');
      if (hidden.length) notes.push(`Not shown on the one-page board (kept for if you switch back): ${hidden.join(', ')}.`);
    }

    // Rows that won't fit: innates (the new board's column) and Immunities/Resistances.
    const cfg = toTP ? schema.twoPage : schema.layouts[next.layout];
    const innMax = toTP ? cfg.innates[1] + tpLayoutOf(next).contRows : next.consumablesShow ? cfg.innates[1] : cfg.innatesSolo;
    const innates = next.innates.slice();
    while (innates.length > Math.min(innMax, cfg.defaultInnates) && isBlankRow(innates[innates.length - 1])) innates.pop();
    while (innates.length < Math.min(innMax, cfg.defaultInnates)) innates.push({ name: '', count: null });
    let lost = innates.slice(innMax).filter((r) => !isBlankRow(r)).length;
    next.innates = innates.slice(0, innMax);
    if (!toTP && next.consumablesShow) {
      const room = Math.max(0, cfg.pipRowsCombined - next.innates.length);
      const con = next.consumables.slice();
      while (con.length > room && isBlankRow(con[con.length - 1])) con.pop();
      lost += con.slice(room).filter((r) => !isBlankRow(r)).length;
      next.consumables = con.slice(0, room);
    }
    const immMax = tableLimit('immunities', next.doll, next.immunitiesCompact, target);
    const imm = next.immunities.slice();
    while (imm.length > schema.defaultRows.immunities && isBlankTableRow('immunities', imm[imm.length - 1])) imm.pop();
    const lostImm = imm.slice(immMax).filter((r) => !isBlankTableRow('immunities', r)).length;
    next.immunities = imm.slice(0, immMax);
    if (lost) notes.push(`${lost} filled innate/consumable row${lost > 1 ? 's' : ''} won't fit and would be removed.`);
    if (lostImm) notes.push(`${lostImm} filled Immunities/Resistances row${lostImm > 1 ? 's' : ''} won't fit and would be removed.`);

    if (hasContent()) {
      const msg = (toTP
        ? 'Congratulations, you broke the game. Stop playing this character and start another.\n\n'
        : 'Switch to the Normal (one-page) board?\n\n')
        + (notes.length ? notes.map((n) => `• ${n}`).join('\n') : 'Everything on your board carries across.')
        + (lost || lostImm ? '\n\nSome information could be lost.' : '');
      if (!window.confirm(msg)) { updateLayoutForm(); return; }
    }
    state = sanitise(next).data;
    renderAll();
    if (state.doll === 'ghost') fillGhostImmunities();
    refill();
    fitPreview();
    scheduleSave();
    toast(toTP ? `Switched to the ${label} board: page 2 is below page 1 in the preview, and both pages print.` : 'Switched to the Normal one-page board.');
  }

  /** In ghost mode the right-hand tables stack: Reductions, then Resistance, then Immunities/Resistances. */
  function positionGhostTables() {
    const resist = $('.mini.resist', sheet);
    const immune = $('.mini.immune:not(.immune-over)', sheet);
    const gap = 3 * pxPerMm;
    if (state.doll !== 'ghost') {
      resist.style.removeProperty('top');
      immune.style.removeProperty('top');
      // The Immunities continuation table starts just under the Resistance table.
      $('.mini.immune-over', sheet).style.top = `${resist.offsetTop + resist.offsetHeight + 1.6 * pxPerMm}px`;
      return;
    }
    const reduce = $('.mini.reduce', sheet);
    resist.style.top = `${reduce.offsetTop + reduce.offsetHeight + gap}px`;
    immune.style.top = `${resist.offsetTop + resist.offsetHeight + gap}px`;
  }

  /** Shrinks text that would overflow its box (keeps long names on the sheet). */
  function fitAll() {
    positionGhostTables();
    sizePipRows();
    pages.flatMap((page) => $$('.fit, .loc-box, .loc-ring, .ptype, .pow-val', page)).forEach((node) => {
      node.style.fontSize = '';
      if (!node.textContent) return;
      let size = parseFloat(getComputedStyle(node).fontSize);
      const min = Math.max(1.4 * pxPerMm, size * 0.55);
      while (node.scrollWidth > node.clientWidth + 0.5 && size > min) {
        size -= 0.25;
        node.style.fontSize = `${size}px`;
      }
    });
    // Long power types (e.g. "Mantic Spirits"): shrink the Power heading slightly so the value box keeps its width.
    const powerK = $('#sheet-power .power-k', sheet);
    powerK.style.fontSize = '';
    powerK.style.letterSpacing = '';
    const powerHead = powerK.parentElement;
    if (powerHead.scrollWidth > powerHead.clientWidth + 0.5) {
      powerK.style.letterSpacing = '0.2mm';
      let size = parseFloat(getComputedStyle(powerK).fontSize);
      while (powerHead.scrollWidth > powerHead.clientWidth + 0.5 && size > 2.4 * pxPerMm) {
        size -= 0.1;
        powerK.style.fontSize = `${size}px`;
      }
    }
    // Class(es) / Race(s) wrap to two lines; only very long entries shrink.
    $$('.fit-lines', sheet).forEach((node) => {
      node.style.fontSize = '';
      if (node.dataset.full !== undefined) node.textContent = node.dataset.full;
      if (!node.textContent) return;
      let size = parseFloat(getComputedStyle(node).fontSize);
      const floor = (node.classList.contains('sv') ? 1.6 : 2.6) * pxPerMm;
      while (node.scrollHeight > node.clientHeight + 0.5 && size > floor) {
        size -= 0.25;
        node.style.fontSize = `${size}px`;
      }
      // Tiny standing boxes: if it still doesn't fit, cut at the last whole word that fits and add "…"
      // (the stored text is untouched; only what is drawn on the sheet is shortened).
      if (node.classList.contains('sv') && node.scrollHeight > node.clientHeight + 0.5) {
        const full = node.textContent;
        node.dataset.full = full;
        const fits = (n) => { node.textContent = `${cpSlice(full, n).trimEnd()}…`; return node.scrollHeight <= node.clientHeight + 0.5; };
        let lo = 0, hi = cpLength(full);
        while (lo < hi) { const mid = Math.ceil((lo + hi) / 2); if (fits(mid)) lo = mid; else hi = mid - 1; }
        const cut = cpSlice(full, lo);
        const space = cut.lastIndexOf(' ');
        node.textContent = `${(space > cut.length * 0.6 ? cut.slice(0, space) : cut).trimEnd()}…`;
      }
    });

    // A long Immunities/Resistances table can run below the doll: push the Notes box down to make room.
    const doll = $('.doll', sheet);
    const notesPanel = $('#sheet-notes', sheet);
    notesPanel.style.marginTop = '';
    const overflow = isTP() ? 0 : Math.max(...['#sheet-immunities', '#sheet-immunities-over'].map((id) => {
      const table = $(id, sheet).closest('table');
      return table.hidden ? -Infinity : table.offsetTop + table.offsetHeight;
    })) - doll.offsetHeight;
    if (overflow > 0) notesPanel.style.marginTop = `${overflow}px`;

    // Notes: page 1's box on the one-page board, page 2's ruled box on the two-page board.
    $$('[data-bind="notes"]', isTP() ? sheet2 : sheet).forEach((notes) => {
      notes.style.fontSize = '';
      notes.style.lineHeight = '';
      if (!notes.textContent) return;
      let size = parseFloat(getComputedStyle(notes).fontSize);
      while (notes.scrollHeight > notes.clientHeight + 0.5 && size > 1.7 * pxPerMm) {
        size -= 0.25;
        notes.style.fontSize = `${size}px`;
        if (isTP()) notes.style.lineHeight = '1.25';
      }
    });
  }

  /**
   * Call after any change to `state`. Redraws the sheet once on the next animation frame (several
   * changes in a row only cause one redraw) and schedules saving to the browser.
   */
  let renderQueued = false;
  function changed() {
    if (!renderQueued) {
      renderQueued = true;
      requestAnimationFrame(() => { renderQueued = false; renderSheet(); updateListControls(); });
    }
    scheduleSave();
  }

  /* ===================================================================
   * Damage Sheet, Kit and Equipment (the optional last page)
   * =================================================================== */
  /** Builds the Damage Sheet table on the sheet from state.damageSheet (text only, via textContent). */
  function renderDamageTable() {
    const ds = state.damageSheet;
    const g = $('#sheet-dmg-table');
    const nc = ds.columns.length;
    const nr = ds.rows.length;
    g.style.gridTemplateColumns = `31mm repeat(${nc}, minmax(0, 1fr))`;
    const cells = [];
    const cell = (cls, t) => { const c = el('span', cls, t); cells.push(c); };
    cell('hc h0', ds.corner);
    ds.columns.forEach((c, i) => cell(`hc${i === nc - 1 ? ' c-last' : ''}`, c));
    ds.rows.forEach((row, r) => {
      const last = r === nr - 1 ? ' r-last' : '';
      cell(`rl${last}`, '');
      cells[cells.length - 1].append(el('span', 'fit', row.label)); // (shrinks to fit a long heading)
      row.values.forEach((v, i) => cell(`v${last}${i === nc - 1 ? ' c-last' : ''}`, v == null ? '' : String(v)));
    });
    g.replaceChildren(...cells);
  }

  /** True if a Damage Sheet column (heading or any value) or row (heading or any value) has content. */
  const dmgColUsed = (i) => state.damageSheet.columns[i] !== '' || state.damageSheet.rows.some((r) => r.values[i] != null);
  const dmgRowUsed = (r) => r.label !== '' || r.values.some((v) => v != null);

  /**
   * The Damage Sheet editor: a grid of inputs matching the printed table (corner, column headings,
   * row headings and 0-99 values), with × buttons to remove a column or row. Rebuilt from state.
   */
  function renderDamageForm() {
    const ds = state.damageSheet;
    const D = schema.damageSheet;
    const T = schema.text;
    const grid = $('#dmg-grid');
    const nc = ds.columns.length;
    const textIn = (value, max, label, onSet, area = false) => {
      const inp = el(area ? 'textarea' : 'input', 'in');
      if (!area) inp.type = 'text';
      inp.maxLength = max;
      inp.value = value;
      inp.setAttribute('aria-label', label);
      inp.addEventListener('input', () => { onSet(liveText(inp.value, max, false)); changed(); });
      inp.addEventListener('change', () => { const v = finalText(inp.value, max, false); inp.value = v; onSet(v); changed(); });
      if (area) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
      return inp;
    };
    const xBtn = (label, disabled, onClick) => {
      const b = el('button', 'btn icon x', '\u00D7');
      b.type = 'button';
      b.setAttribute('aria-label', label);
      b.disabled = disabled;
      b.addEventListener('click', onClick);
      return b;
    };
    const head = el('tr', 'h');
    const corner = el('th', 'lab');
    corner.append(textIn(ds.corner, T.dmgLabel, 'Damage Sheet corner heading', (v) => { ds.corner = v; }));
    head.append(corner);
    ds.columns.forEach((c, i) => {
      const th = el('th', '');
      th.append(xBtn(`Remove column ${i + 1}`, nc <= D.columns[0], () => {
        if (dmgColUsed(i) && !window.confirm(`Remove column ${i + 1}${c ? ` ("${c}")` : ''} and its values?`)) return;
        ds.columns.splice(i, 1);
        ds.rows.forEach((r) => r.values.splice(i, 1));
        renderDamageForm(); changed();
      }));
      th.append(textIn(c, T.dmgHead, `Column ${i + 1} heading`, (v) => { ds.columns[i] = v; }, true));
      head.append(th);
    });
    head.append(el('th', ''));
    const body = ds.rows.map((row, r) => {
      const tr = el('tr', '');
      const lab = el('td', 'lab');
      lab.append(textIn(row.label, T.dmgLabel, `Row ${r + 1} heading`, (v) => { row.label = v; }));
      tr.append(lab);
      row.values.forEach((v, i) => {
        const td = el('td', 'val');
        const inp = el('input', 'in');
        inp.type = 'text';
        inp.inputMode = 'numeric';
        inp.maxLength = 2;
        inp.value = v == null ? '' : String(v);
        inp.setAttribute('aria-label', `${row.label || `Row ${r + 1}`}, ${ds.columns[i] || `column ${i + 1}`}`);
        inp.addEventListener('input', () => {
          const [min, max] = schema.int.dmgValue;
          const { value, error } = readInt(inp, min, max);
          inp.classList.toggle('bad', !!error);
          inp.title = error;
          row.values[i] = value;
          changed();
        });
        td.append(inp);
        tr.append(td);
      });
      const rx = el('td', 'rx');
      rx.append(xBtn(`Remove row ${r + 1}`, ds.rows.length <= D.rows[0], () => {
        if (dmgRowUsed(row) && !window.confirm(`Remove row ${r + 1}${row.label ? ` ("${row.label}")` : ''} and its values?`)) return;
        ds.rows.splice(r, 1);
        renderDamageForm(); KIT_KEYS.forEach(renderList); changed();
      }));
      tr.append(rx);
      return tr;
    });
    grid.replaceChildren(head, ...body);
    $('[data-dmg="addCol"]').disabled = nc >= D.columns[1];
    $('[data-dmg="addRow"]').disabled = ds.rows.length >= D.rows[1];
    $('#dmg-count').textContent = `${nc} / ${D.columns[1]} columns · ${ds.rows.length} / ${D.rows[1]} rows`;
    const k = kitMax(ds.rows.length);
    $('#kit-note').textContent = `Two lists side by side under the "Kit and Equipment" heading, with use boxes like Consumables. They start with ${D.kitDefault} rows each; with ${ds.rows.length} Damage Sheet row${ds.rows.length === 1 ? '' : 's'} up to ${k} rows each fit on the page.`;
  }

  $('[data-dmg="addCol"]').addEventListener('click', () => {
    const ds = state.damageSheet;
    if (ds.columns.length >= schema.damageSheet.columns[1]) return;
    ds.columns.push('');
    ds.rows.forEach((r) => r.values.push(null));
    renderDamageForm(); changed();
    $$('#dmg-grid tr.h textarea').pop()?.focus();
  });
  $('[data-dmg="addRow"]').addEventListener('click', () => {
    const ds = state.damageSheet;
    if (ds.rows.length >= schema.damageSheet.rows[1]) return;
    // A taller table leaves less room for Kit and Equipment: trim blank rows, ask before losing filled ones.
    const max = kitMax(ds.rows.length + 1);
    const lost = KIT_KEYS.reduce((n, key) => {
      const list = state[key].slice();
      while (list.length > max && isBlankRow(list[list.length - 1])) list.pop();
      return n + list.slice(max).length;
    }, 0);
    if (lost && !window.confirm(`Another Damage Sheet row leaves room for ${max} Kit and Equipment rows each, so ${lost} filled row${lost > 1 ? 's' : ''} would be removed. Go ahead?`)) return;
    for (const key of KIT_KEYS) {
      while (state[key].length > max && isBlankRow(state[key][state[key].length - 1])) state[key].pop();
      state[key].length = Math.min(state[key].length, max);
    }
    ds.rows.push({ label: '', values: ds.columns.map(() => null) });
    renderDamageForm(); KIT_KEYS.forEach(renderList); changed();
    $$('#dmg-grid td.lab input').pop()?.focus();
  });

  /** Switches the Damage Sheet, Kit and Equipment page on or off (its contents are kept either way). */
  function applyDamageSheetShow(on) {
    state.damageSheet.show = on;
    if (on) {
      // Kit and Equipment start with blank rows to fill in (never past what fits).
      const n = Math.min(schema.damageSheet.kitDefault, listLimits.kit);
      for (const key of KIT_KEYS) while (state[key].length < n) state[key].push(blankRow(key));
    }
    renderAll();
    fitPreview();
    scheduleSave();
    const total = pageCount();
    toast(on ? `${schema.damageSheet.label} added as page ${total} of ${total}: it prints last, in the same colours.` : `${schema.damageSheet.label} page removed (what you typed on it is kept).`);
  }

  /** Redraws everything: the form, every list, the layout-dependent notes and the sheet. */
  function renderAll() {
    fillForm();
    Object.keys(listLimits).forEach(renderList);
    updateLayoutForm();
    renderSheet();
  }

  /** Shows/hides form parts that depend on the layout and paper-doll choice. */
  function updateLayoutForm() {
    const cfg = layoutCfg();
    const tp = isTP();
    // Sections that belong to one board format only (data-format-show="normal" or "twoPage").
    $$('[data-format-show]').forEach((node) => { node.hidden = node.dataset.formatShow !== state.format; });
    $$('[data-format]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.format === state.format)));
    document.body.dataset.format = state.format;
    document.body.dataset.pages = String(pageCount());
    $('#dmg-form').hidden = !state.damageSheet.show;
    renderDamageForm();
    const tblpSel = $('[data-path="tblpRows"]', editor);
    const custom = !tp && cfg.tblpRows == null;
    tblpSel.disabled = !custom;
    $('#tblp-rows-note').textContent = tp
      ? `On the ${schema.twoPage.label} board the T.B.L.P. box sits under the paper doll, with ${tblpRowCount()} lines.`
      : custom ? '' : `Set by the ${cfg.label} layout: ${cfg.tblpRows} lines.`;
    const lineFields = (prefix, count, noteId) => {
      const inputs = $$(`[data-path^="${prefix}."]`, editor);
      inputs.forEach((inp, i) => { inp.hidden = i >= count; });
      const hiddenWithText = inputs.filter((inp, i) => i >= count && inp.value.trim() !== '').length;
      $(noteId).textContent = hiddenWithText ? `${hiddenWithText} hidden line${hiddenWithText > 1 ? 's' : ''} with text won't print in this layout (kept in case you switch back).` : '';
    };
    lineFields('tblpLines', tblpTextRows(), '#tblp-hidden-note');
    lineFields('powerLines', cfg.powerRows, '#power-hidden-note');
    // Two-page Powers box: parts switched off hide their fields; each power shows the lines that fit.
    const tpLines = tpPowerLines();
    $$('[data-tp-part]', editor).forEach((node) => { node.hidden = node.dataset.tpPart !== 'power1' && !state.twoPage.show[node.dataset.tpPart]; });
    let tpHiddenText = 0;
    for (let n = 0; n < schema.twoPage.powers; n++) {
      const inputs = $$(`[data-path^="twoPage.powers.${n}.lines."]`, editor);
      const count = tpLines[`power${n + 1}`];
      inputs.forEach((inp, i) => { inp.hidden = i >= count; });
      tpHiddenText += inputs.filter((inp, i) => i >= count && inp.value.trim() !== '').length;
    }
    $('#tp-power-hidden-note').textContent = tpHiddenText
      ? `${tpHiddenText} power line${tpHiddenText > 1 ? 's' : ''} with text ${tpHiddenText > 1 ? "don't" : "doesn't"} fit with these parts switched on (kept in case you switch one off).` : '';
    const lay = tpLayoutOf();
    const cont = state.twoPage.show.innatesCont;
    $$('[data-kind="tprows"]', editor).forEach((sel) => { sel.disabled = !cont; });
    $('#tp-cont-note').textContent = cont
      ? `Page 2 holds ${lay.p2Imm} Immunities/Resistances line${lay.p2Imm === 1 ? '' : 's'}, ${lay.p2Regen} Regeneration Powers line${lay.p2Regen === 1 ? '' : 's'} and ${lay.contRows} Innates (continued) row${lay.contRows === 1 ? '' : 's'}. Fewer lines in the other two tables make the box bigger; a table set to 0 is left off.`
      : `Switch this on to continue your innates on page 2. It takes the bottom of the Immunities/Resistances table, and you can shorten that table or Regeneration Powers to make it bigger.`;
    const poolsToggle = $('[data-path="poolsShow"]', editor);
    poolsToggle.disabled = !!cfg.pools;
    poolsToggle.checked = !!cfg.pools || state.poolsShow;
    $('#pools-note').textContent = cfg.pools ? `Always shown in the ${cfg.label} layout.` : '';
    $('#power-layout-note').textContent = cfg.power === false
      ? `The Power box isn't on the sheet in the ${cfg.label} layout. Anything entered here is kept in case you switch layouts.` : '';
    const ghost = state.doll === 'ghost';
 $('#tblp-ghost-note').textContent = ghost && !tp ? "While the paper doll is \u201cWoo I'm a ghost\u201d, the T.B.L.P. box is replaced by a small T.B.L.P. (and LTM, if shown) box inside the ghost. The lines below aren't printed but are kept." : '';
    $('details[data-sec="locations"]').hidden = ghost;
    $('#pip-combined').textContent = String(cfg.pipRowsCombined);
    $('#tp-innates-note').textContent = tp ? `On the ${schema.twoPage.label} board up to ${schema.twoPage.innates[1]} innates fit on page 1, with blank rows filling the rest of the column${cont ? `, and ${lay.contRows} more in Innates (continued) on page 2 (up to ${innateMax()} in all)` : ''}.` : '';
    const hiddenLocs = schema.dollHidden[state.doll] || [];
    $$('[data-loc]', editor).forEach((node) => { node.hidden = hiddenLocs.includes(node.dataset.loc); });
  }

  /* ===================================================================
   * Preview scaling, tabs and click-to-edit
   * =================================================================== */
  const layout = $('.layout');
  const preview = $('#preview');
  const scaler = $('#preview-scale');
  const SHEET_W = 210 * pxPerMm;
  const SHEET_H = 297 * pxPerMm;
  const desktopMq = window.matchMedia('(min-width: 981px)');

  /**
   * Scales the A4 sheet to fit the preview panel (and, on desktop, the window height) using a CSS
   * transform. Printing ignores this and uses the sheet at its real size.
   */
  function fitPreview() {
    const topbar = $('.topbar');
    document.documentElement.style.setProperty('--topbar-h', `${topbar.offsetHeight}px`);
    const w = preview.clientWidth;
    if (!w) return;
    let scale = w / SHEET_W;
    if (desktopMq.matches) scale = Math.min(scale, (window.innerHeight - topbar.offsetHeight - 32) / SHEET_H);
    scale = Math.max(0.25, Math.min(scale, 1.25));
    scaler.style.transform = `scale(${scale})`;
    scaler.style.marginLeft = `${Math.max(0, (w - SHEET_W * scale) / 2)}px`;
    // The two-page board shows page 2 under page 1 (8 mm apart); the preview scrolls to it.
    const n = pageCount();
    const height = SHEET_H * n + 8 * pxPerMm * (n - 1);
    preview.style.height = `${height * scale}px`;
  }
  new ResizeObserver(fitPreview).observe(preview);
  window.addEventListener('resize', fitPreview);

  /** Phones: switch between the Edit and Preview tabs. */
  function showView(view) {
    layout.dataset.view = view;
    $('#tab-edit').setAttribute('aria-selected', String(view === 'edit'));
    $('#tab-preview').setAttribute('aria-selected', String(view === 'preview'));
    if (view === 'preview') requestAnimationFrame(() => { fitPreview(); fitAll(); });
  }
  $('#tab-edit').addEventListener('click', () => showView('edit'));
  $('#tab-preview').addEventListener('click', () => showView('preview'));

  // Click-to-edit: clicking a part of the sheet opens, scrolls to and briefly highlights the
  // matching section of the editor (data-section on the sheet = data-sec on the form).
  scaler.addEventListener('click', (e) => {
    const target = e.target.closest('[data-section]');
    if (!target) return;
    const sec = $(`details[data-sec="${target.dataset.section}"]`);
    if (!sec) return;
    showView('edit');
    sec.open = true;
    sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    sec.classList.remove('flash');
    void sec.offsetWidth;
    sec.classList.add('flash');
    sec.querySelector('.in, button.add')?.focus({ preventScroll: true });
  });

  /* ===================================================================
   * Actions: new, import, export, print
   * =================================================================== */
  /**
   * Sends an imported file to api.php to be cleaned on the server, with the CSRF token from the
   * page. Throws if the server can't be reached or refuses, so the caller can clean it locally.
   */
  async function serverSanitise(payload) {
    // The Windows desktop version has no PHP server (its API address is left empty): clean locally.
    if (!API_URL) throw new Error('No server in this version.');
    const res = await fetch(API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload),
    });
    let body = null;
    try { body = await res.json(); } catch { /* fall through */ }
    if (!res.ok || !body || body.ok !== true) {
      const err = new Error(body?.error || `Server error (${res.status}).`);
      err.fromServer = res.status >= 400 && res.status < 500;
      throw err;
    }
    return body;
  }

  /** A safe file name from the character name (letters, digits and dashes only). */
  function slug(name) {
    const letters = { æ: 'ae', œ: 'oe', ß: 'ss', ø: 'o', þ: 'th', ð: 'd', ł: 'l', đ: 'd', ı: 'i' };
    const s = (name || '').toLowerCase().replace(/[æœßøþðłđı]/g, (ch) => letters[ch])
      .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '');
    return s || 'character';
  }

  /*
   * The four top-bar buttons (matched by their data-action):
   *   new     start a blank board (after confirming, as it replaces the saved board)
   *   import  open the file picker; the file is handled by the #import-file listener below
   *   export  clean the board, wrap it with app name, version, generator and date, and download
   *           it as indented, human-readable JSON named after the character
   *   print   redraw at full size, then open the browser's print dialog (Save as PDF lives there)
   */
  const actions = {
    new() {
      if (!window.confirm(`Start a blank board? This replaces the board saved ${SAVED_WHERE}. Export first if you want to keep it.`)) return;
      const format = state.format; // a blank board of the same kind (one page or two)
      state = blankCharacter();
      state.format = format;
      renderAll();
      refill();
      fitPreview();
      scheduleSave();
      toast('Started a blank board.');
    },
    import() {
      $('#import-file').click();
    },
    export() {
      const clean = sanitise(state).data;
      const payload = {
        app: 'battleboard',
        version: schema.version,
        generator: document.querySelector('meta[name="bb-generator"]').content,
        exportedAt: new Date().toISOString(),
        character: clean,
      };
      const contents = JSON.stringify(payload, null, 2) + '\n';
      const fileName = `${slug(clean.name)}-battleboard.json`;
      // Windows desktop app (Tauri): show the normal Windows "Save As" dialog instead of a download.
      // The app's Rust side writes the file (see save_export in src-tauri/src/main.rs).
      const tauri = window.__TAURI__?.core;
      if (tauri) {
        tauri.invoke('save_export', { fileName, contents })
          .then((savedTo) => { if (savedTo) toast(`Exported ${savedTo}`); })
          .catch((err) => toast(`Sorry, the file couldn't be saved: ${err}`));
        return;
      }
      const blob = new Blob([contents], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = el('a');
      a.href = url;
      a.download = fileName;
      document.body.append(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      toast(`Exported ${a.download}`);
    },
    print() {
      document.activeElement?.blur?.();
      renderSheet();
      setTimeout(() => { fitAll(); window.print(); }, 50);
    },
  };
  $$('[data-action]').forEach((b) => b.addEventListener('click', () => actions[b.dataset.action]()));
  // Board format toggle (top bar): Normal / 2 Page 15k+ nutjobs.
  $$('[data-format]').forEach((b) => b.addEventListener('click', () => applyFormat(b.dataset.format)));
  window.addEventListener('beforeprint', fitAll);

  /**
   * Decodes an imported file. Handles files saved by Windows Notepad and similar editors:
   * UTF-8 with a byte-order mark, and UTF-16 (little or big endian) with a byte-order mark.
   */
  function decodeText(buffer) {
    const b = new Uint8Array(buffer);
    let enc = 'utf-8';
    let skip = 0;
    if (b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF) skip = 3;
    else if (b[0] === 0xFF && b[1] === 0xFE) { enc = 'utf-16le'; skip = 2; }
    else if (b[0] === 0xFE && b[1] === 0xFF) { enc = 'utf-16be'; skip = 2; }
    return new TextDecoder(enc).decode(b.subarray(skip)).replace(/^\uFEFF/, '');
  }

  /*
   * Import, step by step:
   *  1. Refuse files over 128 KB (UTF-16 files may be up to twice that on disk).
   *  2. Decode the text (UTF-8 or UTF-16) and parse the JSON.
   *  3. Clean it on the server; if that fails for any reason, clean it here with the same rules.
   *  4. Replace the board, redraw, top up blank rows for "fill" layouts, save, and show the
   *     result, listing any adjustments that were made.
   */
  $('#import-file').addEventListener('change', async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > MAX_IMPORT_BYTES * 2) { toast('That file is too large (128 KB maximum).'); return; } // UTF-16 files are twice the size
    let parsed;
    try {
      const text = decodeText(await file.arrayBuffer());
      if (new TextEncoder().encode(text).length > MAX_IMPORT_BYTES) { toast('That file is too large (128 KB maximum).'); return; }
      parsed = JSON.parse(text);
    } catch {
      toast('That file is not valid JSON.');
      return;
    }
    let result;
    try {
      result = await serverSanitise(parsed);
    } catch {
      // Server unreachable, busy (rate limit) or session expired: the browser applies exactly the
      // same rules (src/Sanitiser.php and sanitise() are kept identical), so import locally instead.
      result = sanitise(parsed);
    }
    // Belt and braces: re-run the local rules on whatever came back.
    // The file says which board it is ("format"); files without it are one-page boards. The top-bar
    // toggle follows the file, whichever board was showing before.
    state = sanitise(result.data).data;
    renderAll();
    refill(); // fill layouts top up blank rows to the space available in this version
    fitPreview();
    scheduleSave();
    const kind = (isTP() ? ` as a ${schema.twoPage.label} (two-page) board` : ' as a one-page board')
      + (state.damageSheet.show ? `, plus the ${schema.damageSheet.label} page` : '');
    const warnings = result.warnings || [];
    if (warnings.length) toast(`Imported ${file.name}${kind} with ${warnings.length} adjustment${warnings.length > 1 ? 's' : ''}:`, warnings);
    else toast(`Imported ${file.name}${kind}.`);
  });

  /* ===================================================================
   * Ink saver & start-up
   * =================================================================== */
  // Ink-saver: a white page with coloured lines only. Remembered separately from the board.
  const ink = $('#ink-saver');
  try { ink.checked = localStorage.getItem(INK_KEY) === '1'; } catch { /* ignore */ }
  const applyInk = () => document.body.classList.toggle('ink-saver', ink.checked);
  ink.addEventListener('change', () => {
    applyInk();
    try { localStorage.setItem(INK_KEY, ink.checked ? '1' : '0'); } catch { /* ignore */ }
  });
  applyInk();

  // Start-up: draw everything, then again once the fantasy fonts have loaded (text sizes change
  // when the real fonts arrive, so the fill and fit steps are re-run).
  renderAll();
  fitPreview();
  if (document.fonts?.ready) document.fonts.ready.then(() => { refill(); fitAll(); fitPreview(); });
  let hadSaved = false;
  try { hadSaved = !!localStorage.getItem(STORE_KEY); } catch { /* storage blocked */ }
  setStatus(hadSaved ? `Loaded your saved board (kept ${SAVED_WHERE}).` : 'Fill in the sections below — the board updates as you type.');
})();
