#!/usr/bin/env node
// Checks that every message catalog under pwa/messages exposes the exact same
// set of (dotted) keys. Reports keys missing in either direction and exits 1 on
// any mismatch. Pure Node, no dependencies.

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const scriptDir = dirname(fileURLToPath(import.meta.url));
const pwaRoot = join(scriptDir, "..");
const messagesDir = join(pwaRoot, "messages");
const localeFile = join(pwaRoot, "src", "i18n", "locale.ts");

// Drive the locale list from SUPPORTED_LOCALES (single source of truth). The
// file is TypeScript, so parse the exported array literal instead of importing.
function readSupportedLocales() {
  const source = readFileSync(localeFile, "utf8");
  const match = source.match(/SUPPORTED_LOCALES\s*=\s*\[([^\]]*)\]/);
  if (!match) {
    throw new Error(`Could not find SUPPORTED_LOCALES in ${localeFile}`);
  }
  const locales = [...match[1].matchAll(/["'`]([^"'`]+)["'`]/g)].map(
    (m) => m[1],
  );
  if (locales.length === 0) {
    throw new Error(`SUPPORTED_LOCALES is empty in ${localeFile}`);
  }
  return locales;
}

// Recursively flatten a nested message object into a set of dotted key paths.
function flatten(obj, prefix, out) {
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (value && typeof value === "object" && !Array.isArray(value)) {
      flatten(value, path, out);
    } else {
      out.add(path);
    }
  }
  return out;
}

function loadKeys(locale) {
  const file = join(messagesDir, `${locale}.json`);
  const data = JSON.parse(readFileSync(file, "utf8"));
  return flatten(data, "", new Set());
}

const locales = readSupportedLocales();
const keysByLocale = new Map(locales.map((l) => [l, loadKeys(l)]));

const union = new Set();
for (const keys of keysByLocale.values()) {
  for (const key of keys) union.add(key);
}

let hasMismatch = false;
for (const locale of locales) {
  const keys = keysByLocale.get(locale);
  const missing = [...union].filter((k) => !keys.has(k)).sort();
  if (missing.length > 0) {
    hasMismatch = true;
    process.stderr.write(
      `Missing ${missing.length} key(s) in ${locale}.json:\n`,
    );
    for (const key of missing) process.stderr.write(`  - ${key}\n`);
  }
}

if (hasMismatch) {
  process.exit(1);
}

process.stdout.write(
  `i18n-check OK: ${union.size} keys in sync across ${locales.join(", ")}\n`,
);
