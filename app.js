// app.js - JavaScript for Inventory and Invoice Management and Categories

// --- Global Variables & Settings ---
const API_BASE_URL = 'api.php'; // Adjust if your API is elsewhere
let inventoryCache = [];
let invoiceCache = [];
let categoriesCache = []; // Cache for categories
let isLoadingInventory = false;
let isLoadingInvoices = false;
let isLoadingCategories = false; // Loading flag for categories

// --- Utility Functions ---

// Show notification message (can specify container)
function showNotification(message, type = 'info', containerId = 'notification') {
    const notification = document.getElementById(containerId);
    if (!notification) {
        const mainNotification = document.getElementById('notification');
        if (mainNotification) { showNotification(message, type, 'notification'); }
        else { console.warn("Notification container #" + containerId + " not found."); }
        return;
    }
    // Check if the same message is already displayed to avoid flooding
    if (notification.textContent === message && notification.style.display === 'block') return;

    notification.textContent = message;
    notification.className = 'notification ' + type; // Apply type for styling (e.g., 'info', 'success', 'warning', 'error')
    notification.style.display = 'block';

    // Scroll to the main notification if it's being used
    if (containerId === 'notification') notification.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Hide non-error notifications automatically after 5 seconds
    // Keep error notifications visible until manually dismissed or replaced
    if (!type.includes('error')) {
        setTimeout(() => {
            // Only hide if the message hasn't changed
            if (notification.textContent === message && notification.style.display === 'block') {
                 notification.style.display = 'none';
            }
        }, 5000);
    }
}

// Format currency (Euro)
function formatCurrency(value) {
    const number = parseFloat(value);
    // Check if the number is valid, otherwise return a default
    return isNaN(number) ? '€0.00' : '€' + number.toFixed(2);
}

// Format Date (YYYY-MM-DD to locale string)
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        // Attempt to parse the date string. Adding 'T00:00:00Z' ensures it's treated as UTC to avoid timezone issues
        const date = new Date(dateString + 'T00:00:00Z');
        // Check if the date is valid
        return isNaN(date.getTime()) ? dateString : date.toLocaleDateString();
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString; // Return original string on error
    }
}

// Calculate Warranty End Date based on last received date and warranty years
function calculateWarrantyEndDate(lastReceivedDateString, warrantyYears) {
    // Validate inputs
    if (!lastReceivedDateString || warrantyYears == null || isNaN(parseFloat(warrantyYears)) || parseFloat(warrantyYears) < 0) return 'N/A';

    try {
        // Parse the received date, treating it as UTC
        const receivedDate = new Date(lastReceivedDateString + 'T00:00:00Z');
        if (isNaN(receivedDate.getTime())) return 'Invalid Received Date';

        const years = parseFloat(warrantyYears);
        // Calculate total months, rounding to the nearest whole month
        const totalMonths = Math.round(years * 12);

        // Create a new date object and add the calculated months
        const endDate = new Date(receivedDate);
        endDate.setUTCMonth(receivedDate.getUTCMonth() + totalMonths);

        return endDate.toLocaleDateString();
    } catch (e) {
        console.error("Error calculating warranty end date:", e);
        return 'Calculation Error'; // Return error message on calculation failure
    }
}

// Format Batch ID (which is the invoice_item id)
function formatBatchId(batchId) {
    // Return '-' if batchId is null or undefined, otherwise return the string representation
    return batchId == null ? '-' : String(batchId);
}


// --- API Call Functions ---
async function apiCall(endpoint, method = 'GET', data = null) {
    const url = `${API_BASE_URL}/${endpoint}`;
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json' // Request JSON response
        }
    };

    // Include body for methods that require data
    if (data && (method === 'POST' || method === 'PUT' || method === 'DELETE')) {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(url, options);
        let responseData = { status: 'error', message: `Request failed with status ${response.status}` }; // Default error response

        // Check content type before attempting to parse JSON
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.includes("application/json")) {
            try {
                responseData = await response.json();
            } catch (e) {
                // Handle JSON parsing errors, especially for non-2xx responses that might still return non-JSON error details
                console.error(`JSON Parse Error ${method} ${endpoint}:`, e);
                 const errorText = await response.text().catch(() => `Status ${response.status}`); // Try to get text response
                 // Throw a more informative error including the partial/invalid response
                 throw new Error(`Server error ${response.status}: Invalid JSON response. Response text: ${errorText.substring(0, 200)}...`);
            }
        } else {
            // Handle non-JSON responses (e.g., plain text errors, 204 No Content)
            const responseText = await response.text();
            if (!response.ok) {
                console.error(`Server error response (${response.status}):`, responseText);
                throw new Error(`Server error ${response.status}: ${responseText.substring(0, 200)}`);
            }
            // Special case for 204 No Content
            if (response.status === 204) return { status: 'success', message: 'Action completed (No Content)' };
            // Log a warning for unexpected non-JSON responses
            console.warn(`Unexpected non-JSON response (${method} ${endpoint}):`, responseText);
            // Decide how to handle unexpected success responses (e.g., treat as success or error)
            // Returning success might mask issues if JSON was expected.
             return { status: 'success', message: 'Action completed (Unexpected format).' };
             // Or throw new Error(`Unexpected non-JSON response received.`);
        }

        // Check for HTTP errors (status outside 2xx range) - This might be redundant if response.ok check is done above
        if (!response.ok) {
            throw new Error(responseData.message || `HTTP error ${response.status}`);
        }

        // Check for API-specific status if the response is JSON
        if (responseData.status && responseData.status !== 'success') {
            throw new Error(responseData.message || `API failure: ${responseData.status}`);
        }

        return responseData; // Return successful response data
    } catch (error) {
        console.error(`API call failed: ${method} ${endpoint}`, error);
        // Re-throw the error for calling functions to handle and potentially display
        // Don't show notification here, let the calling function decide
        throw error;
    }
}

// --- Inventory Specific Functions (mostly for index.php) ---

// Load inventory data from the API
async function loadInventory() {
    if (isLoadingInventory) return; // Prevent multiple simultaneous loads
    isLoadingInventory = true;

    // Show loading indicator and hide sections
    const loadingDiv = document.getElementById('loading');
    if (loadingDiv) loadingDiv.style.display = 'block';
    const orderedSection = document.getElementById('ordered-section');
    const instockSection = document.getElementById('instock-section');
    const outofstockSection = document.getElementById('outofstock-section'); // Get new section
    if (orderedSection) orderedSection.style.display = 'none';
    if (instockSection) instockSection.style.display = 'none';
    if (outofstockSection) outofstockSection.style.display = 'none'; // Hide new section initially

    try {
        const response = await apiCall('inventory');
        if (response.status === 'success') {
            inventoryCache = response.data || []; // Cache the data
            // console.log("Inventory data loaded:", inventoryCache); // DEBUG: Log loaded data
            renderInventory(inventoryCache); // Render the tables
        } else {
            // Show error and clear data/filters on failure
            showNotification(response.message || 'Failed to load inventory.', 'error');
            inventoryCache = [];
            renderInventory([]);
            updateStoreFilter([]);
            updateCategoryFilter([]);
        }
    } catch (error) {
        // Show error and clear data/filters on API call error
        showNotification(`Error loading inventory: ${error.message}`, 'error');
        inventoryCache = [];
        renderInventory([]);
        updateStoreFilter([]);
        updateCategoryFilter([]);
    } finally {
        // Hide loading indicator
        if (loadingDiv) loadingDiv.style.display = 'none';
        isLoadingInventory = false;
    }
}

