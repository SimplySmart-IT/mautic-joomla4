#!/usr/bin/env php
<?php
// Self-contained PHP implementation of bump-deploy.

// -------------------- Colors & helpers --------------------
// Inline color sequences
const FC_GREEN_INLINE = "\033[1;32m";
const FC_RED_INLINE = "\033[1;31m";
const FC_YELLOW_INLINE = "\033[1;33m";
const FC_BLUE_INLINE = "\033[1;34m";
const FC_BOLDU_INLINE = "\033[1;4m";
const FC_BOLD_INLINE = "\033[1m";

// Clear sequences
const CLEAR_COLOR_INLINE = "\033[0m";
const CLEAR_COLOR = "\n\x1b[K\x1b[0m\n";

function terminal_width(): int
{
    $cols = 80;
    $out = @shell_exec('tput cols 2>/dev/null');
    if ($out !== null) {
        $out = trim($out);
        if (is_numeric($out) && (int)$out > 0) {
            $cols = (int)$out;
        }
    }
    return $cols;
}

function pad_left(string $str): string
{
    $width = terminal_width();
    $len = mb_strlen($str);
    if ($len > $width) {
        $len = $len - (int)($len / $width) * $width;
    }
    $pad_right = max(0, $width - $len);
    return $str . str_repeat(' ', $pad_right);
}

function bg_blue(string $str): void
{
    $empty = pad_left(' ');
    $full = pad_left(' > ' . $str);
    echo "\n\033[1;44m\033[K" . $empty . "\n" . $full . "\n" . $empty . "\033[0m\n";
}
function bg_red(string $str): void
{
    $empty = pad_left(' ');
    $full = pad_left(' > ' . $str);
    echo "\n\033[1;41m\033[K" . $empty . "\n" . $full . "\n" . $empty . "\033[0m\n";
}
function bg_yellow(string $str): void
{
    $empty = pad_left(' ');
    $full = pad_left(' > ' . $str);
    echo "\n\033[1;43m\033[K" . $empty . "\n" . $full . "\n" . $empty . "\033[0m\n";
}
function bg_green(string $str): void
{
    $empty = pad_left(' ');
    $full = pad_left(' > ' . $str);
    echo "\n\033[1;42m\033[K" . $empty . "\n" . $full . "\n" . $empty . "\033[0m\n";
}

function fc_echo(string $text, string $color = FC_GREEN_INLINE): void
{
    echo $color . $text . CLEAR_COLOR_INLINE . PHP_EOL;
}

// -------------------- BumpVersion class --------------------
class BumpVersion
{
    public $extensionRoot;
    public $extensionVersion;
    public $command;
    public $projectRoot = null; // if set, excludes are evaluated relative to this root

    private $directoryLoopExcludeDirectories = [
        '/.git',
        '/.github',
        '/vendor',
        '/lib_mautic/src/vendor',
        '/node_modules',
        '/phan'
    ];

    private $directoryLoopExcludeFiles = [];

    private $packageJsonFiles = [
        '/package.json',
        '/package-lock.json',
    ];

