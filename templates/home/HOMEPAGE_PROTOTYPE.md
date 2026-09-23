# Homepage v1.0 prototype

This is a throwaway design review for [Prototype the v1.0 homepage information architecture and copy](https://github.com/lbacik/paysubscriptions/issues/26). It is not a production page.

Run from the repository root with dependencies installed and `APP_ENV=dev`:

```sh
php -S 127.0.0.1:8087 -t public public/index.php
```

Open `http://127.0.0.1:8087/?variant=A`. Use the bottom arrows or the left and right arrow keys to switch between three shareable variants:

- **A, Outcome + preview:** Lead with the promise of visibility, then show an illustrative upcoming-renewals view, a short three-step flow, and a concise data-handling section adapted from C. This is the selected direction.
- **B, Renewal timeline:** Lead with the next-charge moment and show the recurring-cost loop before the status/roadmap section.
- **C, Trust first:** Lead with the manual-first and data-handling boundaries, then explain the practical tracking experience.

All variants explicitly label current capabilities and planned v1.0 work. The hero language is a proposed launch direction; it must be checked against the implemented product before release. The current production registration, contact, and password-reset failures are release blockers, so the signup CTA cannot ship until those paths are repaired and verified. The mock renewal entries are illustrative, not screenshots of shipped functionality. The shared header and footer come from the current application; their copy needs separate production review.

Human review selected A as the homepage foundation, with the brief data-handling section from C. The explicit "Available now" and "Planned for v1.0" distinction is approved for the pre-release page; after release, claims must be updated to match verified shipped behavior.

The existing homepage remains at `/` without a `variant` parameter. Prototype variants are available only in `dev`; production always renders the existing homepage. The prototype branch should stay outside the main development branch after the design decision is recorded.
