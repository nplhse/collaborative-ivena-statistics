import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'item', 'position'];

    connect() {
        this.update();
    }

    moveUp(event) {
        this.move(event.currentTarget, -1);
    }

    moveDown(event) {
        this.move(event.currentTarget, 1);
    }

    move(button, offset) {
        const item = button.closest('[data-data-table-columns-target="item"]');
        const index = this.itemTargets.indexOf(item);
        const target = this.itemTargets[index + offset];
        if (!item || !target) {
            return;
        }

        if (offset < 0) {
            this.listTarget.insertBefore(item, target);
        } else {
            this.listTarget.insertBefore(target, item);
        }
        this.update();
    }

    update() {
        this.itemTargets.forEach((item, index, items) => {
            const buttons = item.querySelectorAll('button[data-action]');
            if (buttons[0]) {
                buttons[0].disabled = index === 0;
            }
            if (buttons[1]) {
                buttons[1].disabled = index === items.length - 1;
            }
            const position = item.querySelector('[data-data-table-columns-target="position"]');
            if (position) {
                position.textContent = `#${index + 1}`;
            }
        });
    }
}
