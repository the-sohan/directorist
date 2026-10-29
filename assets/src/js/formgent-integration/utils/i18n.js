import { getLocaleData, setLocaleData } from '@wordpress/i18n';

/**
 * Share WordPress JSON translations with this legacy bundle and supplement them
 * with the frontend MO catalog. REST requests can use the user's profile locale.
 */
export const initializeEnquiryTranslations = () => {
	const localeData = {
		...getLocaleData('directorist'),
		...window.wp?.i18n?.getLocaleData('directorist'),
	};
	const translations = window.directoristFormgentData?.translations || {};
	Object.entries(translations).forEach(([source, translation]) => {
		if (translation && translation !== source) {
			localeData[source] = [translation];
		}
	});
	setLocaleData(localeData, 'directorist');
};
