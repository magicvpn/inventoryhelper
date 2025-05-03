<?php /* index.php - Displays Inventory */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Stock & Orders</title> <?php /* Updated Title */ ?>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Use Flexbox for vertical alignment, but align items to the start horizontally */
        td.actions {
            display: flex;           /* Enable Flexbox */
            flex-direction: row;     /* Align buttons horizontally */
            align-items: center;     /* Vertically center items in the flex container */
            justify-content: flex-start; /* Horizontally align buttons to the start (left) */
            gap: 5px;                /* Space between buttons */
            /* Remove height: 100% as it might not be needed and can cause issues */
            padding: 4px 2px;        /* Adjust padding as needed */
        }
        td.actions button {
             margin: 0; /* Reset default margins */
             /* Adjust padding if buttons look too tall/short */
             padding-top: 4px;
             padding-bottom: 4px;
        }
        /* Style for stock adjustment controls (keep vertical middle alignment) */
        td.stock-controls-horizontal {
             vertical-align: middle;
             text-align: center; /* Keep horizontal center for this specific cell */
             white-space: nowrap; /* Prevent wrapping */
        }
        td.stock-controls-horizontal button,
        td.stock-controls-horizontal span {
            vertical-align: middle; /* Align buttons and span */
            margin: 0 2px; /* Add slight horizontal spacing */
        }
        /* Ensure other cells maintain default vertical alignment if needed */
        #ordered-inventory-table td,
        #instock-inventory-table td,
        #outofstock-inventory-table td {
             vertical-align: middle; /* Apply middle vertical align to all cells by default */
        }
         /* Override for notes/product if needed */
        #ordered-inventory-table td:nth-child(3), /* Product Name */
        #instock-inventory-table td:nth-child(2), /* Product Name */
        #outofstock-inventory-table td:nth-child(2), /* Product Name */
        #ordered-inventory-table td:nth-child(8), /* Notes */
        #instock-inventory-table td:nth-child(10), /* Notes */
        #outofstock-inventory-table td:nth-child(10) /* Notes */
         {
             text-align: left;
             vertical-align: top; /* Or middle, depending on preference */
             padding-left: 5px; /* Add some padding */
        }


    </style>
</head>
<body id="inventory-page"> <?php /* Add ID for JS detection */ ?>
    <div class="container">
        <header>
            <h1>Inventory Management</h1>
            <nav>
                <a href="index.php" class="active">Inventory/Orders</a>
                <a href="invoices.php">Invoices/Receive Stock</a>
                 <?php /* Optional: Link to manage categories directly */ ?>
                 <?php /* <a href="categories.php">Manage Categories</a> */ ?>
            </nav>
        </header>

        <div class="notification" id="notification" style="display: none;"></div>

        <div class="filter-container">
            <h3>Filter & Search Inventory</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="search-id">Search by ID</label>
                    <input type="text" id="search-id" placeholder="Enter exact ID...">
                </div>
                <div class="form-group">
                    <label for="search">Search Product/Store/Category</label>
                    <input type="text" id="search" placeholder="Enter keyword...">
                </div>
                <div class="form-group">
                    <label for="filter-store">Filter by Store</label>
                    <select id="filter-store">
                        <option value="">All Stores</option>
                        <?php /* Options populated by JS */ ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter-category">Filter by Category</label>
                    <select id="filter-category">
                        <option value="">All Categories</option>
                         <?php /* Options populated by JS */ ?>
                    </select>
                </div>
            </div>
        </div>

        <div id="loading" class="loading" style="display: none;">Loading inventory...</div>

        <?php /* Ordered Items Section */ ?>
        <div class="inventory-section" id="ordered-section" style="display: none;">
            <h2>Ordered Items (Pending Arrival)</h2>
            <table id="ordered-inventory-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Store</th>
                        <th>Product</th>
                        <th>Order Price</th>
                        <th>Amount Ordered</th>
                        <th>Expected Arrival</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <?php /* Actions column removed */ ?>
                    </tr>
                </thead>
                <tbody id="ordered-inventory-body"></tbody>
            </table>
            <div id="no-ordered-items" class="no-items" style="display: none;">No pending orders found matching filters.</div>
        </div>

        <?php /* In Stock Items Section */ ?>
        <div class="inventory-section" id="instock-section" style="display: none;">
            <h2>In Stock Items</h2>
            <table id="instock-inventory-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Avg. Unit Price</th>
                        <th>Current Stock</th>
                        <th>Total Value</th>
                        <th>Last Received</th>
                        <th>Warranty Ending</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="instock-inventory-body"></tbody>
            </table>
            <div id="no-instock-items" class="no-items" style="display: none;">No items currently in stock matching filters.</div>
        </div>

        <?php /* Out of Stock Items Section */ ?>
        <div class="inventory-section" id="outofstock-section" style="display: none;">
            <h2>Out of Stock Items</h2>
            <table id="outofstock-inventory-table">
                <thead>
                     <tr>
                         <th>ID</th>
                         <th>Product</th>
                         <th>Category</th>
                         <th>Avg. Unit Price</th>
                         <th>Current Stock</th>
                         <th>Total Value</th>
                         <th>Last Received</th>
                         <th>Warranty Ending</th>
                         <th>Status</th>
                         <th>Notes</th>
                         <th>Actions</th>
                     </tr>
                </thead>
                <tbody id="outofstock-inventory-body"></tbody>
            </table>
            <div id="no-outofstock-items" class="no-items" style="display: none;">No items currently out of stock matching filters.</div>
        </div>

        <?php /* Edit Item Modal */ ?>
        <div id="edit-item-modal" class="modal" style="display:none;">
            <div class="modal-content">
                <span class="close-modal" onclick="closeModal('edit-item-modal')">&times;</span>
                <h2>Edit Item Details (<span id="edit-modal-product-name"></span>)</h2>
                <form id="edit-item-form" novalidate>
                    <input type="hidden" id="edit-item-id">

                    <div class="form-group">
                        <label for="edit-item-category">Category</label>
                        <select id="edit-item-category" name="category_id" class="category-select">
                            <option value="">-- Select Category --</option>
                             <?php /* Options populated by JS */ ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-item-warranty">Warranty (Years)</label>
                        <input type="number" id="edit-item-warranty" name="warranty_years" step="0.1" min="0" placeholder="e.g., 2, 0.5">
                    </div>

                    <div class="form-group notes-group" style="flex-basis: 100%;"> <?php /* Allow notes to take full width */ ?>
                        <label for="edit-item-notes">Notes</label>
                        <input type="text" id="edit-item-notes" name="notes">
                    </div>

                    <div style="margin-top: 20px;">
                         <button type="submit" id="save-edit-btn">Save Changes</button>
                         <button type="button" class="secondary-btn" onclick="closeModal('edit-item-modal')">Cancel</button>
                    </div>
                </form>
                 <div class="notification modal-notification" id="edit-modal-notification" style="margin-top: 15px; display: none;"></div>
            </div>
        </div>

    </div> <?php /* End container */ ?>
    <script src="app.js"></script>
</body>
</html>