// Render inventory tables (Ordered, In Stock, Out of Stock) based on cached data
function renderInventory(items) {
    // console.log("Rendering inventory for", items.length, "items"); // DEBUG
    // Get table body elements
    const orderedBody = document.getElementById('ordered-inventory-body');
    const inStockBody = document.getElementById('instock-inventory-body');
    const outOfStockBody = document.getElementById('outofstock-inventory-body'); // Get new body

    // Get 'no items' message divs
    const noOrderedMsg = document.getElementById('no-ordered-items');
    const noInStockMsg = document.getElementById('no-instock-items');
    const noOutOfStockMsg = document.getElementById('no-outofstock-items'); // Get new message div

    // Get section elements
    const orderedSection = document.getElementById('ordered-section');
    const instockSection = document.getElementById('instock-section');
    const outofstockSection = document.getElementById('outofstock-section'); // Get new section

    // Get filter select elements and their current values
     const storeFilterSelect = document.getElementById('filter-store');
     const categoryFilterSelect = document.getElementById('filter-category');
     const uniqueStores = new Set(); // To collect unique store names for the filter
     const uniqueCategories = new Map(); // To collect unique category IDs and names for the filter
     const currentStoreFilter = storeFilterSelect ? storeFilterSelect.value : '';
     const currentCategoryFilter = categoryFilterSelect ? categoryFilterSelect.value : '';

    // Basic check if necessary elements are present
    if (!orderedBody || !inStockBody || !outOfStockBody || !noOrderedMsg || !noInStockMsg || !noOutOfStockMsg || !orderedSection || !instockSection || !outofstockSection) {
        console.error("Inventory table elements missing!");
        return;
    }

    // Clear existing table rows
    orderedBody.innerHTML = '';
    inStockBody.innerHTML = '';
    outOfStockBody.innerHTML = ''; // Clear all bodies

    let orderedCount = 0;
    let inStockCount = 0;
    let outOfStockCount = 0; // Add counter for out of stock

    // Iterate through each item and add it to the appropriate table
    items.forEach(item => {
         // console.log(`Processing item ID: ${item.id}, Status: ${item.status}, Amount: ${item.amount}`); // DEBUG
        const row = document.createElement('tr');
        // Store data attributes on the row for filtering
        row.dataset.itemId = item.id;
        row.dataset.itemStore = item.store_name;
        // Ensure category_id is stored as a string, handle null/undefined
        row.dataset.itemCategory = item.category_id !== null && item.category_id !== undefined ? String(item.category_id) : '';

        // Determine status class and text
        let statusClass = `status-${item.status || 'unknown'}`;
        let statusText = item.status === 'ordered' ? 'Ordered' : (item.status === 'in_stock' ? 'In Stock' : 'Unknown');
        // Check amount *after* confirming status is 'in_stock'
        let isOutOfStock = false;
        if (item.status === 'in_stock') {
            isOutOfStock = (parseInt(item.amount || 0) <= 0); // Check if amount is 0 or less
            if (isOutOfStock) {
                 statusText = 'Out of Stock';
                 statusClass = 'status-out_of_stock';
            }
        }

        // Prepare item data for display
        const notesText = item.notes || '-';
        const storeName = item.store_name || 'N/A';
        const productName = item.product_name || 'N/A';
        const inventoryId = item.id || '-'; // Display '-' if ID is missing
        const categoryName = item.category_name || 'Uncategorized';

        // Collect unique stores and categories for filters
         if (item.store_name) uniqueStores.add(item.store_name);
         if (item.category_id !== null && item.category_id !== undefined && item.category_name) {
             uniqueCategories.set(String(item.category_id), item.category_name); // Use string ID as key
         }

        // Add row to the 'Ordered' table
        if (item.status === 'ordered') {
            orderedCount++;
            // ** Correctly remove the actions <td> for ordered items **
            row.innerHTML = `
                <td>${inventoryId}</td>
                <td>${storeName}</td>
                <td>${productName}</td>
                <td>${formatCurrency(item.unit_price)}</td>
                <td>${item.amount || 0}</td>
                <td>${formatDate(item.last_received_date)}</td>
                <td class="${statusClass}">${statusText}</td>
                <td>${notesText}</td>
                <?php /* No actions column content */ ?>
            `;
            orderedBody.appendChild(row);
        }
        // Add row to 'In Stock' or 'Out of Stock' table
        else if (item.status === 'in_stock') {
            const totalValue = formatCurrency(parseFloat(item.unit_price || 0) * parseInt(item.amount || 0));
            const warrantyEndDate = calculateWarrantyEndDate(item.last_received_date, item.warranty_years);

            // Check if item ID exists for button actions
            const hasItemId = !!item.id;
            const disabledAttr = hasItemId ? '' : 'disabled';
            const disabledTitle = hasItemId ? '' : 'title="Action unavailable (Missing Item ID)"';
            const stockDisabledAttr = hasItemId ? '' : 'disabled'; // Also disable stock adjust if ID missing
            const stockDisabledTitle = hasItemId ? '' : 'title="Action unavailable (Missing Item ID)"';


            // ID | Product | Category | Avg. Unit Price | Current Stock | Total Value | Last Received | Warranty Ending | Status | Notes | Actions
            const rowHTML = `
                <td>${inventoryId}</td>
                <td>${productName}</td>
                <td>${categoryName}</td>
                <td>${formatCurrency(item.unit_price)}</td>
                <td class="stock-controls-horizontal">
                    <button class="stock-adjust-btn stock-adjust-minus" onclick="adjustStock(${item.id}, -1)" title="-1" ${stockDisabledAttr} ${stockDisabledTitle}>-</button>
                    <span id="amount-display-${item.id}" class="amount-display">${item.amount || 0}</span>
                    <button class="stock-adjust-btn stock-adjust-plus" onclick="adjustStock(${item.id}, 1)" title="+1" ${stockDisabledAttr} ${stockDisabledTitle}>+</button>
                </td>
                <td>${totalValue}</td>
                <td>${formatDate(item.last_received_date)}</td>
                <td>${warrantyEndDate}</td>
                <td class="${statusClass}">${statusText}</td>
                <td>${notesText}</td>
                <td class="actions">
                    <button class="edit-btn" onclick="openEditModal(${item.id})" title="Edit Item" ${disabledAttr} ${disabledTitle}>Edit</button>
                    <button class="delete-btn" onclick="confirmDeleteInventory(${item.id})" title="Delete Item" ${disabledAttr} ${disabledTitle}>Delete</button>
                </td>
            `;
            row.innerHTML = rowHTML;

            // Append to correct table based on quantity
            if (isOutOfStock) {
                // console.log(` -> Adding item ID ${item.id} to Out of Stock`); // DEBUG
                outOfStockCount++;
                outOfStockBody.appendChild(row); // Append to Out of Stock table
            } else {
                 // console.log(` -> Adding item ID ${item.id} to In Stock`); // DEBUG
                inStockCount++;
                inStockBody.appendChild(row); // Append to In Stock table
            }
        } else {
             console.warn(`Item ID ${item.id} has unknown status: ${item.status}`); // Log items with unexpected status
        }
    });

    // console.log(`Render complete. Ordered: ${orderedCount}, In Stock: ${inStockCount}, Out of Stock: ${outOfStockCount}`); // DEBUG

    // Show/hide sections (always show the sections themselves, the 'no items' messages handle emptiness)
    orderedSection.style.display = 'block';
    instockSection.style.display = 'block';
    outofstockSection.style.display = 'block'; // Show the Out of Stock section

    // Populate filters with unique values found in the current inventory data
    updateStoreFilter(Array.from(uniqueStores), currentStoreFilter);
    // Convert Map to array of objects for sorting/populating
    const categoryArray = Array.from(uniqueCategories, ([id, name]) => ({ id, name }));
    updateCategoryFilter(categoryArray, currentCategoryFilter);

    // Apply filters to the rendered tables (this function also updates the 'no items' messages based on visible rows)
    filterInventory();
}

// Update store filter dropdowns (for both inventory and invoices)
function updateStoreFilter(stores, currentValue = '') {
    const filterStore = document.getElementById('filter-store'); // Inventory filter
    const filterInvoiceStore = document.getElementById('filter-invoice-store'); // Invoice filter

    // Iterate over both potential select elements
    [filterStore, filterInvoiceStore].forEach(select => {
        if (!select) return; // Skip if element doesn't exist
        const currentVal = select.value || currentValue; // Use existing value or provided default
        select.innerHTML = '<option value="">All Stores</option>'; // Add default "All" option
        stores.sort().forEach(store => { // Sort stores alphabetically
            const option = document.createElement('option');
            option.value = store;
            option.textContent = store;
            if (store === currentVal) option.selected = true; // Select the current value
            select.appendChild(option);
        });
         // Ensure the previously selected value is retained if it exists in the new list
         if (stores.includes(currentVal)) {
             select.value = currentVal;
         } else {
             select.value = ""; // Reset if previous value is no longer valid
         }
    });
}

// Update category filter dropdown (inventory page only)
function updateCategoryFilter(categories, currentValue = '') {
    const filterCategory = document.getElementById('filter-category');
    if (!filterCategory) return; // Exit if the element doesn't exist

    // Clear existing options except the first one ("All Categories")
    while (filterCategory.options.length > 1) {
        filterCategory.remove(1);
    }
    // Ensure the default option is correct
    if (filterCategory.options.length === 0 || filterCategory.options[0].value !== "") {
         filterCategory.innerHTML = '<option value="">All Categories</option>';
    } else {
        filterCategory.options[0].textContent = "All Categories"; // Ensure text is correct
    }

    categories.sort((a, b) => a.name.localeCompare(b.name)).forEach(category => { // Sort categories by name
        const option = document.createElement('option');
        option.value = category.id; // Use category ID as value
        option.textContent = category.name;
        filterCategory.appendChild(option);
    });
     // Ensure the previously selected value is retained if it exists in the new list
     if (categories.some(cat => String(cat.id) === currentValue)) {
        filterCategory.value = currentValue;
    } else {
        filterCategory.value = ""; // Reset if previous value is no longer valid
    }
}


