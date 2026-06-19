document.addEventListener('trix-before-initialize', () => {
    if (typeof window.Trix === 'undefined') {
        return;
    }

    window.Trix.config.dompurify = window.Trix.config.dompurify || {};
    const dompurify = window.Trix.config.dompurify;

    dompurify.ADD_TAGS = [
        ...new Set([...(dompurify.ADD_TAGS || []), 'img', 'figure', 'figcaption']),
    ];
    dompurify.ADD_ATTR = [
        ...new Set([
            ...(dompurify.ADD_ATTR || []),
            'src',
            'alt',
            'loading',
            'class',
            'data-fslightbox',
            'href',
            'target',
            'rel',
        ]),
    ];
});
