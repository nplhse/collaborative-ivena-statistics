import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['body'];

    sort(event) {
        const button = event.currentTarget;
        const columnIndex = Number.parseInt(button.dataset.sortIndex ?? '', 10);
        if (Number.isNaN(columnIndex)) {
            return;
        }

        const sortType = button.dataset.sortType ?? 'text';
        let direction = 'number' === sortType ? 'desc' : 'asc';
        if (this.activeIndex === columnIndex) {
            direction = this.activeDirection === 'asc' ? 'desc' : 'asc';
        }

        this.activeIndex = columnIndex;
        this.activeDirection = direction;

        this.element.querySelectorAll('.table-sort').forEach((sortButton) => {
            sortButton.classList.remove('sorted-asc', 'sorted-desc');
            sortButton.removeAttribute('aria-sort');
        });
        button.classList.add(direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
        button.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');

        const rows = Array.from(this.bodyTarget.rows).filter(
            (row) => !row.querySelector('[data-testid="stats-analysis-explorer-table-empty"]'),
        );

        const multiplier = direction === 'asc' ? 1 : -1;
        rows.sort((rowA, rowB) => multiplier * this.compareRows(rowA, rowB, columnIndex, sortType));
        rows.forEach((row) => this.bodyTarget.appendChild(row));
    }

    compareRows(rowA, rowB, columnIndex, sortType) {
        const valueA = this.sortValueForCell(rowA.cells[columnIndex], sortType);
        const valueB = this.sortValueForCell(rowB.cells[columnIndex], sortType);

        if ('number' === sortType) {
            return valueA - valueB;
        }

        return String(valueA).localeCompare(String(valueB), undefined, {
            sensitivity: 'base',
            numeric: true,
        });
    }

    sortValueForCell(cell, sortType) {
        if (!cell) {
            return 'number' === sortType ? 0 : '';
        }

        const rawValue = cell.dataset.sortValue;
        if (undefined !== rawValue) {
            if ('number' === sortType) {
                const parsed = Number.parseFloat(rawValue);
                return Number.isFinite(parsed) ? parsed : 0;
            }

            return rawValue;
        }

        return 'number' === sortType ? 0 : (cell.textContent ?? '').trim();
    }
}
