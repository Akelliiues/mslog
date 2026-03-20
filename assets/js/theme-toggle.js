(function () {
    const theme = localStorage.getItem('theme') || 'light';
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
    }

    window.addEventListener('DOMContentLoaded', () => {
        const toggleButtons = document.querySelectorAll('.theme-toggle');
        
        const updateButtons = (isDark) => {
            toggleButtons.forEach(btn => {
                btn.innerHTML = isDark ? '☀️' : '🌙';
                btn.setAttribute('title', isDark ? 'สลับเป็นโหมดสว่าง' : 'สลับเป็นโหมดมืด');
            });
        };

        updateButtons(theme === 'dark');

        toggleButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark-mode');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                updateButtons(isDark);
            });
        });
    });
})();
