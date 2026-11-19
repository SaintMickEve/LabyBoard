<?php
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
use function Battleboard\e;
?>
<div class="sheet" id="sheet" data-theme="parchment" aria-label="Battleboard preview">
 <div class="frame">
  <?php /* Decorative double border and corner flourishes, then the watermark behind everything. */ ?>
  <svg class="corner tl" viewBox="0 0 20 20" aria-hidden="true"><path d="M1,19 L1,6 Q1,1 6,1 L19,1" class="c1"/><path d="M3.5,19 L3.5,7.5 Q3.5,3.5 7.5,3.5 L19,3.5" class="c2"/><path d="M6,6 m-2.2,0 a2.2,2.2 0 1,0 4.4,0 a2.2,2.2 0 1,0 -4.4,0" class="c3"/><path d="M10,6 Q13,3.5 16,6 Q13,8.5 10,6 Z M6,10 Q3.5,13 6,16 Q8.5,13 6,10 Z" class="c4"/></svg><svg class="corner tr" viewBox="0 0 20 20" aria-hidden="true"><path d="M1,19 L1,6 Q1,1 6,1 L19,1" class="c1"/><path d="M3.5,19 L3.5,7.5 Q3.5,3.5 7.5,3.5 L19,3.5" class="c2"/><path d="M6,6 m-2.2,0 a2.2,2.2 0 1,0 4.4,0 a2.2,2.2 0 1,0 -4.4,0" class="c3"/><path d="M10,6 Q13,3.5 16,6 Q13,8.5 10,6 Z M6,10 Q3.5,13 6,16 Q8.5,13 6,10 Z" class="c4"/></svg><svg class="corner bl" viewBox="0 0 20 20" aria-hidden="true"><path d="M1,19 L1,6 Q1,1 6,1 L19,1" class="c1"/><path d="M3.5,19 L3.5,7.5 Q3.5,3.5 7.5,3.5 L19,3.5" class="c2"/><path d="M6,6 m-2.2,0 a2.2,2.2 0 1,0 4.4,0 a2.2,2.2 0 1,0 -4.4,0" class="c3"/><path d="M10,6 Q13,3.5 16,6 Q13,8.5 10,6 Z M6,10 Q3.5,13 6,16 Q8.5,13 6,10 Z" class="c4"/></svg><svg class="corner br" viewBox="0 0 20 20" aria-hidden="true"><path d="M1,19 L1,6 Q1,1 6,1 L19,1" class="c1"/><path d="M3.5,19 L3.5,7.5 Q3.5,3.5 7.5,3.5 L19,3.5" class="c2"/><path d="M6,6 m-2.2,0 a2.2,2.2 0 1,0 4.4,0 a2.2,2.2 0 1,0 -4.4,0" class="c3"/><path d="M10,6 Q13,3.5 16,6 Q13,8.5 10,6 Z M6,10 Q3.5,13 6,16 Q8.5,13 6,10 Z" class="c4"/></svg>
  <div class="frame-in">
   <svg class="wm" id="sheet-wm" viewBox="0 0 194 281" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false" hidden><use width="194" height="281"></use></svg>
   <?php /* HEADER: name banner with alignment, then the Class(es) and Race(s) lines. */ ?>
   <header class="head">
     <div class="title-wrap" data-section="identity"><svg class="banner" viewBox="0 0 160 22" preserveAspectRatio="none" aria-hidden="true">
  <path class="b-tail" d="M2,6 L16,6 L16,20 L2,20 L7,13 Z"/>
  <path class="b-tail" d="M158,6 L144,6 L144,20 L158,20 L153,13 Z"/>
  <path class="b-fold" d="M16,20 L22,17 L16,17 Z M144,20 L138,17 L144,17 Z"/>
  <path class="b-main" d="M16,2 Q80,-1 144,2 L144,17 Q80,14 16,17 Z"/>
  <path class="b-rule" d="M19,4 Q80,1.2 141,4 M19,15 Q80,12.2 141,15"/>
