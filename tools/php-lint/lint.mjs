import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import fs from 'fs';

/**
 * PHP syntax linter (php -l معادل) بدون نیاز به نصب PHP — بر بستر WASM.
 *
 * استفاده:
 *   npm install
 *   node lint.mjs <file1.php> <file2.php> ...
 *
 * خروجی هر فایل: ✅ (سینتکس سالم) یا ❌ SYNTAX_ERROR: <پیام>
 * نکته: «runtime-note: Error» یعنی فایل از نظر نحوی سالما ولی کلاس والد/وابستگی
 *       در محیط WASM بار نشده (طبیعی برای lint). فقط «SYNTAX_ERROR» مهم است.
 */
const files = process.argv.slice(2);

if (files.length === 0) {
  console.error('Usage: node lint.mjs <file.php> [...]');
  process.exit(1);
}

const php = new PHP(await loadNodeRuntime('8.4', { emscriptenOptions: { processId: 42 } }));

for (const f of files) {
  const code = fs.readFileSync(f, 'utf8');
  php.writeFile('/lint.php', code);
  const out = await php.run({ code: `
<?php
try {
    include '/lint.php';
    echo "OK";
} catch (\\ParseError $e) {
    echo "SYNTAX_ERROR: " . $e->getMessage();
} catch (\\Throwable $e) {
    echo "OK (runtime-note: " . get_class($e) . ")";
}
` });
  const verdict = out.text.replace(/<br\s*\/?>/g, ' ').replace(/\s+/g, ' ').trim();
  console.log((verdict.startsWith('OK') ? '✅' : '❌') + '  ' + f + '  →  ' + verdict);
}
