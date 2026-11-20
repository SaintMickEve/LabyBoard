<?php

declare(strict_types=1);

namespace Battleboard;

/**
 * Validates and normalises a battleboard character against config/schema.json.
 *
 * Everything coming in is treated as untrusted: unknown keys are dropped,
 * strings are cleaned and length-limited, numbers are range-checked and
 * anything that fails is reset (with a warning) rather than passed through.
 * The client-side sanitiser in assets/js/app.js applies the same rules.
 *
 * HOW IT WORKS
 * sanitise() builds a brand-new character from scratch, field by field, reading each value from
 * the input only through a small "cleaner" for its type:
 *   text()          strings: Unicode-normalised, invisible/control characters removed, trimmed,
 *                   cut to the field's maximum length
 *   int()           whole numbers inside the field's range (numeric strings like "12" are accepted)
 *   bool()          only true, 1, "1" or "true" count as on; anything else is off
 *   enum()          one of a fixed list of options (case doesn't matter), otherwise blank
 *   rows()          lists of rows, capped at the number that fits on the sheet
 * Because the output is built only from these, nothing unexpected can ever pass through.
 *
 * NEVER REJECT, ALWAYS EXPLAIN
 * A value that fails is replaced with its default (blank, off, or the schema default) and a short
 * human-readable warning is recorded. The importer shows the warnings, so the user knows exactly
 * what changed. Missing fields simply get defaults, which is what lets files from older versions
 * load, and unknown fields are ignored (and listed), which lets files from newer versions load.
 *
 * KEEPING PHP AND JAVASCRIPT IN STEP
 * sanitise() in assets/js/app.js is a line-for-line twin of this class, so an import gives exactly
 * the same result whether it is cleaned here or in the browser. If you change a rule here, make the
 * same change there (and vice versa). Output keys are built in the same order in both.
 */
final class Sanitiser
{
    /**
     * Control characters, zero-width characters and bidi overrides.
     * These are invisible or reorder text on screen, so they could be used to disguise content;
     * they are always removed. (Newlines are kept in the multi-line Notes field only.)
     */
    private const STRIP = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        . '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u';

    /** Messages collected during one sanitise() call, shown to the user after an import. @var list<string> */
    private array $warnings = [];

    /** @param array<string, mixed> $schema The decoded config/schema.json (limits and options). */
    public function __construct(private readonly array $schema)
    {
    }

