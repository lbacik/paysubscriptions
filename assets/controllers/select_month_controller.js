import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['options'];
  static values = { url: String };

  connect() {
    this._onDocumentClick = this._onDocumentClick.bind(this);
    document.addEventListener('click', this._onDocumentClick);
  }

  disconnect() {
    document.removeEventListener('click', this._onDocumentClick);
  }

  _onDocumentClick(event) {
    if (this.hasOptionsTarget && !this.element.contains(event.target) && !this.optionsTarget.classList.contains('hidden')) {
      this.optionsTarget.classList.add('hidden');
    }
  }

  click(event) {
    event.preventDefault();
    event.stopPropagation();
    this.optionsTarget.classList.toggle('hidden');
  }

  select(event) {
    event.preventDefault();
    event.stopPropagation();
    this.optionsTarget.classList.add('hidden');

    const value = event.currentTarget.getAttribute('value');
    const separator = this.urlValue.includes('?') ? '&' : '?';
    const month = parseInt(value, 10) + 1;

    window.Turbo.visit(`${this.urlValue}${separator}month=${month}`, { frame: 'chart' });
  }
}
