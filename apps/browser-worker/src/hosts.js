const TRACKERS = {
  analytics: [
    'google-analytics.com', 'analytics.google.com', 'region1.google-analytics.com', 'ssl.google-analytics.com',
    'googletagmanager.com', 'googletagservices.com', 'tagmanager.google.com',
    'matomo.cloud', 'innocraft.cloud', 'piwik.pro', 'piwikpro.com', 'containers.piwik.pro',
    'etracker.com', 'etracker.de', 'econda-monitor.de', 'econda.de', 'webtrekk.net', 'wt-safetag.com', 'wt-eu02.net', 'mapp.com', 'mappintelligence.com',
    'plausible.io', 'simpleanalytics.com', 'simpleanalyticscdn.com', 'usefathom.com', 'fathom.io', 'pirsch.io', 'umami.is', 'cloud.umami.is', 'counter.dev', 'goatcounter.com', 'statcounter.com',
    'cloudflareinsights.com', 'static.cloudflareinsights.com',
    'segment.com', 'segment.io', 'cdn.segment.com', 'api.segment.io', 'rudderstack.com', 'rudderlabs.com', 'mparticle.com', 'tealium.com', 'tealiumiq.com', 'tiqcdn.com', 'ensighten.com', 'commandersact.com', 'tagcommander.com',
    'mixpanel.com', 'mxpnl.com', 'amplitude.com', 'heap.io', 'heapanalytics.com', 'kissmetrics.io', 'kissmetrics.com', 'woopra.com', 'chartbeat.com', 'chartbeat.net', 'parsely.com', 'parse.ly', 'quantserve.com', 'quantcount.com', 'scorecardresearch.com', 'comscore.com', 'nielsen.com', 'imrworldwide.com',
    'adobedtm.com', 'omtrdc.net', 'demdex.net', '2o7.net', 'everesttech.net', 'assets.adobedtm.com',
    'yandex.ru/metrika', 'mc.yandex.ru', 'mc.yandex.com', 'metrika.yandex.ru',
    'clicky.com', 'getclicky.com', 'static.getclicky.com', 'gosquared.com', 'kameleoon.com', 'kameleoon.eu', 'snowplowanalytics.com', 'posthog.com', 'i.posthog.com', 'eu.posthog.com', 'pendo.io', 'contentsquare.com', 'piano.io', 'tinypass.com', 'atinternet.com', 'xiti.com', 'ati-host.net',
    'newrelic.com', 'nr-data.net', 'bam.nr-data.net', 'js-agent.newrelic.com', 'datadoghq-browser-agent.com', 'browser-intake-datadoghq.com', 'browser-intake-datadoghq.eu', 'sentry-cdn.com', 'ingest.sentry.io', 'bugsnag.com', 'd2wy8f7a9ursnm.cloudfront.net', 'trackjs.com', 'raygun.io', 'speedcurve.com', 'lr-ingest.io', 'akstat.io', 'go-mpulse.net', 'mpstat.us',
  ],
  session_replay: [
    'hotjar.com', 'hotjar.io', 'static.hotjar.com', 'script.hotjar.com', 'contentsquare.net', 'clarity.ms', 'c.clarity.ms', 'mouseflow.com', 'smartlook.com', 'smartlook.cloud', 'fullstory.com', 'logrocket.com', 'logrocket.io', 'lr-in.com', 'luckyorange.com', 'luckyorange.net', 'inspectlet.com', 'crazyegg.com', 'quantummetric.com', 'decibelinsight.net', 'glassboxdigital.io', 'sessioncam.com', 'clicktale.net', 'userzoom.com', 'uxcam.com', 'heatmap.it', 'plerdy.com', 'zoho.com/pagesense', 'pagesense.io', 'microsoft.com/clarity',
  ],
  advertising: [
    'doubleclick.net', 'googleadservices.com', 'googlesyndication.com', 'pagead2.googlesyndication.com', 'adservice.google.com', 'adservice.google.de', 'google.com/pagead', 'google.de/pagead', 'g.doubleclick.net', 'stats.g.doubleclick.net', 'ad.doubleclick.net', 'adnxs.com', 'adsrvr.org', 'criteo.com', 'criteo.net', 'taboola.com', 'outbrain.com', 'outbrainimg.com', 'amazon-adsystem.com', 'media.net', 'pubmatic.com', 'rubiconproject.com', 'openx.net', 'casalemedia.com', 'indexww.com', 'smartadserver.com', 'teads.tv', 'yieldlab.net', 'adform.net', 'adformnet.com', 'adition.com', 'theadex.com', 'semasio.net', 'mediamath.com', 'mathtag.com', 'bidswitch.net', 'sharethrough.com', 'triplelift.com', '3lift.com', 'contextweb.com', 'sovrn.com', 'lijit.com', 'gumgum.com', 'zemanta.com', 'yieldmo.com', 'advertising.com', 'adtech.de', 'adsafeprotected.com', 'moatads.com', 'doubleverify.com', 'serving-sys.com', 'flashtalking.com', 'innovid.com', 'adroll.com', 'perfectaudience.com', 'quantcast.com', 'rlcdn.com', 'liveramp.com', 'bluekai.com', 'krxd.net', 'exelator.com', 'eyeota.net', 'tapad.com', 'agkn.com', 'crwdcntrl.net', 'lotame.com', 'id5-sync.com', 'uidapi.com', 'zeotap.com', 'emetriq.de', 'emxdgt.com', 'nativo.com', 'plista.com', 'ligatus.com', 'stroeer.de', 'stroeerdigitalgroup.de', 'yieldscale.com', 'adup-tech.com', 'retargetly.com', 'adspirit.de', 'adtelligence.de', 'sitescout.com', 'simpli.fi', 'steelhousemedia.com', 'mntn.com', 'ad.360yield.com', 'improvedigital.com', 'smaato.net', 'inmobi.com', 'mgid.com', 'revcontent.com', 'propellerads.com', 'popads.net', 'adsterra.com', 'exoclick.com', 'trafficjunky.net',
    'ads-twitter.com', 'analytics.twitter.com', 'static.ads-twitter.com', 't.co/i/adsct', 'ads.linkedin.com', 'px.ads.linkedin.com', 'snap.licdn.com', 'linkedin.com/px', 'bat.bing.com', 'bing.com/bat', 'clarity.microsoft.com', 'ads.microsoft.com', 'ct.pinterest.com', 'pinimg.com/ct', 's.pinimg.com/ct', 'ads.pinterest.com', 'analytics.tiktok.com', 'ads.tiktok.com', 'business-api.tiktok.com', 'tr.snapchat.com', 'sc-static.net', 'ads.reddit.com', 'redditstatic.com/ads', 'alb.reddit.com', 'quora.com/_/ad', 'q.quora.com', 'ads.yahoo.com', 'analytics.yahoo.com', 'sp.analytics.yahoo.com', 'yimg.com/wi/ytc', 'ads.vk.com', 'top-fwz1.mail.ru', 'ad.mail.ru',
  ],
  social_pixels: [
    'connect.facebook.net', 'facebook.com/tr', 'www.facebook.com/tr', 'graph.facebook.com/tr', 'pixel.facebook.com', 'facebook.net', 'fbcdn.net/signals', 'instagram.com/logging', 'tiktok.com/i18n/pixel', 'pinterest.com/v3', 'snapchat.com/p', 'twitter.com/i/adsct', 'platform.twitter.com/oct.js',
  ],
  affiliate: [
    'awin1.com', 'zenaps.com', 'dwin1.com', 'dwin2.com', 'awin.com', 'zanox.com', 'zanox-affiliate.de', 'belboon.com', 'belboon.de', 'tradedoubler.com', 'tradetracker.net', 'tradetracker.com', 'webgains.com', 'webgains.io', 'financeads.net', 'financeads.de', 'adcell.de', 'adcell.com', 'emjcd.com', 'dpbolvw.net', 'anrdoezrs.net', 'jdoqocy.com', 'kqzyfj.com', 'tkqlhce.com', 'qksrv.net', 'cj.com', 'linksynergy.com', 'rakuten.com/affiliate', 'prf.hn', 'partnerize.com', 'impact.com', 'impactradius.com', 'impactradius-event.com', 'sjv.io', 'pxf.io', 'ojrq.net', 'evyy.net', 'shareasale.com', 'shareasale-analytics.com', 'avantlink.com', 'pepperjamnetwork.com', 'pjtra.com', 'gopjn.com', 'flexoffers.com', 'admitad.com', 'ad.admitad.com', 'skimlinks.com', 'skimresources.com', 'viglink.com', 'sovrn.co', 'idealo.de/affiliate', 'billiger.de/tracking', 'geizhals.de/tracking', 'guenstiger.de/tracking', 'solute.de', 'refersion.com', 'everflow.io', 'eflow.team', 'tapfiliate.com', 'post-affiliate-pro.com', 'postaffiliatepro.com', 'affiliatly.com', 'leaddyno.com', 'partnerstack.com', 'getrewardful.com', 'rewardful.com',
  ],
  marketing_automation: [
    'klaviyo.com', 'static.klaviyo.com', 'a.klaviyo.com', 'fast.a.klaviyo.com', 'static-tracking.klaviyo.com', 'mailchimp.com', 'list-manage.com', 'chimpstatic.com', 'sendinblue.com', 'brevo.com', 'sibautomation.com', 'sib-forms.com', 'hubspot.com', 'hs-scripts.com', 'hs-analytics.net', 'hs-banner.com', 'hsforms.net', 'hsforms.com', 'hscollectedforms.net', 'hsadspixel.net', 'hubapi.com', 'usemessages.com', 'marketo.net', 'marketo.com', 'mktoresp.com', 'pardot.com', 'salesforce-sites.com/pardot', 'exacttarget.com', 'sfmc-content.com', 'igodigital.com', 'emarsys.net', 'emarsys.com', 'scarabresearch.com', 'cleverreach.com', 'cleverreach.de', 'rapidmail.de', 'newsletter2go.com', 'mailjet.com', 'getresponse.com', 'activecampaign.com', 'trackcmp.net', 'omnisend.com', 'omnisnippet1.com', 'drip.com', 'getdrip.com', 'convertkit.com', 'ck.page', 'privy.com', 'privymktg.com', 'justuno.com', 'optinmonster.com', 'opmnstr.com', 'sumo.com', 'sumome.com', 'wisepops.com', 'wisepops.net', 'poptin.com', 'sleeknote.com', 'getsitecontrol.com', 'hellobar.com', 'convertflow.com', 'convertflow.co', 'yieldify.com', 'bouncex.net', 'bounceexchange.com', 'wunderkind.co', 'attentivemobile.com', 'attn.tv', 'postscript.io', 'smsbump.com', 'yotpo.com/sms', 'retention.com', 'listrak.com', 'listrakbi.com', 'cordial.io', 'iterable.com', 'braze.com', 'appboy.com', 'braze-images.com', 'customer.io', 'customerioforms.com', 'intercomcdn.com/messenger-pixel', 'dotdigital.com', 'dmtrk.net', 'trackedlink.net', 'selligent.com', 'sendgrid.net/wf', 'inxmail.com', 'inxserver.com', 'evalanche.cloud', 'sc-eu.de', 'episerver.net', 'optimizely.com/episerver', 'mapp.cloud', 'xtremepush.com', 'insider.com', 'useinsider.com', 'api.useinsider.com', 'bloomreach.com', 'exponea.com', 'exponea.cloud',
  ],
  ab_testing_personalisation: [
    'optimizely.com', 'optimizelyapis.com', 'cdn.optimizely.com', 'logx.optimizely.com', 'visualwebsiteoptimizer.com', 'vwo.com', 'dev.visualwebsiteoptimizer.com', 'abtasty.com', 'try.abtasty.com', 'convertexperiments.com', 'convert.com', 'dynamicyield.com', 'dy-api.com', 'dy-api.eu', 'monetate.net', 'evergage.com', 'evgnet.com', 'qubit.com', 'qubitproducts.com', 'goqubit.com', 'nosto.com', 'nosto.net', 'richrelevance.com', 'certona.net', 'barilliance.net', 'trbo.com', 'trbo.io', 'epoq.de', 'prudsys.de', 'prudsys-rde.de', 'sitespect.com', 'unbounce.com', 'ubembed.com', 'instapage.com', 'launchdarkly.com', 'growthbook.io', 'statsig.com', 'split.io',
  ],
  push_and_consent_analytics: [
    'onesignal.com', 'cdn.onesignal.com', 'pushengage.com', 'pushcrew.com', 'subscribers.com', 'webpushr.com', 'pushowl.com', 'pushalert.co', 'truepush.com', 'izooto.com', 'cleverpush.com', 'aimtell.com', 'gravitec.net', 'sendpulse.com', 'pushnami.com',
  ],
  fingerprinting_and_attribution: [
    'fingerprintjs.com', 'fpjs.io', 'fpcdn.io', 'api.fpjs.io', 'branch.io', 'app.link', 'bnc.lt', 'appsflyer.com', 'onelink.me', 'adjust.com', 'adj.st', 'kochava.com', 'singular.net', 'tune.com', 'hasoffers.com', 'go2cloud.org', 'voluum.com', 'redtrack.io', 'clickcease.com', 'clickguard.com', 'ppcprotect.com', 'cheq.ai', 'cheqzone.com', 'hybrid.ai', 'trustpid.com', 'utiq.com', 'netid.de', 'adsquare.com', 'mookie1.com', 'mediaplex.com', 'tradedesk.com', 'thetradedesk.com', 'ipredictive.com', 'conversantmedia.com', 'dotomi.com', 'intentmedia.net', 'clarium.io', 'trustarc.com/analytics', 'leadfeeder.com', 'lfeeder.com', 'dealfront.com', 'echobot.de', 'albacross.com', 'clearbit.com', 'clearbitjs.com', 'zoominfo.com', 'ws.zoominfo.com', 'snitcher.com', 'leadinfo.net', 'leadinfo.com', 'visitor-analytics.io', 'wiredminds.de', 'wm.wiredminds.de', 'leadlab.click', 'pinpoll.com',
  ],
};

