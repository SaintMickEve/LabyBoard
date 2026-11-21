<?php

declare(strict_types=1);

/**
 * The app's single page: the editor form on the left and the live A4 battleboard preview on the right.
 *
 * HOW THE PAGE IS PUT TOGETHER
 * PHP builds the form fields from config/schema.json, so limits (lengths, number ranges, row counts,
 * dropdown options) are defined in one place. Every field carries data- attributes that tell
 * assets/js/app.js what it edits:
 *   data-path="ltm.value"   where the value lives in the character data (dots go into nested objects)
 *   data-kind="int|text|bool|enum|multiline"   how the value is read and cleaned
 *   data-min / data-max     the allowed range for numbers
 *   data-autoshow="..."     typing a value switches the named optional row on (e.g. LTM)
 * The sheet itself is templates/board.php. Its elements carry data-bind="path", and app.js writes
 * each value into them as plain text whenever anything changes.
 *
 * The page contains no character data: that is loaded by app.js from the browser's own storage.
 * Everything PHP writes into the HTML goes through e(), which escapes it.
 */

namespace Battleboard;

require __DIR__ . '/src/bootstrap.php';

// Load the schema, make sure this visitor has a CSRF token for imports, then release the session
// lock straight away (the page doesn't need the session again).
$schema = Sanitiser::loadSchema(SCHEMA_PATH);
$csrf = csrfToken();
session_write_close();

sendSecurityHeaders();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

