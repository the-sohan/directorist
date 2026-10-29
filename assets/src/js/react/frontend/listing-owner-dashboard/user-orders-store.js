import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register, select } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';
import { getCrudConfig } from '@shamim-ahmed/data';

export const orderStoreName = 'directorist/user-orders';
export const orderStorePath = '/directorist/v2/orders';

function normalizeUserOrders(body, headers) {
	const data = body?.data ?? body;
	const items = Array.isArray(data)
		? data
		: Array.isArray(data?.items) ? data.items : [];
	const rawTotal = headers.get('X-WP-Total') ?? data?.total ?? body?.total;
	const total = Number(rawTotal ?? NaN);

	return {
		items,
		total: rawTotal !== '' && Number.isFinite(total) && total >= 0
			? total : items.length,
	};
}

async function loadUserOrders(queryParams) {
	const response = await apiFetch({
		path: addQueryArgs(orderStorePath, queryParams),
		parse: false,
	}).catch(async (error) => {
		// apiFetch returns a raw failed Response when parse is disabled.
		throw typeof error?.json === 'function' ? await error.json() : error;
	});

	return normalizeUserOrders(await response.json(), response.headers);
}

export function registerUserOrdersStore() {
	if (select(orderStoreName)) {
		return;
	}

	const config = getCrudConfig({ path: orderStorePath });
	config.resolvers.get = () => async ({ dispatch, select: selectStore }) => {
		const orders = await loadUserOrders(selectStore.getQueryParams());
		dispatch.setItems(orders);
	};
	register(createReduxStore(orderStoreName, config));
}