    /** Reads and decodes config/schema.json. @return array<string, mixed> */
    public static function loadSchema(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Schema not readable');
        }
        /** @var array<string, mixed> $schema */
        $schema = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        return $schema;
    }

    /**
     * Cleans one character. Takes whatever json_decode() produced from the uploaded file and returns
     * a complete, valid character plus the list of warnings.
     *
     * @return array{data: array<string, mixed>, warnings: list<string>}
     */
    public function sanitise(mixed $input): array
    {
        $this->warnings = [];

        // Accept either an exported file ({app, version, character}) or a bare character object.
        // Files from newer versions are accepted: unknown fields are ignored and missing ones get defaults.
        if (is_array($input) && isset($input['character']) && is_array($input['character'])) {
            $fileVersion = $input['version'] ?? null;
            if (is_int($fileVersion) && $fileVersion > (int) $this->schema['version']) {
                $this->warn('This file was made with a newer version of the generator; anything this version does not support was ignored.');
            }
            $input = $input['character'];
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            $this->warn('The file did not contain a character, so a blank board was loaded.');
            $input = [];
        }

        // Short names for the parts of the schema used throughout:
        //   $t text length limits · $i number ranges · $r row-count limits
        $s = $this->schema;
        $t = $s['text'];
        $i = $s['int'];
        $r = $s['rows'];

        // Layout and paper doll come first, because they decide how many rows other lists may have.
        // $L is the chosen layout's settings block from the schema.

        // Board format: "normal" (one page) or "twoPage" (2 Page 15k+ nutjobs). The two-page board has its own
        // left column ($s['twoPage']), so it sets the innate limit; the one-page layout is kept for switching back.
        $format = $this->enum($input['format'] ?? $s['formatDefault'], array_keys($s['formats']), 'Board format') ?: $s['formatDefault'];
        $TP = $s['twoPage'];
        $twoPage = $format === 'twoPage';
        $layout = $this->enum($input['layout'] ?? $s['layoutDefault'], array_keys($s['layouts']), 'Layout') ?: $s['layoutDefault'];
        $L = $s['layouts'][$layout];
        $doll = $this->enum($input['doll'] ?? $s['dollDefault'], array_keys($s['dolls']), 'Paper doll') ?: $s['dollDefault'];
        // Table limits that depend on the doll (the ghost has room for more rows) and on the
        // optional "compact rows" setting for Immunities/Resistances.
        $maxColour = $s['dollLimits'][$doll]['colourResist'] ?? $r['colourResist'][1];
        $immCompact = $this->bool($input['immunitiesCompact'] ?? false);
        // The Consumables table can be switched off (older files: on). Its rows are kept but don't take space.
        $conShow = $this->bool($input['consumablesShow'] ?? true);
        // Two-page board switches and page-2 line counts (read now: they decide the innate and Immunities limits).
        $tpIn = is_array($input['twoPage'] ?? null) ? $input['twoPage'] : [];
        $tpLayout = $this->twoPageLayout($tpIn);
        $maxImmune = $immCompact
            ? ($s['immunitiesCompact']['limits'][$doll] ?? $r['immunities'][1])
            : ($s['dollLimits'][$doll]['immunities'] ?? $r['immunities'][1]);
        if ($twoPage) {
            // Page 1 holds the doll's main table (the ghost's whole column); the rest continue on page 2.
            $page1 = $doll === 'ghost' ? $TP['ghostImmunities'][$immCompact ? 'compact' : 'normal'] : $s['immunitiesMainRows'][$immCompact ? 'compact' : 'normal'];
            $maxImmune = $page1 + $tpLayout['p2Imm'];
        }
        $innatesMax = $twoPage ? $TP['innates'][1] + $tpLayout['contRows'] : ($conShow ? $L['innates'][1] : $L['innatesSolo']);

        // The clean character, built field by field. The order of keys here is the order they appear
        // in exported files, and must match the JavaScript twin.
        $data = [
            'format'  => $format,
            'layout'  => $layout,
            'doll'    => $doll,
            'theme'   => $this->enum($input['theme'] ?? $s['themeDefault'], array_keys($s['themes']), 'Sheet colour') ?: $s['themeDefault'],
            'watermark' => $this->enum($input['watermark'] ?? $s['watermarkDefault'], array_keys($s['watermarks']), 'Watermark') ?: $s['watermarkDefault'],
            'name'    => $this->text($input['name'] ?? '', $t['name'], 'Character name'),
            'alignment' => $this->enum($input['alignment'] ?? '', $s['alignments'], 'Alignment'),
            'classes' => $this->text($input['classes'] ?? '', $t['classes'], 'Class(es)'),
            'races'   => $this->text($input['races'] ?? '', $t['races'], 'Race(s)'),
            'tblp'    => $this->int($input['tblp'] ?? null, $i['tblp'], 'T.B.L.P.'),
            'ltm' => [
                'show'  => $this->bool($input['ltm']['show'] ?? false),
                'value' => $this->int($input['ltm']['value'] ?? null, $i['ltm'], 'LTM'),
            ],
            'castingStam' => [
                'show'  => $this->bool($input['castingStam']['show'] ?? false),
                'value' => $this->int($input['castingStam']['value'] ?? null, $i['castingStam'], 'Casting Stam'),
            ],
            'tblpRows' => $this->int($input['tblpRows'] ?? $s['tblpRowsDefault'], $i['tblpRows'], 'T.B.L.P. lines') ?? $s['tblpRowsDefault'],
            'tblpLines' => $this->fixedLines($input['tblpLines'] ?? [], $r['tblpLines'], $t['tblpLine'], 'T.B.L.P. line'),
            'power' => [
                'type'  => $this->enum($input['power']['type'] ?? '', $s['powerTypes'], 'Power type'),
                'value' => $this->int($input['power']['value'] ?? null, $i['powerValue'], 'Power value'),
            ],
            'powerLines' => $this->fixedLines($input['powerLines'] ?? [], $r['powerLines'], $t['powerLine'], 'Power line'),
            'powB' => [
                'label' => $this->text($input['powB']['label'] ?? $s['powLabelDefault'], $t['powLabel'], 'Pow column heading'),
                'value' => $this->int($input['powB']['value'] ?? null, $i['powValue'], 'Pow column value'),
            ],
            'resChance' => $this->int($input['resChance'] ?? null, $i['resChance'], 'Res Chance'),
            'poolsShow'   => $this->bool($input['poolsShow'] ?? false),
            'consumablesShow' => $conShow,
            'immunitiesCompact' => $immCompact,
            'pools'       => $this->pools($input['pools'] ?? []),
            'innates'     => $this->pipRows($input['innates'] ?? [], $innatesMax, $t['innateName'], 'Innate'),
            'consumables' => $this->pipRows($input['consumables'] ?? [], $L['consumables'][1], $t['consumableName'], 'Consumable'),
            'armour'      => [],
            'locations'   => [],
            'resist'      => [],
            'reductions'  => [],
            'combatWary'  => [
                'show'  => $this->bool($input['combatWary']['show'] ?? false),
                'value' => $this->text($input['combatWary']['value'] ?? '', $t['reduction'], 'Combat Wary reduction'),
            ],
            'colourResist' => [],
            'immunities'  => [],
            'notes' => $this->text($input['notes'] ?? '', $t['notes'], 'Notes', true),
            // Guilds: the Social / Professional / Political boxes under Notes can be switched off (older files: on).
            'guildsShow'   => $this->bool($input['guildsShow'] ?? true),
            'social'       => $this->text($input['social'] ?? '', $t['standing'], 'Social'),
            'professional' => $this->text($input['professional'] ?? '', $t['standing'], 'Professional'),
            'political'    => $this->text($input['political'] ?? '', $t['standing'], 'Political'),
            'points' => $this->int($input['points'] ?? null, $i['points'], 'Points'),
            'twoPage' => $this->twoPageData($tpIn, $tpLayout),
            'damageSheet' => ($dmg = $this->damageSheetData(is_array($input['damageSheet'] ?? null) ? $input['damageSheet'] : [])),
            // Kit and Equipment (on the Damage Sheet page): as many rows as fit under its table.
            'kit' => $this->pipRows($input['kit'] ?? [], $this->kitMax(count($dmg['rows'])), $t['kitName'], 'Kit'),
            'equipment' => $this->pipRows($input['equipment'] ?? [], $this->kitMax(count($dmg['rows'])), $t['kitName'], 'Equipment'),
        ];

        // Innates + consumables share the same space on the sheet.
        $combined = $L['pipRowsCombined'];
        // (Consumables aren't on the two-page board, so they don't share its innate column.)
        if (!$twoPage && $conShow && count($data['innates']) + count($data['consumables']) > $combined) {
            $keep = max(0, $combined - count($data['innates']));
            $data['consumables'] = array_slice($data['consumables'], 0, $keep);
            $this->warn("Innates and consumables are limited to {$combined} rows in total; extra rows were dropped.");
        }

        // Fields whose names come from the schema lists: the five shields, the body locations
        // (loc + AC each), the five resistances and the three reductions.
        foreach ($s['armour'] as $key) {
            $data['armour'][$key] = $this->int($input['armour'][$key] ?? null, $i['armour'], strtoupper($key));
        }
        foreach (array_keys($s['locations']) as $key) {
            $loc = is_array($input['locations'][$key] ?? null) ? $input['locations'][$key] : [];
            $label = $s['locations'][$key];
            $data['locations'][$key] = [
                'hits' => $this->int($loc['hits'] ?? null, $i['locHits'], "{$label} loc"),
                'ac'   => $this->int($loc['ac'] ?? null, $i['locAc'], "{$label} AC"),
            ];
        }
        foreach ($s['resistances'] as $key => $label) {
            $data['resist'][$key] = $this->int($input['resist'][$key] ?? $s['resistDefault'], $i['resist'], "{$label} resistance");
        }
        foreach ($s['reductions'] as $key => $label) {
            $data['reductions'][$key] = $this->text($input['reductions'][$key] ?? '', $t['reduction'], "{$label} reduction");
        }

        // Colour Resist rows: free text plus a fraction such as 1/2 (pattern from the schema).
        foreach ($this->rows($input['colourResist'] ?? [], $maxColour, 'Colour resist') as $n => $row) {
            $fraction = $this->text($row['fraction'] ?? '', 5, 'Colour resist fraction');
            $fraction = str_replace(' ', '', $fraction);
            if ($fraction !== '' && !preg_match('#' . $s['fractionPattern'] . '#', $fraction)) {
                $this->warn('Colour resist row ' . ($n + 1) . ': "' . $fraction . '" is not a fraction like 1/2, so it was cleared.');
                $fraction = '';
            }
            $data['colourResist'][] = [
                'text' => $this->text($row['text'] ?? '', $t['colourText'], 'Colour resist text'),
                'fraction' => $fraction,
            ];
        }

        // Immunities/Resistances rows: free text plus a number, Y or N.
        foreach ($this->rows($input['immunities'] ?? [], $maxImmune, 'Immunities/Resistances') as $n => $row) {
            $data['immunities'][] = [
                'text'  => $this->text($row['text'] ?? '', $t['immunityText'], 'Immunity text'),
                'value' => $this->immunityValue($row['value'] ?? '', $n + 1),
            ];
        }

        // Tell the user about any top-level fields this version doesn't know (for example from a
        // newer version of the site). Their names are cleaned and shortened before being shown.
        $unknown = array_diff(array_map('strval', array_keys($input)), array_keys($data));
        if ($unknown !== []) {
            $names = array_map(fn (string $k): string => mb_substr((string) preg_replace(self::STRIP, '', $k), 0, 30), array_slice(array_values($unknown), 0, 10));
            $this->warn('Ignored ' . count($unknown) . ' field(s) this version does not use: ' . implode(', ', $names) . (count($unknown) > 10 ? ', …' : '') . '.');
        }

        return ['data' => $data, 'warnings' => $this->warnings];
    }

    // ------------------------------------------------------------------ helpers

    /** Records a warning for the user. Capped at 50 so a hostile file can't produce a huge reply. */
    private function warn(string $message): void
    {
        if (count($this->warnings) < 50) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Cleans a piece of text. Whole numbers are accepted and turned into text; anything else that
     * isn't a string is cleared. Steps: check it's valid UTF-8, normalise Unicode (NFC, so "é" typed
     * two different ways is stored the same), unify line endings, remove invisible characters,
     * collapse whitespace (single-line fields) or runs of blank lines (Notes), trim, then shorten to
     * $max characters. $label is the field's name used in warnings.
     */
    private function text(mixed $value, int $max, string $label, bool $multiline = false): string
    {
        if (is_int($value) && abs($value) <= 9007199254740991) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            if ($value !== null) {
                $this->warn("{$label}: expected text, so it was cleared.");
            }
            return '';
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            $this->warn("{$label}: invalid characters, so it was cleared.");
            return '';
        }
        if (function_exists('normalizer_normalize')) {
            $value = normalizer_normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value);
        $value = (string) preg_replace(self::STRIP, '', $value);
        if ($multiline) {
            $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
        } else {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max) {
            $value = rtrim(mb_substr($value, 0, $max, 'UTF-8'));
            $this->warn("{$label}: shortened to {$max} characters.");
        }
        return $value;
    }

    /**
     * Cleans a whole number. Blank means "left empty for pen" (null). Numeric strings of up to six
     * digits and whole-valued floats such as 12.0 are accepted; anything else, or anything outside
     * [min, max], is cleared with a warning.
     *
     * @param array{0:int,1:int} $range [min, max] from the schema
     */
    private function int(mixed $value, array $range, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value)) {
            $value = (int) trim($value);
        }
        if (is_float($value) && abs($value) <= 1e9 && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $range[0] || $value > $range[1]) {
            $this->warn("{$label}: must be a whole number from {$range[0]} to {$range[1]}, so it was cleared.");
            return null;
        }
        return $value;
    }

    /** On/off switches. Only clearly-true values count as on, so junk can't switch anything on. */
    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Picks one option from a fixed list (case-insensitive) and returns it in the list's own spelling.
     * Blank stays blank; anything not on the list is cleared with a warning.
     *
     * @param list<string> $allowed
     */
    private function enum(mixed $value, array $allowed, string $label): string
    {
        if ($value === '' || $value === null) {
            return '';
        }
        foreach ($allowed as $option) {
            if (is_string($value) && strcasecmp(trim($value), $option) === 0) {
                return $option;
            }
        }
        $this->warn("{$label}: must be one of " . implode(', ', $allowed) . ', so it was cleared.');
        return '';
    }

    /**
     * A fixed number of free-text lines (T.B.L.P. and Power lines). Always returns exactly $count
     * lines, padding with blanks, so every line has a slot even if the layout currently hides it.
     *
     * @return list<string>
     */
    private function fixedLines(mixed $lines, int $count, int $max, string $label): array
    {
        $lines = is_array($lines) ? array_values($lines) : [];
        if (count($lines) > $count) {
            $this->warn("{$label}s: only {$count} lines fit, extra lines were dropped.");
        }
        $out = [];
        for ($n = 0; $n < $count; $n++) {
            $out[] = $this->text($lines[$n] ?? '', $max, "{$label} " . ($n + 1));
        }
        return $out;
    }

    /**
     * Prepares a variable-length list of rows: anything that isn't a list becomes empty, rows that
     * aren't objects are skipped, and the list is cut to the most rows that fit on the sheet.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows, int $max, string $label): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        if (count($rows) > $max) {
            $this->warn("{$label} rows: only {$max} fit on the sheet, extra rows were dropped.");
            $rows = array_slice($rows, 0, $max);
        }
        return $rows;
    }

    /** The three named pools used by the Pool Princess layout. @return list<array{name: string, value: int|null}> */
    private function pools(mixed $pools): array
    {
        $pools = is_array($pools) ? array_values($pools) : [];
        $out = [];
        for ($n = 0; $n < 3; $n++) {
            $row = is_array($pools[$n] ?? null) ? $pools[$n] : [];
            $out[] = [
                'name'  => $this->text($row['name'] ?? 'Pool ' . ($n + 1), $this->schema['text']['poolName'], 'Pool ' . ($n + 1) . ' name'),
                'value' => $this->int($row['value'] ?? null, $this->schema['int']['poolValue'], 'Pool ' . ($n + 1) . ' value'),
            ];
        }
        return $out;
    }

    /**
     * Innate or consumable rows: a name plus how many of the eight use-boxes are usable (1–8).
     * A null count means all eight boxes are left open for pen.
     *
     * @return list<array{name: string, count: int|null}>
     */
    private function pipRows(mixed $rows, int $max, int $nameMax, string $label): array
    {
        $out = [];
        foreach ($this->rows($rows, $max, $label) as $n => $row) {
            $out[] = [
                'name'  => $this->text($row['name'] ?? '', $nameMax, "{$label} " . ($n + 1) . ' name'),
                'count' => $this->int($row['count'] ?? null, $this->schema['int']['pipCount'], "{$label} " . ($n + 1) . ' uses'),
            ];
        }
        return $out;
    }

    /**
     * The parts only the two-page board uses (kept, unprinted, while the one-page board is shown):
     * two named powers with three lines each, Total Heal Innate and True Believer (a value and a line
     * each), and the page-2 tables. Every list always has its full, fixed number of slots.
     *
     * @param array<mixed> $tp
     * @return array<string, mixed>
     */
    /**
     * The two-page board's on/off switches (twoPage.show) and page-2 line counts (twoPage.rows), and what
     * they leave: page 2's Immunities/Resistances and Regeneration rows (the full tables unless the
     * Innates (continued) box is on) and how many rows that box holds. Matches twoPageLayout() in app.js.
     *
     * @return array{show: array<string, bool>, rows: array{immunities: int, regeneration: int}, p2Imm: int, p2Regen: int, contRows: int}
     */
    private function twoPageLayout(array $tp): array
    {
        $TP = $this->schema['twoPage'];
        $i = $this->schema['int'];
        $showIn = is_array($tp['show'] ?? null) ? $tp['show'] : [];
        $rowsIn = is_array($tp['rows'] ?? null) ? $tp['rows'] : [];
        $show = [];
        foreach ($TP['showDefault'] as $k => $d) {
            $show[$k] = $this->bool($showIn[$k] ?? $d);
        }
        $D = $TP['p2RowsDefault'];
        $rows = [
            'immunities'   => $this->int($rowsIn['immunities'] ?? $D['immunities'], $i['tpImmRows'], 'Page 2 Immunities/Resistances lines') ?? $D['immunities'],
            'regeneration' => $this->int($rowsIn['regeneration'] ?? $D['regeneration'], $i['tpRegenRows'], 'Page 2 Regeneration Powers lines') ?? $D['regeneration'],
        ];
        $cont = $show['innatesCont'];
        $p2Imm = $cont ? $rows['immunities'] : $TP['page2']['immunities'];
        $p2Regen = $cont ? $rows['regeneration'] : $TP['page2']['regeneration'];
        return ['show' => $show, 'rows' => $rows, 'p2Imm' => $p2Imm, 'p2Regen' => $p2Regen,
            'contRows' => $cont ? $this->innatesContRows($p2Imm, $p2Regen) : 0];
    }

    /**
     * Rows in the Innates (continued) box on page 2: what is left of the left column after the shown
     * Immunities/Resistances and Regeneration Powers rows (schema twoPage.page2Col, tenths of a mm).
     * A table with 0 rows isn't shown, so its heading and gap are freed too. Same sums in app.js.
     */
    private function innatesContRows(int $imm, int $regen): int
    {
        $c = $this->schema['twoPage']['page2Col'];
        $panels = 1;
        $used = $c['panelMm10'];
        if ($imm > 0) {
            $panels++;
            $used += $c['panelMm10'] + $imm * $c['rowMm10'];
        }
        if ($regen > 0) {
            $panels++;
            $used += $c['panelMm10'] + $regen * $c['rowMm10'];
        }
        $used += ($panels - 1) * $c['gapMm10'];
        return max(0, intdiv($c['heightMm10'] - $used, $c['rowMm10']));
    }

    /** @param array{show: array<string, bool>, rows: array{immunities: int, regeneration: int}} $layout */
    private function twoPageData(array $tp, array $layout): array
    {
        $s = $this->schema;
        $t = $s['text'];
        $TP = $s['twoPage'];
        $p2 = $TP['page2'];
        $obj = fn (mixed $v): array => is_array($v) ? $v : [];
        $list = fn (mixed $v): array => is_array($v) ? array_values($v) : [];
        $powersIn = $list($tp['powers'] ?? []);
        $regenIn = $list($tp['regeneration'] ?? []);
        if (count($regenIn) > $p2['regeneration']) {
            $this->warn("Regeneration Powers rows: only {$p2['regeneration']} fit, extra rows were dropped.");
        }
        $extra = fn (string $key, string $label): array => [
            'value' => $this->int($obj($tp[$key] ?? null)['value'] ?? null, $s['int']['poolValue'], "{$label} value"),
            'line'  => $this->text($obj($tp[$key] ?? null)['line'] ?? '', $t['powerLine'], "{$label} line"),
        ];
        $powers = [];
        for ($n = 0; $n < $TP['powers']; $n++) {
            $row = $obj($powersIn[$n] ?? null);
            $label = 'Power ' . ($n + 1);
            $powers[] = [
                'name'  => $this->text($row['name'] ?? $label, $t['tpPowerName'], "{$label} name"),
                'value' => $this->int($row['value'] ?? null, $s['int']['poolValue'], "{$label} value"),
                'lines' => $this->fixedLines($row['lines'] ?? [], $TP['powerLines'], $t['powerLine'], "{$label} line"),
            ];
        }
        $totalHeal = $extra('totalHeal', 'Total Heal Innate');
        $trueBeliever = $extra('trueBeliever', 'True Believer');
        $regen = [];
        for ($n = 0; $n < $p2['regeneration']; $n++) {
            $row = $obj($regenIn[$n] ?? null);
            $regen[] = [
                'text'  => $this->text($row['text'] ?? '', $t['tpLine'], 'Regeneration Powers ' . ($n + 1)),
                'value' => $this->text($row['value'] ?? '', $t['tpRegenValue'], 'Regeneration Powers ' . ($n + 1) . ' value'),
            ];
        }
        return [
            'show' => $layout['show'],
            'rows' => $layout['rows'],
            'powers' => $powers,
            'totalHeal' => $totalHeal,
            'trueBeliever' => $trueBeliever,
            'regeneration' => $regen,
            'damageGrades' => $this->fixedLines($tp['damageGrades'] ?? [], $p2['damageGrades'], $t['tpLine'], 'Damage Grades line'),
            'damageReductions' => $this->fixedLines($tp['damageReductions'] ?? [], $p2['damageReductions'], $t['tpLine'], 'Damage Reductions line'),
            'additionalResistances' => $this->fixedLines($tp['additionalResistances'] ?? [], $p2['additionalResistances'], $t['tpLine'], 'Additional Resistances line'),
        ];
    }

    /**
     * The optional last page's Damage Sheet table: a corner heading, 1–8 column headings (bold, up to
     * 40 characters) and 1–13 rows, each a bold heading (up to 15 characters) and one 0–99 value per
     * column. Older files and files without it get the example table (switched off). Matches app.js.
     *
     * @param array<mixed> $ds
     * @return array{show: bool, corner: string, columns: list<string>, rows: list<array{label: string, values: list<int|null>}>}
     */
    private function damageSheetData(array $ds): array
    {
        $D = $this->schema['damageSheet'];
        $t = $this->schema['text'];
        $cols = is_array($ds['columns'] ?? null) ? array_values($ds['columns']) : $D['columnsDefault'];
        if (count($cols) > $D['columns'][1]) {
            $this->warn("Damage Sheet columns: only {$D['columns'][1]} fit, extra columns were dropped.");
            $cols = array_slice($cols, 0, $D['columns'][1]);
        }
        $corner = $this->text($ds['corner'] ?? $D['corner'], $t['dmgLabel'], 'Damage Sheet corner heading');
        $columns = [];
        foreach ($cols as $n => $c) {
            $columns[] = $this->text($c ?? '', $t['dmgHead'], 'Damage Sheet column ' . ($n + 1) . ' heading');
        }
        if (!$columns) {
            $columns[] = '';
        }
        $rowsIn = is_array($ds['rows'] ?? null)
            ? $this->rows($ds['rows'], $D['rows'][1], 'Damage Sheet')
            : array_map(fn (string $label): array => ['label' => $label], $D['rowsDefault']);
        $rows = [];
        foreach ($rowsIn as $n => $row) {
            $label = $this->text($row['label'] ?? '', $t['dmgLabel'], 'Damage Sheet row ' . ($n + 1) . ' heading');
            $vals = is_array($row['values'] ?? null) ? array_values($row['values']) : [];
            if (count($vals) > count($columns)) {
                $this->warn('Damage Sheet row ' . ($n + 1) . ': values beyond column ' . count($columns) . ' were dropped.');
            }
            $values = [];
            foreach ($columns as $c => $_) {
                $values[] = $this->int($vals[$c] ?? null, $this->schema['int']['dmgValue'], 'Damage Sheet row ' . ($n + 1) . ', column ' . ($c + 1));
            }
            $rows[] = ['label' => $label, 'values' => $values];
        }
        if (!$rows) {
            $rows[] = ['label' => '', 'values' => array_fill(0, count($columns), null)];
        }
        return ['show' => $this->bool($ds['show'] ?? false), 'corner' => $corner, 'columns' => $columns, 'rows' => $rows];
    }

    /** Kit and Equipment rows that fit under a Damage Sheet table of $rows rows (schema damageSheet.kitFit). Same sum in app.js. */
    private function kitMax(int $rows): int
    {
        $f = $this->schema['damageSheet']['kitFit'];
        return max(0, intdiv($f['heightMm10'] - $rows * $f['dmgRowMm10'], $f['kitRowMm10']));
    }

    /** The right-hand cell of an Immunities/Resistances row: "Y", "N", a number in range, or blank. */
    private function immunityValue(mixed $value, int $row): string|int
    {
        if ($value === '' || $value === null) {
            return '';
        }
        if (is_string($value) && in_array(strtoupper(trim($value)), ['Y', 'N'], true)) {
            return strtoupper(trim($value));
        }
        $int = $this->int($value, $this->schema['int']['immunityNumber'], "Immunity row {$row} value");
        return $int ?? '';
    }
}