const PAYMENT_PROVIDERS = [
  'stripe.com', 'stripe.network', 'stripecdn.com', 'stripe.dev',
  'paypal.com', 'paypalobjects.com', 'paypal-activation.com', 'paypal.me', 'venmo.com', 'braintreegateway.com', 'braintree-api.com', 'braintreepayments.com', 'hyperwallet.com',
  'klarna.com', 'klarnacdn.net', 'klarnaservices.com', 'klarnaevt.com', 'sofort.com', 'sofort-ueberweisung.de', 'sofortueberweisung.de',
  'mollie.com', 'mollie.nl', 'adyen.com', 'adyenpayments.com', 'adyen.link',
  'pay.google.com', 'payments.google.com', 'google.com/pay', 'apple.com/apple-pay', 'apple-pay-gateway.apple.com', 'applepay.cdn-apple.com',
  'amazonpay.com', 'payments.amazon.com', 'payments.amazon.de', 'payments-eu.amazon.com', 'pay.amazon.com', 'pay.amazon.de', 'static-eu.payments-amazon.com', 'payments-amazon.com',
  'novalnet.de', 'novalnet.com', 'unzer.com', 'heidelpay.com', 'heidelpay.de', 'payone.com', 'pay1.de', 'bspayone.com', 'ratepay.com', 'computop.com', 'computop-paygate.com', 'concardis.com', 'nexi.de', 'nexigroup.com', 'nets.eu', 'dibspayment.eu', 'easy.nets.eu', 'checkout.dibspayment.eu',
  'paydirekt.de', 'giropay.de', 'giropay.com', 'paydirekt.com', 'girosolution.de', 'secupay.com', 'secupay-ag.de', 'wirecard.com', 'wirecard.de', 'checkout-portal.com', 'ingenico.com', 'ogone.com', 'worldline.com', 'worldline-solutions.com', 'direct.worldline-solutions.com', 'six-payment-services.com', 'saferpay.com', 'datatrans.ch', 'datatrans.com', 'twint.ch', 'postfinance.ch', 'postfinance.com', 'wallee.com', 'payrexx.com', 'mpay24.com', 'eps-ueberweisung.at', 'stuzza.at', 'paysafecard.com', 'paysafe.com', 'skrill.com', 'neteller.com', 'paysafecash.com',
  'billie.io', 'billpay.de', 'easycredit.de', 'easycredit-ratenkauf.de', 'teambank.de', 'payolution.com', 'afterpay.com', 'afterpay.de', 'riverty.com', 'arvato-payment.de', 'clearpay.co.uk', 'clearpay.com', 'scalapay.com', 'alma.eu', 'getalma.eu', 'zip.co', 'affirm.com', 'affirm.ca', 'sezzle.com', 'splitit.com', 'mondu.ai', 'paylater.de', 'santander.de/ratenkauf', 'consorsfinanz.de', 'hokodo.co', 'tabby.ai', 'tamara.co', 'humm.com', 'laybuy.com', 'openpay.com.au', 'atome.sg',
  'squareup.com', 'square.com', 'squarecdn.com', 'sqip.com', 'web.squarecdn.com', 'checkout.com', 'cko.com', 'checkoutshopper-live.adyen.com', 'authorize.net', 'authorizenet.com', 'cybersource.com', 'visa.com', 'visaonline.com', 'mastercard.com', 'masterpass.com', 'click2pay.com', 'src.mastercard.com', 'americanexpress.com', 'aexp-static.com', 'discover.com', 'jcb.co.jp', 'unionpayintl.com',
  'worldpay.com', 'worldpay.de', 'fiserv.com', 'firstdata.com', 'clover.com', 'globalpayments.com', 'globalpay.com', 'realexpayments.com', 'elavon.com', 'converge.com', 'opayo.co.uk', 'sagepay.com', 'paymentsense.com', 'gocardless.com', 'trustly.com', 'trustly.net', 'tink.com', 'plaid.com', 'yapily.com', 'truelayer.com', 'nordigen.com', 'finapi.io', 'klarna-paymentsapi.com',
  'shopify.com/payments', 'shoppay.com', 'shop.app', 'pay.shopify.com', 'bolt.com', 'fastlane.paypal.com', 'link.com', 'stripelink.com',
  'payu.com', 'payu.de', 'payu.in', 'przelewy24.pl', 'przelewy24.com', 'blik.com', 'tpay.com', 'dotpay.pl', 'bluemedia.pl', 'ideal.nl', 'currence.nl', 'bancontact.com', 'payconiq.com', 'multisafepay.com', 'buckaroo.nl', 'buckaroo.com', 'pay.nl', 'paynl.com', 'icepay.com', 'cm.com/payments', 'vivawallet.com', 'vivapayments.com', 'satispay.com', 'nexi.it', 'paypo.pl', 'swish.nu', 'vipps.no', 'mobilepay.dk', 'mobilepay.fi', 'bizum.es', 'redsys.es', 'sipay.es', 'multibanco.pt', 'mbway.pt', 'ifthenpay.com', 'easypay.pt', 'hipay.com', 'lemonway.com', 'mangopay.com', 'payplug.com', 'lyra.com', 'payzen.eu', 'systempay.fr', 'monetico-paiement.fr', 'paybox.com', 'verifone.com', 'verifone.cloud', '2checkout.com', '2co.com', 'paddle.com', 'paddle.net', 'fastspring.com', 'onfastspring.com', 'chargebee.com', 'recurly.com', 'recurly.io', 'bluesnap.com', 'nuvei.com', 'safecharge.com', 'payoneer.com', 'wise.com', 'revolut.com', 'revolut.me', 'n26.com', 'coinbase.com', 'commerce.coinbase.com', 'bitpay.com', 'btcpayserver.org', 'coingate.com', 'nowpayments.io', 'crypto.com/pay', 'binance.com/pay', 'alipay.com', 'alipayobjects.com', 'wechatpay.com', 'tenpay.com', 'razorpay.com', 'paytm.com', 'mercadopago.com', 'pagseguro.uol.com.br', 'ebanx.com', 'dlocal.com', 'payfort.com', 'paytabs.com', 'hyperpay.com', 'tap.company', 'paystack.com', 'flutterwave.com', 'payfast.co.za', 'yoco.com', 'iyzico.com', 'paytr.com',
  '3dsecure.io', 'acs.mastercard.com', 'securecode.com', 'verifiedbyvisa.com', 'arcot.com', 'cardinalcommerce.com', 'songbird.cardinalcommerce.com', 'centinelapi.cardinalcommerce.com', 'netcetera.com', 'gpayments.com', 'modirum.com', 'ravelin.com', 'riskified.com', 'signifyd.com', 'forter.com', 'sift.com', 'siftscience.com', 'kount.net', 'kount.com', 'seon.io', 'nethone.io', 'feedzai.com', 'threatmetrix.com', 'online-metrix.net', 'iovation.com', 'iesnare.com', 'accertify.com', 'nofraud.com', 'fraudlabspro.com', 'minfraud.com', 'maxmind.com', 'cdn.forter.com', 'fraud.net',
];

