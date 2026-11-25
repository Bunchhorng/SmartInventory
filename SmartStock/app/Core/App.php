<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\InventoryReceipt;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\InventoryCost;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnCorrection;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierAdjustment;
use App\Models\SupplierCreditReturnCorrection;
use App\Models\Payable;
use App\Models\Unit;
use App\Models\User;
use App\Models\ValuationPreflightReport;
use App\Services\AuthService;
use App\Services\InventoryService;
use App\Services\GoodsReceivingService;
use App\Services\MovingAverageCostService;
use App\Services\PaymentService;
use App\Services\CreditNoteService;
use App\Services\ProductDomainService;
use App\Services\SalesService;
use App\Services\SalesReturnService;
use App\Services\SupplierService;
use App\Services\PurchasingService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseReturnCorrectionService;
use App\Services\SupplierInvoiceService;
use App\Services\AccountsPayableService;
use App\Services\SupplierPaymentService;
use App\Services\SupplierAdjustmentService;
use App\Services\SupplierPayableBalanceService;
use App\Services\SupplierCreditReturnCorrectionService;
use App\Services\StockTransferService;
use PDO;

final class App
{
    private User $users;
    private AuditLog $auditLog;
    private AuthService $auth;
    private Category $categories;
    private Customer $customers;
    private Unit $units;
    private Product $products;
    private ProductDomainService $productDomain;
    private InventoryReceipt $inventoryReceipts;
    private InventoryCost $inventoryCosts;
    private MovingAverageCostService $movingAverageCosts;
    private Sale $sales;
    private SaleReturn $saleReturns;
    private Payment $payments;
    private CreditNote $creditNotes;
    private Supplier $suppliers;
    private PurchaseOrder $purchaseOrders;
    private ValuationPreflightReport $valuationPreflight;
    private SalesService $salesService;
    private SalesReturnService $salesReturnService;
    private PaymentService $paymentService;
    private CreditNoteService $creditNoteService;
    private SupplierService $supplierService;
    private PurchasingService $purchasingService;
    private InventoryService $inventory;
    private GoodsReceipt $goodsReceipts;
    private GoodsReceivingService $goodsReceiving;
    private SupplierInvoice $supplierInvoices;
    private Payable $payables;
    private AccountsPayableService $accountsPayable;
    private SupplierInvoiceService $supplierInvoiceService;
    private SupplierPayment $supplierPayments;
    private SupplierPaymentService $supplierPaymentService;
    private SupplierAdjustment $supplierAdjustments;
    private SupplierCreditReturnCorrection $supplierCreditReturnCorrections;
    private SupplierPayableBalanceService $supplierPayableBalances;
    private SupplierAdjustmentService $supplierAdjustmentService;
    private PurchaseReturn $purchaseReturns;
    private PurchaseReturnService $purchaseReturnService;
    private PurchaseReturnCorrection $purchaseReturnCorrections;
    private PurchaseReturnCorrectionService $purchaseReturnCorrectionService;
    private SupplierCreditReturnCorrectionService $supplierCreditReturnCorrectionService;
    private StockTransfer $stockTransfers;
    private StockTransferService $stockTransferService;

