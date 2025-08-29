// @copyright   (C) 2025 Mautic <https://www.mautic.org>, 2024 - 2025, JCrafts Software - Martina Scholz &amp; Benjamin Trenkle <https://jcrafts.software>. All rights reserved.
// @license     GNU/GPLv3 or later http://www.gnu.org/licenses/gpl-3.0.html

import fs from "fs";
import { execSync } from "child_process";

// DO NOT DELETE THIS FILE
// This file is used by build system to build a clean extension install package.

const __dirname = import.meta.dirname;

function main() {
    const source = fs.readFileSync(__dirname + "/../package.json").toString('utf-8');
    const sourceObj = JSON.parse(source);
    sourceObj.scripts = {};
    sourceObj.scripts = {
        "postpack": "tarball=$(npm list --depth 0 | sed 's/@/-/g; s/ .*/.tgz/g; 1q;'); tar -tf $tarball | sed 's/^package\\///' | zip -@r ${npm_package_name}-${npm_package_version}.zip -x package.json; rm $tarball"
    };
    sourceObj.devDependencies = {};
    const config = sourceObj.config;
    sourceObj.config = {};

    // ensure tmp folder exists
    fs.mkdirSync(__dirname + "/tmp", { recursive: true });

    fs.writeFileSync(__dirname + "/tmp/package.json", Buffer.from(JSON.stringify(sourceObj, null, 2), "utf-8") );

    config.dev.extension.forEach((ext) => {
        console.log('test', ext);

        fs.mkdirSync(__dirname + `/tmp/${ext.name}`, { recursive: true });

        if (fs.existsSync(__dirname + `/tmp/${ext.name}`)) {
            // Prepare the extensions
            fs.cpSync(__dirname + `/../${ext.name}`, __dirname + `/tmp/${ext.name}`, { recursive: true });
            sourceObj.name = ext.name;
            sourceObj.version = ext.version;
            fs.writeFileSync(__dirname + `/tmp/${ext.name}/package.json`, Buffer.from(JSON.stringify(sourceObj, null, 2), "utf-8") );
            fs.copyFileSync(__dirname + "/.npmignore", __dirname + `/tmp/${ext.name}/.npmignore`);
            if (fs.existsSync(__dirname + `/tmp/${ext.name}/media/js`)) {
                execSync(`esbuild "${__dirname}/tmp/${ext.name}/media/js/**/*.js" --allow-overwrite --outdir="${__dirname}/tmp/${ext.name}/media/js" --banner:js=\"// @package     ${ext.name}  v${ext.version}\n// @copyright   ${config.build.bannercopyright}\n\n\"`, { stdio: "inherit" });
                execSync(`esbuild "${__dirname}/tmp/${ext.name}/media/js/**/*.js" --minify --entry-names=[name].min --allow-overwrite --outdir=${__dirname}/tmp/${ext.name}/media/js --analyze --color=true`, { stdio: "inherit" });
            }
            if (fs.existsSync(__dirname + "/../LICENSE.md")) {
                fs.copyFileSync(__dirname + "/../LICENSE.md", __dirname + `/tmp/${ext.name}/LICENSE.txt`);
            }

            // Build the extensions
            execSync('npm pack', { cwd: `${__dirname}/tmp/${ext.name}`, stdio: 'inherit' });
            fs.renameSync(`${__dirname}/tmp/${ext.name}/${ext.name}-${ext.version}.zip`, `${__dirname}/tmp/${ext.name}.zip`, { recursive: true });
            fs.rmSync(__dirname + `/tmp/${ext.name}`, { recursive: true, force: true });

            
        }
    });

    // Prepare the package
    fs.cpSync(__dirname + `/../package`, __dirname + `/tmp`, { recursive: true });

    fs.copyFileSync(__dirname + "/.npmignore", __dirname + "/tmp/.npmignore");

    if (fs.existsSync(__dirname + "/../LICENSE.md")) {
        fs.copyFileSync(__dirname + "/../LICENSE.md", __dirname + "/tmp/LICENSE.txt");
    }

    if (fs.existsSync(__dirname + "/../README.md")) {
        fs.copyFileSync(__dirname + "/../README.md", __dirname + "/tmp/README.md");
    }
}

main();