// Filter inventory tables based on all active filters (search, store, category)
function filterInventory() {
    // Get filter values, using nullish coalescing to provide default empty strings
    const searchIdTerm = document.getElementById('search-id')?.value.trim() ?? '';
    const searchTerm = document.getElementById('search')?.value.toLowerCase().trim() ?? '';
    const storeFilter = document.getElementById('filter-store')?.value ?? '';
    const categoryFilterId = document.getElementById('filter-category')?.value ?? ''; // Get selected category ID

    // Get all rows from all three inventory tables
    const orderedRows = document.querySelectorAll('#ordered-inventory-body tr');
    const inStockRows = document.querySelectorAll('#instock-inventory-body tr');
    const outOfStockRows = document.querySelectorAll('#outofstock-inventory-body tr'); // Get out of stock rows
    const allRows = [...orderedRows, ...inStockRows, ...outOfStockRows]; // Combine all rows into one array

    let orderedVisibleCount = 0;
    let inStockVisibleCount = 0;
    let outOfStockVisibleCount = 0; // Add counter for visible out of stock rows

    // Iterate through each row and determine if it should be displayed
    allRows.forEach(row => {
        // Get data attributes from the row
        const rowId = row.dataset.itemId || '';
        const rowStoreName = row.dataset.itemStore || '';
        const rowCategoryId = row.dataset.itemCategory || ''; // Get category ID from data attribute

        let productText = '', categoryText = '';
        const tbodyId = row.closest('tbody')?.id; // Get the ID of the parent tbody

        // Extract text content based on the table structure (column index)
        if (tbodyId === 'ordered-inventory-body') {
            productText = row.cells[2]?.textContent?.toLowerCase() || ''; // Product name is in the 3rd cell (index 2)
            categoryText = ''; // Ordered items don't have category displayed in the table
        } else {
            // For In Stock and Out of Stock tables
            productText = row.cells[1]?.textContent?.toLowerCase() || ''; // Product name is in the 2nd cell (index 1)
            categoryText = row.cells[2]?.textContent?.toLowerCase() || ''; // Category name is in the 3rd cell (index 2)
        }

        let showRow = true; // Assume row should be shown initially

        // Apply filters sequentially
        if (searchIdTerm && rowId !== searchIdTerm) showRow = false;
        if (showRow && storeFilter && rowStoreName !== storeFilter) showRow = false;
        // Filter by category ID stored in data attribute
        if (showRow && categoryFilterId && rowCategoryId !== categoryFilterId) showRow = false; // Compare IDs
        // Apply general search term across relevant columns
        if (showRow && searchTerm &&
            !rowId.includes(searchTerm) &&
            !productText.includes(searchTerm) &&
            !rowStoreName.toLowerCase().includes(searchTerm) &&
            !categoryText.includes(searchTerm) // Include category text in search
           ) {
            showRow = false;
        }

        // Set row display style
        row.style.display = showRow ? '' : 'none';

        // Increment the correct visible row counter if the row is shown
        if (showRow) {
            if (tbodyId === 'ordered-inventory-body') orderedVisibleCount++;
            else if (tbodyId === 'instock-inventory-body') inStockVisibleCount++;
            else if (tbodyId === 'outofstock-inventory-body') outOfStockVisibleCount++;
        }
    });

    // Update 'No items found' messages based on *visible* rows in each section
    const noOrderedMsg = document.getElementById('no-ordered-items');
    const noInStockMsg = document.getElementById('no-instock-items');
    const noOutOfStockMsg = document.getElementById('no-outofstock-items'); // Get new message div

    if (noOrderedMsg) noOrderedMsg.style.display = orderedVisibleCount === 0 ? 'block' : 'none';
    if (noInStockMsg) noInStockMsg.style.display = inStockVisibleCount === 0 ? 'block' : 'none';
    if (noOutOfStockMsg) noOutOfStockMsg.style.display = outOfStockVisibleCount === 0 ? 'block' : 'none'; // Update new message div
}


// --- Edit Modal Logic ---
// Open the edit modal for a specific inventory item
function openEditModal(itemId) {
     // Prevent opening if itemId is invalid
     if (!itemId) {
        showNotification('Cannot edit item: Invalid Item ID.', 'error');
        return;
    }
    // Find the item in the cache
    const item = inventoryCache.find(i => i.id == itemId); // Use == for potential type coercion
    if (!item) {
        showNotification(`Cannot find item data for ID ${itemId}.`, 'error');
        return;
    }

    // Get modal elements
    const modal = document.getElementById('edit-item-modal');
    const form = document.getElementById('edit-item-form');
    const categorySelect = document.getElementById('edit-item-category');
    const warrantyInput = document.getElementById('edit-item-warranty');
    const notesInput = document.getElementById('edit-item-notes');
    const idInput = document.getElementById('edit-item-id');
    const productNameSpan = document.getElementById('edit-modal-product-name');
    const modalNotification = document.getElementById('edit-modal-notification');

    // Check if all required elements are present
    if (!modal || !form || !categorySelect || !warrantyInput || !notesInput || !idInput || !productNameSpan || !modalNotification) {
        console.error('Edit modal elements missing!');
        return;
    }

    // Reset form, hide notification, and set default notification class
    form.reset();
    modalNotification.style.display = 'none';
    modalNotification.textContent = '';
    modalNotification.className = 'notification modal-notification'; // Use a specific class for modal notifications

    // Populate form fields with item data
    idInput.value = item.id;
    productNameSpan.textContent = item.product_name || 'N/A';
    notesInput.value = item.notes || '';
    // Handle potential null/undefined warranty years
    warrantyInput.value = item.warranty_years != null ? item.warranty_years : '';

    // Populate the category dropdown and pre-select the item's category
    populateCategoryDropdown(categorySelect, item.category_id);

    // Disable editing of certain fields for 'ordered' items
    const isOrdered = item.status === 'ordered';
    notesInput.disabled = isOrdered;
    categorySelect.disabled = isOrdered;
    warrantyInput.disabled = isOrdered;
    document.getElementById('save-edit-btn').disabled = isOrdered;

    // Show a warning message for ordered items
    if(isOrdered) {
        showNotification("Order details (price, amount, date) cannot be edited here. Use Invoices page.", 'warning', 'edit-modal-notification');
    } else {
         modalNotification.style.display = 'none'; // Ensure no old message shows for editable items
    }

    // Display the modal
    modal.style.display = 'block';
}

// Handle submission of the edit item form
async function handleEditFormSubmit(event) {
    event.preventDefault(); // Prevent default form submission

    const saveBtn = document.getElementById('save-edit-btn');
    saveBtn.disabled = true; // Disable button to prevent double submission

    // Get form values
    const itemId = document.getElementById('edit-item-id').value;
    // Ensure itemId is valid before proceeding
    if (!itemId) {
        showNotification('Cannot save: Invalid Item ID.', 'error', 'edit-modal-notification');
        saveBtn.disabled = false;
        return;
    }

    const categoryIdValue = document.getElementById('edit-item-category').value;
    // Set category_id to null if the selected value is an empty string
    const categoryId = categoryIdValue === "" ? null : categoryIdValue;
    const warrantyValue = document.getElementById('edit-item-warranty').value;
    // Set warranty_years to null if the value is empty or null, otherwise parse as float
    const warrantyYears = (warrantyValue === '' || warrantyValue === null) ? null : parseFloat(warrantyValue);
    const notes = document.getElementById('edit-item-notes').value.trim();

    // Validate warranty years
    if (warrantyYears !== null && (isNaN(warrantyYears) || warrantyYears < 0)) {
        showNotification('Invalid Warranty value. Please enter a non-negative number.', 'error', 'edit-modal-notification');
        saveBtn.disabled = false; // Re-enable button
        return;
    }

    // Prepare data for the API call
    // Include action 'edit_instock' if your API requires it to differentiate updates
    const updateData = { notes: notes, category_id: categoryId, warranty_years: warrantyYears, action: 'edit_instock' };

    try {
        // Make the API call to update the item
        const response = await apiCall(`inventory/${itemId}`, 'PUT', updateData);

        if (response.status === 'success') {
            showNotification(response.message || 'Item updated!', 'success'); // Show on main page
            closeModal('edit-item-modal'); // Close the modal on success
            await loadInventory(); // Reload inventory to reflect changes
        } else {
            // Show error message from API response inside the modal
            showNotification(response.message || 'Failed to update.', 'error', 'edit-modal-notification');
        }
    } catch (error) {
        // Show error message for API call failure inside the modal
        showNotification(`Error updating: ${error.message}`, 'error', 'edit-modal-notification');
    } finally {
        saveBtn.disabled = false; // Re-enable button
    }
}

