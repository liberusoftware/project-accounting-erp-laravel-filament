<?php

// Preserve queued jobs and serialized references created before modular extraction.
$aliases = [
    'App\\Http\\Controllers\\Controller' => 'Liberu\\Accounting\\ErpHttp\\Http\\Controllers\\Controller',
    'App\\Http\\Controllers\\PortalAccessController' => 'Liberu\\Accounting\\ErpHttp\\Http\\Controllers\\PortalAccessController',
    'App\\Http\\Controllers\\SaasPremiumBillingController' => 'Liberu\\Accounting\\ErpHttp\\Http\\Controllers\\SaasPremiumBillingController',
    'App\\Http\\Resources\\TransactionResource' => 'Liberu\\Accounting\\BankAccountsApi\\Http\\Resources\\TransactionResource',
    'App\\Http\\Controllers\\Api\\BillController' => 'Liberu\\Accounting\\SupplierBillsApi\\Http\\Controllers\\BillController',
    'App\\Http\\Controllers\\Api\\ChartOfAccountController' => 'Liberu\\Accounting\\ChartOfAccountsApi\\Http\\Controllers\\ChartOfAccountController',
    'App\\Http\\Controllers\\Api\\EstimateController' => 'Liberu\\Accounting\\EstimatesAndQuotesApi\\Http\\Controllers\\EstimateController',
    'App\\Http\\Controllers\\Api\\GeneralLedgerController' => 'Liberu\\Accounting\\GeneralLedgerApi\\Http\\Controllers\\ErpGeneralLedgerController',
    'App\\Http\\Controllers\\Api\\InvoiceController' => 'Liberu\\Accounting\\SalesInvoicingApi\\Http\\Controllers\\InvoiceController',
    'App\\Http\\Controllers\\Api\\JournalEntryController' => 'Liberu\\Accounting\\GeneralLedgerApi\\Http\\Controllers\\JournalEntryController',
    'App\\Http\\Controllers\\Api\\OpenApiController' => 'Liberu\\Accounting\\ErpHttp\\Http\\Controllers\\Api\\OpenApiController',
    'App\\Http\\Controllers\\Api\\PlaidController' => 'Liberu\\Accounting\\Plaid\\Http\\Controllers\\PlaidController',
    'App\\Http\\Controllers\\Api\\PlaidWebhookController' => 'Liberu\\Accounting\\Plaid\\Http\\Controllers\\PlaidWebhookController',
    'App\\Http\\Controllers\\Api\\QboController' => 'Liberu\\Accounting\\QuickBooksOnlineMigrationApi\\Http\\Controllers\\QboController',
    'App\\Http\\Controllers\\Api\\QboWebhookController' => 'Liberu\\Accounting\\QuickBooksOnlineMigrationApi\\Http\\Controllers\\QboWebhookController',
    'App\\Http\\Controllers\\Api\\RevolutController' => 'Liberu\\Accounting\\Revolut\\Http\\Controllers\\RevolutController',
    'App\\Http\\Controllers\\Api\\RevolutWebhookController' => 'Liberu\\Accounting\\Revolut\\Http\\Controllers\\RevolutWebhookController',
    'App\\Http\\Controllers\\Api\\SageController' => 'Liberu\\Accounting\\SageAccountingMigrationApi\\Http\\Controllers\\SageController',
    'App\\Http\\Controllers\\Api\\TransactionController' => 'Liberu\\Accounting\\BankAccountsApi\\Http\\Controllers\\TransactionController',
    'App\\Http\\Controllers\\Api\\WiseController' => 'Liberu\\Accounting\\Wise\\Http\\Controllers\\WiseController',
    'App\\Http\\Controllers\\Api\\WiseWebhookController' => 'Liberu\\Accounting\\Wise\\Http\\Controllers\\WiseWebhookController',
    'App\\Http\\Controllers\\Api\\XeroController' => 'Liberu\\Accounting\\XeroMigrationApi\\Http\\Controllers\\XeroController',
    'App\\Http\\Controllers\\Api\\XeroWebhookController' => 'Liberu\\Accounting\\XeroMigrationApi\\Http\\Controllers\\XeroWebhookController',
];

spl_autoload_register(static function (string $class) use ($aliases): void {
    if (isset($aliases[$class])) {
        class_alias($aliases[$class], $class);
    }
});
