import { Controller } from '@hotwired/stimulus';

/*
* The following line makes this controller "lazy": it won't be downloaded until needed
* See https://github.com/symfony/stimulus-bridge#lazy-controllers
*/
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['chapters', 'arrow']

    toggle() {
        this.chaptersTarget.classList.toggle('hidden')
        this.arrowTarget.classList.toggle('fa-chevron-right')
        this.arrowTarget.classList.toggle('fa-chevron-down')
    }
}
