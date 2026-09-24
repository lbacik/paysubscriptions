import { Controller } from '@hotwired/stimulus';

/**
 * Detects the browser time zone via the Intl API.
 *
 * On pages with a hidden timezone field (registration) the detected zone is
 * filled in automatically on connect. On pages with an explicit control
 * (settings) it is only applied when the user asks via the detect action,
 * so an existing choice is never overwritten. The server keeps only valid
 * IANA zones and falls back to UTC otherwise.
 */
export default class extends Controller {
  static targets = ['field'];

  connect() {
    if (
      this.hasFieldTarget &&
      this.fieldTarget instanceof HTMLInputElement &&
      this.fieldTarget.type === 'hidden' &&
      !this.fieldTarget.value
    ) {
      const detected = this.detectedTimezone();
      if (detected) {
        this.fieldTarget.value = detected;
      }
    }
  }

  detect(event) {
    if (event) {
      event.preventDefault();
    }
    if (!this.hasFieldTarget) {
      return;
    }
    const detected = this.detectedTimezone();
    if (!detected) {
      return;
    }
    const field = this.fieldTarget;
    if (field instanceof HTMLSelectElement) {
      const known = Array.from(field.options).some((option) => option.value === detected);
      if (known) {
        field.value = detected;
      }
    } else {
      field.value = detected;
    }
  }

  detectedTimezone() {
    try {
      return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
      return '';
    }
  }
}
