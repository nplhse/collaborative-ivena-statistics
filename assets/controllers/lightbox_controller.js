import { Controller } from '@hotwired/stimulus';

let fslightboxLoaded = false;

export default class extends Controller {
    static values = {
        gallery: { type: String, default: 'content-gallery' },
    };

    connect() {
        this.boundRefresh = this.refresh.bind(this);
        this.refresh();
        document.addEventListener('turbo:load', this.boundRefresh);
        document.addEventListener('turbo:frame-load', this.boundRefresh);
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.boundRefresh);
        document.removeEventListener('turbo:frame-load', this.boundRefresh);
    }

    refresh() {
        this.wrapBareImages();
        this.initLightbox();
    }

    wrapBareImages() {
        this.element.querySelectorAll('img[src]').forEach((img) => {
            if (img.closest('[data-fslightbox]')) {
                return;
            }

            if (img.closest('a')) {
                return;
            }

            const src = img.getAttribute('src');
            if (!src) {
                return;
            }

            const link = document.createElement('a');
            link.href = src;
            link.setAttribute('data-fslightbox', this.galleryValue);
            link.classList.add('page-content-image__link');
            img.parentNode.insertBefore(link, img);
            link.appendChild(img);
        });
    }

    initLightbox() {
        if (!fslightboxLoaded) {
            import('fslightbox').then(() => {
                fslightboxLoaded = true;
                if (typeof globalThis.refreshFsLightbox === 'function') {
                    globalThis.refreshFsLightbox();
                }
            });
            return;
        }

        if (typeof globalThis.refreshFsLightbox === 'function') {
            globalThis.refreshFsLightbox();
        }
    }
}
