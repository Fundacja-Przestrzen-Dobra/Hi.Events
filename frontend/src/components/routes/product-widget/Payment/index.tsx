import React, {useState} from "react";
import {useNavigate, useParams} from "react-router";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {CheckoutStepTitle} from "../../../layouts/Checkout/CheckoutStepTitle";
import {StripePaymentMethod} from "./PaymentMethods/Stripe";
import {OfflinePaymentMethod} from "./PaymentMethods/Offline";
import {Button, Group, Text} from "@mantine/core";
import {IconBuildingBank, IconCreditCard, IconLock, IconWallet} from "@tabler/icons-react";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {t, Trans} from "@lingui/macro";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {
    useTransitionOrderToOfflinePaymentPublic
} from "../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts";
import {Card} from "../../../common/Card";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {showError} from "../../../../utilites/notifications.tsx";
import {orderClientPublic} from "../../../../api/order.client.ts";
import {getConfig} from "../../../../utilites/config.ts";
import classes from "./Payment.module.scss";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";
import {eventCheckoutPath} from "../../../../utilites/urlHelper.ts";
import {
    PaymentLinkActiveNotice,
    PaymentLinkEntryPoint,
    PaymentLinkModal,
    PaymentRequestNotice,
    usePaymentLinkModal,
} from "./PaymentLink";

