#!/usr/bin/env node
/**
 * Mail Scheduler · compilación de estilos por skin
 *
 *   npm run build           Compila todas las skins de build/palettes/*
 *   npm run build -- imora  Solo una
 *   npm run check           Falla si algún CSS no está al día (para la CI)
 *
 * Para añadir otra skin derivada de Elastic:
 *   1. Copia su styles/colors.less en build/palettes/<skin>/colors.less
 *   2. npm run build
 * Las plantillas se copian desde skins/elastic/templates.
 */

import { readFile, writeFile, mkdir, readdir, cp } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import less from 'less';
import CleanCSS from 'clean-css';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'build', 'mail_scheduler.less');
const palettes = join(root, 'build', 'palettes');
const checkOnly = process.argv.includes('--check');
const only = process.argv.slice(2).filter((a) => !a.startsWith('--'));

const header = (skin) => `/*! Mail Scheduler for Roundcube · skin "${skin}" · generado con npm run build, no editar */\n`;

async function compile(skin) {
    const input = await readFile(source, 'utf8');
    const out = await less.render(input, {
        filename: source,
        paths: [join(palettes, skin), join(root, 'build')],
        math: 'always',
    });

    const min = new CleanCSS({ level: 1 }).minify(out.css);

    if (min.errors.length) {
        throw new Error(min.errors.join('\n'));
    }

    return header(skin) + min.styles + '\n';
}

async function main() {
    const skins = (await readdir(palettes, { withFileTypes: true }))
        .filter((d) => d.isDirectory() && existsSync(join(palettes, d.name, 'colors.less')))
        .map((d) => d.name)
        .filter((s) => !only.length || only.includes(s));

    let stale = 0;

    for (const skin of skins) {
        const css = await compile(skin);
        const dir = join(root, 'skins', skin);
        const target = join(dir, 'styles', 'mail_scheduler.css');

        if (checkOnly) {
            const current = existsSync(target) ? await readFile(target, 'utf8') : '';
            if (current !== css) {
                console.error(`✗ ${skin}: styles/mail_scheduler.css no está al día (ejecuta npm run build)`);
                stale++;
            } else {
                console.log(`✓ ${skin}`);
            }
            continue;
        }

        await mkdir(join(dir, 'styles'), { recursive: true });
        await writeFile(target, css);

        // Las skins de marca usan las mismas plantillas que Elastic
        if (skin !== 'elastic') {
            await cp(join(root, 'skins', 'elastic', 'templates'), join(dir, 'templates'), { recursive: true });
        }

        console.log(`✓ ${skin} (${(css.length / 1024).toFixed(1)} KB)`);
    }

    if (checkOnly) {
        // Las plantillas de las skins de marca deben ser idénticas a las de Elastic
        for (const skin of skins.filter((s) => s !== 'elastic')) {
            const base = join(root, 'skins', 'elastic', 'templates');
            for (const file of await readdir(base)) {
                const a = await readFile(join(base, file), 'utf8');
                const bPath = join(root, 'skins', skin, 'templates', file);
                const b = existsSync(bPath) ? await readFile(bPath, 'utf8') : null;
                if (a !== b) {
                    console.error(`✗ ${skin}: templates/${file} difiere de Elastic (ejecuta npm run build)`);
                    stale++;
                }
            }
        }

        if (stale) {
            process.exit(1);
        }
    }
}

main().catch((e) => {
    console.error(e.message || e);
    process.exit(1);
});
