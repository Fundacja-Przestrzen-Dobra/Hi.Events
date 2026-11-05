import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const GET_ORDER_PAYMENT_STATUS_PUBLIC_QUERY_KEY = 'getOrderPaymentStatusPublic';

/**
 * Polls / asks the provider about an order's payment.
 *
 * With `verify` the backend also queries the payment provider for the current status
 * and settles the order when the provider confirms the payment. That is the fallback
 * for a webhook that never arrived; the request is only made when the checkout has
 * already waited for the webhook.
 */
export const useGetOrderPaymentStatusPublic = (eventId: IdParam, orderShortId: IdParam, verify: boolean = false) => {
    return useQuery({
        queryKey: [GET_ORDER_PAYMENT_STATUS_PUBLIC_QUERY_KEY, eventId, orderShortId, verify],

        queryFn: async () => {
            const {data} = await orderClientPublic.findProviderPaymentStatus(
                Number(eventId),
                String(orderShortId),
                verify,
            );
            return data;
        },

        retry: false,
    });
}
