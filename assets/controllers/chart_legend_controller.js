import { Controller } from '@hotwired/stimulus';
import { useClickOutside } from 'stimulus-use';
import { Chart } from 'chart.js';

export default class extends Controller {
  static targets = [
    'card',
    'list',
    'badge',
    'countBadge',
    'toggleBtn',
    'search',
    'emptyState',
  ];

  connect() {
    this._onConnect = this._onConnect.bind(this);
    this._onKeyDown = this._onKeyDown.bind(this);
    this.isOpen = false;

    this.element.addEventListener('chartjs:connect', this._onConnect);
    window.addEventListener('keydown', this._onKeyDown);

    useClickOutside(this, { only: ['clickOutside'] });

    // Check if chart is already attached to canvas inside this element
    const canvas = this.element.querySelector('canvas');
    if (canvas && typeof Chart.getChart === 'function') {
      const chartInstance = Chart.getChart(canvas);
      if (chartInstance) {
        this.chart = chartInstance;
        this.renderLegend();
      }
    }
  }

  disconnect() {
    this.element.removeEventListener('chartjs:connect', this._onConnect);
    window.removeEventListener('keydown', this._onKeyDown);
  }

  _onConnect(event) {
    this.chart = event.detail.chart;
    this.renderLegend();
  }

  _onKeyDown(event) {
    if (event.key === 'Escape' && this.isOpen) {
      this.close();
    }
  }

  clickOutside(event) {
    if (!this.isOpen) return;
    if (this.hasToggleBtnTarget && this.toggleBtnTarget.contains(event.target)) {
      return;
    }
    if (this.hasCardTarget && !this.cardTarget.contains(event.target)) {
      this.close();
    }
  }

