@if(auth()->check() && in_array('auth', request()->route()?->gatherMiddleware() ?? [], true))
<script>
(() => {
    const conceal = () => {
        document.documentElement.style.setProperty('visibility', 'hidden', 'important');
        document.documentElement.inert = true;
    };

    // Conceal before history freezing, so a restored snapshot starts hidden.
    window.addEventListener('pagehide', (event) => {
        if (event.persisted) {
            conceal();
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            conceal();
            // A fresh document must pass the server's current session and role checks.
            window.location.reload();
        }
    });
})();
</script>
@endif
