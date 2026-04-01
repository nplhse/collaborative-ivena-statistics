import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    sync () {
        const params = new URLSearchParams(window.location.search)
        const controls = this.element.querySelectorAll('select[name]')

        controls.forEach((control) => {
            const key = control.getAttribute('name')
            params.delete(key)

            if (control.multiple) {
                Array.from(control.selectedOptions).forEach((option) => {
                    params.append(key, option.value)
                })
                return
            }

            if (control.value !== '') {
                params.set(key, control.value)
            }
        })

        const nextUrl = `${window.location.pathname}?${params.toString()}`
        window.history.replaceState({}, '', nextUrl)
    }
}
