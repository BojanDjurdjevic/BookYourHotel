<script>
    (() => {
        // Livewire retains head scripts but replaces <html> attributes on navigation.
        if (window.applyBookYourHotelTheme) {
            window.applyBookYourHotelTheme();
            return;
        }

        const readTheme = () => {
            try {
                const saved = window.localStorage.getItem('bookyourhotel-theme');
                if (saved === 'light' || saved === 'dark') return saved;
            } catch (_) {
                // Keep the in-memory preference when browser storage is unavailable.
            }
            return window.__bookYourHotelTheme || 'dark';
        };

        window.applyBookYourHotelTheme = () => {
            const theme = readTheme();
            document.documentElement.classList.toggle('dark', theme === 'dark');
            window.__bookYourHotelTheme = theme;
            window.dispatchEvent(new CustomEvent('bookyourhotel-theme-changed', { detail: { theme } }));
        };

        window.toggleBookYourHotelTheme = () => {
            const theme = window.__bookYourHotelTheme === 'dark' ? 'light' : 'dark';
            window.__bookYourHotelTheme = theme;
            try {
                window.localStorage.setItem('bookyourhotel-theme', theme);
            } catch (_) {}
            window.applyBookYourHotelTheme();
        };

        document.addEventListener('livewire:navigating', event => {
            // Livewire 4 calls onSwap synchronously after replacing the document,
            // before initializing Alpine or waiting for newly loaded scripts.
            event.detail?.onSwap?.(window.applyBookYourHotelTheme);
        });
        document.addEventListener('livewire:navigated', window.applyBookYourHotelTheme);
        window.addEventListener('storage', event => {
            if (event.key === 'bookyourhotel-theme') window.applyBookYourHotelTheme();
        });
        window.applyBookYourHotelTheme();
    })();
</script>
