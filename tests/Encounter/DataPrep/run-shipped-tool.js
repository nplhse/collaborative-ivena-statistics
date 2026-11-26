// Runs a downloaded data preparation tool offline (#1301): `node run-shipped-tool.js <tool.html> <source.csv>
// <key.json> <out.csv> [<local-map.json>]`.
//
// Takes the configuration and the inline scripts from the HTML file exactly as shipped, evaluates them in a fresh
// context without network (`fetch` throws), uses the bundled catalogs, detects the profile like the app (exactly
// one match) and writes the exchange file with the tool's own version. The app script needs a page, so it is only
// compiled. Prints a JSON summary. Called by ShippedDataPrepToolTest.
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';

const [htmlPath, sourcePath, keyPath, outPath, localMapPath] = process.argv.slice(2);
const html = readFileSync(htmlPath, 'utf8');

const config = JSON.parse(
    html.match(/<script type="application\/json" id="data-prep-config">([\s\S]*?)<\/script>/)[1],
);
const scripts = [...html.matchAll(/<script>\n([\s\S]*?)\n<\/script>/g)].map((match) => match[1]);
const csp = html.match(/<meta http-equiv="Content-Security-Policy" content="([^"]*)">/)[1];
const external = /<(script|link|img|iframe)[^>]+(src|href)=/i.test(html);

const offline = () => {
    throw new Error('offline: no network in this check');
};
const context = vm.createContext({
    crypto: globalThis.crypto,
    TextDecoder,
    TextEncoder,
    fetch: offline,
    XMLHttpRequest: offline,
    console,
});
context.globalThis = context;
// Core and schema modules run; the app (last script) is compiled only.
scripts
    .slice(0, -1)
    .forEach((script, index) =>
        vm.runInContext(script, context, { filename: `script-${index}.js` }),
    );
new vm.Script(scripts.at(-1), { filename: 'app.js' });

const prep = context.EncounterDataPrep;
const catalogs = Object.fromEntries(
    Object.entries(config.catalogs).map(([id, catalog]) => [id, prep.parseCatalog(catalog)]),
);
const { text } = prep.decodeBytes(readFileSync(sourcePath));
const header = prep
    .parseCsv(text.slice(0, 20000), ';')[0]
    .map((name) => name.replace(/\s+/g, ' ').trim());
const matches = prep.matchingProfiles(header, config.profiles);
if (matches.length !== 1) {
    throw new Error(`Expected exactly one profile, got ${matches.length}.`);
}
const profile = matches[0];
const schema = config.schemas[profile.schema];
const key = await prep.loadKey(JSON.parse(readFileSync(keyPath, 'utf8')));
const result = await prep.transform(prep.readTable(text, profile.source.delimiter), {
    profile,
    schema,
    key,
    localMap: localMapPath
        ? prep.loadLocalMap(readFileSync(localMapPath, 'utf8'), profile)
        : prep.emptyLocalMap(profile),
    catalogs,
});
writeFileSync(
    outPath,
    prep.buildExchangeFile(result, {
        profile,
        schema,
        toolVersion: config.version,
        keyCheck: key.check,
    }),
);
process.stdout.write(
    JSON.stringify({
        profile: profile.id,
        schema: profile.schema,
        rows: result.rows.length,
        scripts: scripts.length,
        csp,
        external,
        catalogs: Object.keys(catalogs),
        fileName: prep.exchangeFileName(result, profile),
    }),
);
