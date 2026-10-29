import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register, select } from '@wordpress/data';
import { addQueryArgs } from '@wordpress/url';
import { getCrudConfig } from '@shamim-ahmed/data';

export const orderStoreName = 'directorist/user-orders';
export const orderStorePath = '/directorist/v2/orders';

export function normalizeUserOrders(body, headers) {
	const payload = body?.data;
	const items = Array.isArray(payload)
		? payload
		: Array.isArray(body?.items)
			? body.items
			: Array.isArray(payload?.items)
				? payload.items
				: [];
	const rawTotal = headers.get('X-WP-Total') ?? body?.total ?? payload?.total;
	const parsedTotal = Number(rawTotal);
	const total =
		rawTotal !== null &&
		rawTotal !== undefined &&
		rawTotal !== '' &&
		Number.isFinite(parsedTotal) &&
		parsedTotal >= 0
			? parsedTotal
			: items.length;

	return { ...body, items, total };
}

export async function loadUserOrders(queryParams) {
	const response = await apiFetch({
		path: addQueryArgs(orderStorePath, queryParams),
		parse: false,
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