  toggle(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  open() {
    if (!this.hasCardTarget) return;

    this.cardTarget.classList.remove('translate-x-full', 'opacity-0', 'pointer-events-none');
    this.cardTarget.classList.add('translate-x-0', 'opacity-100', 'pointer-events-auto');

    if (this.hasToggleBtnTarget) {
      this.toggleBtnTarget.classList.add('bg-slate-900', 'text-white', 'border-slate-900', 'shadow-xs');
      this.toggleBtnTarget.classList.remove('bg-white', 'text-slate-700', 'border-slate-200/80');
      this.toggleBtnTarget.setAttribute('aria-expanded', 'true');
    }

    this.isOpen = true;

    if (this.hasSearchTarget) {
      setTimeout(() => this.searchTarget.focus(), 150);
    }
  }

  close() {
    if (!this.hasCardTarget) return;

    this.cardTarget.classList.remove('translate-x-0', 'opacity-100', 'pointer-events-auto');
    this.cardTarget.classList.add('translate-x-full', 'opacity-0', 'pointer-events-none');

    if (this.hasToggleBtnTarget) {
      this.toggleBtnTarget.classList.remove('bg-slate-900', 'text-white', 'border-slate-900', 'shadow-xs');
      this.toggleBtnTarget.classList.add('bg-white', 'text-slate-700', 'border-slate-200/80');
      this.toggleBtnTarget.setAttribute('aria-expanded', 'false');
    }

    this.isOpen = false;
  }

  renderLegend() {
    if (!this.hasListTarget || !this.chart || !this.chart.data || !this.chart.data.datasets) {
      return;
    }

    this.listTarget.innerHTML = '';
    const datasets = this.chart.data.datasets;

    datasets.forEach((dataset, index) => {
      const isVisible = this.chart.isDatasetVisible(index);
      const row = this.createRowElement(dataset, index, isVisible);
      this.listTarget.appendChild(row);
    });

    this.updateCounters();
  }

  createRowElement(dataset, index, isVisible) {
    const row = document.createElement('div');
    row.className = `flex items-center justify-between p-2 rounded-xl transition-all cursor-pointer select-none group hover:bg-slate-100/70 ${
      isVisible ? '' : 'opacity-50'
    }`;
    row.dataset.index = index;
    row.dataset.label = (dataset.label || '').toLowerCase();
    row.dataset.action = 'click->chart-legend#toggleDataset';

    const color = dataset.backgroundColor || '#64748b';
    const label = dataset.label || `Item ${index + 1}`;
    const amount = dataset.amount || '';

    row.innerHTML = `
      <div class="flex items-center gap-2.5 min-w-0 flex-1 mr-2">
        <span class="color-dot w-3 h-3 rounded-full flex-shrink-0 shadow-2xs border border-white/60 transition-opacity ${
          isVisible ? 'opacity-100' : 'opacity-30'
        }" style="background-color: ${color}"></span>
        <span class="label-text text-xs font-medium truncate group-hover:text-slate-900 transition-colors ${
          isVisible ? 'text-slate-800' : 'text-slate-400 line-through'
        }">${this.escapeHtml(label)}</span>
        ${
          amount
            ? `<span class="text-[10px] text-slate-400 font-medium ml-auto shrink-0">${this.escapeHtml(amount)}</span>`
            : ''
        }
      </div>
      <div class="flex-shrink-0">
        <div class="check-box w-4 h-4 rounded-md border flex items-center justify-center transition-all ${
          isVisible
            ? 'bg-blue-600 border-blue-600 text-white'
            : 'bg-white border-slate-300 text-transparent'
        }">
          <i class="fa-solid fa-check text-[9px]"></i>
        </div>
      </div>
    `;

    return row;
  }

  toggleDataset(event) {
    event.preventDefault();
    event.stopPropagation();

    const row = event.currentTarget;
    const index = parseInt(row.dataset.index, 10);
    if (!this.chart || isNaN(index)) return;

    const currentlyVisible = this.chart.isDatasetVisible(index);
    const newVisible = !currentlyVisible;

    this.chart.setDatasetVisibility(index, newVisible);
    this.chart.update();

    this.updateRowVisual(row, newVisible);
    this.updateCounters();
  }

  showAll(event) {
    if (event) event.preventDefault();
    if (!this.chart || !this.chart.data || !this.chart.data.datasets) return;

    this.chart.data.datasets.forEach((_, index) => {
      this.chart.setDatasetVisibility(index, true);
    });
    this.chart.update();

    if (this.hasListTarget) {
      this.listTarget.querySelectorAll('[data-index]').forEach((row) => {
        this.updateRowVisual(row, true);
      });
    }

    this.updateCounters();
  }

  hideAll(event) {
    if (event) event.preventDefault();
    if (!this.chart || !this.chart.data || !this.chart.data.datasets) return;

    this.chart.data.datasets.forEach((_, index) => {
      this.chart.setDatasetVisibility(index, false);
    });
    this.chart.update();

    if (this.hasListTarget) {
      this.listTarget.querySelectorAll('[data-index]').forEach((row) => {
        this.updateRowVisual(row, false);
      });
    }

    this.updateCounters();
  }

  updateRowVisual(row, isVisible) {
    const colorDot = row.querySelector('.color-dot');
    const labelText = row.querySelector('.label-text');
    const checkBox = row.querySelector('.check-box');

    if (isVisible) {
      row.classList.remove('opacity-50');
      if (colorDot) {
        colorDot.classList.remove('opacity-30');
        colorDot.classList.add('opacity-100');
      }
      if (labelText) {
        labelText.classList.remove('text-slate-400', 'line-through');
        labelText.classList.add('text-slate-800');
      }
      if (checkBox) {
        checkBox.classList.remove('bg-white', 'border-slate-300', 'text-transparent');
        checkBox.classList.add('bg-blue-600', 'border-blue-600', 'text-white');
      }
    } else {
      row.classList.add('opacity-50');
      if (colorDot) {
        colorDot.classList.remove('opacity-100');
        colorDot.classList.add('opacity-30');
      }
      if (labelText) {
        labelText.classList.remove('text-slate-800');
        labelText.classList.add('text-slate-400', 'line-through');
      }
      if (checkBox) {
        checkBox.classList.remove('bg-blue-600', 'border-blue-600', 'text-white');
        checkBox.classList.add('bg-white', 'border-slate-300', 'text-transparent');
      }
    }
  }

  updateCounters() {
    if (!this.chart || !this.chart.data || !this.chart.data.datasets) return;

    const total = this.chart.data.datasets.length;
    let visible = 0;
    for (let i = 0; i < total; i++) {
      if (this.chart.isDatasetVisible(i)) {
        visible++;
      }
    }

    if (this.hasCountBadgeTarget) {
      this.countBadgeTarget.textContent = `${visible} of ${total} visible`;
    }

    if (this.hasBadgeTarget) {
      this.badgeTarget.textContent = visible === total ? `${total}` : `${visible}/${total}`;
    }
  }

  filter(event) {
    const query = (event.target.value || '').toLowerCase().trim();
    if (!this.hasListTarget) return;

    const rows = this.listTarget.querySelectorAll('[data-index]');
    let matchCount = 0;

    rows.forEach((row) => {
      const label = row.dataset.label || '';
      if (!query || label.includes(query)) {
        row.classList.remove('hidden');
        matchCount++;
      } else {
        row.classList.add('hidden');
      }
    });

    if (this.hasEmptyStateTarget) {
      if (matchCount === 0 && rows.length > 0) {
        this.emptyStateTarget.classList.remove('hidden');
      } else {
        this.emptyStateTarget.classList.add('hidden');
      }
    }
  }

  escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }
}
