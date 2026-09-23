import { Controller } from '@hotwired/stimulus';
import { useClickOutside } from 'stimulus-use'

export default class extends Controller {
  static targets = ['menu'];

  connect() {
    super.connect();
    useClickOutside(this);
  }

  toggle() {
    const isHidden = this.menuTarget.classList.toggle('hidden');
    this._syncExpanded(!isHidden);
  }

  clickOutside(event) {
    this.menuTarget.classList.add('hidden');
    this._syncExpanded(false);
  }

  _syncExpanded(isOpen) {
    const button = this.element.querySelector('[aria-expanded]');
    if (button) {
      button.setAttribute('aria-expanded', String(isOpen));
    }
  }
}
