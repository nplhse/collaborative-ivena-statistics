import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'feedback', 'meter', 'meterBar', 'meterLabel', 'requirement'];

    static values = {
        policy: Object,
        strengthLabels: Array,
        acceptableLabel: String,
    };

    connect() {
        this.evaluate();
    }

    evaluate() {
        const password = this.inputTarget.value;
        const policy = this.policyValue;
        const strength = estimateStrength(password);
        const hasPassword = password.length > 0;

        this.updateMeter(password, strength, hasPassword, policy);
        this.updateRequirements(password, strength, policy);
    }

    updateMeter(password, strength, hasPassword, policy) {
        const labels = this.strengthLabelsValue;
        const levelCount = this.policyValue.strengthLevelCount ?? 5;
        const requirementsMet =
            password.length >= policy.minLength && strength >= policy.minStrengthScore;
        let percent = hasPassword ? Math.round(((strength + 1) / levelCount) * 100) : 0;

        this.meterTarget.setAttribute('aria-valuenow', String(percent));
        this.meterBarTarget.style.width = `${percent}%`;
        this.meterBarTarget.classList.remove('bg-danger', 'bg-warning', 'bg-success');

        if (!hasPassword) {
            this.meterLabelTarget.textContent = '';
            this.meterBarTarget.classList.add('bg-danger');
            this.feedbackTarget.classList.add('opacity-75');

            return;
        }

        this.feedbackTarget.classList.remove('opacity-75');

        if (requirementsMet) {
            if (strength >= 2) {
                this.meterLabelTarget.textContent = labels[strength] ?? '';
            } else {
                this.meterLabelTarget.textContent = this.acceptableLabelValue;
                percent = Math.max(
                    percent,
                    Math.round(((policy.minStrengthScore + 1) / levelCount) * 100),
                );
                this.meterTarget.setAttribute('aria-valuenow', String(percent));
                this.meterBarTarget.style.width = `${percent}%`;
            }

            this.meterBarTarget.classList.add('bg-success');

            return;
        }

        this.meterLabelTarget.textContent = labels[strength] ?? labels[0] ?? '';

        if (strength <= 1) {
            this.meterBarTarget.classList.add('bg-danger');
        } else if (strength === 2) {
            this.meterBarTarget.classList.add('bg-warning');
        } else {
            this.meterBarTarget.classList.add('bg-success');
        }
    }

    updateRequirements(password, strength, policy) {
        const checks = {
            min_length: password.length >= policy.minLength,
            strength: strength >= policy.minStrengthScore,
        };

        this.requirementTargets.forEach((element) => {
            const key = element.dataset.passwordStrengthRequirementParam;
            const met = checks[key] ?? false;
            const metIcon = element.querySelector(
                '[data-password-strength-target="requirementMetIcon"]',
            );
            const openIcon = element.querySelector(
                '[data-password-strength-target="requirementOpenIcon"]',
            );

            element.classList.toggle('text-success', met);
            element.classList.toggle('text-muted', !met);

            if (metIcon) {
                metIcon.classList.toggle('d-none', !met);
            }

            if (openIcon) {
                openIcon.classList.toggle('d-none', met);
            }
        });
    }
}

/**
 * Mirrors Symfony\Component\Validator\Constraints\PasswordStrengthValidator::estimateStrength().
 *
 * @returns {0|1|2|3|4}
 */
function estimateStrength(password) {
    const length = password.length;

    if (0 === length) {
        return 0;
    }

    const charCounts = {};
    for (let index = 0; index < length; index += 1) {
        const chr = password.charCodeAt(index);
        charCounts[chr] = (charCounts[chr] ?? 0) + 1;
    }

    const chars = Object.keys(charCounts).length;
    let control = 0;
    let digit = 0;
    let upper = 0;
    let lower = 0;
    let symbol = 0;
    let other = 0;

    for (const chrKey of Object.keys(charCounts)) {
        const chr = Number(chrKey);

        if (chr < 32 || 127 === chr) {
            control = 33;
        } else if (chr >= 48 && chr <= 57) {
            digit = 10;
        } else if (chr >= 65 && chr <= 90) {
            upper = 26;
        } else if (chr >= 97 && chr <= 122) {
            lower = 26;
        } else if (chr >= 128) {
            other = 128;
        } else {
            symbol = 33;
        }
    }

    const pool = lower + upper + digit + symbol + control + other;
    const entropy =
        chars * (Math.log(pool) / Math.log(2)) + (length - chars) * (Math.log(chars) / Math.log(2));

    if (entropy >= 120) {
        return 4;
    }

    if (entropy >= 100) {
        return 3;
    }

    if (entropy >= 80) {
        return 2;
    }

    if (entropy >= 60) {
        return 1;
    }

    return 0;
}
