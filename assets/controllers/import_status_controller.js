import { Controller } from '@hotwired/stimulus'

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        initialInterval: { type: Number, default: 1000 },
        maxInterval: { type: Number, default: 15000 },
        startPolling: { type: Boolean, default: true },
        processingHint: String,
        statusPayload: Object,
    }

    static targets = [
        'hint',
        'activityBlock',
        'activitySpinner',
        'message',
        'progressWrapper',
        'progressBar',
        'detailLink',
        'error',
        'heroAvatar',
        'heroIcon',
        'stepsList',
        'stepItem',
    ]

    connect () {
        this._currentInterval = this.initialIntervalValue
        this._pollTimer = null
        this._inFlight = false
        this._stopped = false

        this._onVisibilityChange = this._handleVisibilityChange.bind(this)
        document.addEventListener('visibilitychange', this._onVisibilityChange)

        if (this.hasStatusPayloadValue) {
            this._applyStatus(this.statusPayloadValue)
        }

        if (this.startPollingValue) {
            this._schedulePoll(0)
        }
    }

    disconnect () {
        this._stopPolling()
        document.removeEventListener('visibilitychange', this._onVisibilityChange)
    }

    _handleVisibilityChange () {
        if (document.hidden) {
            this._clearPollTimer()
            return
        }

        this._currentInterval = this.initialIntervalValue
        if (this.startPollingValue && !this._stopped) {
            void this._fetchStatus()
            this._schedulePoll(this._currentInterval)
        }
    }

    _schedulePoll (delayMs) {
        this._clearPollTimer()
        if (this._stopped || document.hidden) {
            return
        }

        this._pollTimer = window.setTimeout(() => {
            void this._fetchStatus()
        }, delayMs)
    }

    _clearPollTimer () {
        if (null !== this._pollTimer) {
            window.clearTimeout(this._pollTimer)
            this._pollTimer = null
        }
    }

    _stopPolling () {
        this._stopped = true
        this._clearPollTimer()
    }

    async _fetchStatus () {
        if (this._inFlight || this._stopped) {
            return
        }

        this._inFlight = true
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                throw new Error(`Status request failed: ${response.status}`)
            }

            const data = await response.json()
            this._applyStatus(data)
            this._hideError()

            if (data.isFinal) {
                this._stopPolling()
                return
            }

            this._currentInterval = Math.min(
                Math.round(this._currentInterval * 1.5),
                this.maxIntervalValue,
            )
            this._schedulePoll(this._currentInterval)
        } catch {
            this._showError()
            this._currentInterval = Math.min(
                Math.round(this._currentInterval * 1.5),
                this.maxIntervalValue,
            )
            this._schedulePoll(this._currentInterval)
        } finally {
            this._inFlight = false
        }
    }

    _applyStatus (data) {
        if (!data || typeof data !== 'object') {
            return
        }

        if (this.hasHintTarget) {
            this.hintTarget.textContent = data.isFinal
                ? (data.message ?? '')
                : (this.processingHintValue ?? '')
        }

        if (this.hasActivityBlockTarget) {
            this.activityBlockTarget.classList.toggle('d-none', Boolean(data.isFinal))
        }

        if (this.hasActivitySpinnerTarget) {
            const showSpinner = !data.isFinal && ('pending' === data.status || 'running' === data.status)
            this.activitySpinnerTarget.classList.toggle('d-none', !showSpinner)
        }

        if (this.hasMessageTarget && !data.isFinal) {
            this.messageTarget.textContent = data.message ?? ''
        }

        if (this.hasProgressWrapperTarget && this.hasProgressBarTarget) {
            if (null === data.progress || undefined === data.progress) {
                this.progressWrapperTarget.classList.add('d-none')
            } else {
                this.progressWrapperTarget.classList.remove('d-none')
                this.progressBarTarget.style.width = `${data.progress}%`
                this.progressBarTarget.setAttribute('aria-valuenow', String(data.progress))
            }
        }

        this._applyHero(data)
        this._applySteps(data)

        if (data.isFinal && this.hasDetailLinkTarget) {
            if (data.detailUrl) {
                this.detailLinkTarget.href = data.detailUrl
            }
            this.detailLinkTarget.classList.remove('d-none')
        }
    }

    _applyHero (data) {
        if (!this.hasHeroAvatarTarget) {
            return
        }

        const tone = data.iconTone ?? 'secondary'
        const avatar = this.heroAvatarTarget
        avatar.className = `avatar avatar-lg bg-${tone}-lt text-${tone} flex-shrink-0 import-processing-hero`

        if (this.hasHeroIconTarget) {
            this.heroIconTargets.forEach((iconEl) => {
                const isActive = iconEl.dataset.statusKey === data.status
                iconEl.classList.toggle('d-none', !isActive)
            })
        }
    }

    _applySteps (data) {
        if (!Array.isArray(data.steps) || data.steps.length === 0) {
            return
        }

        const modifier = data.stepsModifier ?? 'green'
        if (this.hasStepsListTarget) {
            this.stepsListTarget.className = `steps steps-vertical steps-${modifier} import-processing-steps my-0`
        }

        const stepsByKey = Object.fromEntries(data.steps.map((step) => [step.key, step]))
        const stepElements = this.hasStepItemTarget
            ? this.stepItemTargets
            : Array.from(this.element.querySelectorAll('[data-step-key]'))

        stepElements.forEach((itemEl) => {
            const step = stepsByKey[itemEl.dataset.stepKey]
            if (!step) {
                return
            }

            itemEl.classList.remove('active')
            if (step.state === 'active') {
                itemEl.classList.add('active')
            }

            const labelEl = itemEl.querySelector('.import-step-label')
            const descEl = itemEl.querySelector('.import-step-description')
            if (labelEl) {
                labelEl.textContent = step.label ?? ''
            }
            if (descEl) {
                descEl.textContent = step.description ?? ''
            }
        })
    }

    _showError () {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.remove('d-none')
        }
    }

    _hideError () {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add('d-none')
        }
    }
}
