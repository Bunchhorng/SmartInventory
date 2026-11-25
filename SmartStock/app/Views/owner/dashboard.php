<section class="page-heading">
    <p class="eyebrow">Owner workspace</p>
    <h1>Welcome, <?= e($user['full_name']) ?></h1>
    <p class="muted">Secure account management, the product catalog, controlled stock receipts, transaction-safe sales, customer credit, and payment allocation are available. Transfers, refunds, and broader financial workflows remain separate modules.</p>
</section>
<section class="dashboard-grid">
    <article class="card">
        <h2>Stock receipts</h2>
        <p>Review valued receipt drafts, confirm one ledger posting, and reverse errors with an audit trail.</p>
        <a class="button" href="?r=inventory-receipts">Stock receipts</a>
    </article>
    <article class="card">
        <h2>Product catalog</h2>
        <p>Manage product categories, configurable units, conversions, barcodes, and selling-price history.</p>
        <a class="button" href="?r=products">Manage products</a>
    </article>
    <article class="card">
        <h2>Sales</h2>
        <p>Confirm sales with locked stock, revenue, and moving-average COGS snapshots.</p>
        <a class="button" href="?r=sales">Sales register</a>
    </article>
    <article class="card">
        <h2>Staff accounts</h2>
        <p>Create accounts with temporary passwords and role-based access.</p>
        <a class="button" href="?r=staff-create">Manage staff</a>
    </article>
    <article class="card">
        <h2>Security status</h2>
        <p>Sessions are recorded server-side. Password changes revoke other signed-in sessions.</p>
        <a class="text-link" href="?r=change-password">Change password</a>
    </article>
    <article class="card card-muted">
        <h2>Next module</h2>
        <p>Refunds/credit notes, returns, transfers, and landed-cost allocation require their own dedicated transaction workflows.</p>
    </article>
</section>