const Payment = () => {
    const navigate = useNavigate();
    const {eventId, orderShortId} = useParams();
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const isLoading = !isOrderFetched;
    const checkoutEvent = order?.event;
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);
    const [activePaymentMethod, setActivePaymentMethod] = useState<'STRIPE' | 'PAYU' | 'OFFLINE' | null>(null);
    const [submitHandler, setSubmitHandler] = useState<(() => Promise<void>) | null>(null);
    const transitionOrderToOfflinePaymentMutation = useTransitionOrderToOfflinePaymentPublic();
    const paymentLinkModal = usePaymentLinkModal();
    const isPaymentLinkPayer = !!order?.is_payment_link_payer;
    const hasPaymentLink = !!order?.payment_link_url;
    // Upstream wants Stripe here; this deployment pays online with PayU (CHANGES.md v12).
    const hasOnlineProvider = (checkoutEvent?.settings?.payment_providers ?? []).some(
        (provider) => provider === 'STRIPE' || provider === 'PAYU',
    );
    const canCreatePaymentLink = !hasPaymentLink
        && !isPaymentLinkPayer
        && !!order?.is_payment_required
        && !!checkoutEvent?.settings?.payment_links_enabled
        && !!checkoutEvent?.settings?.payment_providers?.includes('OFFLINE')
        && hasOnlineProvider;

    // This deployment offers PayU (online) and direct bank transfer only. Stripe is
    // not offered in the UI; its code path stays in place but unreachable.
    const isStripeEnabled = false;
    const isPayuEnabled = checkoutEvent?.settings?.payment_providers?.includes('PAYU');
    const isOfflineEnabled = checkoutEvent?.settings?.payment_providers?.includes('OFFLINE');
    const isPayingLater = activePaymentMethod === 'OFFLINE';
    const enabledPaymentMethodCount = [isStripeEnabled, isPayuEnabled, isOfflineEnabled].filter(Boolean).length;

    React.useEffect(() => {
        if (isStripeEnabled) {
            setActivePaymentMethod('STRIPE');
        } else if (isPayuEnabled) {
            setActivePaymentMethod('PAYU');
        } else if (isOfflineEnabled) {
            setActivePaymentMethod('OFFLINE');
        } else {
            setActivePaymentMethod(null);
        }
    }, [isStripeEnabled, isPayuEnabled, isOfflineEnabled]);

    React.useEffect(() => {
        window?.scrollTo(0, 0);
    }, []);

    React.useEffect(() => {
        if (order && (order.status === 'COMPLETED' || order.status === 'AWAITING_OFFLINE_PAYMENT')) {
            navigate(eventCheckoutPath(eventId, orderShortId, 'summary'), {replace: true});
        }
    }, [order?.status]);

    const handleParentSubmit = () => {
        if (submitHandler) {
            setIsPaymentLoading(true);
            submitHandler().finally(() => setIsPaymentLoading(false));
        }
    };

    const handleSubmit = async () => {
        if (activePaymentMethod === 'STRIPE') {
            handleParentSubmit();
        } else if (activePaymentMethod === 'PAYU') {
            setIsPaymentLoading(true);

            try {
                const response = await orderClientPublic.createProviderPaymentIntent(eventId, orderShortId, 'PAYU');
                const redirectUrl = response.data?.redirect_url;

                if (!redirectUrl) {
                    throw new Error('Missing redirect URL');
                }

                window.location.assign(redirectUrl);
            } catch (error: any) {
                setIsPaymentLoading(false);
                showError(error?.response?.data?.message || t`Could not start the payment. Please try again or contact the event organizer.`);
            }
        } else if (activePaymentMethod === 'OFFLINE') {
            setIsPaymentLoading(true);

            await transitionOrderToOfflinePaymentMutation.mutateAsync({
                eventId,
                orderShortId
            }, {
                onSuccess: () => {
                    const totalCents = Math.round((order?.total_gross || 0) * 100);
                    trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_OFFLINE, { value: totalCents });
                    navigate(`/checkout/${eventId}/${orderShortId}/summary`);
                },
                onError: (error: any) => {
                    setIsPaymentLoading(false);
                    showError(error.response?.data?.message || t`Offline payment failed. Please try again or contact the event organizer.`);
                }
            });
        }
    };

    if (!isStripeEnabled && !isPayuEnabled && !isOfflineEnabled && isOrderFetched) {
        return (
            <CheckoutContent>
                <Card>
                    {t`No payment methods are currently available. Please contact the event organizer for assistance.`}
                </Card>
            </CheckoutContent>
        );
    }

    return (
        <>
            <CheckoutContent>
                {isPaymentLinkPayer
                    ? <CheckoutStepTitle title={t`Pay for this order`} subtitle={t`Review the order, then choose how to pay`}/>
                    : <CheckoutStepTitle title={t`Payment`} subtitle={isPayingLater ? t`Next: how to pay` : t`Next: review your order`}/>}

                {(checkoutEvent && order && isPaymentLinkPayer) && (
                    <PaymentRequestNotice order={order} event={checkoutEvent}/>
                )}

                {(checkoutEvent && order && hasPaymentLink && !isPaymentLinkPayer) && (
                    <PaymentLinkActiveNotice order={order} event={checkoutEvent} onShareClick={paymentLinkModal.open}/>
                )}

                {(checkoutEvent && order) && (
                    <InlineOrderSummary event={checkoutEvent} order={order} defaultExpanded={isPaymentLinkPayer}/>
                )}
                {isPayuEnabled && (
                    <div style={{display: activePaymentMethod === 'PAYU' ? 'block' : 'none'}}>
                        <Card>
                            <Text size="sm" c="dimmed">
                                {t`Zapłacisz na stronie PayU — kartą, BLIK-iem albo przelewem online.`}
                            </Text>
                        </Card>
                    </div>
                )}

                {enabledPaymentMethodCount > 1 && (
                    <div className={classes.paymentMethodSelector}>
                        <Text size="sm" c="dimmed" className={classes.paymentMethodLabel}>
                            {t`Payment method`}
                        </Text>
                        <div className={classes.paymentMethodTabs}>
                            {isStripeEnabled && (
                                <button
                                    type="button"
                                    className={`${classes.paymentMethodTab} ${activePaymentMethod === 'STRIPE' ? classes.active : ''}`}
                                    onClick={() => setActivePaymentMethod('STRIPE')}
                                >
                                    <IconWallet size={18}/>
                                    <span>{t`Online`}</span>
                                </button>
                            )}
                            <button
                                type="button"
                                className={`${classes.paymentMethodTab} ${activePaymentMethod === 'PAYU' ? classes.active : ''}`}
                                onClick={() => setActivePaymentMethod('PAYU')}
                            >
                                <IconCreditCard size={18}/>
                                <span>{t`PayU`}</span>
                            </button>
                            <button
                                type="button"
                                className={`${classes.paymentMethodTab} ${activePaymentMethod === 'OFFLINE' ? classes.active : ''}`}
                                onClick={() => setActivePaymentMethod('OFFLINE')}
                            >
                                <IconBuildingBank size={18}/>
                                <span>{t`Przelew`}</span>
                            </button>
                        </div>
                    </div>
                )}

                {isStripeEnabled && (
                    <div style={{display: activePaymentMethod === 'STRIPE' ? 'block' : 'none'}}>
                        <StripePaymentMethod enabled={true} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isOfflineEnabled && (
                    <div style={{display: activePaymentMethod === 'OFFLINE' ? 'block' : 'none'}}>
                        <OfflinePaymentMethod isPayer={isPaymentLinkPayer}/>
                    </div>
                )}

                <div className={classes.checkoutActions}>
                    <Button
                        className={classes.continueButton}
                        loading={isLoading || isPaymentLoading}
                        onClick={handleSubmit}
                        data-testid={activePaymentMethod === 'OFFLINE' ? 'offline-payment-button' : undefined}
                    >
                        {isPayingLater ? t`Place order` : order?.is_payment_required ? (
                            <Group gap={8} wrap="nowrap">
                                <IconLock size={16}/>
                                <Text fw={600}>{t`Pay`} {formatCurrency(order.total_gross, order.currency)}</Text>
                            </Group>
                        ) : t`Complete Payment`}
                    </Button>
                    {(isPayingLater && order?.is_payment_required) && (
                        <Text className={classes.dueLater} data-testid="pay-later-amount">
                            {t`${formatCurrency(order.total_gross, order.currency)} due after you place your order`}
                        </Text>
                    )}
                    {canCreatePaymentLink && <PaymentLinkEntryPoint onClick={paymentLinkModal.open}/>}
                    {getConfig('VITE_TOS_URL') && (
                        <p className={classes.tosNotice}>
                            <Trans>
                                By continuing, you agree to the{' '}
                                <a
                                    href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service') as string}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {getConfig('VITE_APP_NAME', 'Hi.Events')} Terms of Service
                                </a>
                            </Trans>
                        </p>
                    )}
                </div>
            </CheckoutContent>
            {(checkoutEvent && order) && (
                <PaymentLinkModal
                    opened={paymentLinkModal.opened}
                    onClose={paymentLinkModal.close}
                    order={order}
                    event={checkoutEvent}
                />
            )}
        </>
    );
}

export default Payment;
