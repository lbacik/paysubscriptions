import {Controller} from '@hotwired/stimulus';

export default class extends Controller {

  static values = {
    url: String,
  }

  months = [
    'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'
  ];

  connect() {
    this._onConnect = this._onConnect.bind(this);
    this.element.addEventListener('chartjs:connect', this._onConnect);
  }

  disconnect() {
    this.element.removeEventListener('chartjs:connect', this._onConnect);
  }

  _onConnect(event) {
    console.log('_onConnect', event.detail.chart);

    event.detail.chart.options.onClick = (mouseEvent, elements) => {
      if (elements.length > 0) {
        const chartElement = elements[0];
        const index = chartElement.index;
        const label = event.detail.chart.data.labels[index];

        if (this.months.includes(label)) {
          const month = this.months.indexOf(label) + 1;
          window.Turbo.visit(`${this.urlValue}&month=${month}`, { frame: 'chart' });
        }
      }
    }
  }
}