// Mark an 'Ordered' item as Arrived - REMOVED as it's handled by invoices now
/*
async function markArrived(itemId) { ... }
*/

// Adjust Stock (+/-) for an 'In Stock' item
async function adjustStock(itemId, change) {
    // Prevent action if itemId is invalid
    if (!itemId) {
       showNotification('Cannot adjust stock: Invalid Item ID.', 'error');
       return;
   }
    const amountSpan = document.getElementById(`amount-display-${itemId}`);
    const row = document.querySelector(`tr[data-item-id='${itemId}']`);
    const currentAmount = parseInt(amountSpan?.textContent || '0');
    const newAmount = currentAmount + change;

    // Prevent negative stock
    if (newAmount < 0) {
        showNotification("Stock cannot be negative.", 'warning');
        return;
    }

    // Disable buttons on the row during the API call
    const rowButtons = row ? row.querySelectorAll(`.stock-adjust-btn`) : [];
    rowButtons.forEach(btn => btn.disabled = true);

    try {
        // Make API call to adjust stock
        const response = await apiCall(`inventory/${itemId}`, 'PUT', { action: 'adjust_stock', change: change });

        if (response.status === 'success') {
            // Update the cached item amount and re-render the inventory
            const updatedAmount = response.newAmount ?? newAmount; // Use new amount from API if provided, otherwise use calculated
            const cacheItemIndex = inventoryCache.findIndex(item => item.id == itemId); // Find index for update
            if(cacheItemIndex > -1) {
                 inventoryCache[cacheItemIndex].amount = updatedAmount;
                 // Update status in cache if amount becomes 0 or > 0 (though API should handle this)
                 if (updatedAmount <= 0 && inventoryCache[cacheItemIndex].status === 'in_stock') {
                     // Potentially update status if needed, but rely on re-render
                 } else if (updatedAmount > 0 && inventoryCache[cacheItemIndex].status === 'in_stock') {
                      // Status remains 'in_stock'
                 }
            }

            renderInventory(inventoryCache); // Re-render needed to move between tables if amount becomes 0 or > 0

            // Add a visual feedback (brief background color change) to the amount display
            // Need to re-find the span after re-render
            const updatedAmountSpan = document.getElementById(`amount-display-${itemId}`);
            if(updatedAmountSpan) {
                updatedAmountSpan.style.transition = 'background-color 0.3s ease';
                updatedAmountSpan.style.backgroundColor = '#d4edda'; // Light green background
                setTimeout(() => {
                    if (updatedAmountSpan) updatedAmountSpan.style.backgroundColor = ''; // Revert background color
                }, 300);
            }

        } else {
            showNotification(response.message || 'Failed to adjust stock.', 'error');
        }
    } catch (error) {
        showNotification(`Error adjusting stock: ${error.message}`, 'error');
    } finally {
        // Re-enable buttons on the row after the API call completes
        // Need to re-find the row and buttons after potential re-render
        const finalRow = document.querySelector(`tr[data-item-id='${itemId}']`);
        const finalButtons = finalRow ? finalRow.querySelectorAll(`.stock-adjust-btn`) : [];
        finalButtons.forEach(btn => btn.disabled = false);
    }
}

// Confirm deletion of an inventory item
function confirmDeleteInventory(itemId) {
    // Prevent action if itemId is invalid
    if (!itemId) {
       showNotification('Cannot delete item: Invalid Item ID.', 'error');
       return;
   }
    const item = inventoryCache.find(i => i.id == itemId);
    const itemDesc = item ? `"${item.product_name}" (ID: ${item.id}, Status: ${item.status})` : `item with ID ${itemId}`;
    // Confirm with user before deleting
    if (confirm(`Are you sure you want to delete ${itemDesc}? This action cannot be undone.`)) {
        deleteInventoryItem(itemId); // Proceed with deletion if confirmed
    }
}

// Delete an inventory item
async function deleteInventoryItem(itemId) {
    // Prevent action if itemId is invalid (double check)
     if (!itemId) {
        showNotification('Cannot delete item: Invalid Item ID.', 'error');
        return;
    }
    showNotification(`Deleting Item ID ${itemId}...`, 'info');
    try {
        // Make API call to delete the item
        const response = await apiCall(`inventory/${itemId}`, 'DELETE');

        if (response.status === 'success') {
            showNotification(response.message || 'Item deleted successfully!', 'success');
            await loadInventory(); // Reload inventory to update the list
        } else {
            showNotification(response.message || 'Failed to delete item.', 'error');
        }
    } catch (error) {
        showNotification(`Error deleting item: ${error.message}`, 'error');
    }
}

// --- Category Management Functions ---
// Load category data from the API
async function loadCategories() {
    if (isLoadingCategories) return; // Prevent multiple simultaneous loads
    isLoadingCategories = true;

    // Show loading indicator and clear existing lists/dropdowns
    const loadingDiv = document.getElementById('loading-categories');
    const categoryList = document.getElementById('category-list');
    const editCategorySelect = document.getElementById('edit-item-category'); // Inventory Edit Modal
    const invoiceCategorySelects = document.querySelectorAll('#invoice-items-container .category-select'); // Invoice Form
    const inventoryFilterCategorySelect = document.getElementById('filter-category'); // Inventory Filter

    if (loadingDiv) loadingDiv.style.display = 'block';
    if (categoryList) categoryList.innerHTML = ''; // Clear category management list
    // Clear dropdowns before populating
    if (editCategorySelect) editCategorySelect.innerHTML = '<option value="">-- Select --</option>';
    invoiceCategorySelects.forEach(sel => sel.innerHTML = '<option value="">-- Select --</option>');
    if (inventoryFilterCategorySelect) inventoryFilterCategorySelect.innerHTML = '<option value="">All Categories</option>';


    try {
        const response = await apiCall('categories');
        if (response.status === 'success') {
            categoriesCache = response.data || []; // Cache the data
            populateCategoryDropdowns(); // Populate all category dropdowns (invoice form, edit modal, inventory filter)
            if (categoryList) renderCategoryList(categoriesCache); // Render the category list on the categories page/modal
        } else {
            // Show error and clear data/filters on failure
            showNotification(response.message || 'Failed to load categories.', 'error', 'notification'); // Use main notification
            categoriesCache = [];
            populateCategoryDropdowns(); // Clear dropdowns
            if (categoryList) renderCategoryList([]); // Clear list
            // if (document.getElementById('filter-category')) updateCategoryFilter([]); // updateCategoryFilter is called within populateCategoryDropdowns
        }
    } catch (error) {
        // Show error and clear data/filters on API call error
        showNotification(`Error loading categories: ${error.message}`, 'error', 'notification'); // Use main notification
        categoriesCache = [];
        populateCategoryDropdowns(); // Clear dropdowns
        if (categoryList) renderCategoryList([]); // Clear list
        // if (document.getElementById('filter-category')) updateCategoryFilter([]);
    } finally {
        // Hide loading indicator
        if (loadingDiv) loadingDiv.style.display = 'none';
        isLoadingCategories = false;
    }
}

// Populate all category dropdowns found in the document (e.g., in forms, modals)
function populateCategoryDropdowns() {
    // Select dropdowns within invoice item rows
    const invoiceFormDropdowns = document.querySelectorAll('#invoice-items-container .category-select');
    invoiceFormDropdowns.forEach(select => populateCategoryDropdown(select));

    // Select dropdown in the inventory item edit modal
    const editModalDropdown = document.getElementById('edit-item-category');
    if (editModalDropdown) populateCategoryDropdown(editModalDropdown);

    // Select dropdown in the inventory filter section
    const inventoryFilterDropdown = document.getElementById('filter-category');
    if (inventoryFilterDropdown) populateCategoryDropdown(inventoryFilterDropdown);
}

