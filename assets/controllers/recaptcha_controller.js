import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = {
    sitekey: String,
    action: String,
  };

  connect() {
    this.handleTurboLoad = this.handleTurboLoad.bind(this);
    document.addEventListener("turbo:load", this.handleTurboLoad);
    this.initRecaptcha();
  }

  disconnect() {
    document.removeEventListener("turbo:load", this.handleTurboLoad);
  }

  handleTurboLoad() {
    console.log("reCAPTCHA: turbo:load event received.");

    this.initRecaptcha();
  }

  initRecaptcha() {

    console.log("reCAPTCHA: Initializing...");

    if (typeof grecaptcha === "undefined") {
      console.log("reCAPTCHA: grecaptcha is not loaded yet. Loading...");

      window.onRecaptchaLoad = () => {

        console.log("reCAPTCHA: Loaded.");

        this.executeRecaptcha();
      };

      if (!document.querySelector('script[src*="recaptcha/api.js"]')) {
        console.log("reCAPTCHA: Loading script...");

        const script = document.createElement("script");
        script.src = "https://www.google.com/recaptcha/api.js?render=" + this.sitekeyValue + "&onload=onRecaptchaLoad";
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
      }
    } else {
      console.log("reCAPTCHA: Already loaded.");
      this.executeRecaptcha();
    }
  }

  executeRecaptcha() {

    console.log("reCAPTCHA: Executing...");

    const submitButton = this.element.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
    }

    grecaptcha.ready(() => {
      grecaptcha.execute(this.sitekeyValue, { action: this.actionValue }).then((token) => {
        this.element.querySelector('input[name="g-recaptcha-response"]').value = token;
        if (submitButton) {
          submitButton.disabled = false;
        }
      });
    });
  }
}