    public function bump()
    {
        // prepare normalized root for relative path calculations
        $rootBase = $this->projectRoot ?? $this->extensionRoot;
        $rootNorm = rtrim(str_replace('\\', '/', $rootBase), '/');

        // normalize exclude patterns to a consistent format (leading slash, no trailing)
        $normalizedExcludes = array_map(function ($e) {
            $p = str_replace('\\', '/', $e);
            $p = '/' . trim($p, '/');
            return $p;
        }, $this->directoryLoopExcludeDirectories);

        if (empty($this->extensionVersion)) {
            $this->usage($this->command);
            die();
        }

        if (empty($this->extensionRoot)) {
            $this->usage($this->command);
            die();
        }

        $versionParts = explode('-', $this->extensionVersion);

        if (!preg_match('#^[0-9]+\.[0-9]+\.[0-9]+$#', $versionParts[0])) {
            $this->usage($this->command);
            die();
        }

        if (isset($versionParts[1]) && !preg_match('#(dev|alpha|beta|rc)[0-9]*#', $versionParts[1])) {
            $this->usage($this->command);
            die();
        }

        if (isset($versionParts[2]) && $versionParts[2] !== 'dev') {
            $this->usage($this->command);
            die();
        }

        $changedFilesSinceVersion  = 0;
        $year = date('Y');
        $month = date('m');

        $directory = new RecursiveDirectoryIterator($this->extensionRoot);

        // Use a callback filter so we can prevent recursion into excluded directories
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function ($current, $key, $iterator) use ($normalizedExcludes, $rootNorm) {
                // Always allow dot files handling to be left to RecursiveDirectoryIterator
                $pathname = $current->getPathname();
                // Normalize
                $path = '/' . ltrim(str_replace('\\', '/', $pathname), '/');
                $relative = '/' . ltrim(substr($path, strlen($rootNorm)), '/');

                // If directory, check excludes; returning false will skip this directory and its children
                if ($current->isDir()) {
                    foreach ($normalizedExcludes as $excludeDirectory) {
                        if (strpos($relative, $excludeDirectory) === 0) {
                            return false;
                        }
                    }
                }
                return true;
            }
        );

        $iterator  = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($this->packageJsonFiles as $packageJsonFile) {
            if (file_exists(dirname($this->extensionRoot) . $packageJsonFile)) {
                $package = json_decode(file_get_contents(dirname($this->extensionRoot) . $packageJsonFile));
                $package->version = $this->extensionVersion;
                file_put_contents(dirname($this->extensionRoot) . $packageJsonFile, str_replace('    ', '  ', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
                echo '- Version in ' . $packageJsonFile . ' changed to ' . $this->extensionVersion . PHP_EOL;
            }
        }

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $filePath     = $file->getPathname();
            // normalize paths and compute relative to project root if provided
            $filePathNorm = '/' . ltrim(str_replace('\\', '/', $filePath), '/');
            $relativePath = '/' . ltrim(substr($filePathNorm, strlen($rootNorm)), '/');

            if (preg_match('#\\.(png|jpeg|jpg|gif|bmp|ico|webp|svg|woff|woff2|ttf|eot)$#', $filePath)) {
                continue;
            }

            // exact file excludes (normalized)
            $normalizedFile = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
            if (\in_array($normalizedFile, $this->directoryLoopExcludeFiles)) {
                continue;
            }

            $changeSinceVersion  = false;

            $fileContents = file_get_contents($filePath);

            if ($relativePath !== '/build/bump.php' && preg_match('#__DEPLOY_VERSION__#', $fileContents)) {
                $changeSinceVersion = true;
                $fileContents       = preg_replace('#__DEPLOY_VERSION__#', $versionParts[0], $fileContents);
                $changedFilesSinceVersion++;
            }

            if (str_ends_with($filePath, '.xml') && preg_match('#<version>[^<]*</version>#', $fileContents)) {
                $changeSinceVersion = true;
                $fileContents = preg_replace('#<version>[^<]*</version>#', '<version>' . $this->extensionVersion . '</version>', $fileContents);
                echo '- Version in Manifest changed to ' . $this->extensionVersion . PHP_EOL;
                echo PHP_EOL;
            }

            if ($changeSinceVersion) {
                file_put_contents($filePath, $fileContents);
            }
        }

        if ($changedFilesSinceVersion > 0) {
            echo '- Since Version changed in ' . $changedFilesSinceVersion . ' files.' . PHP_EOL;
            echo PHP_EOL;
        }

        echo '-> Version bump for package complete!';
        echo PHP_EOL . PHP_EOL;
    }

    public function usage($command)
    {
        echo PHP_EOL;
        echo 'Usage: php ' . $command . PHP_EOL;
        echo PHP_EOL;
    }
}

