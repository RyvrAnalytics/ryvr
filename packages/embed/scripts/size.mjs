// Fails when the built embed library grows past its gzip budget.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BUDGET = 12288;
const size = gzipSync(
    readFileSync(new URL('../../../public/embed.js', import.meta.url)),
).length;
console.log(`embed.js: ${size} bytes gzipped (budget ${BUDGET})`);
if (size > BUDGET) process.exit(1);
