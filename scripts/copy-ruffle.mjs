import { cpSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(repositoryRoot, 'node_modules/@ruffle-rs/ruffle');
const destination = resolve(repositoryRoot, 'public/ruffle');

if (!existsSync(source)) {
    throw new Error('Ruffle package is missing. Run npm ci before building the web assets.');
}

rmSync(destination, { recursive: true, force: true });
mkdirSync(destination, { recursive: true });
cpSync(source, destination, { recursive: true });