</svg><h1 class="char-name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span></h1></div>
     <div class="identity" data-section="identity">
       <label><span class="k">Class(es)</span><span class="fill fit-lines" data-bind="classes"></span></label>
       <label><span class="k">Race(s)</span><span class="fill fit-lines" data-bind="races"></span></label>
     </div>
   </header>

   <?php /* BODY: three columns. Left: T.B.L.P., Power, optional pools, Innates, Consumables, Res Chance. */ ?>
   <main class="body">
    <section class="col">
      <div class="panel" data-section="tblp" id="sheet-tblp">
        <div class="ph"><span class="k">T.B.L.P.</span><span class="box"><span class="fit" data-bind="tblp"></span></span><span class="opt-group" data-show="ltm.show"><span class="k sep">LTM</span><span class="box"><span class="fit" data-bind="ltm.value"></span></span></span><span class="spacer"></span></div>
        <div class="row"><span class="fill fit" data-bind="tblpLines.0"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.1"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.2"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.3"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.4"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.5"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.6"></span></div><div class="row"><span class="fill fit" data-bind="tblpLines.7"></span></div><div class="row cs-row" data-show="castingStam.show"><span class="cs-k">Casting Stam</span><span class="cs-v"><span class="fit" data-bind="castingStam.value"></span></span><span class="fill"></span></div>
      </div>
      <div class="panel" data-section="power" id="sheet-power">
        <div class="ph"><span class="k power-k">Power (<span class="ptype" data-bind="power.type"></span>)</span><span class="box"><span class="fit" data-bind="power.value"></span></span><span class="spacer"></span></div>
        <div class="row"><span class="fill fit" data-bind="powerLines.0"></span></div><div class="row"><span class="fill fit" data-bind="powerLines.1"></span></div><div class="row"><span class="fill fit" data-bind="powerLines.2"></span></div><div class="row"><span class="fill fit" data-bind="powerLines.3"></span></div><div class="row"><span class="fill fit" data-bind="powerLines.4"></span></div>
      </div>
      <div class="pools" data-section="pools">
        <div class="pool"><div class="pool-head"><span class="pn fit" data-bind="pools.0.name"></span><span class="pv"><span class="fit" data-bind="pools.0.value"></span></span></div><div class="pool-line"></div><div class="pool-line"></div><div class="pool-line"></div></div><div class="pool"><div class="pool-head"><span class="pn fit" data-bind="pools.1.name"></span><span class="pv"><span class="fit" data-bind="pools.1.value"></span></span></div><div class="pool-line"></div><div class="pool-line"></div><div class="pool-line"></div></div><div class="pool"><div class="pool-head"><span class="pn fit" data-bind="pools.2.name"></span><span class="pv"><span class="fit" data-bind="pools.2.value"></span></span></div><div class="pool-line"></div><div class="pool-line"></div><div class="pool-line"></div></div>
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
          <span class="res-box"><span class="fit" data-bind="resChance"></span></span></div>
      </div>
    </section>

    <?php /* MIDDLE: the editable Pow column, a tall strip of boxes to tick off. */ ?>
    <section class="pow">
      <div class="ph" data-section="powB"><span class="k"><span class="fit" data-bind="powB.label"></span></span><span class="sub">(<span class="pow-val" data-bind="powB.value"></span>)</span></div>
      <div class="cells"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
    </section>

    <section class="col">
      <?php /* RIGHT: the five shields, then the paper doll with its location boxes and side tables, then Notes. */ ?>
      <div class="armour" data-section="armour">
        <div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">PAC</span><span class="shield-val"><span class="fit" data-bind="armour.pac"></span></span>
        </div>
        <div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">DAC</span><span class="shield-val"><span class="fit" data-bind="armour.dac"></span></span>
        </div>
        <div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">SAC</span><span class="shield-val"><span class="fit" data-bind="armour.sac"></span></span>
        </div>
        <div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">MAC</span><span class="shield-val"><span class="fit" data-bind="armour.mac"></span></span>
        </div>
        <div class="shield">
          <svg viewBox="0 0 20 22" aria-hidden="true"><path class="shield-fill" d="M1,2 Q10,-0.5 19,2 L19,10 Q19,18 10,21.3 Q1,18 1,10 Z"/><path class="shield-in" d="M2.6,3.3 Q10,1.3 17.4,3.3 L17.4,10 Q17.4,16.8 10,19.6 Q2.6,16.8 2.6,10 Z"/></svg>
          <span class="shield-label">NAC</span><span class="shield-val"><span class="fit" data-bind="armour.nac"></span></span>
        </div>
      </div>
      <?php /* PAPER DOLL: one SVG holds every body piece for all three dolls; CSS shows the pieces for the chosen doll. Each chip is a location's loc box and AC circle. */ ?>
      <div class="doll">
        <div data-section="locations">
        <svg class="doll-svg" viewBox="0 0 88 182" width="88mm" height="182mm" aria-hidden="true"><defs><pattern id="lines" width="4" height="5" patternUnits="userSpaceOnUse"><line x1="0" y1="4.9" x2="4" y2="4.9" class="writeline"/></pattern><pattern id="hatch" width="1.1" height="1.1" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line x1="0" y1="0" x2="0" y2="1.1" class="hatchline"/></pattern><clipPath id="clip-head"><path d="M28,30.3 L28,6.5 Q28,0.8 33.7,0.8 L54.3,0.8 Q60,0.8 60,6.5 L60,30.3 Q44,32.8 28,30.3 Z"/></clipPath><clipPath id="clip-larm"><path d="M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,101 Q21.5,105 17.5,105 L3.7,105 Q-0.3,105 -0.3,101 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z"/></clipPath><clipPath id="clip-chest"><path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,75.2 L26,75.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z"/></clipPath><clipPath id="clip-rarm"><path d="M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,101 Q66.5,105 70.5,105 L85.5,105 Q89.5,105 89.5,101 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z"/></clipPath><clipPath id="clip-abdomen"><path d="M24.5,77.7 L63.5,77.7 L62,110.2 Q62,112.2 60,112.2 L28,112.2 Q26,112.2 26,110.2 Z"/></clipPath><clipPath id="clip-lleg"><path d="M24,114.2 L43,114.2 L42,179.8 Q42,183.8 38,183.8 L29,183.8 Q25,183.8 25,179.8 Z"/></clipPath><clipPath id="clip-rleg"><path d="M45,114.2 L64,114.2 L63,179.8 Q63,183.8 59,183.8 L50,183.8 Q46,183.8 46,179.8 Z"/></clipPath><clipPath id="clip-torso"><path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,109.2 Q62.5,112.2 59.5,112.2 L28.5,112.2 Q25.5,112.2 25.5,109.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z"/></clipPath></defs>
  <g class="piece" id="piece-head">
    <path d="M28,30.3 L28,6.5 Q28,0.8 33.7,0.8 L54.3,0.8 Q60,0.8 60,6.5 L60,30.3 Q44,32.8 28,30.3 Z" class="piece-fill"/>
    <g clip-path="url(#clip-head)">
      <rect x="28" y="9.8" width="32" height="31.4" fill="url(#lines)"/>
      <rect x="57.4" y="0.8" width="2.6" height="31.4" fill="url(#hatch)" class="shade"/>
      <rect x="28" y="30.399999999999995" width="32" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M28,30.3 L28,6.5 Q28,0.8 33.7,0.8 L54.3,0.8 Q60,0.8 60,6.5 L60,30.3 Q44,32.8 28,30.3 Z" class="stitch" transform="translate(44.0 16.5) scale(0.9250 0.9236) translate(-44.0 -16.5)"/>
    <path d="M28,30.3 L28,6.5 Q28,0.8 33.7,0.8 L54.3,0.8 Q60,0.8 60,6.5 L60,30.3 Q44,32.8 28,30.3 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-larm">
    <path d="M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,101 Q21.5,105 17.5,105 L3.7,105 Q-0.3,105 -0.3,101 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z" class="piece-fill"/>
    <g clip-path="url(#clip-larm)">
      <rect x="-2.3" y="44.7" width="24.8" height="69.3" fill="url(#lines)"/>
      <rect x="19.9" y="35.7" width="2.6" height="69.3" fill="url(#hatch)" class="shade"/>
      <rect x="-2.3" y="103.2" width="24.8" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,101 Q21.5,105 17.5,105 L3.7,105 Q-0.3,105 -0.3,101 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z" class="stitch" transform="translate(10.100000000000001 70.35) scale(0.9032 0.9654) translate(-10.100000000000001 -70.35)"/>
    <path d="M1.7,35.7 L19.5,35.7 Q22.5,35.7 22.5,39.7 L21.5,101 Q21.5,105 17.5,105 L3.7,105 Q-0.3,105 -0.3,101 L-2.3,39.7 Q-2.3,35.7 1.7,35.7 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-chest">
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,75.2 L26,75.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="piece-fill"/>
    <g clip-path="url(#clip-chest)">
      <rect x="24.5" y="43.2" width="39" height="41" fill="url(#lines)"/>
      <rect x="60.9" y="34.2" width="2.6" height="41" fill="url(#hatch)" class="shade"/>
      <rect x="24.5" y="73.4" width="39" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,75.2 L26,75.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="stitch" transform="translate(44.0 54.7) scale(0.9385 0.9415) translate(-44.0 -54.7)"/>
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62,75.2 L26,75.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-rarm">
    <path d="M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,101 Q66.5,105 70.5,105 L85.5,105 Q89.5,105 89.5,101 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z" class="piece-fill"/>
    <g clip-path="url(#clip-rarm)">
      <rect x="65.5" y="44.7" width="26.0" height="69.3" fill="url(#lines)"/>
      <rect x="88.9" y="35.7" width="2.6" height="69.3" fill="url(#hatch)" class="shade"/>
      <rect x="65.5" y="103.2" width="26.0" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,101 Q66.5,105 70.5,105 L85.5,105 Q89.5,105 89.5,101 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z" class="stitch" transform="translate(78.5 70.35) scale(0.9077 0.9654) translate(-78.5 -70.35)"/>
    <path d="M87.5,35.7 L68.5,35.7 Q65.5,35.7 65.5,39.7 L66.5,101 Q66.5,105 70.5,105 L85.5,105 Q89.5,105 89.5,101 L91.5,39.7 Q91.5,35.7 87.5,35.7 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-abdomen">
    <path d="M24.5,77.7 L63.5,77.7 L62,110.2 Q62,112.2 60,112.2 L28,112.2 Q26,112.2 26,110.2 Z" class="piece-fill"/>
    <g clip-path="url(#clip-abdomen)">
      <rect x="24.5" y="86.7" width="39" height="34.5" fill="url(#lines)"/>
      <rect x="60.9" y="77.7" width="2.6" height="34.5" fill="url(#hatch)" class="shade"/>
      <rect x="24.5" y="110.4" width="39" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M24.5,77.7 L63.5,77.7 L62,110.2 Q62,112.2 60,112.2 L28,112.2 Q26,112.2 26,110.2 Z" class="stitch" transform="translate(44.0 94.95) scale(0.9385 0.9304) translate(-44.0 -94.95)"/>
    <path d="M24.5,77.7 L63.5,77.7 L62,110.2 Q62,112.2 60,112.2 L28,112.2 Q26,112.2 26,110.2 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-lleg">
    <path d="M24,114.2 L43,114.2 L42,179.8 Q42,183.8 38,183.8 L29,183.8 Q25,183.8 25,179.8 Z" class="piece-fill"/>
    <g clip-path="url(#clip-lleg)">
      <rect x="24" y="123.2" width="19" height="69.6" fill="url(#lines)"/>
      <rect x="40.4" y="114.2" width="2.6" height="69.6" fill="url(#hatch)" class="shade"/>
      <rect x="24" y="182.0" width="19" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M24,114.2 L43,114.2 L42,179.8 Q42,183.8 38,183.8 L29,183.8 Q25,183.8 25,179.8 Z" class="stitch" transform="translate(33.5 149.0) scale(0.8737 0.9655) translate(-33.5 -149.0)"/>
    <path d="M24,114.2 L43,114.2 L42,179.8 Q42,183.8 38,183.8 L29,183.8 Q25,183.8 25,179.8 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-rleg">
    <path d="M45,114.2 L64,114.2 L63,179.8 Q63,183.8 59,183.8 L50,183.8 Q46,183.8 46,179.8 Z" class="piece-fill"/>
    <g clip-path="url(#clip-rleg)">
      <rect x="45" y="123.2" width="19" height="69.6" fill="url(#lines)"/>
      <rect x="61.4" y="114.2" width="2.6" height="69.6" fill="url(#hatch)" class="shade"/>
      <rect x="45" y="182.0" width="19" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M45,114.2 L64,114.2 L63,179.8 Q63,183.8 59,183.8 L50,183.8 Q46,183.8 46,179.8 Z" class="stitch" transform="translate(54.5 149.0) scale(0.8737 0.9655) translate(-54.5 -149.0)"/>
    <path d="M45,114.2 L64,114.2 L63,179.8 Q63,183.8 59,183.8 L50,183.8 Q46,183.8 46,179.8 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-torso">
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,109.2 Q62.5,112.2 59.5,112.2 L28.5,112.2 Q25.5,112.2 25.5,109.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="piece-fill"/>
    <g clip-path="url(#clip-torso)">
      <rect x="24.5" y="43.2" width="39" height="78" fill="url(#lines)"/>
      <rect x="60.9" y="34.2" width="2.6" height="78" fill="url(#hatch)" class="shade"/>
      <rect x="24.5" y="110.4" width="39" height="1.8" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,109.2 Q62.5,112.2 59.5,112.2 L28.5,112.2 Q25.5,112.2 25.5,109.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="stitch" transform="translate(44.0 73.2) scale(0.9385 0.9692) translate(-44.0 -73.2)"/>
    <path d="M29.5,34.2 L58.5,34.2 Q63.5,34.2 63.5,39.2 L62.5,109.2 Q62.5,112.2 59.5,112.2 L28.5,112.2 Q25.5,112.2 25.5,109.2 L24.5,39.2 Q24.5,34.2 29.5,34.2 Z" class="piece-edge"/>
  </g>
  <g class="piece" id="piece-ghost">
    <clipPath id="clip-ghost"><path d="M0,121 L0,30.5 A30,30 0 0 1 60,30.5 L60,121 L52.5,113.5 L45,121 L37.5,113.5 L30,121 L22.5,113.5 L15,121 L7.5,113.5 Z"/></clipPath>
    <path d="M0,121 L0,30.5 A30,30 0 0 1 60,30.5 L60,121 L52.5,113.5 L45,121 L37.5,113.5 L30,121 L22.5,113.5 L15,121 L7.5,113.5 Z" class="piece-fill"/>
    <g clip-path="url(#clip-ghost)">
      <rect x="0" y="46" width="60" height="76" fill="url(#lines)"/>
      <rect x="57.4" y="0" width="2.6" height="122" fill="url(#hatch)" class="shade"/>
    </g>
    <path d="M0,121 L0,30.5 A30,30 0 0 1 60,30.5 L60,121 L52.5,113.5 L45,121 L37.5,113.5 L30,121 L22.5,113.5 L15,121 L7.5,113.5 Z" class="stitch" transform="translate(30 60.75) scale(0.9600 0.9801) translate(-30 -60.75)"/>
    <path d="M0,121 L0,30.5 A30,30 0 0 1 60,30.5 L60,121 L52.5,113.5 L45,121 L37.5,113.5 L30,121 L22.5,113.5 L15,121 L7.5,113.5 Z" class="piece-edge"/>
  </g></svg>
        <div class="chip" id="chip-head"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.head.hits"></span><span class="loc-ring" data-bind="locations.head.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-head"><?= e($schema['locations']['head']) ?></div>
        <div class="chip" id="chip-larm"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.larm.hits"></span><span class="loc-ring" data-bind="locations.larm.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-larm"><?= e($schema['locations']['larm']) ?></div>
        <div class="chip" id="chip-chest"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.chest.hits"></span><span class="loc-ring" data-bind="locations.chest.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-chest"><?= e($schema['locations']['chest']) ?></div>
        <div class="chip" id="chip-rarm"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.rarm.hits"></span><span class="loc-ring" data-bind="locations.rarm.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-rarm"><?= e($schema['locations']['rarm']) ?></div>
        <div class="chip" id="chip-abdomen"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.abdomen.hits"></span><span class="loc-ring" data-bind="locations.abdomen.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-abdomen"><?= e($schema['locations']['abdomen']) ?></div>
        <div class="chip" id="chip-lleg"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.lleg.hits"></span><span class="loc-ring" data-bind="locations.lleg.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-lleg"><?= e($schema['locations']['lleg']) ?></div>
        <div class="chip" id="chip-rleg"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.rleg.hits"></span><span class="loc-ring" data-bind="locations.rleg.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-rleg"><?= e($schema['locations']['rleg']) ?></div>
        <div class="chip" id="chip-torso"><span class="loc-label">loc</span><span class="loc-box" data-bind="locations.torso.hits"></span><span class="loc-ring" data-bind="locations.torso.ac" data-empty-class="is-empty"></span></div>
        <div class="piece-name" id="pname-torso"><?= e($schema['locations']['torso']) ?></div>
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
          <?php foreach ($schema['reductions'] as $key => $label): ?><tr><th><?= e($label) ?></th><td><span class="fit" data-bind="reductions.<?= e($key) ?>"></span></td></tr><?php endforeach; ?>
          <tr data-show="combatWary.show"><th>Combat Wary</th><td><span class="fit" data-bind="combatWary.value"></span></td></tr>
          </tbody></table>
        <table class="mini resist" data-section="resist"><colgroup><col><col class="n"></colgroup>
          <thead><tr><th>Resistance</th><th>LvL</th></tr></thead><tbody>
          <?php foreach ($schema['resistances'] as $key => $label): ?><tr><th><?= e($label) ?></th><td><span class="fit" data-bind="resist.<?= e($key) ?>"></span></td></tr><?php endforeach; ?>
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
     <span class="name"><span class="fit" data-bind="name" data-placeholder="Character Name"></span></span><span class="align" data-bind="alignment" data-wrap="brackets" data-placeholder="(Alignment)"></span><span class="dia">&#9670;</span><span class="orn"></span></footer>
  </div>
 </div>
</div>
