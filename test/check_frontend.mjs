/**
 * 前端 ES 模块真实语法校验 + 相对导入解析检查。
 *
 * 说明：node 不暴露 SourceTextModule（未加 --experimental-vm-modules），
 * 故采用「剥离模块语法后用 vm.Script 以 async 包裹编译」的方式做语法校验：
 *  - 仅删除 `import ...` / `import '...'` 整行；
 *  - 仅删除 `export` 关键字本身（保留其后的 function/const/class 声明），
 *    这样 `export function foo(){}` 不会被破坏；
 *  - `export default X` 改写为 `const __default__ = X`。
 * 由于用 async 函数包裹，函数体首层的 `await` 也被允许。
 *
 * 用法: node temp/check_frontend.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const ROOT = path.resolve('public/assets/js');

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.js')) out.push(p);
  }
  return out;
}

function stripModuleSyntax(src) {
  return src
    // 多行 import { ... } from '...' （含单行）
    .replace(/^[ \t]*import\s*\{[^}]*\}\s*from\s*['"][^'"]*['"]\s*;?/gm, '')
    // import X from '...' / import * as X from '...' / import '...'
    .replace(/^[ \t]*import\s[^\n]*?from\s*['"][^'"]*['"]\s*;?[ \t]*$/gm, '')
    .replace(/^[ \t]*import\s*['"][^'"]*['"]\s*;?[ \t]*$/gm, '')
    // 具名导出列表：export { a, b };
    .replace(/^[ \t]*export\s*\{[^}]*\}\s*;?[ \t]*$/gm, '')
    // export default X → const __default__ = X
    .replace(/^([ \t]*)export\s+default\s+/gm, '$1const __default__ = ')
    // 其余 export 关键字（保留其后的声明）
    .replace(/^([ \t]*)export\s+/gm, '$1');
}

const files = walk(ROOT);
let syntaxFail = 0;
let importFail = 0;

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const rel = path.relative(ROOT, f);

  try {
    new vm.Script('(async()=>{\n' + stripModuleSyntax(src) + '\n})');
  } catch (e) {
    console.log(`[SYNTAX] ${rel}: ${e.message}`);
    syntaxFail++;
  }

  const re = /from\s*['"]([^'"]+)['"]|^\s*import\s*['"]([^'"]+)['"]/gm;
  let m;
  while ((m = re.exec(src)) !== null) {
    const spec = m[1] || m[2];
    if (!spec || !spec.startsWith('.')) continue;
    const target = path.resolve(path.dirname(f), spec);
    if (!fs.existsSync(target)) {
      console.log(`[IMPORT] ${rel} -> ${spec} (missing)`);
      importFail++;
    }
  }
}

console.log(`\nchecked ${files.length} files -> ${syntaxFail} syntax error(s), ${importFail} unresolved import(s)`);
process.exit(syntaxFail + importFail > 0 ? 1 : 0);