    /** @param array<string, mixed> $config */
    public function __construct(private PDO $pdo, private array $config)
    {
        $this->users = new User($pdo);
        $this->auditLog = new AuditLog($pdo);
        $this->auth = new AuthService($pdo, $this->users, $this->auditLog, $config);
        $this->categories = new Category($pdo);
        $this->customers = new Customer($pdo);
        $this->units = new Unit($pdo);
        $this->products = new Product($pdo);
        $this->productDomain = new ProductDomainService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->categories,
            $this->units,
            $this->products,
        );
        $this->inventoryReceipts = new InventoryReceipt($pdo);
        $this->inventoryCosts = new InventoryCost($pdo);
        $this->movingAverageCosts = new MovingAverageCostService($this->inventoryCosts);
        $this->inventory = new InventoryService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->inventoryReceipts,
            $this->inventoryCosts,
            $this->movingAverageCosts,
        );
        $this->sales = new Sale($pdo);
        $this->saleReturns = new SaleReturn($pdo);
        $this->payments = new Payment($pdo);
        $this->creditNotes = new CreditNote($pdo);
        $this->suppliers = new Supplier($pdo);
        $this->purchaseOrders = new PurchaseOrder($pdo);
        $this->valuationPreflight = new ValuationPreflightReport($pdo);
        $this->salesService = new SalesService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->customers,
            $this->payments,
            $this->sales,
            $this->inventoryReceipts,
            $this->movingAverageCosts,
            $this->valuationPreflight,
        );
        $this->paymentService = new PaymentService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->customers,
            $this->payments,
        );
        $this->creditNoteService = new CreditNoteService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->sales,
            $this->payments,
            $this->creditNotes,
        );
        $this->salesReturnService = new SalesReturnService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->sales,
            $this->creditNotes,
            $this->saleReturns,
            $this->inventoryReceipts,
            $this->movingAverageCosts,
        );
        $this->supplierService = new SupplierService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->suppliers,
        );
        $this->purchasingService = new PurchasingService(
            $pdo,
            $this->users,
            $this->auditLog,
            $this->suppliers,
            $this->products,
            $this->purchaseOrders,
        );
        $this->goodsReceipts = new GoodsReceipt($pdo);
        $this->goodsReceiving = new GoodsReceivingService(
            $pdo, $this->users, $this->auditLog, $this->goodsReceipts,
            $this->purchaseOrders, $this->inventoryReceipts, $this->inventory,
        );
        $this->supplierInvoices = new SupplierInvoice($pdo);
        $this->payables = new Payable($pdo);
        $this->supplierAdjustments = new SupplierAdjustment($pdo);
        $this->supplierCreditReturnCorrections = new SupplierCreditReturnCorrection($pdo);
        $this->supplierPayableBalances = new SupplierPayableBalanceService($this->payables, $this->supplierAdjustments, $this->supplierCreditReturnCorrections);
        $this->accountsPayable = new AccountsPayableService($pdo, $this->users, $this->payables, $this->supplierPayableBalances);
        $this->supplierInvoiceService = new SupplierInvoiceService(
            $pdo, $this->users, $this->auditLog, $this->supplierInvoices, $this->purchaseOrders,
            $this->goodsReceipts, $this->suppliers, $this->payables, $this->accountsPayable,
        );
        $this->supplierPayments = new SupplierPayment($pdo);
        $this->supplierPaymentService = new SupplierPaymentService($pdo, $this->users, $this->auditLog,
            $this->suppliers, $this->supplierInvoices, $this->payables, $this->payments, $this->supplierPayments, $this->accountsPayable, $this->supplierPayableBalances);
        $this->supplierAdjustmentService = new SupplierAdjustmentService($pdo, $this->users, $this->auditLog, $this->suppliers,
            $this->supplierInvoices, $this->payables, $this->payments, $this->supplierPayments, $this->supplierAdjustments, $this->supplierPayableBalances, $this->supplierPaymentService, $this->supplierInvoiceService);
        $this->purchaseReturns = new PurchaseReturn($pdo);
        $this->purchaseReturnService = new PurchaseReturnService($pdo,$this->users,$this->auditLog,$this->purchaseReturns,
            $this->purchaseOrders,$this->goodsReceipts,$this->inventoryReceipts,$this->inventory,$this->goodsReceiving,
            $this->suppliers,$this->supplierInvoices,$this->payables,$this->supplierAdjustments,$this->supplierAdjustmentService);
        $this->purchaseReturnCorrections = new PurchaseReturnCorrection($pdo);
        $this->purchaseReturnCorrectionService = new PurchaseReturnCorrectionService($pdo,$this->users,$this->auditLog,
            $this->purchaseReturns,$this->purchaseReturnCorrections,$this->inventory,$this->supplierCreditReturnCorrections);
        $this->supplierCreditReturnCorrectionService = new SupplierCreditReturnCorrectionService($pdo,$this->users,$this->auditLog,
            $this->suppliers,$this->purchaseReturns,$this->supplierAdjustments,$this->supplierAdjustmentService,$this->payables,
            $this->supplierPayableBalances,$this->supplierCreditReturnCorrections);
        $this->stockTransfers = new StockTransfer($pdo);
        $this->stockTransferService = new StockTransferService(
            $pdo, $this->users, $this->auditLog, $this->stockTransfers, $this->inventoryReceipts, $this->inventory,
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function users(): User
    {
        return $this->users;
    }

    public function auditLog(): AuditLog
    {
        return $this->auditLog;
    }

    public function auth(): AuthService
    {
        return $this->auth;
    }

    public function categories(): Category
    {
        return $this->categories;
    }

    public function customers(): Customer
    {
        return $this->customers;
    }

    public function units(): Unit
    {
        return $this->units;
    }

    public function products(): Product
    {
        return $this->products;
    }

    public function productDomain(): ProductDomainService
    {
        return $this->productDomain;
    }

    public function inventoryReceipts(): InventoryReceipt
    {
        return $this->inventoryReceipts;
    }

    public function inventoryCosts(): InventoryCost
    {
        return $this->inventoryCosts;
    }

    public function movingAverageCosts(): MovingAverageCostService
    {
        return $this->movingAverageCosts;
    }

    public function sales(): Sale
    {
        return $this->sales;
    }

    public function saleReturns(): SaleReturn
    {
        return $this->saleReturns;
    }

    public function payments(): Payment
    {
        return $this->payments;
    }

    public function creditNotes(): CreditNote
    {
        return $this->creditNotes;
    }

    public function suppliers(): Supplier
    {
        return $this->suppliers;
    }

    public function purchaseOrders(): PurchaseOrder
    {
        return $this->purchaseOrders;
    }

    public function salesService(): SalesService
    {
        return $this->salesService;
    }

    public function salesReturnService(): SalesReturnService
    {
        return $this->salesReturnService;
    }

    public function paymentService(): PaymentService
    {
        return $this->paymentService;
    }

    public function creditNoteService(): CreditNoteService
    {
        return $this->creditNoteService;
    }

    public function supplierService(): SupplierService
    {
        return $this->supplierService;
    }

    public function purchasingService(): PurchasingService
    {
        return $this->purchasingService;
    }

    public function valuationPreflight(): ValuationPreflightReport
    {
        return $this->valuationPreflight;
    }

    public function inventory(): InventoryService
    {
        return $this->inventory;
    }

    public function goodsReceipts(): GoodsReceipt { return $this->goodsReceipts; }

    public function goodsReceiving(): GoodsReceivingService { return $this->goodsReceiving; }

    public function supplierInvoices(): SupplierInvoice { return $this->supplierInvoices; }
    public function payables(): Payable { return $this->payables; }
    public function accountsPayable(): AccountsPayableService { return $this->accountsPayable; }
    public function supplierInvoiceService(): SupplierInvoiceService { return $this->supplierInvoiceService; }
    public function supplierPayments(): SupplierPayment { return $this->supplierPayments; }
    public function supplierPaymentService(): SupplierPaymentService { return $this->supplierPaymentService; }
    public function supplierAdjustments(): SupplierAdjustment { return $this->supplierAdjustments; }
    public function supplierCreditReturnCorrections(): SupplierCreditReturnCorrection { return $this->supplierCreditReturnCorrections; }
    public function supplierPayableBalances(): SupplierPayableBalanceService { return $this->supplierPayableBalances; }
    public function supplierAdjustmentService(): SupplierAdjustmentService { return $this->supplierAdjustmentService; }
    public function purchaseReturns(): PurchaseReturn { return $this->purchaseReturns; }
    public function purchaseReturnService(): PurchaseReturnService { return $this->purchaseReturnService; }
    public function purchaseReturnCorrections(): PurchaseReturnCorrection { return $this->purchaseReturnCorrections; }
    public function purchaseReturnCorrectionService(): PurchaseReturnCorrectionService { return $this->purchaseReturnCorrectionService; }
    public function supplierCreditReturnCorrectionService(): SupplierCreditReturnCorrectionService { return $this->supplierCreditReturnCorrectionService; }
    public function stockTransfers(): StockTransfer { return $this->stockTransfers; }
    public function stockTransferService(): StockTransferService { return $this->stockTransferService; }
}
