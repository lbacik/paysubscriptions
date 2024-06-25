import { Controller } from '@hotwired/stimulus';
import { useClickOutside } from 'stimulus-use'

export default class extends Controller {
  static targets = ['menu'];

  connect() {
    super.connect();
    useClickOutside(this);
  }

  toggle() {
    this.menuTarget.classList.toggle('hidden');
  }

  clickOutside(event) {
    this.menuTarget.classList.add('hidden');
  }
}
