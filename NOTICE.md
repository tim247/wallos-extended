# Notice

**Wallos Extended** is a modified version of [Wallos](https://github.com/ellite/Wallos),
the open-source personal subscription tracker by [henrique.pt](https://henrique.pt) and the
Wallos contributors.

- Original work: Wallos, © the Wallos authors, licensed under the
  [GNU General Public License v3.0](LICENSE.md).
- This modified work is licensed under the same GNU General Public License v3.0.
  You may use, change and redistribute it under the terms of that license. It comes
  **without any warranty**.
- Wallos Extended is an independent project. It is **not affiliated with, endorsed by or
  supported by** the original Wallos project. Please report problems with Wallos Extended
  here, not upstream.

## Modifications (GPL-3.0 section 5a)

Based on Wallos **v5.11.1**. Changes made in Wallos Extended (2026):

- New modules: contracts, insurances, financing (annuity and interest-only loans,
  Bausparvertrag, bank statement reconciliation, loan redemption), consumption / meter
  readings (incl. PV and Home Assistant import), yearly due overview with monthly reserve.
- Cancellation letters as PDF with status tracking and an admin template.
- Paperless-ngx document links and suggestions.
- Household dashboard and statistics across all areas, calendar and iCal events for all
  areas, archive and price history for subscriptions.
- Restore of raw `wallos.db` files from older versions, help page, German translations of
  the default categories.
- Renamed to "Wallos Extended" with a modified logo; database migrations of upstream
  v5.x renumbered to `000101`+.
- Docker image and Proxmox LXC installer for this fork.

The full list of changes is in the Git history of this repository.

Third-party components keep their own licenses (see `libs/` and `scripts/libs/`, e.g.
PHPMailer, Chart.js, ApexCharts, Sortable). Icons by [icons8](https://icons8.com/).
