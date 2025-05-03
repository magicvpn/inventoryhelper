<?php /* invoices.php - Manage Invoices (Receive Stock) */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices / Receive Stock</title>
    <link rel="stylesheet" href="style.css">
    <?php /* Add specific styles for status if needed */ ?>
    <style>
        .status-delivered { color: green; font-weight: bold; }
        .status-pending { color: orange; }
        .deliver-btn { background-color: #28a745; color: white; /* Green */ }
        .deliver-btn:hover { background-color: #218838; }
        .modal-notification { margin-top: 15px; } /* Add margin for modal notifications */
        .notes-group { flex-basis: 100%; } /* Allow notes to take full width */
        .filter-container .form-row { align-items: flex-end; } /* Align filter items better */

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
         /* Ensure other cells maintain default vertical alignment if needed */
         #invoice-list-table td {
             vertical-align: middle; /* Apply middle vertical align to all cells by default */
         }
         /* Override for notes/product if needed */
         #invoice-list-table td:nth-child(2), /* Store */
         #invoice-list-table td:nth-child(8) /* Notes */
         {
             text-align: left;
             vertical-align: top; /* Or middle, depending on preference */
             padding-left: 5px; /* Add some padding */
         }
    </style>
</head>
<body id="invoices-page"> <?php /* Add ID for page detection in JS */ ?>
    <div class="container">
        <header>
            <h1>Inventory Management</h1>
            <nav>
                <a href="index.php">Inventory/Orders</a>
                <a href="invoices.php" class="active">Invoices/Receive Stock</a>
                <?php /* Consider adding a link to categories.php if you create it */ ?>
                <?php /* <a href="categories.php">Manage Categories</a> */ ?>
            </nav>
        </header>

        <?php /* Main notification area */ ?>
        <div class="notification" id="notification" style="display: none;"></div>

        <div class="form-container">
            <h2>Create New Invoice / Receive Stock</h2>
            <form id="invoice-form" novalidate> <?php /* Add novalidate to prevent browser validation interfering with JS validation */ ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="invoice-store">Store Name *</label>
                        <input type="text" id="invoice-store" name="store_name" required>
                    </div>
                    <div class="form-group">
                        <label for="invoice-date">Invoice Date / Received Date *</label>
                        <input type="date" id="invoice-date" name="invoice_date" required>
                    </div>
                    <div class="form-group">
                        <label for="scheduled-delivery-date">Scheduled Delivery Date (Optional)</label>
                        <input type="date" id="scheduled-delivery-date" name="scheduled_delivery_date">
                    </div>
                    <div class="form-group">
                        <label for="original-invoice-id">Original Invoice ID (Optional)</label>
                        <input type="text" id="original-invoice-id" name="original_invoice_id" placeholder="e.g., INV-12345, Order#XYZ">
                    </div>
                </div>
                 <div class="form-row">
                     <div class="form-group notes-group">
                         <label for="invoice-notes">Invoice Notes (Optional)</label>
                         <input type="text" id="invoice-notes" name="notes" placeholder="Any notes about this invoice...">
                     </div>
                 </div>

                <hr>
                <h3>Invoice Items <button type="button" class="secondary-btn" id="manage-categories-btn" style="font-size: 0.9em; padding: 5px 10px; vertical-align: middle;">Manage Categories</button></h3>
                <div id="invoice-items-container">
                    <?php /* Initial invoice item row */ ?>
                    <div class="invoice-item-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="item_product_name[]" required>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="item_category_id[]" class="category-select">
                                <option value="">-- Select Category --</option>
                                <?php /* Options populated by JS */ ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Quantity Received *</label>
                            <input type="number" name="item_quantity[]" min="1" required>
                        </div>
                        <div class="form-group">
                            <label>Total Price Paid (€) *</label>
                            <input type="number" name="item_total_price[]" step="0.0001" min="0" required placeholder="Total cost for this quantity">
                        </div>
                         <div class="form-group">
                             <label>Warranty (Years)</label>
                             <input type="number" name="item_warranty_years[]" step="0.1" min="0" value="2.0" placeholder="e.g., 2, 0.5">
                         </div>
                        <div class="form-group action-group">
                            <label>&nbsp;</label> <?php /* Spacer for alignment */ ?>
                            <button type="button" class="remove-item-btn delete-btn" onclick="removeInvoiceItem(this)" style="visibility: hidden;">Remove</button> <?php /* Hide remove on first row initially */ ?>
                        </div>
                    </div>
                </div>
                 <button type="button" id="add-item-btn" class="secondary-btn">Add Another Item</button>
                 <hr>

                <button type="submit" id="submit-invoice-btn">Save Invoice & Update Stock</button>
            </form>
        </div>

        <div class="filter-container">
            <h3>Filter Past Invoices</h3>
            <div class="form-row">
                 <div class="form-group">
                     <label for="search-invoice-item-id">Search by Item/Batch ID</label>
                     <input type="number" id="search-invoice-item-id" placeholder="Enter exact Item/Batch ID...">
                 </div>
                 <div class="form-group">
                     <label for="filter-invoice-store">Filter by Store</label>
                     <select id="filter-invoice-store">
                         <option value="">All Stores</option>
                         <?php /* Options populated by JS */ ?>
                     </select>
                 </div>
                 <div class="form-group">
                     <label for="filter-invoice-date">Filter by Invoice Date</label>
                     <input type="date" id="filter-invoice-date">
                 </div>
                 <div class="form-group">
                      <?php /* Button moved here for better alignment */ ?>
                     <button type="button" id="clear-invoice-date-filter" class="secondary-btn" style="padding: 5px 10px; font-size: 0.9em;">Clear Date</button>
                 </div>
            </div>
        </div>

        <div class="inventory-section" id="invoice-list-section">
            <h2>Past Invoices</h2>
            <div id="loading-invoices" class="loading" style="display: none;">Loading invoices...</div>
            <table id="invoice-list-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Store</th>
                        <th>Invoice Date</th>
                        <th>Delivery Date</th>
                        <th>Original Inv. ID</th>
                        <th>Items</th>
                        <th>Total Value</th>
                        <th>Notes</th>
                        <th>Status</th> <?php /* Renamed from Viewed On */ ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="invoice-list-body">
                    <?php /* Invoice rows populated by JS */ ?>
                </tbody>
            </table>
             <div id="no-invoices" class="no-items" style="display: none;">No invoices found matching filters.</div>
         </div>

         <?php /* Invoice Detail Modal */ ?>
         <div id="invoice-detail-modal" class="modal" style="display:none;">
             <div class="modal-content large"> <?php /* Consider adding 'large' class for more space */ ?>
                 <span class="close-modal" onclick="closeModal('invoice-detail-modal')">&times;</span>
                 <h2>Invoice Details (<span id="modal-invoice-id"></span>)</h2>
                 <p><strong>Store:</strong> <span id="modal-store-name"></span></p>
                 <p><strong>Invoice Date:</strong> <span id="modal-invoice-date"></span></p>
                 <p><strong>Scheduled Delivery Date:</strong> <span id="modal-delivery-date"></span></p>
                 <p><strong>Original Invoice ID:</strong> <span id="modal-original-invoice-id"></span></p>
                 <p><strong>Notes:</strong> <span id="modal-invoice-notes"></span></p>
                 <p><strong>Status:</strong> <span id="modal-invoice-status"></span></p> <?php /* Added Status */ ?>
                 <h3>Items:</h3>
                 <table id="modal-items-table">
                     <thead>
                         <tr>
                             <th>Product</th>
                             <th>Category</th>
                             <th>Qty</th>
                             <th>Unit Price (€)</th>
                             <th>Total Price (€)</th>
                             <th>Warranty (Yrs)</th>
                             <th>Warranty End Date</th>
                             <th>Batch ID</th>
                         </tr>
                     </thead>
                     <tbody id="modal-items-body"></tbody>
                 </table>
                 <?php /* No notification area needed here, use main one or JS alerts */ ?>
             </div>
         </div>

         <?php /* Category Management Modal */ ?>
         <div id="category-management-modal" class="modal" style="display:none;">
             <div class="modal-content">
                  <span class="close-modal" onclick="closeModal('category-management-modal')">&times;</span>
                 <h2>Manage Categories</h2>
                 <div class="notification modal-notification" id="category-modal-notification" style="display: none;"></div> <?php /* Notification area specific to this modal */ ?>
                 <div class="loading" id="loading-categories" style="display: none;">Loading categories...</div>
                 <ul id="category-list">
                     <?php /* Categories populated by JS */ ?>
                 </ul>
                 <p id="no-categories" class="no-items" style="display: none;">No categories defined.</p>

                 <form id="add-category-form" novalidate>
                     <h3>Add New Category</h3>
                     <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" id="new-category-name" placeholder="Enter category name" required style="flex-grow: 1;">
                        <button type="submit" class="primary-btn">Add Category</button>
                     </div>
                 </form>
             </div>
         </div>

    </div> <?php /* End container */ ?>

    <script src="app.js"></script>
</body>
</html>
