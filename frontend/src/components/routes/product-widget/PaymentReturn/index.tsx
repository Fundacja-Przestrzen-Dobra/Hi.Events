import {usePollGetOrderPublic} from "../../../../queries/usePollGetOrderPublic.ts";
import {useNavigate, useParams} from "react-router";
import {useEffect, useRef, useState} from "react";
import classes from './PaymentReturn.module.scss';
import {t} from "@lingui/macro";
import {useGetOrderPaymentStatusPublic} from "../../../../queries/useGetOrderPaymentStatusPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {isSsr} from "../../../../utilites/helpers.ts";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";

/**
 * This component is responsible for handling the return from the payment provider.
 * The provider sends a webhook to the backend, which moves the order to 'COMPLETED'.
 * If the webhook is delayed or never arrives, we poll the order status and, once the
 * short wait is over, ask the backend to verify the payment with the provider
 * (`verify=1`). The backend settles the order through the same transition the webhook
 * uses, so a payment is never applied twice.
 **/
export const PaymentReturn = () => {
    const [shouldPoll, setShouldPoll] = useState(true);
    const {eventId, orderShortId} = useParams();
    const {data: order} = usePollGetOrderPublic(eventId, orderShortId, shouldPoll, ['event']);
    const navigate = useNavigate();
    const [attemptManualConfirmation, setAttemptManualConfirmation] = useState(false);
    const paymentStatusQuery = useGetOrderPaymentStatusPublic(eventId, orderShortId, attemptManualConfirmation);
    const [cannotConfirmPayment, setCannotConfirmPayment] = useState(false);
    const hasTrackedPurchase = useRef(false);

    useEffect(
        () => {
            const timeout = setTimeout(() => {
                setShouldPoll(false);
                setAttemptManualConfirmation(true);
            }, 10000); //todo - this should be a env variable

            return () => {
                clearTimeout(timeout);
            };
        },
        []
    );

    useEffect(() => {
        if (!paymentStatusQuery.isFetched) {
            return;
        }
        if (paymentStatusQuery.data?.order_status === 'COMPLETED') {
            if (!hasTrackedPurchase.current && order) {
                hasTrackedPurchase.current = true;
                const totalCents = Math.round((order.total_gross || 0) * 100);
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_PAID, { value: totalCents });
            }
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
            return;
        }
        if (attemptManualConfirmation) {
            // The provider did not confirm the payment either — show the failure state
            // instead of waiting forever. A later webhook can still complete the order.
            setCannotConfirmPayment(true);
        }
    }, [paymentStatusQuery.isFetched, paymentStatusQuery.data, attemptManualConfirmation]);

    useEffect(() => {
        if (isSsr() || !order) {
            return;
        }

        if (order?.status === 'COMPLETED') {
            if (!hasTrackedPurchase.current) {
                hasTrackedPurchase.current = true;
                const totalCents = Math.round((order.total_gross || 0) * 100);
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_PAID, { value: totalCents });
            }
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
        }
        if (order?.payment_status === 'PAYMENT_FAILED' || (typeof window !== 'undefined' && window?.location.search.includes('failed'))) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + '?payment_failed=true');
        }
    }, [order]);

    return (
        <CheckoutContent>
            <div className={classes.container}>
                {!cannotConfirmPayment && (
                    <HomepageInfoMessage
                        status="processing"
                        message={(
                            <>
                                {(!shouldPoll && paymentStatusQuery.isFetched) && t`We could not process your payment. Please try again or contact support.`}
                                {(!shouldPoll && !paymentStatusQuery.isFetched) && t`Almost there! We're just waiting for your payment to be processed. This should only take a few seconds.`}
                                {shouldPoll && t`We're processing your order. Please wait...`}
                            </>
                        )}
                    />
                )}

                {cannotConfirmPayment && (
                    <HomepageInfoMessage
                        status="error"
                        message={t`We were unable to confirm your payment. Please try again or contact support.`}
                    />
                )}
            </div>
        </CheckoutContent>
    );
}

export default PaymentReturn;
