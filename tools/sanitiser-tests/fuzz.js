const fs = require('fs');
const src = fs.readFileSync(require('path').join(__dirname, '../../BB/') + 'assets/js/app.js', 'utf8');
const schema = JSON.parse(fs.readFileSync(require('path').join(__dirname, '../../BB/') + 'config/schema.json', 'utf8'));
const start = src.indexOf('  const STRIP =');
const end = src.indexOf('  function blankCharacter');
const body = src.slice(start, end);
const sanitise = new Function('schema', body + '\nreturn sanitise;')(schema);
const cases = JSON.parse(fs.readFileSync('cases.json', 'utf8'));
const php = JSON.parse(fs.readFileSync('php_out.json', 'utf8'));
let fail = 0, diff = 0;
cases.forEach((c, i) => {
  try {
    const r = sanitise(c);
    const r2 = sanitise(r.data);
    if (r2.warnings.length) { fail++; console.log('not idempotent', i, r2.warnings); }
    if (JSON.stringify(r.data) !== JSON.stringify(php[i])) {
      if (diff++ < 3) { const a = r.data, b = php[i]; for (const k of Object.keys(a)) if (JSON.stringify(a[k]) !== JSON.stringify(b[k])) console.log('diff', i, k, JSON.stringify(a[k]).slice(0,120), '| php:', JSON.stringify(b[k]).slice(0,120)); }
    }
  } catch (e) { fail++; console.log('case', i, e.message); }
});
console.log(`js cases: ${cases.length} failures: ${fail} php/js differences: ${diff}`);
