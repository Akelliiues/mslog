// Initialize font size immediately to avoid FOUC
(function() {
    const savedFontSize = localStorage.getItem('fontSize');
    if (savedFontSize) {
        document.documentElement.style.setProperty('--base-font-size', savedFontSize + 'px');
    }
})();

document.addEventListener('DOMContentLoaded', () => {
    const htmlElement = document.documentElement;
    const btnIncrease = document.getElementById('btn-increase-font');
    const btnDecrease = document.getElementById('btn-decrease-font');
    const btnReset = document.getElementById('btn-reset-font');

    const defaultSize = 16;
    const maxSize = 24;
    const minSize = 12;
    const step = 2;

    let currentSize = parseInt(localStorage.getItem('fontSize')) || defaultSize;

    function applyFontSize(size) {
        htmlElement.style.setProperty('--base-font-size', size + 'px');
        localStorage.setItem('fontSize', size);
        currentSize = size;
    }

    if (btnIncrease) {
        btnIncrease.addEventListener('click', () => {
            if (currentSize < maxSize) {
                applyFontSize(currentSize + step);
            }
        });
    }

    if (btnDecrease) {
        btnDecrease.addEventListener('click', () => {
            if (currentSize > minSize) {
                applyFontSize(currentSize - step);
            }
        });
    }

    if (btnReset) {
        btnReset.addEventListener('click', () => {
            applyFontSize(defaultSize);
        });
    }
});
