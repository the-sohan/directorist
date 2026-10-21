import { setGoogleSearchLocation } from '../assets/src/js/public/components/google-search-location';

const place = (lat, lng) => ({
	geometry: { location: { lat: () => lat, lng: () => lng } },
});
beforeEach(() => {
	document.body.innerHTML =
		'<div class="directorist-search-field"><input name="address"><input id="cityLat" value="41"><input id="cityLng" value="12"></div><div class="directorist-search-field"><input id="cityLat" value="99"><input id="cityLng" value="88"></div>';
});

test('publishes one bubbling change only after both selected coordinates are committed', () => {
	const input = document.querySelector('[name=address]');
	const values = [];
	const change = () =>
		values.push(
			Array.from(document.querySelectorAll('#cityLat,#cityLng')).map(
				(el) => el.value,
			),
		);
	document.body.addEventListener('change', change);
	expect(
		setGoogleSearchLocation(input, place(45.4642, 9.19), 'cityLat', 'cityLng'),
	).toBe(true);
	expect(values).toEqual([['45.4642', '9.19', '99', '88']]);
	document.body.removeEventListener('change', change);
});

test('accepts valid zero coordinates', () => {
	setGoogleSearchLocation(
		document.querySelector('[name=address]'),
		place(0, 0),
		'cityLat',
		'cityLng',
	);
	expect(document.querySelector('#cityLat').value).toBe('0');
	expect(document.querySelector('#cityLng').value).toBe('0');
});

test.each([null, {}, { geometry: {} }, { geometry: { location: {} } }])(
	'ignores incomplete Places results without firing a false search',
	(value) => {
		const input = document.querySelector('[name=address]');
		const change = jest.fn();
		input.addEventListener('change', change);
		expect(setGoogleSearchLocation(input, value, 'cityLat', 'cityLng')).toBe(
			false,
		);
		expect(document.querySelector('#cityLat').value).toBe('41');
		expect(change).not.toHaveBeenCalled();
	},
);

test('does not partially update a field missing a coordinate input', () => {
	document.querySelector('#cityLng').remove();
	expect(
		setGoogleSearchLocation(
			document.querySelector('[name=address]'),
			place(45, 9),
			'cityLat',
			'cityLng',
		),
	).toBe(false);
	expect(document.querySelector('#cityLat').value).toBe('41');
});