// Populate a single category dropdown element
function populateCategoryDropdown(selectElement, selectedValue = null) {
    if (!selectElement) return; // Exit if the element is not provided

    const currentValue = selectedValue ?? selectElement.value; // Use provided selectedValue or the element's current value
    const isFilterDropdown = selectElement.id === 'filter-category'; // Check if it's the filter dropdown

    // Preserve existing options if they exist (e.g., if called multiple times)
    // Clear existing options except the default one(s)
    const defaultOptionValue = isFilterDropdown ? "" : ""; // Filter uses "All Categories", others use "-- Select --"
    const defaultOptionText = isFilterDropdown ? "All Categories" : "-- Select --";

    // Clear existing options but keep the first one (default)
    const firstOption = selectElement.options[0];
    while (selectElement.options.length > 1) {
        selectElement.remove(1);
    }
    // Ensure the default option is correct
    if (!firstOption || firstOption.value !== defaultOptionValue) {
         selectElement.innerHTML = `<option value="${defaultOptionValue}">${defaultOptionText}</option>`;
    } else {
        firstOption.textContent = defaultOptionText; // Ensure text is correct
    }


    // Add options from the cached categories, sorting by name
    categoriesCache.sort((a, b) => a.name.localeCompare(b.name)).forEach(category => {
        const option = document.createElement('option');
        option.value = category.id;
        option.textContent = category.name;
        selectElement.appendChild(option);
    });

    // Set the selected value after populating
    selectElement.value = currentValue;
     // If the currentValue wasn't found (e.g., category deleted), reset to default
    if (selectElement.value !== String(currentValue) && currentValue !== null) {
         selectElement.value = defaultOptionValue;
    }
}

// Render the list of categories on the categories management page/modal
function renderCategoryList(categories) {
    const categoryList = document.getElementById('category-list');
    const noCategoriesMsg = document.getElementById('no-categories');

    if (!categoryList || !noCategoriesMsg) return; // Exit if elements are missing

    categoryList.innerHTML = ''; // Clear the list

    if (categories.length === 0) {
        noCategoriesMsg.style.display = 'block'; // Show 'no categories' message
        return;
    }

    noCategoriesMsg.style.display = 'none'; // Hide 'no categories' message

    // Add each category to the list, sorting by name
    categories.sort((a, b) => a.name.localeCompare(b.name)).forEach(category => {
        const listItem = document.createElement('li');
        listItem.innerHTML = `
            <span>${category.name}</span>
            <button class="delete-btn" onclick="confirmDeleteCategory(${category.id})" title="Delete Category">Delete</button>
        `;
        categoryList.appendChild(listItem);
    });
}

// Add a new category (called from category modal)
async function addCategoryFromModal(event) {
    event.preventDefault(); // Prevent default form submission
    const input = document.getElementById('new-category-name');
    const categoryName = input ? input.value.trim() : '';
    const addButton = event.target.querySelector('button[type="submit"]') || event.target; // Get the button

    if (!categoryName) {
        showNotification('Category name is required.', 'warning', 'category-modal-notification'); // Show notification inside modal
        return;
    }
    // Check if category already exists (case-insensitive)
    if (categoriesCache.some(cat => cat.name.toLowerCase() === categoryName.toLowerCase())) {
        showNotification('Category with this name already exists.', 'warning', 'category-modal-notification');
        return;
    }

    addButton.disabled = true; // Disable button during API call
    showNotification('Adding category...', 'info', 'category-modal-notification');

    try {
        // Make API call to add category
        const response = await apiCall('categories', 'POST', { name: categoryName });

        if (response.status === 'success') {
            showNotification(response.message || 'Category added successfully!', 'success', 'category-modal-notification');
            if (input) input.value = ''; // Clear the input field
            await loadCategories(); // Reload categories to update lists/dropdowns everywhere
            // Also reload inventory if on inventory page to update category filter
            if (document.getElementById('inventory-page')) {
                 // No need to call loadInventory, loadCategories calls populateCategoryDropdowns which updates the filter
                 // await loadInventory();
            }
        } else {
            showNotification(response.message || 'Failed to add category.', 'error', 'category-modal-notification');
        }
    } catch (error) {
        showNotification(`Error adding category: ${error.message}`, 'error', 'category-modal-notification');
    } finally {
        addButton.disabled = false; // Re-enable button
    }
}


// Confirm deletion of a category
function confirmDeleteCategory(categoryId) {
    // Prevent action if categoryId is invalid
    if (!categoryId) {
       showNotification('Cannot delete category: Invalid Category ID.', 'error', 'category-modal-notification');
       return;
   }
    const category = categoriesCache.find(c => c.id == categoryId);
    const categoryName = category ? `"${category.name}"` : `category with ID ${categoryId}`;
    // Confirm with user before deleting, explaining the consequence for items
    if (confirm(`Are you sure you want to delete ${categoryName}? Items currently assigned to this category will become 'Uncategorized'. This action cannot be undone.`)) {
        deleteCategory(categoryId); // Proceed with deletion if confirmed
    }
}

// Delete a category
async function deleteCategory(categoryId) {
     // Prevent action if categoryId is invalid (double check)
     if (!categoryId) {
        showNotification('Cannot delete category: Invalid Category ID.', 'error', 'category-modal-notification');
        return;
    }
    showNotification('Deleting category...', 'info', 'category-modal-notification'); // Show in modal
    try {
        // Make API call to delete category
        const response = await apiCall(`categories/${categoryId}`, 'DELETE');

        if (response.status === 'success') {
            showNotification(response.message || 'Category deleted successfully!', 'success', 'category-modal-notification');
            await loadCategories(); // Reload categories
            // Reload inventory only if on the inventory page, as items might have become uncategorized
            if (document.getElementById('inventory-page')) {
                await loadInventory(); // Reload inventory to reflect uncategorized items
            }
        } else {
            showNotification(response.message || `Failed to delete category.`, 'error', 'category-modal-notification');
        }
    } catch (error) {
        showNotification(`Error deleting category: ${error.message}`, 'error', 'category-modal-notification');
    }
}

// --- Invoice Specific Functions (mostly for invoices.php) ---

// Add a new row for an invoice item in the invoice form
function addInvoiceItemRow() {
    const container = document.getElementById('invoice-items-container');
    if (!container) return; // Exit if the container is not found

    const newItemRow = document.createElement('div');
    newItemRow.classList.add('invoice-item-row'); // Add class for styling and selection

    // HTML structure for a single invoice item row
    newItemRow.innerHTML = `
        <div class="form-group">
            <label>Product Name *</label>
            <input type="text" name="item_product_name[]" required>
        </div>
        <div class="form-group">
            <label>Category</label>
            <select name="item_category_id[]" class="category-select">
                <option value="">-- Select Category --</option>
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
            <button type="button" class="remove-item-btn delete-btn" onclick="removeInvoiceItem(this)">Remove</button>
        </div>
    `;

    container.appendChild(newItemRow); // Add the new row to the container

    // Make the remove button visible for all rows now
    container.querySelectorAll('.remove-item-btn').forEach(btn => btn.style.visibility = 'visible');
    // Hide remove button if only one row remains
    if (container.children.length === 1) {
        const firstRemoveBtn = container.querySelector('.remove-item-btn');
        if (firstRemoveBtn) firstRemoveBtn.style.visibility = 'hidden';
    }


    // Focus on the product name input of the new row for easier data entry
    newItemRow.querySelector('input[name="item_product_name[]"]').focus();

    // Populate the category dropdown in the newly added row
    populateCategoryDropdown(newItemRow.querySelector('.category-select'));
}

// Remove an invoice item row from the form
function removeInvoiceItem(button) {
    const row = button.closest('.invoice-item-row'); // Find the parent row
    const container = document.getElementById('invoice-items-container');

    // Prevent removing the last item row
    if (container && container.children.length > 1) {
        row.remove(); // Remove the row
        // If only one row remains after removal, hide its remove button
        if (container.children.length === 1) {
             const firstRemoveBtn = container.querySelector('.remove-item-btn');
             if (firstRemoveBtn) firstRemoveBtn.style.visibility = 'hidden';
        }
    } else {
        showNotification('Cannot remove the last invoice item.', 'warning');
    }
}

// Submit the invoice form data to the API
async function submitInvoice(formData) {
    const btn = document.getElementById('submit-invoice-btn');
    if(btn) btn.disabled = true; // Disable submit button during submission

    try {
        // Make API call to create the invoice
        const response = await apiCall('invoices', 'POST', formData);

        if (response.status === 'success') {
            showNotification(response.message || 'Invoice created successfully!', 'success');
            resetInvoiceForm(); // Reset the form on success
            await loadInvoices(); // Reload invoices list
            // Optionally, show a notification that inventory might be updated
            showNotification('Inventory may have been updated based on the new invoice.', 'info');
        } else {
            // Show error message from API response
            showNotification(response.message || 'Failed to create invoice.', 'error');
        }
    } catch (error) {
        // Show error message for API call failure
        showNotification(`Error submitting invoice: ${error.message}`, 'error');
    } finally {
        if(btn) btn.disabled = false; // Re-enable submit button
    }
}

