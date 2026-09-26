import { Controller } from '@hotwired/stimulus';

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://github.com/symfony/stimulus-bridge#lazy-controllers
*/
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['menu'];

    toggle() {
        const isHidden = this.menuTarget.classList.toggle('hidden');
        const button = this.element.querySelector('[aria-expanded]');
        if (button) {
            button.setAttribute('aria-expanded', String(!isHidden));
        }
    }
}
