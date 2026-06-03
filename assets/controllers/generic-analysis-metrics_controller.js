import { Controller } from '@hotwired/stimulus'

/**
 * Keeps the chart visual-metric dropdown in sync with selected metric checkboxes.
 */
export default class extends Controller {
    static targets = ['metricCheckbox', 'visualSelect']

    connect () {
        this.syncVisualOptions()
    }

    metricCheckboxTargetConnected () {
        this.syncVisualOptions()
    }

    metricCheckboxTargetDisconnected () {
        this.syncVisualOptions()
    }

    metricCheckboxChanged () {
        this.syncVisualOptions()
    }

    syncVisualOptions () {
        if (!this.hasVisualSelectTarget) {
            return
        }

        const selectedKeys = new Set(['count'])
        this.metricCheckboxTargets.forEach((input) => {
            if (input.checked) {
                selectedKeys.add(input.dataset.metricKey)
            }
        })

        const select = this.visualSelectTarget
        const previous = select.value
        select.replaceChildren()

        const countOption = document.createElement('option')
        countOption.value = 'count'
        countOption.textContent = this.countLabel()
        select.appendChild(countOption)

        this.metricCheckboxTargets.forEach((input) => {
            if (!input.checked) {
                return
            }
            const option = document.createElement('option')
            option.value = input.dataset.metricKey
            option.textContent = input.dataset.metricLabel
            select.appendChild(option)
        })

        if (!select.querySelector(`option[value="${previous}"]`)) {
            const countOption = select.querySelector('option[value="count"]')
            select.value = countOption ? 'count' : select.options[0]?.value ?? 'count'
        } else {
            select.value = previous
        }
    }

    countLabel () {
        const countInput = this.element.querySelector('[data-testid="stats-generic-analysis-metric-count"]')
        const label = countInput?.closest('.form-check')?.querySelector('label')

        return label?.textContent?.trim() || 'Count'
    }
}