// Reset the invoice form to its initial state
function resetInvoiceForm() {
    const form = document.getElementById('invoice-form');
    if (form) {
        form.reset(); // Reset form fields

        const container = document.getElementById('invoice-items-container');
        if(container) {
            // Remove all but the first invoice item row
            while (container.children.length > 1) {
                container.removeChild(container.lastChild);
            }
            // Reset the first row's inputs
            const firstRow = container.querySelector('.invoice-item-row');
            if(firstRow) {
                firstRow.querySelectorAll('input, select').forEach(input => {
                    input.style.borderColor = ''; // Clear any validation highlighting
                    if (input.name === 'item_warranty_years[]') input.value = '2.0'; // Set default warranty
                    else if (input.tagName === 'SELECT') input.value = ''; // Reset dropdown
                    else if (input.type !== 'button') input.value = ''; // Clear other input types
                });
                // Repopulate the first row's category dropdown
                populateCategoryDropdown(firstRow.querySelector('.category-select'));
                 // Hide remove button on the single remaining row
                 const firstRemoveBtn = firstRow.querySelector('.remove-item-btn');
                 if (firstRemoveBtn) firstRemoveBtn.style.visibility = 'hidden';
            }
        }
        setDefaultInvoiceDate(); // Set default date
        // Clear optional fields explicitly as reset might not clear them reliably
        const deliveryDateInput = document.getElementById('scheduled-delivery-date');
        if (deliveryDateInput) deliveryDateInput.value = '';
        const originalInvoiceIdInput = document.getElementById('original-invoice-id');
        if (originalInvoiceIdInput) originalInvoiceIdInput.value = '';
        const notesInput = document.getElementById('invoice-notes');
        if (notesInput) notesInput.value = '';
    }
}

// Set the default invoice date to today's date
function setDefaultInvoiceDate() {
    const dateInput = document.getElementById('invoice-date');
    if (dateInput) {
        dateInput.value = new Date().toISOString().split('T')[0]; // Format as<x_bin_880>-MM-DD
    }
}

// Load invoice data from the API
// Can optionally filter by item_id, store_name, or invoice_date
async function loadInvoices(itemId = null, storeName = null, invoiceDate = null) {
    if (isLoadingInvoices) return; // Prevent multiple simultaneous loads
    isLoadingInvoices = true;

    // Get elements for loading indicator, list body, and 'no invoices' message
    const loadingDiv = document.getElementById('loading-invoices');
    const listBody = document.getElementById('invoice-list-body');
    const noInvoicesMsg = document.getElementById('no-invoices');

    // Show loading indicator and clear list
    if (loadingDiv) loadingDiv.style.display = 'block';
    if (listBody) listBody.innerHTML = '';
    if (noInvoicesMsg) noInvoicesMsg.style.display = 'none';

    // Build query string for filtering if parameters are provided
    const params = new URLSearchParams();
    if (itemId) params.append('item_id', itemId);
    if (storeName) params.append('store_name', storeName);
    if (invoiceDate) params.append('invoice_date', invoiceDate);
    const queryString = params.toString();

    // Determine the API endpoint
    const endpoint = queryString ? `invoices?${queryString}` : 'invoices';

    try {
        const response = await apiCall(endpoint);
        if (response.status === 'success') {
            const fetchedInvoices = response.data || [];
            renderInvoiceList(fetchedInvoices); // Render the invoice list

            // Cache invoices and update store filter if no specific filters were applied
            // Only update cache if it's a full load, not a filtered load
            if (!itemId && !storeName && !invoiceDate) {
                invoiceCache = fetchedInvoices;
                const uniqueStores = new Set(invoiceCache.map(inv => inv.store_name).filter(Boolean));
                updateStoreFilter(Array.from(uniqueStores), document.getElementById('filter-invoice-store')?.value); // Update store filter on the invoices page, preserving current selection
            }
        } else {
            // Show error and clear data on failure
            showNotification(response.message || 'Failed to load invoices.', 'error');
            renderInvoiceList([]);
            if (!itemId && !storeName && !invoiceDate) {
                 invoiceCache = [];
                 updateStoreFilter([]);
            }
        }
    } catch (error) {
        // Show error and clear data on API call error
        showNotification(`Error loading invoices: ${error.message}`, 'error');
        renderInvoiceList([]);
        if (!itemId && !storeName && !invoiceDate) {
             invoiceCache = [];
             updateStoreFilter([]);
        }
    } finally {
        // Hide loading indicator
        if (loadingDiv) loadingDiv.style.display = 'none';
        isLoadingInvoices = false;
    }
}

// Render the list of invoices in the table
function renderInvoiceList(invoices) {
    const listBody = document.getElementById('invoice-list-body');
    const noInvoicesMsg = document.getElementById('no-invoices');

    if (!listBody || !noInvoicesMsg) {
        console.error("Invoice list body or no-invoices message element not found.");
        return;
    }

    listBody.innerHTML = ''; // Clear the list

    if (invoices.length === 0) {
        noInvoicesMsg.style.display = 'block'; // Show 'no invoices' message
        return;
    }

    noInvoicesMsg.style.display = 'none'; // Hide 'no invoices' message

    // Add each invoice as a row in the table
    invoices.forEach(invoice => {
        const row = document.createElement('tr');
        const currentInvoiceId = invoice.invoice_id; // Get the ID for checks
        const hasInvoiceId = !!currentInvoiceId; // Check if ID exists and is truthy

        // Use invoice_id for data attribute if it exists, otherwise use a placeholder but log a warning
        if (hasInvoiceId) {
             row.dataset.invoiceId = currentInvoiceId;
        } else {
            row.dataset.invoiceId = `missing-${Math.random().toString(36).substring(2, 9)}`;
            console.warn('Invoice data missing invoice_id:', invoice);
        }
        row.dataset.invoiceStore = invoice.store_name || ''; // Store store name for filtering
        row.dataset.invoiceDate = invoice.invoice_date || ''; // Store date for filtering

        // Use pre-calculated values from API
        const totalInvoicePrice = invoice.total_value ?? 0; // Use total_value from API, default 0
        const itemCount = invoice.item_count ?? 0; // Use item_count from API, default 0


        const notesText = invoice.notes || '-';
        const originalInvIdText = invoice.original_invoice_id || '-';

        // Determine delivery status and button
        const isDelivered = !!invoice.delivered_at;
        const statusText = isDelivered ? `Delivered (${formatDate(invoice.delivered_at)})` : 'Pending Delivery';
        const statusClass = isDelivered ? 'status-delivered' : 'status-pending';

        // --- Button Generation with ID Check ---
        const disabledAttr = hasInvoiceId ? '' : 'disabled';
        const disabledTitle = hasInvoiceId ? '' : 'title="Action unavailable (Missing Invoice ID)"';

        const viewButtonHTML = `<button class="view-btn" onclick="viewInvoiceDetails(${currentInvoiceId})" title="View Details" ${disabledAttr} ${disabledTitle}>View</button>`;

        const deliveryButtonHTML = (!isDelivered && hasInvoiceId) // Only show if not delivered AND has ID
            ? `<button class="deliver-btn" onclick="markInvoiceDelivered(${currentInvoiceId})" title="Mark as Delivered">Arrived</button>`
            : (!isDelivered && !hasInvoiceId) // Show disabled if not delivered but missing ID
            ? `<button class="deliver-btn" title="Action unavailable (Missing Invoice ID)" disabled>Deliver</button>`
            : ''; // Don't show if already delivered

        const deleteButtonHTML = `<button class="delete-btn" onclick="confirmDeleteInvoice(${currentInvoiceId})" title="Delete Invoice" ${disabledAttr} ${disabledTitle}>Delete</button>`;


        // Columns: ID | Store | Invoice Date | Delivery Date | Original Inv. ID | Items | Total Value | Notes | Status | Actions
        // Match the order of columns in invoices.php thead
        row.innerHTML = `
            <td>${currentInvoiceId || '-'}</td>
            <td>${invoice.store_name || 'N/A'}</td>
            <td>${formatDate(invoice.invoice_date)}</td>
            <td>${formatDate(invoice.scheduled_delivery_date)}</td>
            <td>${originalInvIdText}</td>
            <td>${itemCount}</td>                  <?php /* Display item_count */ ?>
            <td>${formatCurrency(totalInvoicePrice)}</td> <?php /* Display total_value */ ?>
            <td>${notesText}</td>
            <td class="${statusClass}">${statusText}</td>
            <td class="actions">
                ${viewButtonHTML}
                ${deliveryButtonHTML}
                ${deleteButtonHTML}
            </td>
        `;
        listBody.appendChild(row);
    });
}


