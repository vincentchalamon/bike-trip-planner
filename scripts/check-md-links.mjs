#!/usr/bin/env node
// Markdown link & anchor checker (Node built-ins only: fs, path, fetch).
//
// - Internal relative links (`](./x.md)`, `](../adr/y.md)`, `](path#anchor)`,
//   `](#anchor)`): the target file must exist; if a `#anchor` is present, the
//   target file must contain a heading whose GitHub slug matches. These
//   breakages are FATAL (exit 1).
// - External `http(s)://` links: best-effort `fetch` (GET, ~8s timeout,
//   following redirects). Non-2xx/3xx are reported but NON-FATAL by default
//   (network flakiness); pass `--external` to include them in the exit code.
//
// Usage:
//   node scripts/check-md-links.mjs [--external] [root]
//
// Scope: every `*.md` in the repo EXCEPT node_modules, vendor*, .claude,
// .features-gen and *-report directories.

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, resolve, relative, extname } from 'node:path';

const ROOT = resolve(process.argv.find((a, i) => i >= 2 && !a.startsWith('--')) ?? '.');
const CHECK_EXTERNAL = process.argv.includes('--external');
const EXTERNAL_TIMEOUT_MS = 8000;

const SKIP_DIRS = new Set(['node_modules', '.git', '.claude', '.features-gen']);
const SKIP_DIR_RE = /(^|\/)(vendor|vendor-bin)$|-report$/;

/** Recursively collect in-scope Markdown files. */
function collectMarkdown(dir, acc = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    const rel = relative(ROOT, full);
    if (entry.isDirectory()) {
      if (SKIP_DIRS.has(entry.name) || SKIP_DIR_RE.test(rel)) continue;
      collectMarkdown(full, acc);
    } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.md')) {
      acc.push(full);
    }
  }
  return acc;
}

/**
 * GitHub heading -> anchor slug.
 * Lowercase, strip punctuation (keep letters/digits/space/hyphen, Unicode-aware),
 * spaces -> hyphens. Duplicates are de-duplicated with -1, -2, ... by the caller.
 */
function slugify(heading) {
  return heading
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s-]/gu, '')
    .replace(/\s/g, '-');
}

