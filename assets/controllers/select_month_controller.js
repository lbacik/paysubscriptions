import { Controller } from '@hotwired/stimulus';
export default class extends Controller {

  static targets = ['options'];
  static values = { url: String };

  connect() {
    console.log('select_month_controller connected');
  }

  click(event) {
    event.preventDefault();
    event.stopPropagation();
    console.log('select month click');
    this.optionsTarget.classList.toggle('hidden');
  }

  select(event) {
    event.preventDefault();
    event.stopPropagation();
    this.optionsTarget.classList.add('hidden');
    window.Turbo.visit(`${this.urlValue}&month=${parseInt(event.currentTarget.getAttribute('value')) + 1}`, { frame: "chart" });
  }
}
