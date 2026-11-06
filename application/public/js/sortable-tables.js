(() => {
    if (window.pollmediaSortTables) return;
    const tables = import('./my-place-tables.js?v=811380bd68ae');
    const mounted = new WeakSet();
    window.pollmediaSortTables = (root = document.body) => {
        if (root === document) root = document.body;
        if (!root || mounted.has(root)) return;
        mounted.add(root);
        tables.then(({mountTables}) => mountTables(root));
    };
    document.body.dataset.language = document.documentElement.lang;
    window.pollmediaSortTables();
})();