const PAYMENT_HOST_HINT = /(^|[.-])(pay|pays|payment|payments|paygate|paygw|checkout|wallet|gateway|acquiring|acquirer|psp|ipg|securepay|epay|e-pay|ewallet|3ds|threeds|acs)([.-]|\d|$)/i;

export const TRACKER_CATEGORIES = Object.keys(TRACKERS);

export const TRACKER_HOSTS = [...new Set(Object.values(TRACKERS).flat().filter((entry) => !entry.includes('/')))];

export const TRACKER_PATHS = [...new Set(Object.values(TRACKERS).flat().filter((entry) => entry.includes('/')))];

export const PAYMENT_PROVIDER_HOSTS = [...new Set(PAYMENT_PROVIDERS.filter((entry) => !entry.includes('/')))];

export const PAYMENT_PROVIDER_PATHS = [...new Set(PAYMENT_PROVIDERS.filter((entry) => entry.includes('/')))];

function hostMatches(host, list) {
  const name = host.toLowerCase();

  return list.some((suffix) => name === suffix || name.endsWith(`.${suffix}`));
}

function pathMatches(parsed, list) {
  const host = parsed.hostname.toLowerCase();
  const path = parsed.pathname.toLowerCase();

  return list.some((entry) => {
    const slash = entry.indexOf('/');
    const entryHost = entry.slice(0, slash);

    return (host === entryHost || host.endsWith(`.${entryHost}`)) && path.startsWith(entry.slice(slash));
  });
}

export function isTracker(parsed) {
  return hostMatches(parsed.hostname, TRACKER_HOSTS) || pathMatches(parsed, TRACKER_PATHS);
}

export function isPaymentProviderHost(host) {
  return hostMatches(host, PAYMENT_PROVIDER_HOSTS);
}

export function isPaymentProvider(parsed) {
  return isPaymentProviderHost(parsed.hostname) || pathMatches(parsed, PAYMENT_PROVIDER_PATHS);
}

export function looksLikePayment(parsed) {
  return isPaymentProvider(parsed) || PAYMENT_HOST_HINT.test(parsed.hostname);
}
