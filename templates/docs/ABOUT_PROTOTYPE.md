# About page and public roadmap prototype

Throwaway design review for [Prototype the About page and public roadmap presentation](https://github.com/lbacik/paysubscriptions/issues/27). This is not a production page.

Run from the repository root with dependencies installed and `APP_ENV=dev`:

```sh
php -S 127.0.0.1:8087 -t public public/index.php
```

Open `http://127.0.0.1:8087/about?variant=A`. The floating bar or left/right arrow keys switch among shareable variants:

- **A — Story + disclosures:** product story and a prominent current/planned card, followed by data-handling details and a three-column roadmap.
- **B — Journey + roadmap:** an illustrative recurring-cost journey, a vertical outcome roadmap, and a current/planned reality check.
- **C — Questions + answers:** trust questions first, detailed handling boundaries, then a compact roadmap.

All variants use the existing application header and footer. The default `/about` page, non-About documentation sections, and all production requests retain their existing output. The shared header/footer copy and links need a separate production accuracy review.

The proposed Now / Next / Later presentation is for publication **with v1.0 after its release gates pass**. In this pre-release prototype, Now is explicitly labelled a v1.0 goal. Next and Later describe areas to explore, without dates or feature guarantees. The current product has manual subscription entry, a list, and monthly/yearly cost equivalents; renewal dates, reminders, categories, a privacy policy, and self-service account deletion are planned. Registration and contact are known to fail in production, so this prototype does not treat them as working calls to action.

Review question: Which information hierarchy should anchor the About page, and which elements of other variants should be combined with it? Is the distinction between current functionality and the proposed public roadmap clear enough? Flag any data-handling wording that feels misleading or too technical.