/** Extract heading anchors (with GitHub de-duplication) from Markdown content. */
function extractAnchors(content) {
  const anchors = new Set();
  const counts = new Map();
  let inFence = false;
  let fenceMarker = '';
  for (const rawLine of content.split('\n')) {
    const line = rawLine.replace(/\r$/, '');
    const fence = line.match(/^\s*(```+|~~~+)/);
    if (fence) {
      if (!inFence) {
        inFence = true;
        fenceMarker = fence[1][0];
      } else if (fence[1][0] === fenceMarker) {
        inFence = false;
      }
      continue;
    }
    if (inFence) continue;
    const m = line.match(/^#{1,6}\s+(.*?)\s*#*\s*$/);
    if (!m) continue;
    // Strip inline markdown (links, emphasis, code) the way GitHub does for slugs.
    const text = m[1]
      .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/\[([^\]]*)\]\[[^\]]*\]/g, '$1')
      .replace(/`([^`]*)`/g, '$1')
      .replace(/[*_~]/g, '');
    let slug = slugify(text);
    if (counts.has(slug)) {
      const n = counts.get(slug) + 1;
      counts.set(slug, n);
      slug = `${slug}-${n}`;
    } else {
      counts.set(slug, 0);
    }
    anchors.add(slug);
  }
  return anchors;
}

/** Parse Markdown links, skipping fenced/inline code. Returns {url,line}. */
function extractLinks(content) {
  const links = [];
  const lines = content.split('\n');
  let inFence = false;
  let fenceMarker = '';
  // Matches [text](target) and [text](target "title"); ignores images via leading-! check.
  const linkRe = /(!?)\[(?:[^\]]*)\]\(\s*(<[^>]*>|[^()\s]+(?:\([^()]*\)[^()\s]*)*)\s*(?:"[^"]*"|'[^']*')?\s*\)/g;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i].replace(/\r$/, '');
    const fence = line.match(/^\s*(```+|~~~+)/);
    if (fence) {
      if (!inFence) {
        inFence = true;
        fenceMarker = fence[1][0];
      } else if (fence[1][0] === fenceMarker) {
        inFence = false;
      }
      continue;
    }
    if (inFence) continue;
    // Drop inline-code spans so links inside backticks are ignored.
    const scrubbed = line.replace(/`[^`]*`/g, (s) => ' '.repeat(s.length));
    let m;
    linkRe.lastIndex = 0;
    while ((m = linkRe.exec(scrubbed)) !== null) {
      if (m[1] === '!') continue; // image, not a link
      let url = m[2].trim();
      if (url.startsWith('<') && url.endsWith('>')) url = url.slice(1, -1).trim();
      if (url) links.push({ url, line: i + 1 });
    }
  }
  return links;
}

/** Best-effort external HEAD/GET probe. Returns {ok, status, error}. */
async function probeExternal(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), EXTERNAL_TIMEOUT_MS);
  try {
    const res = await fetch(url, {
      method: 'GET',
      redirect: 'follow',
      signal: controller.signal,
      headers: { 'user-agent': 'bike-trip-planner-link-check/1.0' },
    });
    return { ok: res.status >= 200 && res.status < 400, status: res.status };
  } catch (err) {
    return { ok: false, status: 0, error: err.name === 'AbortError' ? 'timeout' : err.message };
  } finally {
    clearTimeout(timer);
  }
}

const anchorCache = new Map();
function anchorsFor(filePath) {
  if (!anchorCache.has(filePath)) {
    anchorCache.set(filePath, extractAnchors(readFileSync(filePath, 'utf8')));
  }
  return anchorCache.get(filePath);
}

async function main() {
  const files = collectMarkdown(ROOT).sort();
  const internalFailures = []; // {file, line, url, reason}
  const externalFailures = []; // {file, line, url, status, error}
  const externalQueue = []; // {file, line, url}

  for (const file of files) {
    const content = readFileSync(file, 'utf8');
    const selfAnchors = extractAnchors(content);
    for (const { url, line } of extractLinks(content)) {
      if (/^(https?:)?\/\//i.test(url)) {
        if (url.startsWith('http://') || url.startsWith('https://')) {
          externalQueue.push({ file, line, url });
        }
        continue; // protocol-relative or other schemes: skip
      }
      if (/^(mailto:|tel:|#!)/i.test(url)) continue;

      // Pure in-document anchor.
      if (url.startsWith('#')) {
        const anchor = decodeURIComponent(url.slice(1));
        if (anchor && !selfAnchors.has(anchor)) {
          internalFailures.push({ file, line, url, reason: `ancre absente dans le fichier (#${anchor})` });
        }
        continue;
      }

      // Relative link, possibly with #anchor.
      const hashIdx = url.indexOf('#');
      const rawPath = hashIdx === -1 ? url : url.slice(0, hashIdx);
      const anchor = hashIdx === -1 ? '' : decodeURIComponent(url.slice(hashIdx + 1));
      const decodedPath = decodeURIComponent(rawPath);
      const target = resolve(dirname(file), decodedPath);

      if (!existsSync(target)) {
        internalFailures.push({ file, line, url, reason: `cible introuvable (${decodedPath})` });
        continue;
      }
      if (anchor) {
        let stat;
        try {
          stat = statSync(target);
        } catch {
          stat = null;
        }
        if (!stat || stat.isDirectory()) {
          internalFailures.push({ file, line, url, reason: `ancre sur une cible non-fichier (${decodedPath})` });
          continue;
        }
        if (extname(target).toLowerCase() === '.md') {
          if (!anchorsFor(target).has(anchor)) {
            internalFailures.push({ file, line, url, reason: `ancre absente dans ${decodedPath} (#${anchor})` });
          }
        }
        // Non-markdown target with anchor: existence is enough.
      }
    }
  }

  // External probes (best-effort, concurrency-limited). Skipped unless --external
  // so the default run — and the per-PR CI job — stays deterministic and offline.
  if (CHECK_EXTERNAL && externalQueue.length) {
    const seen = new Map();
    const CONCURRENCY = 8;
    let idx = 0;
    async function worker() {
      while (idx < externalQueue.length) {
        const item = externalQueue[idx++];
        let result = seen.get(item.url);
        if (!result) {
          result = await probeExternal(item.url);
          seen.set(item.url, result);
        }
        if (!result.ok) {
          externalFailures.push({ ...item, status: result.status, error: result.error });
        }
      }
    }
    await Promise.all(Array.from({ length: Math.min(CONCURRENCY, externalQueue.length) }, worker));
  }

  // Report.
  const byFile = new Map();
  for (const f of internalFailures) {
    if (!byFile.has(f.file)) byFile.set(f.file, []);
    byFile.get(f.file).push(f);
  }
  if (internalFailures.length) {
    console.log('\nLiens/ancres internes cassés:\n');
    for (const [file, items] of [...byFile].sort()) {
      console.log(`  ${relative(ROOT, file)}`);
      for (const it of items.sort((a, b) => a.line - b.line)) {
        console.log(`    L${it.line}: ${it.url} -> ${it.reason}`);
      }
    }
  } else {
    console.log('\nLiens/ancres internes: OK');
  }

  if (externalFailures.length) {
    console.log('\nLiens externes non-2xx/3xx (best-effort):\n');
    for (const it of externalFailures.sort((a, b) => `${a.file}${a.line}`.localeCompare(`${b.file}${b.line}`))) {
      const detail = it.error ? it.error : `HTTP ${it.status}`;
      console.log(`  ${relative(ROOT, it.file)} L${it.line}: ${it.url} -> ${detail}`);
    }
  } else if (CHECK_EXTERNAL && externalQueue.length) {
    console.log('\nLiens externes: OK');
  }

  console.log('\nRésumé:');
  console.log(`  fichiers scannés       : ${files.length}`);
  console.log(
    `  liens externes         : ${externalQueue.length}${CHECK_EXTERNAL ? " (probés)" : " (trouvés, probe ignoré — --external pour vérifier)"}`,
  );
  console.log(`  cassures internes      : ${internalFailures.length}`);
  if (CHECK_EXTERNAL) {
    console.log(`  externes non-2xx/3xx   : ${externalFailures.length}`);
  }

  const failed = internalFailures.length > 0 || (CHECK_EXTERNAL && externalFailures.length > 0);
  process.exit(failed ? 1 : 0);
}

main().catch((err) => {
  console.error(err);
  process.exit(2);
});
