/* Verify direct source delivery without running a webpack or asset build. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const sourceTrees = ['assets/src/js/admin/mcp', 'assets/src/css/admin/mcp'];

function files(folder, prefix = '') {
  return fs.readdirSync(folder, { withFileTypes: true }).flatMap((entry) => {
    const relative = path.posix.join(prefix, entry.name);
    return entry.isDirectory() ? files(path.join(folder, entry.name), relative) : [relative];
  }).sort();
}
const expected = sourceTrees.flatMap((tree) => files(path.join(root, tree)).map((file) => tree + '/' + file)).sort();

function temporary(run) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'directorist-mcp-package-'));
  try { run(directory); }
  finally { fs.rmSync(directory, { recursive: true, force: true }); }
}

test('distribution filters ship exactly the MCP source subtrees', () => temporary((directory) => {
  const fixture = path.join(directory, 'source');
  const destination = path.join(directory, 'destination');
  fs.mkdirSync(destination);
  for (const relative of [...expected, 'assets/src/js/admin/settings-manager.js', 'assets/src/js/public/unrelated.js', 'assets/src/scss/unrelated.scss', 'assets/src/css/admin/unrelated.css']) {
    const file = path.join(fixture, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, 'fixture');
  }
  const output = execFileSync('rsync', ['-an', '--itemize-changes', '--exclude-from=' + path.join(root, '.distignore'), fixture + '/', destination + '/'], { encoding: 'utf8' });
  const included = output.split('\n').filter((line) => /^>f/.test(line)).map((line) => line.slice(line.indexOf(' ') + 1).trim()).filter((file) => file.startsWith('assets/src/')).sort();
  assert.deepEqual(included, expected);
  assert.equal(expected.length, 12);
}));

test('Git archives ship MCP sources while excluding unrelated source files', () => temporary((directory) => {
  // An alternate index leaves the developer's staging area and branch untouched.
  const env = { ...process.env, GIT_INDEX_FILE: path.join(directory, 'index') };
  const git = (args) => execFileSync('git', args, { cwd: root, env, encoding: 'utf8' });
  git(['read-tree', 'HEAD']);
  git(['add', '--', '.gitattributes', ...sourceTrees]);
  const tree = git(['write-tree']).trim();
  const archive = path.join(directory, 'sources.tar');
  git(['archive', '--format=tar', '--output=' + archive, tree, 'assets/src']);
  const contents = execFileSync('tar', ['-tf', archive], { encoding: 'utf8' }).split('\n').filter((name) => name && !name.endsWith('/')).sort();
  assert.deepEqual(contents, expected);
}));

test('legacy ZIP packaging restores only MCP sources after pruning src', () => temporary((directory) => {
  const context = {
    module: { exports: {} },
    require(name) {
      if (name === './webpack.legacy.dev.js') return { plugins: [] };
      if (name === 'filemanager-webpack-plugin') return class FileManagerPlugin { constructor(options) { this.options = options; } };
      throw new Error('Unexpected packaging dependency: ' + name);
    },
  };
  vm.runInNewContext(fs.readFileSync(path.join(root, 'webpack.legacy.prod.js'), 'utf8'), context);
  const operations = context.module.exports.plugins[0].options.events.onEnd;
  const packagePrefix = './__build/directorist/directorist/';
  const packageSources = path.join(directory, 'assets/src');
  fs.mkdirSync(packageSources, { recursive: true });
  fs.writeFileSync(path.join(packageSources, 'unrelated.js'), 'fixture');
  let pruned = false;
  let archived = false;
  for (const operation of operations) {
    if ((operation.delete || []).includes(packagePrefix + 'assets/src')) {
      fs.rmSync(packageSources, { recursive: true });
      pruned = true;
    }
    for (const copy of operation.copy || []) {
      if (!sourceTrees.includes(copy.source.replace(/^\.\//, ''))) continue;
      assert.equal(pruned, true);
      assert.equal(copy.destination.startsWith(packagePrefix), true);
      fs.cpSync(path.resolve(root, copy.source), path.join(directory, copy.destination.slice(packagePrefix.length)), { recursive: true });
    }
    if (operation.archive) {
      assert.equal(pruned, true);
      assert.deepEqual(files(packageSources).map((file) => 'assets/src/' + file).sort(), expected);
      archived = true;
    }
  }
  assert.equal(archived, true);
}));
