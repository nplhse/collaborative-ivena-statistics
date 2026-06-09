import { Controller } from '@hotwired/stimulus';
import List from 'list.js';

const NUMERIC_COLUMNS = new Set(['sort-population', 'sort-participants', 'sort-coverage']);

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.list = new List(this.element.id, {
            sortClass: 'table-sort',
            listClass: 'table-tbody',
            valueNames: [
                'sort-state',
                'sort-dispatch-area',
                { attr: 'data-population', name: 'sort-population' },
                { attr: 'data-participants', name: 'sort-participants' },
                { attr: 'data-coverage', name: 'sort-coverage' },
            ],
            sortFunction: (itemA, itemB, options) => {
                const valueName = options.valueName;
                const aValue = itemA.values()[valueName] ?? '';
                const bValue = itemB.values()[valueName] ?? '';

                if (NUMERIC_COLUMNS.has(valueName)) {
                    return (parseFloat(aValue) || 0) - (parseFloat(bValue) || 0);
                }

                return aValue.localeCompare(bValue, undefined, { sensitivity: 'base', numeric: true });
            },
        });

        this.list.sort('sort-coverage', { order: 'desc' });
    }
}