// Function to trigger reloading invoices based on filters
function applyInvoiceFilters() {
    const itemId = document.getElementById('search-invoice-item-id')?.value.trim() || null;
    const storeName = document.getElementById('filter-invoice-store')?.value || null;
    const invoiceDate = document.getElementById('filter-invoice-date')?.value || null;
    loadInvoices(itemId, storeName, invoiceDate);
}

// Clear the invoice date filter and reload
function clearInvoiceDateFilter() {
    const dateInput = document.getElementById('filter-invoice-date');
    if (dateInput) dateInput.value = '';
    applyInvoiceFilters(); // Reload with cleared date
}


// View detailed information for a specific invoice using the existing modal structure
async function viewInvoiceDetails(invoiceId) {
    // Prevent opening if invoiceId is invalid
    if (!invoiceId) {
       showNotification('Cannot view details: Invalid Invoice ID.', 'error');
       return;
   }
    const modal = document.getElementById('invoice-detail-modal');
    const modalInvoiceIdSpan = document.getElementById('modal-invoice-id');
    const modalStoreNameSpan = document.getElementById('modal-store-name');
    const modalInvoiceDateSpan = document.getElementById('modal-invoice-date');
    const modalDeliveryDateSpan = document.getElementById('modal-delivery-date');
    const modalOriginalInvoiceIdSpan = document.getElementById('modal-original-invoice-id');
    const modalNotesSpan = document.getElementById('modal-invoice-notes');
    const modalItemsBody = document.getElementById('modal-items-body');
    // Add spans for status if they exist in the modal HTML, otherwise log a warning
    const modalStatusSpan = document.getElementById('modal-invoice-status');


    if (!modal || !modalInvoiceIdSpan || !modalStoreNameSpan || !modalInvoiceDateSpan ||
        !modalDeliveryDateSpan || !modalOriginalInvoiceIdSpan || !modalNotesSpan || !modalItemsBody) {
        console.error('Invoice details modal elements missing!');
        showNotification('Error displaying invoice details modal.', 'error');
        return;
    }

    // Clear previous content and show loading state (optional)
    modalInvoiceIdSpan.textContent = 'Loading...';
    modalStoreNameSpan.textContent = '...';
    modalInvoiceDateSpan.textContent = '...';
    modalDeliveryDateSpan.textContent = '...';
    modalOriginalInvoiceIdSpan.textContent = '...';
    modalNotesSpan.textContent = '...';
    modalItemsBody.innerHTML = '<tr><td colspan="8">Loading items...</td></tr>';
     if (modalStatusSpan) modalStatusSpan.textContent = '...';

    modal.style.display = 'block'; // Show the modal

    try {
        // Fetch details for the specific invoice
        const response = await apiCall(`invoices/${invoiceId}`);

        if (response.status === 'success' && response.data) {
            const invoice = response.data;

            // Populate modal header fields
            modalInvoiceIdSpan.textContent = invoice.invoice_id || '-';
            modalStoreNameSpan.textContent = invoice.store_name || 'N/A';
            modalInvoiceDateSpan.textContent = formatDate(invoice.invoice_date);
            modalDeliveryDateSpan.textContent = formatDate(invoice.scheduled_delivery_date) || 'N/A';
            modalOriginalInvoiceIdSpan.textContent = invoice.original_invoice_id || 'N/A';
            modalNotesSpan.textContent = invoice.notes || '-';

             // Populate status
             if (modalStatusSpan) {
                 const isDelivered = !!invoice.delivered_at;
                 modalStatusSpan.textContent = isDelivered ? `Delivered (${formatDate(invoice.delivered_at)})` : 'Pending Delivery';
             } else {
                 console.warn("Modal element 'modal-invoice-status' not found. Status won't be displayed.");
             }


            // Populate items table
            modalItemsBody.innerHTML = ''; // Clear loading message
            if (invoice.items && invoice.items.length > 0) {
                invoice.items.forEach(item => {
                    const itemRow = document.createElement('tr');
                    // Use total_price calculated by API/JS, calculate unit price for display if needed
                    const calculatedUnitPrice = (parseInt(item.quantity || 1) > 0) ? parseFloat(item.total_price || 0) / parseInt(item.quantity || 1) : 0;
                    const warrantyEndDate = calculateWarrantyEndDate(invoice.invoice_date, item.warranty_years); // Use invoice date for warranty calculation

                    // Columns: Product | Category | Qty | Unit Price (€) | Total Price (€) | Warranty (Yrs) | Warranty End Date | Batch ID
                    itemRow.innerHTML = `
                        <td>${item.product_name || 'N/A'}</td>
                        <td>${item.category_name || 'Uncategorized'}</td>
                        <td>${item.quantity || 0}</td>
                        <td>${formatCurrency(calculatedUnitPrice)}</td>
                        <td>${formatCurrency(item.total_price)}</td>
                        <td>${item.warranty_years != null ? item.warranty_years : '-'}</td>
                        <td>${warrantyEndDate}</td>
                        <td>${formatBatchId(item.id)}</td>
                    `;
                    modalItemsBody.appendChild(itemRow);
                });
            } else {
                modalItemsBody.innerHTML = '<tr><td colspan="8">No items found for this invoice.</td></tr>';
            }
        } else {
            // Show error message if fetching details failed
            showNotification(response.message || 'Failed to load invoice details.', 'error');
            // Optionally update modal to show error
            modalInvoiceIdSpan.textContent = 'Error';
            modalItemsBody.innerHTML = '<tr><td colspan="8">Error loading details.</td></tr>';
        }
    } catch (error) {
        // Show error message for API call failure
        showNotification(`Error loading invoice details: ${error.message}`, 'error');
        // Optionally update modal to show error
        modalInvoiceIdSpan.textContent = 'Error';
        modalItemsBody.innerHTML = `<tr><td colspan="8">Error: ${error.message}</td></tr>`;
    }
}

// Confirm marking an invoice as delivered
function markInvoiceDelivered(invoiceId) {
    // Prevent action if invoiceId is invalid
    if (!invoiceId) {
       showNotification('Cannot mark as delivered: Invalid Invoice ID.', 'error');
       return;
   }
    if (confirm(`Mark Invoice ID ${invoiceId} as delivered? This will update associated 'ordered' inventory items.`)) {
        updateInvoiceDeliveryStatus(invoiceId);
    }
}

// Update invoice delivery status via API
async function updateInvoiceDeliveryStatus(invoiceId) {
    // Prevent action if invoiceId is invalid (double check)
    if (!invoiceId) {
       showNotification('Cannot mark as delivered: Invalid Invoice ID.', 'error');
       return;
   }
    showNotification(`Marking Invoice ID ${invoiceId} as delivered...`, 'info');
    try {
        // API endpoint is PUT /invoices/{id}/deliver
        const response = await apiCall(`invoices/${invoiceId}/deliver`, 'PUT'); // Body might not be needed

        if (response.status === 'success') {
            showNotification(response.message || 'Invoice marked as delivered!', 'success');
            await loadInvoices(); // Reload invoices list to reflect the change
            // Optionally reload inventory if on inventory page to see status change
            if (document.getElementById('inventory-page')) {
                await loadInventory();
            }
        } else {
            // Use a more specific error message if the API provides one
            const errorMessage = response.message || `Failed to mark invoice ID ${invoiceId} as delivered.`;
            showNotification(errorMessage, 'error');
        }
    } catch (error) {
        showNotification(`Error marking invoice ID ${invoiceId} as delivered: ${error.message}`, 'error');
    }
}


// Confirm deletion of an invoice
function confirmDeleteInvoice(invoiceId) {
    // Prevent action if invoiceId is invalid
    if (!invoiceId) {
       showNotification('Cannot delete invoice: Invalid Invoice ID.', 'error');
       return;
   }
    // Confirm with user before deleting
    if (confirm(`Are you sure you want to delete Invoice ID ${invoiceId}? This action cannot be undone.`)) {
        deleteInvoice(invoiceId); // Proceed with deletion if confirmed
    }
}

