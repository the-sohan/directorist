/** Commit a Places selection before notifying native/third-party search listeners. */
export function setGoogleSearchLocation(input, place, latitudeId, longitudeId) {
	const location = place?.geometry?.location;
	const field = input.closest('.directorist-search-field');
	const latitude = field?.querySelector(`#${latitudeId}`);
	const longitude = field?.querySelector(`#${longitudeId}`);
	if (
		!latitude ||
		!longitude ||
		typeof location?.lat !== 'function' ||
		typeof location?.lng !== 'function'
	) {
		return false;
	}

	latitude.value = location.lat();
	longitude.value = location.lng();
	input.dispatchEvent(new Event('change', { bubbles: true }));
	return true;
}
