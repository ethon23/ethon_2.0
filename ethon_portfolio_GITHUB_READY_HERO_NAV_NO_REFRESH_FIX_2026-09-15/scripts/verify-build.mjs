import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const required = [
  'index.html', 'admin.html', 'checkout.html', 'invoice.html',
  'admin.js', 'server.js', 'server.runtime.js', 'data.json', 'package.json'
];
const missing = required.filter(file => !fs.existsSync(path.join(root, file)));
if (missing.length) throw new Error(`Missing required files: ${missing.join(', ')}`);

const jsFiles = [];
function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.isFile() && full.endsWith('.js')) jsFiles.push(full);
  }
}
walk(root);
for (const file of jsFiles) execFileSync(process.execPath, ['--check', file], { stdio: 'ignore' });

const runtime = fs.readFileSync(path.join(root, 'server.runtime.js'), 'utf8');
const checks = [
  ['Google Pay gateway', runtime.includes("{ key: 'GOOGLE PAY'" )],
  ['Six gateways', ['STRIPE','PAYPAL','PAYONEER','GOOGLE PAY','BINANCE PAY','BKASH'].every(x => runtime.includes(`'${x}'`))],
  ['Currency order', runtime.includes("const PAYMENT_CURRENCIES = ['USD', 'GBP', 'EUR', 'BDT']")],
  ['Supabase fallback support', runtime.includes('SUPABASE_SERVICE_ROLE_KEY') && runtime.includes('data.json')],
  ['Health endpoint', runtime.includes("app.get(['/health', '/api/health']")],
  ['Chat access control', runtime.includes('requireChatAccess')],
  ['Admin protection', runtime.includes('requireAdmin')],
  ['Password endpoint', runtime.includes("app.post('/api/admin/password'")],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) throw new Error(`Invariant checks failed: ${failed.join(', ')}`);

for (const legacy of ['nav.css.txt', 'css_tail.txt']) {
  if (fs.existsSync(path.join(root, legacy))) throw new Error(`Dead legacy file still present: ${legacy}`);
}

console.log(`Build verification: PASS (${jsFiles.length} JS files checked; ${checks.length} runtime invariants checked)`);
