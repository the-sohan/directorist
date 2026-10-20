/**
 * Regression test for taxonomy child toggles after AJAX content replacement.
 *
 * Run with: node tests/js/category-location-pagination.js
 */

const path = require('path');
const { chromium } = require('@playwright/test');

const toggleMarkup = `
	<div class="directorist-taxonomy-list-one">
		<a class="directorist-taxonomy-list__toggle" href="/location/parent/">
			<span class="directorist-taxonomy-list__name">Parent location</span>
			<span class="directorist-taxonomy-list__toggler">Toggle</span>
		</a>
	</div>
`;

(async () => {
	const browser = await chromium.launch({
		channel: 'chrome',
		headless: true,
	});
	const page = await browser.newPage();

	try {
		await page.setContent(
			`<div id="taxonomy-wrapper">${toggleMarkup}</div>`
		);
		await page.addScriptTag({
			content: `
				window.jQuery = function () {
					return {
						on: function (eventName, selector, handler) {
							document.addEventListener(eventName, function (event) {
								var matchedElement = event.target.closest(selector);
								if (matchedElement) {
									handler.call(matchedElement, event);
								}
							});
						}
					};
				};
			`,
		});
		await page.addScriptTag({
			path: path.resolve(
				__dirname,
				'../../assets/build/js/public/all-location-category.js'
			),
		});
		await page.evaluate(() => window.dispatchEvent(new Event('load')));

		await page.click('.directorist-taxonomy-list__toggler');
		if (
			!(await page
				.locator('.directorist-taxonomy-list__toggle')
				.evaluate((element) =>
					element.classList.contains(
						'directorist-taxonomy-list__toggle--open'
					)
				))
		) {
			throw new Error('The initial child-location toggle did not open.');
		}

		await page.locator('#taxonomy-wrapper').evaluate((element, markup) => {
			element.innerHTML = markup;
		}, toggleMarkup);
		await page.click('.directorist-taxonomy-list__toggler');

		if (
			!(await page
				.locator('.directorist-taxonomy-list__toggle')
				.evaluate((element) =>
					element.classList.contains(
						'directorist-taxonomy-list__toggle--open'
					)
				))
		) {
			throw new Error(
				'The child-location toggle did not open after content replacement.'
			);
		}

		console.log(
			'PASS: taxonomy child toggle works after AJAX-style content replacement.'
		);
	} finally {
		await browser.close();
	}
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
