<section class="page-heading">
    <p class="eyebrow">Staff workspace</p>
    <h1>Welcome, <?= e($user['full_name']) ?></h1>
    <p class="muted">You can view active products, create quantity-only stock receipt drafts, and post authorized sales. Product costs and profit remain Owner-only.</p>
</section>
<section class="dashboard-grid">
    <article class="card">
        <h2>Stock receipts</h2>
        <p>Create a draft receipt for Owner valuation and confirmation. Drafts never change stock.</p>
        <a class="button" href="?r=inventory-receipts">Create stock draft</a>
    </article>
    <article class="card">
        <h2>Product lookup</h2>
        <p>Find active product information and configured selling prices for daily operations.</p>
        <a class="button" href="?r=products">View products</a>
    </article>
    <article class="card">
        <h2>Sales</h2>
        <p>Create and confirm retail or wholesale sales at configured selling prices.</p>
        <a class="button" href="?r=sales">Sales register</a>
    </article>
    <article class="card">
        <h2>Account</h2>
        <p>Keep your credentials private and update your password when needed.</p>
        <a class="text-link" href="?r=change-password">Change password</a>
    </article>
    <article class="card card-muted">
        <h2>Coming next</h2>
        <p>Payment receipt recording is available. Sales returns and operational stock transfers will be added with their own ledger controls.</p>
    </article>
</section>