// Delete an invoice
async function deleteInvoice(invoiceId) {
    // Prevent action if invoiceId is invalid (double check)
    if (!invoiceId) {
       showNotification('Cannot delete invoice: Invalid Invoice ID.', 'error');
       return;
   }
    showNotification(`Deleting Invoice ID ${invoiceId}...`, 'info');
    try {
        // Make API call to delete the invoice
        const response = await apiCall(`invoices/${invoiceId}`, 'DELETE');

        if (response.status === 'success') {
            showNotification(response.message || 'Invoice deleted successfully!', 'success');
            await loadInvoices(); // Reload invoices list
            // Optionally, show a notification that inventory might be updated
            showNotification('Inventory may have been updated after invoice deletion.', 'info');
        } else {
            showNotification(response.message || `Failed to delete invoice ID ${invoiceId}.`, 'error');
        }
    } catch (error) {
        showNotification(`Error deleting invoice ID ${invoiceId}: ${error.message}`, 'error');
    }
}


// --- Modal Helper Function ---
// Close a modal by its ID
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        // Clear modal-specific notifications when closing
        const modalNotification = modal.querySelector('.modal-notification');
        if (modalNotification) {
             modalNotification.style.display = 'none';
             modalNotification.textContent = '';
             modalNotification.className = 'notification modal-notification'; // Reset class
        }
    }
}

// Open a modal by its ID
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
    }
}

// --- Event Listeners ---

// Event listener for the DOMContentLoaded event to initialize the application
document.addEventListener('DOMContentLoaded', () => {
    // Determine current page (using body ID or a specific element ID)
    const isInventoryPage = !!document.getElementById('inventory-page');
    const isInvoicesPage = !!document.getElementById('invoices-page');
    // const isCategoriesPage = !!document.getElementById('categories-page'); // If you have a dedicated categories page

    // --- Inventory Page Specific Listeners ---
    if (isInventoryPage) {
        loadInventory();
        loadCategories(); // Load categories for filters and edit modal

        // Add event listeners for inventory filters
        document.getElementById('search-id')?.addEventListener('input', filterInventory);
        document.getElementById('search')?.addEventListener('input', filterInventory);
        document.getElementById('filter-store')?.addEventListener('change', filterInventory);
        document.getElementById('filter-category')?.addEventListener('change', filterInventory);

        // Add event listener for the edit item form submission
        document.getElementById('edit-item-form')?.addEventListener('submit', handleEditFormSubmit);

        // Add event listener for closing the edit modal (using the specific close button)
        document.querySelector('#edit-item-modal .close-modal')?.addEventListener('click', () => closeModal('edit-item-modal'));
    }

    // --- Invoices Page Specific Listeners ---
    else if (isInvoicesPage) {
        loadInvoices(); // Load all invoices initially
        loadCategories(); // Load categories for the invoice form and category modal
        setDefaultInvoiceDate(); // Set default date for the new invoice form

        // Add event listener for adding invoice items
        document.getElementById('add-item-btn')?.addEventListener('click', addInvoiceItemRow);
        // Hide remove button on initial single row
        const firstRemoveBtn = document.querySelector('#invoice-items-container .remove-item-btn');
        if (firstRemoveBtn) firstRemoveBtn.style.visibility = 'hidden';


        // Add event listener for the invoice form submission
        document.getElementById('invoice-form')?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const formData = {};
            let formIsValid = true; // Flag for validation

            // Clear previous validation styles
            form.querySelectorAll('input, select').forEach(el => el.style.borderColor = '');


            // Collect main invoice data
            formData.store_name = form.elements['store_name'].value.trim();
            formData.invoice_date = form.elements['invoice_date'].value;
            formData.scheduled_delivery_date = form.elements['scheduled_delivery_date'].value || null;
            formData.original_invoice_id = form.elements['original_invoice_id'].value.trim() || null;
            formData.notes = form.elements['notes'].value.trim() || null; // Collect notes

            // Basic validation for main fields
            if (!formData.store_name) {
                showNotification('Store Name is required.', 'error');
                form.elements['store_name'].style.borderColor = 'red';
                form.elements['store_name'].focus();
                formIsValid = false;
            }
            if (formIsValid && !formData.invoice_date) {
                showNotification('Invoice Date is required.', 'error');
                 form.elements['invoice_date'].style.borderColor = 'red';
                form.elements['invoice_date'].focus();
                formIsValid = false;
            }

            // Collect invoice item data from all rows
            formData.items = [];
            const itemRows = form.querySelectorAll('.invoice-item-row');
            itemRows.forEach((row, index) => {
                if (!formIsValid) return; // Stop collecting if form is already invalid

                // Clear previous item validation styles
                row.querySelectorAll('input, select').forEach(el => el.style.borderColor = '');

                const item = {
                    product_name: row.querySelector('[name="item_product_name[]"]').value.trim(),
                    category_id: row.querySelector('[name="item_category_id[]"]').value || null,
                    quantity: parseInt(row.querySelector('[name="item_quantity[]"]').value),
                    total_price: parseFloat(row.querySelector('[name="item_total_price[]"]').value),
                    warranty_years: parseFloat(row.querySelector('[name="item_warranty_years[]"]').value) // Allow null/NaN here, handle below
                };

                // Detailed validation for item data
                 let itemErrorField = null;
                 if (!item.product_name) {
                     itemErrorField = row.querySelector('[name="item_product_name[]"]');
                     showNotification(`Product Name is required for item #${index + 1}.`, 'error');
                 } else if (isNaN(item.quantity) || item.quantity <= 0) {
                     itemErrorField = row.querySelector('[name="item_quantity[]"]');
                     showNotification(`Valid Quantity (> 0) is required for item #${index + 1}.`, 'error');
                 } else if (isNaN(item.total_price) || item.total_price < 0) {
                     itemErrorField = row.querySelector('[name="item_total_price[]"]');
                     showNotification(`Valid Total Price (>= 0) is required for item #${index + 1}.`, 'error');
                 } else if (item.warranty_years !== null && (isNaN(item.warranty_years) || item.warranty_years < 0)) {
                     // Handle non-numeric or negative warranty if entered, default to null if empty
                     if (row.querySelector('[name="item_warranty_years[]"]').value === '') {
                        item.warranty_years = null; // Treat empty as null
                     } else {
                        itemErrorField = row.querySelector('[name="item_warranty_years[]"]');
                        showNotification(`Valid Warranty (non-negative number) or empty is required for item #${index + 1}.`, 'error');
                     }
                 }

                 if (itemErrorField) {
                     itemErrorField.focus();
                     itemErrorField.style.borderColor = 'red'; // Highlight error
                     formIsValid = false;
                 } else {
                     formData.items.push(item);
                 }
            });

            // Ensure at least one valid item is added
            if (formIsValid && formData.items.length === 0) {
                showNotification('Please add at least one valid item to the invoice.', 'warning');
                formIsValid = false;
            }

            // Submit the collected data if valid
            if (formIsValid) {
                submitInvoice(formData);
            }
        });

         // Add event listeners for invoice filters to trigger reload
         document.getElementById('search-invoice-item-id')?.addEventListener('input', applyInvoiceFilters);
         document.getElementById('filter-invoice-store')?.addEventListener('change', applyInvoiceFilters);
         document.getElementById('filter-invoice-date')?.addEventListener('change', applyInvoiceFilters);
         document.getElementById('clear-invoice-date-filter')?.addEventListener('click', clearInvoiceDateFilter);


         // Add event listener for closing the invoice details modal
         document.querySelector('#invoice-detail-modal .close-modal')?.addEventListener('click', () => closeModal('invoice-detail-modal'));

         // Add event listener to open the category management modal
         document.getElementById('manage-categories-btn')?.addEventListener('click', () => {
             openModal('category-management-modal');
             loadCategories(); // Ensure categories are loaded/refreshed when modal opens
         });

         // Add event listener for closing the category management modal
         document.querySelector('#category-management-modal .close-modal')?.addEventListener('click', () => closeModal('category-management-modal'));

         // Add event listener for submitting the add category form within the modal
         document.getElementById('add-category-form')?.addEventListener('submit', addCategoryFromModal);

    }

    // --- Dedicated Categories Page Listeners (If applicable) ---
    /*
    else if (isCategoriesPage) {
        loadCategories();
        // Add event listener for adding a new category (using the main page form)
        document.getElementById('add-category-btn')?.addEventListener('click', () => {
            const input = document.getElementById('new-category-name');
            if (input) addCategory(input.value); // Need a separate addCategory function for the main page if needed
        });
         // Allow adding category by pressing Enter in the input field
        document.getElementById('new-category-name')?.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent form submission if input is in a form
                const input = document.getElementById('new-category-name');
                if (input) addCategory(input.value);
            }
        });
    }
    */

    // Add general event listeners for closing modals by clicking outside
     window.addEventListener('click', (event) => {
         document.querySelectorAll('.modal').forEach(modal => {
             if (event.target === modal) {
                 closeModal(modal.id);
             }
         });
     });
});
