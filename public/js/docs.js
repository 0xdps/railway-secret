document.addEventListener('DOMContentLoaded', () => {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }

    const OFFSET = 90;
    const headings = Array.from(document.querySelectorAll('.docs-body h2[id]'));
    const tocLinks = Array.from(document.querySelectorAll('.docs-toc a'));

    if (headings.length === 0 || tocLinks.length === 0) {
        return;
    }

    function setActive(id) {
        tocLinks.forEach((link) => {
            link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
        });
    }

    function onScroll() {
        const scrollPos = window.scrollY + OFFSET;
        let active = headings[0];

        for (const heading of headings) {
            if (heading.offsetTop <= scrollPos) {
                active = heading;
            }
        }

        setActive(active.id);
    }

    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});
