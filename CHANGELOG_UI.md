# UI Improvements — Vikoba

Date: 2026-06-19

Summary:
- Introduced modern typography (`Inter`) and subtle background gradients.
- Added improved card shadows, button styles, and entrance animations.
- Added sticky footer with theme toggle (light/dark) persisted via `localStorage`.
- Replaced key icon-fonts on login and contributions pages with inline SVGs for crisper visuals.
- Created a simple SVG favicon and wired it into `includes/header.php`.

Files changed:
- includes/header.php
- includes/footer.php
- public/css/style.css
- public/js/app.js
- public/images/favicon.svg
- pages/member_contributions.php
- index.php

Next steps:
- Replace remaining icon-font `<i>` usages across other pages with SVGs.
- Replace tabler icon font dependency if desired.
- Run a responsive QA sweep and tweak spacing on small screens.
