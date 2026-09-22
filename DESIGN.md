# PaySubscriptions — Design Decisions & UI Architecture

Status: **Approved and Implemented** (Branch: `feature/ui-redesign`, 2026-09-22).  
This document records the design system, page architecture, and user experience decisions implemented across PaySubscriptions' public pages and application dashboard.

---

## 1. Core Product & Design Principles

1. **Privacy-First Clarity**:
   - Zero bank connections, no card requirements, and no inbox scraping.
   - Every public touchpoint explicitly reassures visitors that their financial data remains entirely their own.
2. **Honest, Actionable Numbers**:
   - Recurring costs are normalized into side-by-side **monthly** and **yearly** equivalents.
   - No hidden tiers, arbitrary upsells, or fake "Pro" upgrades. The free tier scope is stated up-front.
3. **Clarity Over Ornamentation**:
   - Transitioned away from casual hand-drawn elements and heavy decorative illustrations toward a clean, professional, high-trust SaaS aesthetic.
   - Content and layout prioritize instant scanning: benefit first, proof second, action third.
4. **Frictionless Path to Value**:
   - Clear visual hierarchy with a single dominant primary CTA ("Create your free account" / "Go to your dashboard").
   - Reassurance micro-copy immediately adjacent to action points (no credit card, free up to the subscription limit, 100% private).

---

## 2. Design System & Typography

### Typography
- **Primary Interface Font**: `Plus Jakarta Sans` (`--font-sans`), loaded via Google Fonts. Used for all headings, body text, form elements, and data figures to ensure modern readability across devices.
- **Brand / Accent Typography**: `Patrick Hand` (`--font-handwriting`) retained strictly for optional brand accents or legacy highlights, removed from primary UI copy and tables.
- **Base Typography Configuration**: Configured with `@tailwindcss/typography` (`prose-slate`) for markdown-rendered documentation and article views.

### Color Palette & Tokens
- **Background Base**: `--color-color-pri: #f8fafc` (Slate 50) — clean, soft off-white providing high contrast with content cards.
- **Text & Structure**: `--color-color-qua: #1e293b` (Slate 800/900) — deep slate for sharp headings and readable typography.
- **Primary Brand Accent**: `--color-color-ter: #577399` (Steel Blue) — used for brand highlights, secondary badges, active indicators, and icons.
- **High-Conversion CTA**: `--color-color-qui: #fe5f55` (Coral Red) — high-visibility accent reserved for primary action buttons (`.btn-cta`), active tabs, and key notifications.
- **Borders & Dividers**: `--color-color-sec: #e2e8f0` (Slate 200) — subtle structural boundaries.
- **Surface Elevation**: Elevated pure white cards (`bg-white`), rounded pill containers (`rounded-full`), and generous card radiuses (`rounded-2xl`, `rounded-3xl`) with gentle ambient gradients (`blur-3xl`).

### Button Hierarchy
- **`.btn-cta`**: Primary conversion trigger. Coral red background, bold text, pill shape (`rounded-full`), subtle elevation shadow (`shadow-md shadow-red-200`), hover lift, and active tactile scale.
- **`.btn-primary`**: Standard primary action. Steel blue background (`#577399`), pill shape, subtle shadow, and active press animation.
- **`.btn-secondary`**: Clean white surface with slate border (`border-slate-300`), dark slate text, pill shape, used for alternative paths and cancellations.
- **`.btn-delete`**: Rose red pill button for destructive actions with distinct confirmation styling.

---

## 3. Public Pages Architecture

### 3.1. Home (`templates/home/index.html.twig`)
- **Above-the-Fold Hero Section**:
  - Clear value proposition headline: *"See what your subscriptions really add up to."*
  - Reassurance pill banner highlighting the limit sourced dynamically from `App\Entity\Limits::DEFAULT_SUBSCRIPTIONS_LIMIT` (30).
  - Dominant CTA block with direct registration link (or dashboard shortcut for authenticated users) and secondary *"See how it works"* anchor.
  - Reassurance badges: *No credit card required*, *Up to 30 subscriptions*, *100% private*.
