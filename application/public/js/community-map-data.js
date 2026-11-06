export function publishedMapFetch(input, options = {}) {
    const url = new URL(input, location.href);
    if (url.origin !== location.origin) return fetch(input, options);
    const file = url.pathname.replace(/^\/maps\//, '');
    const supported = /^(?:india-overview(?:-2001)?-metrics\.json|constituency-map-metrics\.json|census-2001-facility-distributions\.json|census-(?:2001|2011)-fields\/(?:field-[a-f0-9]+|[A-Za-z0-9_]+)\.json|constituency-fields-2001\/[A-Za-z0-9_]+\.json)$/.test(file);
    return fetch(supported ? '/api/community/map-data?file=' + encodeURIComponent(file) : input, {...options, cache: 'no-store'});
}
