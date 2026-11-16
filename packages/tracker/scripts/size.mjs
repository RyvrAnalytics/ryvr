// Fails when the built tracker grows past its gzip budget.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET = 2560;
const size = gzipSync(readFileSync(new URL('../../../public/r.js', import.meta.url))).length;
console.log(`r.js: ${size} bytes gzipped (budget ${BUDGET})`);
if (size > BUDGET) process.exit(1);