- **Realistic App UI Mockup**:
  - Replaces abstract financial illustrations with a realistic PaySubscriptions dashboard preview.
  - Showcases dual-metric summary cards (Monthly Total and Yearly Equivalent) and sample subscriptions (Netflix, Spotify, iCloud+, Developer Pack) with normalized annual breakdowns.
  - Explicitly labels sample data to set accurate user expectations.
- **Key Value Pillars (3 Cards)**:
  - *One Honest Total*: Side-by-side monthly and yearly cost totals.
  - *100% Privacy First*: Reassurance regarding no bank access, credentials, or tracking.
  - *Visual Breakdown*: Interactive charts to spot budget leaks.
- **3-Step Workflow ("How It Works")**:
  - Numbered walkthrough explaining the core flow: (1) Add your services, (2) Compare & Calculate, (3) Take control and review renewals.

### 3.2. Pricing (`templates/pricing/index.html.twig`)
- **Single Transparent Offer**:
  - Avoids mock multi-tier enterprise models. Features a single, focused **"Free Plan"** card ($0 / free forever) for individuals and households.
  - Clear list of included capabilities: tracking up to the dynamic account limit, monthly/yearly equivalents, interactive charts, and zero bank requirements.
- **Integrated FAQ Grid**:
  - Two-column responsive FAQ covering core objections: card requirements, bank credentials, subscription limits, and project sustainability.
- **Support Transparency**:
  - Any future donation or voluntary support is strictly differentiated from core account capabilities.

### 3.3. About & Product Transparency (`templates/docs/index.html.twig`)
- **Trust-Building Layout**:
  - Replaced the rigid documentation sidebar layout with a clean, centered narrative card.
- **Trust Badges Bar**:
  - Visual summary highlighting *Zero Bank Access*, *Calculated Totals*, and *Private & Free*.
- **Clear Roadmap Demarcation**:
  - Delineates features available today (cost tracking, manual entry, monthly/yearly equivalents) from exploratory features without misleading dates or false commitments.
- **Creator Contact & Feedback**:
  - Direct contact CTA linking to the maintainer for feedback, suggestions, and feature discussions.

### 3.4. Global Header & Footer (`templates/partials/`)
- **Sticky Navigation**:
  - Frosted glass effect (`backdrop-blur-md`, `bg-color-pri/95`), responsive mobile slide-out menu, and crisp logo dimensions (`36px` height).
  - Distinct auth button hierarchy: subtle text link for "Sign In", prominent dark button for "Register".
- **Structured Footer**:
  - Brand overview with semantic version stamp (`appVersion`).
  - Clear site directory navigation links.
  - Product updates newsletter form with transparent disclaimer regarding the shared `gprodb.com` mailing list.
  - Bottom bar with copyright and privacy/contact links.

---

## 4. Dashboard & Interactive Experience

### 4.1. KPI Summary Cards
- **Monthly Overview**: Primary calculated monthly total combining direct monthly bills and normalized yearly subscriptions.
- **Yearly Overview**: Primary calculated yearly total combining direct annual bills and normalized monthly subscriptions.
- **Subscription Limit Card**: Visual capacity progress bar displaying currently used slots vs. maximum allowed limit, with colored status indicators (green / amber / red) and remaining capacity badge.

### 4.2. Interactive Charting & Legend Drawer
- **Chart View Switching**: Seamless toggle between monthly breakdown, yearly view, and expense categories via `ChartSwitcher`.
- **Sliding Legend Panel (`chart_legend_controller.js`)**:
  - Stimulus-powered slide-out drawer providing dataset visibility toggling.
  - Dataset search filter for quick lookup across subscriptions.
  - Quick action buttons ("Show All", "Hide All").
  - Live count indicator of visible vs. total datasets.
  - Accessible keyboard control (Escape key dismisses panel).

---

## 5. Technical & Accessibility Standards

1. **HTML & Metadata Standards**:
   - `base.html.twig` includes `lang="{{ app.request.locale|default('en') }}"` and responsive viewport meta tag.
   - Dynamic meta descriptions and page titles configured across all routes for SEO and accessibility.
2. **Responsive Layout**:
   - Multi-breakpoint grid layouts (mobile, tablet, desktop) ensuring no awkward line wrapping or clipped cards on narrow screens.
3. **Interactive Elements**:
   - Explicit focus styles (`focus:ring-2`), accessible button contrast ratios, and tactile active states across buttons and inputs.
