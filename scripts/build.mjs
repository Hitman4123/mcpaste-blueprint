#!/usr/bin/env node
/**
 * Packs extension/ into dist/mcpaste.blueprint, the file a panel owner
 * installs with `blueprint -install mcpaste`.
 *
 * A .blueprint file is a zip of the extension folder with conf.yml at its
 * root, which is what Blueprint's own `blueprint -export` makes (minus .dist,
 * .git and .gitkeep). Doing it here means building a release needs neither a
 * panel nor the zip tool. Entries are sorted and dated 1980-01-01, so the
 * same sources always give the same bytes.
 *
 * Before packing, it checks what Blueprint would refuse at install time:
 * conf.yml's required fields, the identifier, and every file or folder
 * conf.yml and Components.yml point at.
 *
 *   node scripts/build.mjs            → dist/mcpaste.blueprint (+ .json: version, target, size, sha256)
 *   node scripts/build.mjs <out>      → <out> (+ <out>.json)
 *
 * Needs Node 22.2 or newer and nothing from npm.
 */

import { createHash } from "node:crypto";
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import * as zlib from "node:zlib";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const SOURCE = join(ROOT, "extension");
const OUT = resolve(process.argv[2] ?? join(ROOT, "dist", "mcpaste.blueprint"));

const SKIP = [/^\.dist(\/|$)/, /^\.git(\/|$)/, /(^|\/)\.gitkeep$/, /(^|\/)\.gitignore$/, /(^|\/)node_modules(\/|$)/];

const crc32 = zlib.crc32;
if (typeof crc32 !== "function") {
    console.error("build: needs Node 22.2 or newer (zlib.crc32)");
    process.exit(1);
}

const fail = message => {
    console.error(`build: ${message}`);
    process.exit(1);
};

// ── conf.yml, read just enough to check it ──────────────────────────────
/** The two-level `section:\n  key: "value"` shape conf.yml uses, nothing more. */
const readConf = text => {
    const conf = {};
    let section = "";
    let sub = "";
    for (const line of text.split(/\r?\n/)) {
        if (!line.trim() || line.trim().startsWith("#")) continue;
        const top = /^(\w+):\s*$/.exec(line);
        if (top) {
            section = top[1];
            sub = "";
            conf[section] = conf[section] ?? {};
            continue;
        }
        const nested = /^ {2}(\w+):\s*$/.exec(line);
        if (nested) {
            sub = nested[1];
            continue;
        }
        const entry = /^ {2,4}(\w+):\s*(?:"([^"]*)"|'([^']*)'|(.*))\s*$/.exec(line);
        if (entry) {
            const key = line.startsWith("    ") && sub ? `${sub}.${entry[1]}` : entry[1];
            if (!line.startsWith("    ")) sub = "";
            conf[section][key] = (entry[2] ?? entry[3] ?? entry[4] ?? "").trim();
        }
    }
    return conf;
};

const conf = readConf(readFileSync(join(SOURCE, "conf.yml"), "utf8"));
const info = conf.info ?? {};

for (const field of ["name", "identifier", "description", "version", "target"])
    if (!info[field]) fail(`conf.yml info.${field} is required`);
if (!/^[a-z]+$/.test(info.identifier)) fail(`conf.yml info.identifier must be a-z only, got "${info.identifier}"`);
if (!conf.admin?.view) fail("conf.yml admin.view is required");

const mustExist = (path, what) => {
    try {
        statSync(join(SOURCE, path));
    } catch {
        fail(`${what} points at ${path}, which isn't in extension/`);
    }
};

for (const [section, values] of Object.entries(conf))
    for (const [key, value] of Object.entries(values))
        if (value && section !== "info") mustExist(value, `conf.yml ${section}.${key}`);
if (info.icon) mustExist(info.icon, "conf.yml info.icon");

// Components.yml: every component it names must exist as .tsx/.ts/.jsx/.js.
const componentsDir = conf.dashboard?.components;
if (componentsDir) {
    const yml = readFileSync(join(SOURCE, componentsDir, "Components.yml"), "utf8");
    const named = [...yml.matchAll(/:\s*'([^']+)'\s*$/gm), ...yml.matchAll(/Component:\s*'([^']+)'/g)].map(it => it[1]);
    for (const component of named) {
        if (/\.\w+$/.test(component) || component.startsWith("..") || component.startsWith("@"))
            fail(`Components.yml: "${component}" must be a path inside ${componentsDir} without an extension`);
        const found = ["tsx", "ts", "jsx", "js"].some(ext => {
            try {
                return statSync(join(SOURCE, componentsDir, `${component}.${ext}`)).isFile();
            } catch {
                return false;
            }
        });
        if (!found) fail(`Components.yml names ${component}, which isn't in ${componentsDir}`);
    }
}

// ── the zip ─────────────────────────────────────────────────────────────
const files = [];
const walk = dir => {
    for (const name of readdirSync(dir).sort()) {
        const full = join(dir, name);
        const path = relative(SOURCE, full).split("\\").join("/");
        if (SKIP.some(it => it.test(path))) continue;
        if (statSync(full).isDirectory()) walk(full);
        else files.push(path);
    }
};
walk(SOURCE);

const DOS_DATE = (0 << 9) | (1 << 5) | 1; // 1980-01-01
const DOS_TIME = 0;

const locals = [];
const centrals = [];
let offset = 0;

for (const path of files) {
    const data = readFileSync(join(SOURCE, path));
    const deflated = zlib.deflateRawSync(data, { level: 9 });
    const useDeflate = deflated.length < data.length;
    const body = useDeflate ? deflated : data;
    const name = Buffer.from(path, "utf8");
    const crc = crc32(data) >>> 0;

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4); // version needed
    local.writeUInt16LE(0x0800, 6); // UTF-8 names
    local.writeUInt16LE(useDeflate ? 8 : 0, 8);
    local.writeUInt16LE(DOS_TIME, 10);
    local.writeUInt16LE(DOS_DATE, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(body.length, 18);
    local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(name.length, 26);
    local.writeUInt16LE(0, 28);
    locals.push(local, name, body);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE((3 << 8) | 20, 4); // made by: Unix
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(useDeflate ? 8 : 0, 10);
    central.writeUInt16LE(DOS_TIME, 12);
    central.writeUInt16LE(DOS_DATE, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(body.length, 20);
    central.writeUInt32LE(data.length, 24);
    central.writeUInt16LE(name.length, 28);
    central.writeUInt32LE((0o100644 << 16) >>> 0, 38); // -rw-r--r--
    central.writeUInt32LE(offset, 42);
    centrals.push(central, name);

    offset += local.length + name.length + body.length;
}

const centralSize = centrals.reduce((sum, it) => sum + it.length, 0);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(files.length, 8);
end.writeUInt16LE(files.length, 10);
end.writeUInt32LE(centralSize, 12);
end.writeUInt32LE(offset, 16);

mkdirSync(dirname(OUT), { recursive: true });
const packed = Buffer.concat([...locals, ...centrals, end]);
writeFileSync(OUT, packed);
// What /hosts shows beside the download link.
writeFileSync(
    `${OUT}.json`,
    JSON.stringify({ version: info.version, target: info.target, bytes: packed.length, sha256: createHash("sha256").update(packed).digest("hex") }, null, 2) + "\n"
);
console.log(`build: ${info.identifier} ${info.version} (target ${info.target}), ${files.length} files → ${relative(ROOT, OUT)}`);