// -------------------- Deployment workflow (main) --------------------
function prompt(string $label, ?string $default = null): string
{
    $prompt = $label;
    if ($default !== null && $default !== '') {
        $prompt .= ' [' . $default . ']';
    }
    $prompt .= ': ';
    echo $prompt;
    $line = trim(fgets(STDIN));
    return $line === '' ? ($default ?? '') : $line;
}

// Auto-detect project root = parent folder of this build directory
$project = realpath(__DIR__ . '/..');
if ($project === false || !is_dir($project)) {
    fc_echo('Could not determine project root from build folder.', FC_RED_INLINE);
    exit(1);
}
fc_echo('Using project root: ' . $project, FC_BLUE_INLINE);

$packageJsonPath = rtrim($project, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'package.json';
$currentVersion = '';
if (is_file($packageJsonPath)) {
    $pkg = json_decode(file_get_contents($packageJsonPath), true);
    if (isset($pkg['version'])) {
        $currentVersion = $pkg['version'];
    }
}

$bumpVersion = prompt('Enter the version (x.x.x)', $currentVersion);
if ($bumpVersion === '') {
    fc_echo('No version specified', FC_RED_INLINE);
    exit(1);
}

$builder = new BumpVersion();
$builder->command = __FILE__;
$builder->extensionRoot = rtrim($project, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'package';
$builder->extensionVersion = $bumpVersion;
$builder->projectRoot = $project;

fc_echo('Running bump for ' . $builder->extensionRoot . ' -> ' . $bumpVersion, FC_BLUE_INLINE);
echo PHP_EOL;
$builder->bump();

// Handle sub-packages defined in root package.json config.dev.extension
$rootPkgPath = $project . DIRECTORY_SEPARATOR . 'package.json';
if (is_file($rootPkgPath)) {

    $rootPkg = json_decode(file_get_contents($rootPkgPath), true);
    if (!empty($rootPkg['config']['dev']['extension']) && is_array($rootPkg['config']['dev']['extension'])) {
        foreach ($rootPkg['config']['dev']['extension'] as $extDef) {
            $extName = $extDef['name'] ?? null;
            if (!$extName) continue;
            $extDir = $project . DIRECTORY_SEPARATOR . $extName;
            if (!is_dir($extDir)) {
                echo PHP_EOL;
                fc_echo('Skipping missing subpackage dir: ' . $extDir, FC_YELLOW_INLINE);
                echo PHP_EOL;
                continue;
            }

            // determine current version (package.json in subpackage or definition)
            $extPkgPath = $extDir . DIRECTORY_SEPARATOR . 'package.json';
            $currentExtVersion = $extDef['version'] ?? '';
            if (is_file($extPkgPath)) {
                $extPkgData = json_decode(file_get_contents($extPkgPath), true);
                if (!empty($extPkgData['version'])) {
                    $currentExtVersion = $extPkgData['version'];
                }
            }

            $extVersion = prompt('Enter version for ' . $extName, $currentExtVersion);
            if ($extVersion === '') {
                echo PHP_EOL;
                fc_echo('Skipping (version empty) ' . $extName, FC_YELLOW_INLINE);
                echo PHP_EOL;
                continue;
            }

            fc_echo('Running bump for ' . $extName . ' to version ' . $extVersion, FC_BLUE_INLINE);
            echo PHP_EOL;

            // update version in root package.json config.dev.extension
            if (isset($rootPkg) && is_array($rootPkg) && !empty($rootPkg['config']['dev']['extension'])) {
                foreach ($rootPkg['config']['dev']['extension'] as &$entry) {
                    if (isset($entry['name']) && $entry['name'] === $extName) {
                        $entry['version'] = $extVersion;
                        echo '- Version for ' . $extName . ' updated to ' . $extVersion . PHP_EOL;
                        break;
                    }
                }
                unset($entry);
            }

            // Find manifest XML in the subpackage and bump its <version> tag
            $manifestFound = false;
            $manifestUpdated = false;
            $searchDirs = [
                $extDir,
                $extDir . DIRECTORY_SEPARATOR . 'admin',
                $extDir . DIRECTORY_SEPARATOR . 'administrator',
            ];

            foreach ($searchDirs as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }
                $files = scandir($dir);
                if ($files === false) {
                    continue;
                }
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    if (str_ends_with($f, '.xml')) {
                        $filePath = $dir . DIRECTORY_SEPARATOR . $f;
                        $contents = file_get_contents($filePath);
                        if (preg_match('#<version>[^<]*</version>#', $contents)) {
                            $manifestFound = true;
                            $newContents = preg_replace('#<version>[^<]*</version>#', '<version>' . $extVersion . '</version>', $contents, 1);
                            if ($newContents !== $contents) {
                                file_put_contents($filePath, $newContents);
                                echo '- Version in Manifest for ' . $extName . ' updated to ' . $extVersion . PHP_EOL;
                                $manifestUpdated = true;
                            } else {
                                echo '- Manifest for ' . $extName . ' already has version ' . $extVersion . PHP_EOL;
                            }
                            break 2; // stop both loops after first manifest found
                        }
                    }
                }
            }

            if (! $manifestFound) {
                echo PHP_EOL;
                fc_echo('No manifest XML with <version> found in typical locations for ' . $extName, FC_YELLOW_INLINE);
                echo PHP_EOL;
            }

            // Run bump for subpackage (prefer src folder)
            $extSrc = $extDir . DIRECTORY_SEPARATOR . 'src';
            $runRoot = is_dir($extSrc) ? $extSrc : $extDir;
            $extBuilder = new BumpVersion();
            $extBuilder->command = __FILE__;
            $extBuilder->extensionRoot = $runRoot;
            $extBuilder->extensionVersion = $extVersion;
            $extBuilder->projectRoot = $project;
            echo '- Running recursive bump for subpackage ' . $extName . PHP_EOL;
            $extBuilder->bump();

            echo '-> Version bump for ' . $extName . ' complete!' . PHP_EOL;
            echo PHP_EOL;
        }
    }
}

