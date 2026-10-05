# Switch to self-hosted Umami analytics at umami.rum.luka.sh

Supersedes [0002](0002-keep-hosted-umami-analytics-for-v1.md).

0002 kept hosted Umami Cloud because self-hosting was an operational cost that didn't fit the solo-maintainer constraint. That cost no longer applies: a self-hosted Umami instance now runs on the same host (`rum`) as this app, behind proxy-ssl. We switch to it (`https://umami.rum.luka.sh/script.js`), which keeps analytics data on our own infrastructure.

The tracker is configured, not hardcoded: `UMAMI_SCRIPT_URL` and `UMAMI_WEBSITE_ID` are Twig globals rendered by `templates/_analytics.html.twig` (included from `base.html.twig` and `maintenance.html.twig`). When the website ID is empty (local dev, tests, CI) no tracker is emitted, so development traffic never reaches production stats. Production receives both values from repo `vars` via the deploy workflow and `compose.prod.yaml`.

Umami remains cookieless and stores no personal data, so no consent banner is needed. Historical Umami Cloud data is not migrated; the new website starts empty, and the website is removed from Umami Cloud once production records visits in the self-hosted instance. The privacy policy and `docs/third-party-services.md` name the new location (our own server, no longer Umami Cloud).