// Short names for the schema's text-length limits and number ranges, used by the field builders.
// $jsonFlags make json_encode() escape < > & ' " so the schema can't break out of its <script> tag.
$t = $schema['text'];
$int = $schema['int'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

/**
 * Numeric text input (text + inputmode avoids the quirks of type=number on phones).
 * Builds an <input> for a whole-number field: $path is where it's stored, $range is [min, max] from
 * the schema (also used for maxlength and the placeholder), $extra adds attributes such as data-autoshow.
 */
$num = static function (string $path, array $range, string $label, string $extra = ''): string {
    $maxlen = strlen((string) $range[1]);
    return '<input class="in num" type="text" inputmode="numeric" autocomplete="off" '
        . 'data-path="' . e($path) . '" data-kind="int" data-min="' . e((string) $range[0]) . '" data-max="' . e((string) $range[1]) . '" '
        . 'maxlength="' . $maxlen . '" aria-label="' . e($label) . '" placeholder="' . e($range[0] . '–' . $range[1]) . '"' . $extra . '>';
};
/** Builds a single-line text <input> limited to $max characters. */
$text = static function (string $path, int $max, string $label, string $placeholder = ''): string {
    return '<input class="in" type="text" autocomplete="off" spellcheck="true" data-path="' . e($path) . '" data-kind="text" '
        . 'maxlength="' . $max . '" aria-label="' . e($label) . '"' . ($placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '') . '>';
};
?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<!-- Values app.js reads at start-up: the import token, the generator name written into exports,
     and the URLs of the watermark drawings and the import API. -->
<meta name="csrf-token" content="<?= e($csrf) ?>">
<meta name="bb-generator" content="<?= e(SITE_TITLE) ?>">
<meta name="bb-watermarks" content="<?= e(url('assets/watermarks.svg?v=1')) ?>">
<meta name="bb-api" content="<?= e(url('api.php?action=sanitise')) ?>">
<meta name="theme-color" content="#2f241a">
<title><?= e(SITE_TITLE) ?></title>
<link rel="icon" href="<?= e(url('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="<?= e(url('assets/fonts/chorus-mediumitalic.woff')) ?>" as="font" type="font/woff" crossorigin>
<link rel="preload" href="<?= e(url('assets/fonts/pagella-regular.woff')) ?>" as="font" type="font/woff" crossorigin>
<!-- The ?v= number changes with each release so browsers fetch the new files instead of old cached ones. -->
<link rel="stylesheet" href="<?= e(url('assets/css/board.css?v=79')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css?v=79')) ?>">
<!-- The whole schema as data (not code), so the browser applies exactly the same limits as the server. -->
<script type="application/json" id="bb-schema"><?= json_encode($schema, $jsonFlags) ?></script>
<script src="<?= e(url('assets/js/app.js?v=79')) ?>" defer></script>
</head>
<body>
<a class="skip" href="#editor">Skip to editor</a>

<!-- Top bar: New / Import / Export / Print. app.js handles each button by its data-action. -->
<header class="topbar">
  <a class="brand" href="<?= e(url()) ?>" title="Home"><span class="brand-mark" aria-hidden="true">&#9670;</span> <span class="brand-name">Labyrinthe Battleboard Generator</span> <span class="brand-rc">RC 3.2.0</span></a>
  <nav class="actions" aria-label="Board actions">
    <?php /* Board format toggle: the normal one-page board or the "2 Page 15k+ nutjobs" two-page board (app.js applyFormat). */ ?>
    <div class="fmt-toggle" role="group" aria-label="Board format">
      <?php foreach ($schema['formats'] as $key => $label): ?>
        <button type="button" class="btn fmt" data-format="<?= e($key) ?>" aria-pressed="<?= $key === $schema['formatDefault'] ? 'true' : 'false' ?>" title="<?= e($key === 'twoPage' ? $label . ', two A4 pages: ' . $schema['twoPage']['description'] : 'The one-page battleboard') ?>"<?= $key === 'twoPage' ? ' aria-label="' . e($label) . '"' : '' ?>><?php
          // The two-page label is split over two lines ("2 Page" / "15k+ nutjobs") to keep the button small.
          $words = explode(' ', $label);
          if ($key === 'twoPage' && count($words) > 2): ?><span class="l1"><?= e($words[0] . ' ' . $words[1]) ?></span><span class="l2"><?= e(implode(' ', array_slice($words, 2))) ?></span><?php
          else: ?><?= e($label) ?><?php endif; ?></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn" data-action="new" title="Start a blank board">New</button>
    <button type="button" class="btn" data-action="import" title="Load a JSON file">Import</button>
    <button type="button" class="btn" data-action="export" title="Save your board as a JSON file">Export</button>
    <button type="button" class="btn btn-primary" data-action="pdf" title="Save the board as an A4 PDF file, ready to print">Save PDF</button>
  </nav>
  <input type="file" id="import-file" accept="application/json,.json" hidden>
</header>

<!-- On phones only one panel fits, so these tabs switch between the editor and the preview. -->
<div class="tabs" role="tablist" aria-label="View">
  <button type="button" role="tab" id="tab-edit" aria-controls="editor" aria-selected="true">Edit</button>
  <button type="button" role="tab" id="tab-preview" aria-controls="preview" aria-selected="false">Preview</button>
</div>

<div class="layout" data-view="edit">
  <!-- EDITOR: one collapsible <details> section per part of the sheet. data-sec matches the
       data-section on the sheet, so tapping a part of the preview opens the matching section. -->
  <main id="editor" class="editor" role="tabpanel" aria-labelledby="tab-edit">
    <p class="status" id="status" role="status" aria-live="polite"></p>

    <details class="sec" data-sec="identity" open>
      <summary>Character</summary>
      <div class="grid">
        <label class="field">Character name <?= $text('name', $t['name'], 'Character name') ?></label>
        <label class="field">Alignment
          <select class="in" data-path="alignment" data-kind="enum" data-options="alignments" aria-label="Alignment">
            <option value="">— choose —</option>
            <?php
            $ethic = ['G' => 'Good', 'N' => 'Neutral', 'E' => 'Evil'];
            $order = ['L' => 'Lawful', 'N' => 'Neutral', 'C' => 'Chaotic'];
            foreach ($schema['alignments'] as $code):
                $desc = ($ethic[$code[0]] ?? '') . ' ' . ($order[$code[1]] ?? '');
            ?>
              <option value="<?= e($code) ?>"><?= e($code . ' — ' . $desc) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Class(es) <?= $text('classes', $t['classes'], 'Class(es)') ?></label>
        <label class="field">Race(s) <?= $text('races', $t['races'], 'Race(s)') ?></label>
      </div>
    </details>

    <details class="sec" data-sec="tblp">
      <summary>T.B.L.P.</summary>
      <label class="field inline">T.B.L.P. <?= $num('tblp', $int['tblp'], 'T.B.L.P.') ?></label>
      <label class="check"><input type="checkbox" data-path="ltm.show" data-kind="bool"> Show LTM on the T.B.L.P. heading</label>
      <label class="field inline">LTM <?= $num('ltm.value', $int['ltm'], 'LTM', ' data-autoshow="ltm.show"') ?></label>
      <label class="check"><input type="checkbox" data-path="castingStam.show" data-kind="bool"> Make the bottom line of the T.B.L.P. box Casting Stam</label>
      <label class="field inline">Casting Stam <?= $num('castingStam.value', $int['castingStam'], 'Casting Stam', ' data-autoshow="castingStam.show"') ?></label>
      <label class="field inline">Lines in the T.B.L.P. box
        <select class="in num" data-path="tblpRows" data-kind="int" data-min="<?= e((string) $int['tblpRows'][0]) ?>" data-max="<?= e((string) $int['tblpRows'][1]) ?>" aria-label="Lines in the T.B.L.P. box">
          <?php for ($n = $int['tblpRows'][1]; $n >= $int['tblpRows'][0]; $n--): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?>
        </select>
      </label>
      <p class="hint warn" id="tblp-ghost-note"></p>
      <p class="hint" id="tblp-rows-note"></p>
      <p class="hint">Lines under the T.B.L.P. heading (optional). Fewer lines move the boxes below up the page.</p>
      <p class="hint warn" id="tblp-hidden-note"></p>
      <div class="stack">
        <?php for ($i = 0; $i < $schema['rows']['tblpLines']; $i++): ?>
          <?= $text("tblpLines.$i", $t['tblpLine'], 'T.B.L.P. line ' . ($i + 1), 'Line ' . ($i + 1)) ?>
        <?php endfor; ?>
      </div>
    </details>

    <fieldset data-format-show="normal" class="sec picker" data-sec="layout">
      <legend>Board layout</legend>
      <?php foreach ($schema['layouts'] as $key => $cfg): ?>
        <label class="pick">
          <input type="radio" name="layout" value="<?= e($key) ?>" data-path="layout" data-kind="enum" data-options="layouts">
          <span><strong><?= e($cfg['label']) ?></strong><small><?= e($cfg['description']) ?></small></span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <details data-format-show="normal" class="sec" data-sec="power">
      <summary>Power</summary>
      <p class="hint warn" id="power-layout-note"></p>
      <div class="grid">
        <label class="field">Power type
          <select class="in" data-path="power.type" data-kind="enum" data-options="powerTypes" aria-label="Power type">
            <option value="">— choose —</option>
            <?php foreach ($schema['powerTypes'] as $type): ?>
              <option value="<?= e($type) ?>"><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Power <?= $num('power.value', $int['powerValue'], 'Power value') ?></label>
      </div>
      <p class="hint">Lines under the Power heading (optional)</p>
      <p class="hint warn" id="power-hidden-note"></p>
      <div class="stack">
        <?php for ($i = 0; $i < $schema['rows']['powerLines']; $i++): ?>
          <?= $text("powerLines.$i", $t['powerLine'], 'Power line ' . ($i + 1), 'Line ' . ($i + 1)) ?>
        <?php endfor; ?>
      </div>
    </details>

    <?php /* 2 Page 15k+ nutjobs only: the Powers box at the top of page 1. */ ?>
    <details data-format-show="twoPage" class="sec" data-sec="tpPowers">
      <summary>Powers</summary>
      <p class="hint">The Powers box at the top of page 1: two named powers (for example Mana or Spirits) with a value from 0 to 999 and lines beneath, then Total Heal Innate and True Believer with a value and one line each. Switch off any part you don't need: the box stays the same size and the powers left on get more lines.</p>
      <div class="checks">
        <label class="check"><input type="checkbox" data-path="twoPage.show.power2" data-kind="bool"> Power 2</label>
        <label class="check"><input type="checkbox" data-path="twoPage.show.totalHeal" data-kind="bool"> Total Heal Innate</label>
        <label class="check"><input type="checkbox" data-path="twoPage.show.trueBeliever" data-kind="bool"> True Believer</label>
      </div>
      <?php for ($n = 0; $n < $schema['twoPage']['powers']; $n++): ?>
        <div class="tp-part-form" data-tp-part="power<?= $n + 1 ?>">
          <div class="grid">
            <label class="field">Power <?= $n + 1 ?> name <?= $text("twoPage.powers.$n.name", $t['tpPowerName'], 'Power ' . ($n + 1) . ' name', 'Power ' . ($n + 1)) ?></label>
            <label class="field">Value <?= $num("twoPage.powers.$n.value", $int['poolValue'], 'Power ' . ($n + 1) . ' value') ?></label>
          </div>
          <div class="stack">
            <?php for ($i = 0; $i < $schema['twoPage']['powerLines']; $i++): ?>
              <?= $text("twoPage.powers.$n.lines.$i", $t['powerLine'], 'Power ' . ($n + 1) . ' line ' . ($i + 1), 'Line ' . ($i + 1)) ?>
            <?php endfor; ?>
          </div>
        </div>
      <?php endfor; ?>
      <p class="hint" id="tp-power-hidden-note"></p>
      <?php foreach (['totalHeal' => 'Total Heal Innate', 'trueBeliever' => 'True Believer'] as $key => $label): ?>
        <div class="grid tp-part-form" data-tp-part="<?= e($key) ?>">
          <label class="field"><?= e($label) ?> <?= $num("twoPage.$key.value", $int['poolValue'], "$label value") ?></label>
          <label class="field">Line <?= $text("twoPage.$key.line", $t['powerLine'], "$label line", 'Line') ?></label>
        </div>
      <?php endforeach; ?>
    </details>

    <details class="sec" data-sec="pools">
      <summary>Pools</summary>
      <label class="check"><input type="checkbox" data-path="poolsShow" data-kind="bool"> Show the three pools on this board, below the Power box and above Innates</label>
      <p class="hint" id="pools-note"></p>
      <p class="hint">Rename each pool and give it a number from 0 to 999. Each pool has three empty lines beneath it. In layouts without spare room, the innate and consumable rows shrink a little to make space.</p>
      <div class="pool-grid">
        <?php for ($n = 0; $n < 3; $n++): ?>
          <?= $text("pools.$n.name", $t['poolName'], 'Pool ' . ($n + 1) . ' name', 'Pool ' . ($n + 1)) ?>
          <?= $num("pools.$n.value", $int['poolValue'], 'Pool ' . ($n + 1) . ' value') ?>
        <?php endfor; ?>
      </div>
    </details>

    <details class="sec" data-sec="powB">
      <summary>Pow column</summary>
      <p class="hint">The heading and the number in brackets at the top of the narrow column. The boxes below are left blank for pen.</p>
      <div class="grid">
        <label class="field">Heading <?= $text('powB.label', $t['powLabel'], 'Pow column heading', 'Pow') ?></label>
        <label class="field">Number in brackets <?= $num('powB.value', $int['powValue'], 'Pow column number') ?></label>
      </div>
    </details>

    <details class="sec" data-sec="innates">
      <summary>Innates <span class="count" data-count="innates"></span></summary>
      <button type="button" class="btn sort" data-sort="innates" title="Sort the innates into alphabetical order by name">Sort A&ndash;Z</button>
      <p class="hint">Name each innate, then tap how many uses it has (1–8). Tap the same number again to clear it.</p>
      <p class="hint" id="tp-innates-note"></p>
      <div class="tp-cont" data-format-show="twoPage">
        <label class="check"><input type="checkbox" data-path="twoPage.show.innatesCont" data-kind="bool"> Innates (continued) box on page 2, for innates that don't fit on page 1</label>
        <div class="grid">
          <label class="field">Page 2 Immunities/Resistances lines
            <select class="in" data-path="twoPage.rows.immunities" data-kind="tprows" aria-label="Page 2 Immunities/Resistances lines">
              <?php for ($n = $int['tpImmRows'][1]; $n >= $int['tpImmRows'][0]; $n--): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?>
            </select></label>
          <label class="field">Page 2 Regeneration Powers lines
            <select class="in" data-path="twoPage.rows.regeneration" data-kind="tprows" aria-label="Page 2 Regeneration Powers lines">
              <?php for ($n = $int['tpRegenRows'][1]; $n >= $int['tpRegenRows'][0]; $n--): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?>
            </select></label>
        </div>
        <p class="hint" id="tp-cont-note"></p>
      </div>
      <ol class="list" data-list="innates"></ol>
      <button type="button" class="btn add" data-add="innates">+ Add innate</button>
    </details>

    <details data-format-show="normal" class="sec" data-sec="consumables">
      <summary>Consumables <span class="count" data-count="consumables"></span></summary>
      <label class="check"><input type="checkbox" data-path="consumablesShow" data-kind="bool"> Show the Consumables table on the sheet (switch off to make room for more innates)</label>
      <p class="hint">Innates and consumables share space: up to <span id="pip-combined"></span> rows in total in this layout.</p>
      <ol class="list" data-list="consumables"></ol>
      <button type="button" class="btn add" data-add="consumables">+ Add consumable</button>
    </details>

    <details class="sec" data-sec="resChance">
      <summary>Res Chance</summary>
      <label class="field inline">Res Chance <?= $num('resChance', $int['resChance'], 'Res Chance') ?></label>
    </details>

    <details class="sec" data-sec="armour">
      <summary>Armour (shields)</summary>
      <div class="grid shields">
        <?php foreach ($schema['armour'] as $a): ?>
          <label class="field"><?= e(strtoupper($a)) ?> <?= $num("armour.$a", $int['armour'], strtoupper($a)) ?></label>
        <?php endforeach; ?>
      </div>
    </details>

    <fieldset class="sec picker" data-sec="doll">
      <legend>Paper doll</legend>
      <?php foreach ($schema['dolls'] as $key => $label): ?>
        <label class="pick">
          <input type="radio" name="doll" value="<?= e($key) ?>" data-path="doll" data-kind="enum" data-options="dolls">
          <span><strong><?= e($label) ?></strong><small><?= e($schema['dollDescriptions'][$key] ?? '') ?></small></span>
        </label>
      <?php endforeach; ?>
    </fieldset>

    <details class="sec" data-sec="locations">
      <summary>Locations (paper doll)</summary>
      <div class="loc-table" role="group" aria-label="Locations">
        <span class="lt-h">Location</span><span class="lt-h">loc (1–999)</span><span class="lt-h">AC (0–36)</span>
        <?php foreach ($schema['locations'] as $key => $label): ?>
          <span class="lt-name" data-loc="<?= e($key) ?>"><?= e($label) ?></span>
          <?= $num("locations.$key.hits", $int['locHits'], "$label loc", ' data-loc="' . e($key) . '"') ?>
          <?= $num("locations.$key.ac", $int['locAc'], "$label AC", ' data-loc="' . e($key) . '"') ?>
        <?php endforeach; ?>
      </div>
    </details>

    <details class="sec" data-sec="resist">
      <summary>Resistances</summary>
      <div class="grid five">
        <?php foreach ($schema['resistances'] as $key => $label): ?>
          <label class="field"><?= e($label) ?> <?= $num("resist.$key", $int['resist'], "$label resistance level") ?></label>
        <?php endforeach; ?>
      </div>
    </details>

    <details class="sec" data-sec="reductions">
      <summary>Reductions</summary>
      <div class="grid three">
        <?php foreach ($schema['reductions'] as $key => $label): ?>
          <label class="field"><?= e($label) ?> <?= $text("reductions.$key", $t['reduction'], "$label reduction", 'max 4') ?></label>
        <?php endforeach; ?>
      </div>
      <label class="check"><input type="checkbox" data-path="combatWary.show" data-kind="bool"> Add a Combat Wary row</label>
      <label class="field inline">Combat Wary
        <input class="in" type="text" autocomplete="off" data-path="combatWary.value" data-kind="text" data-autoshow="combatWary.show" maxlength="<?= e((string) $t['reduction']) ?>" aria-label="Combat Wary reduction" placeholder="max 4">
      </label>
    </details>

    <details class="sec" data-sec="colourResist">
      <summary>Colour Resist <span class="count" data-count="colourResist"></span></summary>
      <p class="hint">Text on the left, a fraction such as 1/2 on the right.</p>
      <ol class="list" data-list="colourResist"></ol>
      <button type="button" class="btn add" data-add="colourResist">+ Add colour resist</button>
    </details>

    <details class="sec" data-sec="immunities">
      <summary>Immunities/Resistances <span class="count" data-count="immunities"></span></summary>
      <p class="hint">Short text on the left; a number, Y or N on the right. On the Default and Snow(man)flake dolls, rows that don't fit in the main table continue in the space below the Resistance table.</p>
      <p class="hint" data-format-show="twoPage">On the <?= e($schema['twoPage']['label']) ?> board, rows that don't fit in the table by the paper doll continue on page 2 (up to <?= (int) $schema['twoPage']['page2']['immunities'] ?> more rows, or fewer if you make room there for Innates (continued): see Innates).</p>
      <label class="check"><input type="checkbox" data-path="immunitiesCompact" data-kind="bool"> Compact rows: shorter lines, so more rows fit</label>
      <ol class="list" data-list="immunities"></ol>
      <button type="button" class="btn add" data-add="immunities">+ Add row</button>
    </details>

    <?php /* 2 Page 15k+ nutjobs only: the fixed tables on page 2. */ ?>
    <details data-format-show="twoPage" class="sec" data-sec="tpRegen">
      <summary>Page 2: Regeneration Powers</summary>
      <p class="hint">Text on the left, a short value (up to <?= (int) $t['tpRegenValue'] ?> characters) on the right. With the Innates (continued) box on, you can show fewer lines (see Innates); hidden lines keep their text.</p>
      <div class="stack">
        <?php for ($i = 0; $i < $schema['twoPage']['page2']['regeneration']; $i++): ?>
          <div class="item-main pair">
            <?= $text("twoPage.regeneration.$i.text", $t['tpLine'], 'Regeneration power ' . ($i + 1), 'Regeneration power ' . ($i + 1)) ?>
            <input class="in small" type="text" autocomplete="off" data-path="twoPage.regeneration.<?= $i ?>.value" data-kind="text" maxlength="<?= e((string) $t['tpRegenValue']) ?>" aria-label="Regeneration power <?= $i + 1 ?> value" placeholder="value">
          </div>
        <?php endfor; ?>
      </div>
    </details>
    <?php foreach (['tpDamageGrades' => ['damageGrades', 'Damage Grades'], 'tpDamageReductions' => ['damageReductions', 'Damage Reductions'], 'tpAdditional' => ['additionalResistances', 'Additional Resistances']] as $sec => [$key, $label]): ?>
    <details data-format-show="twoPage" class="sec" data-sec="<?= e($sec) ?>">
      <summary>Page 2: <?= e($label) ?></summary>
      <div class="stack">
        <?php for ($i = 0; $i < $schema['twoPage']['page2'][$key]; $i++): ?>
          <?= $text("twoPage.$key.$i", $t['tpLine'], "$label line " . ($i + 1), 'Line ' . ($i + 1)) ?>
        <?php endfor; ?>
      </div>
    </details>
    <?php endforeach; ?>

    <details class="sec" data-sec="notes">
      <summary><span data-format-show="normal">Notes, Points &amp; Guilds</span><span data-format-show="twoPage" hidden>Notes &amp; Points</span></summary>
      <p class="hint" data-format-show="twoPage" hidden>On the <?= e($schema['twoPage']['label']) ?> board, Notes print on page 2 and Points sit beside Res Chance. The Guilds boxes aren't on this board (anything in them is kept).</p>
      <label class="field">Notes
        <textarea class="in" rows="6" data-path="notes" data-kind="multiline" maxlength="<?= e((string) $t['notes']) ?>" aria-describedby="notes-count"></textarea>
      </label>
      <p class="hint" id="notes-count"></p>
      <label class="field inline">Points <?= $num('points', $int['points'], 'Points') ?></label>
      <div data-format-show="normal">
      <label class="check"><input type="checkbox" data-path="guildsShow" data-kind="bool"> Show the Guilds boxes (Social, Professional, Political) on the sheet (switch off for more room for notes)</label>
      <div id="guild-fields">
      <div class="grid three standing-fields">
        <?php foreach (['social' => 'Social', 'professional' => 'Professional', 'political' => 'Political'] as $key => $label): ?>
          <label class="field"><?= e($label) ?>
            <textarea class="in" rows="2" data-path="<?= e($key) ?>" data-kind="text" maxlength="<?= e((string) $t['standing']) ?>" aria-label="<?= e($label) ?>"></textarea>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Social, Professional and Political print in small type in three boxes under the Notes heading (two short lines, 50 characters each). The box label disappears once you type in it; text that doesn't fit is cut off with "…".</p>
      </div>
      </div>
    </details>

    <details class="sec" data-sec="damageSheet">
      <summary><?= e($schema['damageSheet']['label']) ?></summary>
      <label class="check"><input type="checkbox" data-path="damageSheet.show" data-kind="bool"> Add a <?= e($schema['damageSheet']['label']) ?> page (always printed last, in the same colours)</label>
      <div class="dmg-form" id="dmg-form">
        <h3 class="sub">Damage Sheet</h3>
        <p class="hint">Headings are bold: up to <?= (int) $t['dmgHead'] ?> characters across the top and <?= (int) $t['dmgLabel'] ?> down the side. Every other box takes a whole number from 0 to 99 (or leave it blank for pen). Up to <?= (int) $schema['damageSheet']['columns'][1] ?> columns after the first and <?= (int) $schema['damageSheet']['rows'][1] ?> rows; the table always fills the width of the page.</p>
        <div class="dmg-scroll"><table class="dmg-grid" id="dmg-grid"></table></div>
        <div class="dmg-btns">
          <button type="button" class="btn add" data-dmg="addCol">+ Add column</button>
          <button type="button" class="btn add" data-dmg="addRow">+ Add row</button>
          <span class="count" id="dmg-count"></span>
        </div>
        <h3 class="sub">Kit and Equipment</h3>
        <p class="hint" id="kit-note"></p>
        <h4 class="sub2">Left-hand list <span class="count" data-count="kit"></span></h4>
        <ol class="list" data-list="kit"></ol>
        <button type="button" class="btn add" data-add="kit">+ Add row</button>
        <h4 class="sub2">Right-hand list <span class="count" data-count="equipment"></span></h4>
        <ol class="list" data-list="equipment"></ol>
        <button type="button" class="btn add" data-add="equipment">+ Add row</button>
      </div>
    </details>

    <details class="sec" data-sec="theme">
      <summary>Sheet colour, watermark &amp; print</summary>
      <fieldset class="themes">
        <legend>Sheet colour</legend>
        <?php foreach ($schema['themes'] as $key => $label): ?>
          <label class="swatch swatch-<?= e($key) ?>">
            <input type="radio" name="theme" value="<?= e($key) ?>" data-path="theme" data-kind="enum" data-options="themes">
            <span class="chip-c" aria-hidden="true"></span><span class="sw-name"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <fieldset class="themes watermarks">
        <legend>Background watermark</legend>
        <?php foreach ($schema['watermarks'] as $key => $label): ?>
          <label class="wm-opt">
            <input type="radio" name="watermark" value="<?= e($key) ?>" data-path="watermark" data-kind="enum" data-options="watermarks">
            <span class="wm-prev" aria-hidden="true"><?php if ($key !== 'none'): ?><svg viewBox="0 0 194 281" preserveAspectRatio="xMidYMid slice"><use href="<?= e(url('assets/watermarks.svg?v=1') . '#wm-' . $key) ?>" width="194" height="281"></use></svg><?php endif; ?></span>
            <span class="sw-name"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <p class="hint">Watermarks print very faintly behind the boxes, in the sheet colour. Ink-saver turns them off.</p>
      <label class="check"><input type="checkbox" id="ink-saver"> Ink-saver (white page, keeps the coloured lines)</label>
      <p class="hint">Use <strong>Save PDF</strong> to save the board as an A4 PDF (every page it uses), then print that at 100% / actual size. Your board is saved automatically; use <strong>Export</strong> to keep a copy you can import later.</p>
    </details>
  </main>

  <!-- PREVIEW: the real A4 sheet. app.js scales it to fit the panel; printing uses it at full size. -->
  <section id="preview" class="preview" role="tabpanel" aria-labelledby="tab-preview">
    <div class="preview-scale" id="preview-scale">
      <?php require __DIR__ . '/templates/board.php'; ?>
    </div>
  </section>
</div>

<!-- Row templates for the editable lists. app.js copies one for each row (innates and consumables,
     Colour Resist, Immunities/Resistances) and fills in the values. -->
<template id="tpl-pip-row">
  <li class="item">
    <div class="item-main">
      <input class="in" type="text" autocomplete="off" data-field="name" aria-label="Name">
      <div class="pip-pick" role="group" aria-label="Number of uses"></div>
    </div>
    <button type="button" class="btn icon remove" aria-label="Remove row">&times;</button>
  </li>
</template>
<template id="tpl-colour-row">
  <li class="item">
    <div class="item-main pair">
      <input class="in" type="text" autocomplete="off" data-field="text" maxlength="<?= e((string) $t['colourText']) ?>" aria-label="Colour resist text" placeholder="Text">
      <input class="in frac" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done" data-field="fraction" maxlength="5" aria-label="Fraction" placeholder="1/2">
    </div>
    <button type="button" class="btn icon remove" aria-label="Remove row">&times;</button>
  </li>
</template>
<template id="tpl-immunity-row">
  <li class="item">
    <div class="item-main pair">
      <input class="in" type="text" autocomplete="off" data-field="text" maxlength="<?= e((string) $t['immunityText']) ?>" aria-label="Immunity or resistance" placeholder="Immunity / resistance">
      <span class="imm-val">
        <select class="in" data-field="mode" aria-label="Value type">
          <option value="">—</option><option value="Y">Y</option><option value="N">N</option><option value="#">Num.</option>
        </select>
        <input class="in num small" type="text" inputmode="numeric" autocomplete="off" data-field="number" maxlength="2" aria-label="Value (0–99)" placeholder="0–99" hidden>
      </span>
    </div>
    <button type="button" class="btn icon remove" aria-label="Remove row">&times;</button>
  </li>
</template>

<!-- Pop-up messages (import results, warnings, layout changes). -->
<div class="toast" id="toast" role="alert" hidden></div>
</body>
</html>