// Write back root package.json if we modified it
if (isset($rootPkg) && is_array($rootPkg)) {
    file_put_contents($rootPkgPath, str_replace('    ', '  ', json_encode($rootPkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
    fc_echo('Saved updated root package.json', FC_GREEN_INLINE);
}

$commitMessage = 'Release ' . $bumpVersion;
$gitDryCmd = 'git --git-dir="' . $project . '/.git" --work-tree="' . $project . '/" commit -am "' . $commitMessage . '" --dry-run';

fc_echo('Git dry-run:', FC_YELLOW_INLINE);
passthru($gitDryCmd, $gitDryRet);

fc_echo('Tag and push tag for ' . $project . '?', FC_BLUE_INLINE);
$confirm = prompt('All files checked? (y/N)', 'N');

if (strtolower($confirm) === 'y') {
    fc_echo('Committing changes...', FC_GREEN_INLINE);
    $gitCommitCmd = 'git --git-dir="' . $project . '/.git" --work-tree="' . $project . '/" commit -am "' . $commitMessage . '"';
    passthru($gitCommitCmd, $retCommit);

    fc_echo('Creating signed tag: ' . $bumpVersion, FC_GREEN_INLINE);
    $gitTagCmd = 'git --git-dir="' . $project . '/.git" --work-tree="' . $project . '/" tag -s ' . escapeshellarg($bumpVersion) . ' -m "' . $commitMessage . '"';
    passthru($gitTagCmd, $retTag);

    fc_echo('Pushing tag to origin: ' . $bumpVersion, FC_GREEN_INLINE);
    $gitPushCmd = 'git --git-dir="' . $project . '/.git" --work-tree="' . $project . '/" push origin --tag ' . escapeshellarg($bumpVersion);
    passthru($gitPushCmd, $retPush);

    fc_echo('Done.', FC_GREEN_INLINE);
} else {
    fc_echo('Aborted by user. No commit or tag created.', FC_YELLOW_INLINE);
}

exit(0);
