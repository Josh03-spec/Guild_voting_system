/**
 * Theme Manager for PollPoint
 * Handles light/dark theme switching with localStorage persistence
 */

(function() {
    const THEME_KEY = 'pollpoint-theme';
    const LIGHT = 'light';
    const DARK = 'dark';

    /**
     * Initialize theme on page load
     */
    function initTheme() {
        const savedTheme = localStorage.getItem(THEME_KEY);
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        // Determine which theme to apply
        let themeToApply = LIGHT; // default
        
        if (savedTheme) {
            // Use saved preference
            themeToApply = savedTheme;
        } else if (prefersDark) {
            // Use OS preference if no saved preference
            themeToApply = DARK;
        }
        
        applyTheme(themeToApply);
        updateToggleButton(themeToApply);
    }

    /**
     * Apply theme by setting data-theme attribute
     */
    function applyTheme(theme) {
        const html = document.documentElement;
        
        if (theme === DARK) {
            html.setAttribute('data-theme', 'dark');
        } else {
            html.removeAttribute('data-theme');
        }
        
        // Save preference
        localStorage.setItem(THEME_KEY, theme);
    }

    /**
     * Update toggle button text and appearance
     */
    function updateToggleButton(theme) {
        const button = document.getElementById('theme-toggle-btn');
        if (!button) return;
        
        if (theme === DARK) {
            button.textContent = '☀️ Light Mode';
            button.setAttribute('aria-label', 'Switch to light mode');
        } else {
            button.textContent = '🌙 Dark Mode';
            button.setAttribute('aria-label', 'Switch to dark mode');
        }
    }

    /**
     * Get current theme
     */
    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || LIGHT;
    }

    /**
     * Toggle between light and dark theme
     */
    function toggleTheme() {
        const current = getCurrentTheme();
        const newTheme = current === LIGHT ? DARK : LIGHT;
        applyTheme(newTheme);
        updateToggleButton(newTheme);
    }

    /**
     * Expose toggleTheme globally so HTML onclick can call it
     */
    window.toggleTheme = toggleTheme;

    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme);
    } else {
        initTheme();
    }
})();
