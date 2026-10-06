import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';
import { readdirSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const addonDir = resolve(scriptDir, '..');
const projectRoot = resolve(addonDir, '../../..');

const bundledWorkflowsDir = join(addonDir, 'resources', 'workflows');
const projectWorkflowsDir = join(projectRoot, 'resources', 'workflows');
const docsDir = join(projectRoot, 'docs', 'workflows');
const mmdc = join(addonDir, 'node_modules', '.bin', 'mmdc');
const tmpDir = join(addonDir, '.diagrams-tmp');

mkdirSync(docsDir, { recursive: true });
mkdirSync(tmpDir, { recursive: true });

const readNames = (dir) => {
    try {
        return readdirSync(dir)
            .filter((file) => file.endsWith('.yaml'))
            .map((file) => file.replace(/\.yaml$/, ''));
    } catch {
        return [];
    }
};

// Project definitions override the bundled defaults by name.
const names = [
    ...new Set([...readNames(bundledWorkflowsDir), ...readNames(projectWorkflowsDir)]),
].sort();

const built = [];

for (const name of names) {
    process.stdout.write(`Dumping ${name}... `);

    const mermaid = execFileSync('php', ['artisan', 'workflow:dump', name, '--format=mermaid'], {
        cwd: projectRoot,
        encoding: 'utf8',
    });

    const mmdPath = join(tmpDir, `${name}.mmd`);
    writeFileSync(mmdPath, mermaid);

    const svgPath = join(docsDir, `${name}.svg`);
    execFileSync(mmdc, ['-i', mmdPath, '-o', svgPath, '-b', 'transparent'], {
        cwd: addonDir,
        stdio: 'inherit',
    });

    built.push(name);
    process.stdout.write(`-> docs/workflows/${name}.svg\n`);
}

rmSync(tmpDir, { recursive: true, force: true });

const sections = built
    .map(
        (name) => `    <section>
      <h2>${name}</h2>
      <object type="image/svg+xml" data="${name}.svg" aria-label="${name} workflow diagram"></object>
    </section>`
    )
    .join('\n');

const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Workflow Diagrams</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 1100px; padding: 0 1rem; color: #1f2937; }
    h1 { font-size: 1.75rem; }
    section { margin: 2.5rem 0; padding-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    h2 { font-size: 1.25rem; color: #374151; }
    object { width: 100%; max-width: 900px; }
    .note { color: #6b7280; font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>Editorial Workflows</h1>
  <p class="note">Auto-generated from <code>resources/workflows/*.yaml</code> via <code>npm run workflows:build</code>. Do not edit by hand.</p>
${sections}
</body>
</html>
`;

writeFileSync(join(docsDir, 'index.html'), html);
process.stdout.write(`\nWrote docs/workflows/index.html (${built.length} diagrams)\n`);
