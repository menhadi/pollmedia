<script async src="https://www.googletagmanager.com/gtag/js?id={{ $measurementId }}"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', @json($measurementId), {
    page_location: @json($pageLocation),
    page_referrer: document.referrer ? document.referrer.split('?')[0].split('#')[0] : '',
    allow_google_signals: false,
    allow_ad_personalization_signals: false
});
</script>
