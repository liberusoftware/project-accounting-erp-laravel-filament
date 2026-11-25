# Accounting Chart of Accounts Filament

Filament 5 presentation adapter for the chart-of-accounts module. Attach
`ChartOfAccountsFilamentPlugin` to an application panel explicitly.
Create and edit pages delegate to `SaveAccount`; the table archive action
delegates to `ArchiveAccount` so UI mutations retain domain invariants.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/module-accounting-chart-of-accounts`.
- Actual Composer identity: `liberusoftware/module-accounting-chart-of-accounts-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/accounting/filament/chart-of-accounts.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Accounting ERP](https://github.com/liberusoftware/project-accounting-erp-laravel-filament): Ledgers, tax, reporting and finance workflows.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
