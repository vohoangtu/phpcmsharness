(() => {
    const slider = document.querySelector('[data-home-slider]');
    if (!slider) return;
    const slides = [...slider.querySelectorAll('[data-home-slide]')];
    if (slides.length < 2) return;
    const dots = [...slider.querySelectorAll('[data-slider-dot]')];
    const interval = Math.max(2000, Number(slider.dataset.interval || 3) * 1000);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let active = Math.max(0, slides.findIndex(slide => slide.classList.contains('is-active')));
    let timer = null;
    let paused = reducedMotion.matches;

    function show(index) {
        active = (index + slides.length) % slides.length;
        slides.forEach((slide, i) => {
            const selected = i === active;
            slide.classList.toggle('is-active', selected);
            slide.setAttribute('aria-hidden', String(!selected));
        });
        dots.forEach((dot, i) => {
            const selected = i === active;
            dot.classList.toggle('is-active', selected);
            dot.setAttribute('aria-current', String(selected));
        });
    }
    function stop() { window.clearInterval(timer); timer = null; }
    function start() {
        stop();
        if (!paused && !document.hidden) timer = window.setInterval(() => show(active + 1), interval);
    }
    slider.querySelector('[data-slider-next]')?.addEventListener('click', () => { show(active + 1); start(); });
    slider.querySelector('[data-slider-prev]')?.addEventListener('click', () => { show(active - 1); start(); });
    dots.forEach(dot => dot.addEventListener('click', () => { show(Number(dot.dataset.sliderDot)); start(); }));
    const pauseButton = slider.querySelector('[data-slider-pause]');
    if (pauseButton && paused) {
        pauseButton.textContent = '▶';
        pauseButton.setAttribute('aria-label', 'Tiếp tục trình chiếu');
    }
    pauseButton?.addEventListener('click', () => {
        paused = !paused;
        pauseButton.textContent = paused ? '▶' : 'Ⅱ';
        pauseButton.setAttribute('aria-label', paused ? 'Tiếp tục trình chiếu' : 'Tạm dừng trình chiếu');
        start();
    });
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', event => { if (!slider.contains(event.relatedTarget)) start(); });
    document.addEventListener('visibilitychange', start);
    reducedMotion.addEventListener?.('change', event => { paused = event.matches; start(); });
    show(active);
    start();
})();
