const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const { chromium } = require('@playwright/test');

const source = fs
	.readFileSync(
		path.resolve(
			__dirname,
			'../../assets/src/js/global/map-scripts/add-listing/google-map.js',
		),
		'utf8',
	)
	.replace(
		"import { get_dom_data } from './../../../lib/helper';",
		'const get_dom_data = () => ({ default_latitude: 0, default_longitude: 0, map_zoom_level: 10 });',
	)
	.replace('export function initAddListingMap()', 'function initAddListingMap()')
	.split('$(document).ready(function () {')[0] +
	'\nwindow.initAddListingMap = initAddListingMap;';

test('required Google address focuses the widget and submits after being filled', async () => {
	const browser = await chromium.launch({
		headless: true,
		...(process.env.CHROME_PATH
			? { executablePath: process.env.CHROME_PATH }
			: {}),
	});

	try {
		const page = await browser.newPage();
		await page.setContent(`
			<form id="listing-form">
				<div class="directorist-form-group">
					<label for="address">Address</label>
					<input id="address" name="address" required>
				</div>
				<button type="submit">Save</button>
			</form>
		`);
		await page.evaluate(() => {
			class PlaceAutocompleteElement extends HTMLElement {
				constructor() {
					super();
					this.tabIndex = 0;
					this.value = '';
				}
			}
			customElements.define('gmp-place-autocomplete', PlaceAutocompleteElement);
			window.directorist = { assets_url: '', countryRestriction: false };
			window.jQuery = () => ({ on() {} });
			window.google = {
				maps: {
					Geocoder: class {},
					importLibrary: async () => ({
						PlaceAutocompleteElement,
					}),
				},
			};
			window.submittedAddress = null;
			document.getElementById('listing-form').addEventListener('submit', (event) => {
				event.preventDefault();
				window.submittedAddress = new FormData(event.target).get('address');
			});
		});
		await page.addScriptTag({ content: source });
		await page.evaluate(() => window.initAddListingMap());
		await page
			.locator('gmp-place-autocomplete')
			.waitFor({ state: 'attached', timeout: 3000 });

		const emptyResult = await page.evaluate(() => {
			document.getElementById('listing-form').requestSubmit();
			return {
				submittedAddress: window.submittedAddress,
				focusedTag: document.activeElement.tagName,
				invalid: document
					.querySelector('gmp-place-autocomplete')
					.getAttribute('aria-invalid'),
					message: document.getElementById(
						'directorist-address-validation-message',
					).textContent,
			};
		});
		assert.equal(emptyResult.submittedAddress, null);
		assert.equal(emptyResult.focusedTag, 'GMP-PLACE-AUTOCOMPLETE');
		assert.equal(emptyResult.invalid, 'true');
		assert.ok(emptyResult.message);

		const filledResult = await page.evaluate(() => {
			const widget = document.querySelector('gmp-place-autocomplete');
			widget.value = 'New York, NY, USA';
			widget.dispatchEvent(new Event('input'));
			document.getElementById('listing-form').requestSubmit();
			return {
				submittedAddress: window.submittedAddress,
				invalid: widget.getAttribute('aria-invalid'),
				message: document.getElementById(
						'directorist-address-validation-message',
				).textContent,
			};
		});
		assert.equal(filledResult.submittedAddress, 'New York, NY, USA');
		assert.equal(filledResult.invalid, null);
		assert.equal(filledResult.message, '');
	} finally {
		await browser.close();
	}
});
