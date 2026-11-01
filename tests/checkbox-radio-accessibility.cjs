/* Run: node tests/checkbox-radio-accessibility.cjs
 * Compiles the actual component and resolves its cascade against native controls.
 * These DOM checks do not replace trusted-browser keyboard QA.
 */
const assert = require('node:assert/strict');
const { createRequire } = require('node:module');
const path = require('node:path');
const requireDependency = process.env.DIRECTORIST_TEST_NODE_MODULES
    ? createRequire(path.join(process.env.DIRECTORIST_TEST_NODE_MODULES, '../package.json'))
    : require;
const sass = requireDependency('sass');
const postcss = requireDependency('postcss');
const { JSDOM } = requireDependency('jsdom');

const css = sass.compile(path.join(__dirname, '../assets/src/scss/component/_checkbox-radio.scss')).css;
const ast = postcss.parse(css);
const document = new JSDOM(`
  <form>
    <div class="directorist-checkbox">
      <input id="consent" type="checkbox" required>
      <label for="consent" class="directorist-checkbox__label">Consent <a href="#policy">Policy</a></label>
    </div>
    <div class="directorist-radio">
      <input id="author" name="type" type="radio" checked>
      <label for="author" class="directorist-radio__label">Author</label>
    </div>
    <div class="directorist-checkbox">
      <input id="hidden-consent" type="checkbox" hidden>
      <label for="hidden-consent" class="directorist-checkbox__label">Hidden consent</label>
    </div>
    <div class="directorist-radio">
      <input id="hidden-author" type="radio" hidden>
      <label for="hidden-author" class="directorist-radio__label">Hidden author</label>
    </div>
    <div id="hidden-ancestor" hidden>
      <div class="directorist-checkbox">
        <input id="ancestor-consent" type="checkbox">
        <label for="ancestor-consent" class="directorist-checkbox__label">Ancestor-hidden consent</label>
      </div>
    </div>
    <input id="unrelated" type="checkbox">
  </form>`).window.document;
const style = document.createElement('style');
style.textContent = css;
document.head.append(style);

function styleFor(element, { focus = false, checked = false, pseudo = '' } = {}) {
    const styles = new Map();
    ast.walkRules((rule) => {
        // The checks below target the desktop component rules, not mobile overrides.
        if (rule.parent.type === 'atrule') return;
        const matches = rule.selectors.some((selector) => {
            if (/:(before|after)$/.test(selector) !== Boolean(pseudo)) return false;
            if (pseudo && !selector.endsWith(`:${pseudo}`)) return false;
            if (/:focus(?:-visible)?/.test(selector) && !focus) return false;
            if (selector.includes(':checked') && !checked) return false;
            const base = selector.replace(/:(before|after)$/, '').replace(/:focus-visible|:focus|:checked/g, '');
            return element.matches(base);
        });
        if (!matches) return;
        rule.walkDecls((decl) => {
            const previous = styles.get(decl.prop);
            if (!previous || decl.important || !previous.important) {
                styles.set(decl.prop, { value: decl.value, important: decl.important });
            }
        });
    });
    return Object.fromEntries([...styles].map(([key, value]) => [key, value.value]));
}

for (const id of ['consent', 'author']) {
    const input = document.getElementById(id);
    const label = input.nextElementSibling;
    const styles = styleFor(input);
    assert.notEqual(styles.display, 'none', `${id} must remain in the native focus order`);
    assert.notEqual(styles.visibility, 'hidden', `${id} must remain focusable`);
    assert.equal(styles.position, 'absolute', `${id} must not change the styled label layout`);
    assert.equal(styles.width, '1px');
    assert.equal(styles.height, '1px');
    assert.equal(styles.overflow, 'hidden');
    assert.ok(styles.clip || styles['clip-path'], 'Native visuals must remain clipped');
    const focus = styleFor(label, { focus: true, pseudo: 'after' });
    assert.match(focus.outline || '', /2px solid/, `${id} needs a visible label focus ring`);
    assert.equal(focus['outline-offset'], '2px');
    const checked = styleFor(label, { checked: true, pseudo: 'after' });
    assert.equal(checked['background-color'], 'var(--directorist-color-primary)');
    assert.equal(input.labels[0], label, 'Native label association must be retained');
}

assert.deepEqual(styleFor(document.getElementById('unrelated')), {}, 'Unrelated native inputs are not restyled');
for (const id of ['hidden-consent', 'hidden-author']) {
    const input = document.getElementById(id);
    assert.equal(document.defaultView.getComputedStyle(input).display, 'none', `${id}: native hidden must not be overridden`);
    input.hidden = false;
    assert.equal(document.defaultView.getComputedStyle(input).display, 'block', `${id}: removing hidden restores the native control`);
    input.hidden = true;
    assert.equal(document.defaultView.getComputedStyle(input).display, 'none', `${id}: reapplying hidden excludes the control again`);
}
assert.equal(document.defaultView.getComputedStyle(document.getElementById('hidden-ancestor')).display, 'none', 'A hidden ancestor remains hidden');
const consent = document.getElementById('consent');
assert.equal(consent.validity.valueMissing, true, 'Required consent validation is retained');
document.querySelector('label[for="consent"]').click();
assert.equal(consent.checked, true, 'Label activation still toggles native state');
assert.equal(consent.validity.valueMissing, false);
assert.equal(document.querySelector('a').tabIndex, 0, 'The nested policy link remains keyboard reachable');
console.log('PASS: native checkbox/radio cascade, hidden state, focus styles, labels and required validation.');
