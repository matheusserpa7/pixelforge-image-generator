// Fails the frontend job on high or critical npm advisories.
//
// picomatch <= 4.0.7 (GHSA-fc5q-39xc-vjpf) has no patched release. Every high
// finding in this tree is that ReDoS, reached through Vite, vue-tsc and ESLint.
// The only "fix" npm offers is a semver-major downgrade of those tools. This
// script still fails when any other high advisory appears, and when picomatch
// itself grows a non-major fix, so the exception cannot outlive the patch.

import { execFileSync } from 'node:child_process';

const PICOMATCH_GHSA = 'GHSA-fc5q-39xc-vjpf';

const output = auditJson();
const report = JSON.parse(output.slice(output.indexOf('{')));
const vulnerabilities = report.vulnerabilities ?? {};

const picomatchFix = vulnerabilities.picomatch?.fixAvailable;

if (
    picomatchFix &&
    typeof picomatchFix === 'object' &&
    picomatchFix.name === 'picomatch' &&
    picomatchFix.isSemVerMajor !== true
) {
    console.error(
        `picomatch ${picomatchFix.version} fixes ${PICOMATCH_GHSA}. Upgrade and delete this exception.`,
    );
    process.exit(1);
}

const blocked = [];

for (const [name, info] of Object.entries(vulnerabilities)) {
    if (info.severity !== 'high' && info.severity !== 'critical') {
        continue;
    }

    if (!onlyPicomatch(name)) {
        blocked.push(`${name} (${info.severity})`);
    }
}

if (blocked.length > 0) {
    console.error('npm audit found high or critical advisories other than the unfixed picomatch ReDoS:');
    for (const name of blocked) {
        console.error(`  ${name}`);
    }
    process.exit(1);
}

const high = report.metadata?.vulnerabilities?.high ?? 0;
const critical = report.metadata?.vulnerabilities?.critical ?? 0;

console.log(
    `npm audit: ${critical} critical, ${high} high, all from ${PICOMATCH_GHSA} (no patched picomatch release).`,
);

function auditJson() {
    try {
        const command = process.platform === 'win32' ? process.env.ComSpec ?? 'cmd.exe' : 'npm';
        const args =
            process.platform === 'win32'
                ? ['/d', '/s', '/c', 'npm audit --json --audit-level=high']
                : ['audit', '--json', '--audit-level=high'];

        return execFileSync(command, args, {
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'inherit'],
        });
    } catch (error) {
        if (typeof error.stdout === 'string' && error.stdout.includes('{')) {
            return error.stdout;
        }

        throw error;
    }
}

function onlyPicomatch(name, seen = new Set()) {
    if (seen.has(name)) {
        return true;
    }

    seen.add(name);

    const info = vulnerabilities[name];

    if (!info) {
        return false;
    }

    for (const via of info.via ?? []) {
        if (typeof via === 'string') {
            if (!onlyPicomatch(via, seen)) {
                return false;
            }

            continue;
        }

        const url = String(via.url ?? '');

        if (via.name !== 'picomatch' || !url.includes(PICOMATCH_GHSA)) {
            return false;
        }
    }

    return (info.via ?? []).length > 0;
}
