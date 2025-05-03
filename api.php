<?php
// api.php - Handle API requests for Inventory AND Invoices and Categories

error_reporting(E_ALL); // Enable error reporting for debugging
ini_set('display_errors', 1); // Display errors for debugging

header('Content-Type: application/json; charset=utf-8');
require_once 'config.php'; // Contains connectDB() function

// --- Helper Function for Sending JSON Response ---
function jsonResponse($data, $statusCode = 200) {
    // Check if headers already sent before trying to set them
    if (!headers_sent()) {
         header('Content-Type: application/json; charset=utf8mb4');
         http_response_code($statusCode);
    } else {
        // If headers are sent, we can't change the status code, but log it
        error_log("jsonResponse called after headers sent. Status Code: $statusCode");
    }

    // Ensure $data is an array or object before encoding
    if (!is_array($data) && !is_object($data)) {
        error_log("Invalid data type passed to jsonResponse: " . gettype($data));
        $data = ['status' => 'error', 'message' => 'Internal server error: Invalid data format for JSON response.'];
        if (!headers_sent()) { http_response_code(500); }
    }


    $jsonOutput = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
    if ($jsonOutput === false) {
        $jsonError = json_last_error_msg();
        error_log("JSON Encode Error: " . $jsonError . " | Data Sample: " . substr(print_r($data, true), 0, 500)); // Log sample data
        // If headers not sent, try sending an error response
        if (!headers_sent()) {
            // Ensure we don't try to set headers again if they were partially sent
            if (http_response_code() !== 500) { // Check if status is already set
                 http_response_code(500);
            }
            echo json_encode(['status' => 'error', 'message' => 'Server error: Failed to encode JSON response (' . $jsonError . ')']);
        } else {
            // If headers already sent, just echo the error directly (less ideal)
            echo '{"status":"error", "message":"Server error: Failed to encode JSON response (' . $jsonError . ')"}';
        }
        exit;
    }
    echo $jsonOutput;
    exit;
}

// --- Main Script Logic ---
$db = null;
try {
    $db = connectDB();
    // Routing Logic
    $request_uri = $_SERVER['REQUEST_URI'];
    $script_name = $_SERVER['SCRIPT_NAME'];

    // More robust base path calculation
    $base_path = dirname($script_name);
    if ($base_path === '/' || $base_path === '\\') {
        $base_path = '';
    }

    // Remove base path from request URI
    $path = $request_uri;
    if ($base_path !== '' && strpos($path, $base_path) === 0) {
        $path = substr($path, strlen($base_path));
    } elseif (strpos($path, $script_name) === 0) { // Fallback if script name is directly in URI
         $path = substr($path, strlen($script_name));
    }

    // Parse the path and query string
    $parsed_url = parse_url($path);
    $path_clean = trim($parsed_url['path'] ?? '', '/');
    $path_parts = explode('/', $path_clean);

    $resource = $path_parts[0] ?? null;
    $resource_id = $path_parts[1] ?? null;
    $sub_resource = $path_parts[2] ?? null; // For actions like /deliver
    $method = $_SERVER['REQUEST_METHOD'];

    // CORS Preflight
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *'); // Adjust in production if needed
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 1 day
        http_response_code(204); // No Content
        exit;
    }

    // CORS Headers for actual requests
    header('Access-Control-Allow-Origin: *'); // Adjust in production if needed
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Accept');

    // Decode JSON input for relevant methods
    $inputData = [];
    if (in_array($method, ['POST', 'PUT'])) { // DELETE might not need body
        $json_data = file_get_contents('php://input');
        if (!empty($json_data)) {
            $inputData = json_decode($json_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON Decode Error: " . json_last_error_msg() . " | Received JSON: " . $json_data);
                jsonResponse(['status' => 'error', 'message' => 'Invalid JSON received: ' . json_last_error_msg()], 400);
            }
        }
    }

    // --- Routing ---
    if ($resource === 'inventory') {
        handleInventoryRequest($db, $method, $resource_id, $inputData);
    } elseif ($resource === 'invoices') {
        // Pass the sub-resource (e.g., 'deliver') to the handler
        handleInvoiceRequest($db, $method, $resource_id, $sub_resource, $inputData);
    } elseif ($resource === 'categories') {
        handleCategoryRequest($db, $method, $resource_id, $inputData);
    } elseif ($resource === null || $resource === '') { // Handle empty resource path
         jsonResponse(['status' => 'info', 'message' => 'Inventory API - Available resources: /inventory, /invoices, /categories']);
    }
    else {
        jsonResponse(['status' => 'error', 'message' => "Resource '$resource' not found"], 404);
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage() . " | SQLState: " . $e->getCode());
    // *** DEBUGGING: Include actual error message in response ***
    // jsonResponse(['status' => 'error', 'message' => 'Database operation failed: ' . $e->getMessage(), 'sqlstate' => $e->getCode()], 500);
    // *** REMEMBER TO REVERT TO GENERIC MESSAGE IN PRODUCTION ***
    jsonResponse(['status' => 'error', 'message' => 'Database operation failed.'], 500); // Generic error for users
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], $statusCode);
} finally {
    // Close connection if needed (PDO usually handles this on script end)
    $db = null;
}

// --- Inventory Request Handler ---
// (No changes needed in this function)
function handleInventoryRequest($db, $method, $id, $inputData) {
    switch ($method) {
        case 'GET':
             // Fetch all inventory items with category name
            $query = "
                SELECT i.id, i.store_name, i.product_name, i.unit_price, i.amount, i.status, i.last_received_date, i.notes, i.warranty_years, i.category_id, c.name AS category_name
                FROM inventory i LEFT JOIN categories c ON i.category_id = c.id
                ORDER BY i.status DESC, i.store_name ASC, i.product_name ASC
            ";
            $stmt = $db->query($query);
            jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'POST':
             // Disallow direct inventory creation via POST
            jsonResponse(['status' => 'error', 'message' => 'Directly adding inventory items is deprecated. Use the /invoices endpoint.'], 405); // Method Not Allowed
            break;
        case 'PUT':
             // Update an existing inventory item (stock adjustment, mark arrived, edit details)
            if (!$id) throw new Exception("Item ID required for PUT", 400);
            $action = $inputData['action'] ?? 'edit_instock'; // Determine the action

            // Fetch the current item state, locking the row
            $stmtCheck = $db->prepare("SELECT id, amount, status, notes, unit_price, warranty_years, category_id FROM inventory WHERE id = :id FOR UPDATE");
            $stmtCheck->execute([':id' => $id]);
            $currentItem = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$currentItem) throw new Exception("Inventory item ID {$id} not found", 404);

            $db->beginTransaction();
            try {
                $message = '';
                $responseData = [];

                if ($action === 'adjust_stock') {
                    // Adjust stock amount for 'in_stock' items
                    if ($currentItem['status'] !== 'in_stock') throw new Exception("Stock adjustment only allowed for 'in_stock' items.", 400);
                    if (!isset($inputData['change']) || !is_numeric($inputData['change'])) throw new Exception("Invalid stock 'change' amount", 400);

                    $change = intval($inputData['change']);
                    $newAmount = $currentItem['amount'] + $change;
                    if ($newAmount < 0) throw new Exception("Stock cannot go below zero", 400);

                    $stmt = $db->prepare("UPDATE inventory SET amount = :amount, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $stmt->execute([':amount' => $newAmount, ':id' => $id]);
                    $message = 'Stock adjusted'; $responseData = ['newAmount' => $newAmount];

                } elseif ($action === 'mark_arrived') {
                    // Mark an 'ordered' item as 'in_stock' - DEPRECATED - Use invoice delivery
                     throw new Exception("'Mark Arrived' action is deprecated. Use the invoice delivery feature.", 400);

                } elseif ($action === 'edit_instock') {
                    // Edit details (notes, category, warranty) for 'in_stock' items
                    if ($currentItem['status'] !== 'in_stock') throw new Exception("This edit action is only for 'in_stock' items.", 400);

                    // Validate optional inputs
                    $categoryId = $inputData['category_id'] ?? null;
                    $warrantyYears = $inputData['warranty_years'] ?? null;
                    if ($categoryId !== null && $categoryId !== '' && (!is_numeric($categoryId) || intval($categoryId) <= 0)) throw new Exception("Invalid Category ID provided.", 400);
                    if ($warrantyYears !== null && $warrantyYears !== '' && (!is_numeric($warrantyYears) || floatval($warrantyYears) < 0)) throw new Exception("Invalid Warranty Years provided (must be non-negative number).", 400);

                    // Prepare update query focusing on allowed fields
                    $updateFields = [];
                    $params = [':id' => $id];
                    if (isset($inputData['notes'])) { $updateFields[] = "notes = :notes"; $params[':notes'] = $inputData['notes']; }
                    // Handle category_id potentially being set to null
                    if (array_key_exists('category_id', $inputData)) { $updateFields[] = "category_id = :category_id"; $params[':category_id'] = ($categoryId === '' || $categoryId === null) ? null : intval($categoryId); }
                    // Handle warranty_years potentially being set to null
                     if (array_key_exists('warranty_years', $inputData)) { $updateFields[] = "warranty_years = :warranty_years"; $params[':warranty_years'] = ($warrantyYears === '' || $warrantyYears === null) ? null : floatval($warrantyYears); }


                    if (empty($updateFields)) throw new Exception("No valid fields provided for update.", 400);

                    $updateFields[] = "updated_at = CURRENT_TIMESTAMP"; // Always update timestamp
                    $sql = "UPDATE inventory SET " . implode(', ', $updateFields) . " WHERE id = :id AND status = 'in_stock'";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $message = 'In-stock item updated'; $responseData = [];

                } else {
                    // Invalid action specified
                    throw new Exception("Invalid action '$action' for inventory item.", 400);
                }

                $db->commit();
                jsonResponse(array_merge(['status' => 'success', 'message' => $message], $responseData)); // Merge response data if any

            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); } throw $e; // Re-throw exception after rollback
            }
            break;
        case 'DELETE':
            // Delete an inventory item
            if (!$id) throw new Exception("Item ID required for DELETE", 400);
            $stmt = $db->prepare("DELETE FROM inventory WHERE id = :id");
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() > 0) jsonResponse(['status' => 'success', 'message' => 'Inventory item deleted']);
            else jsonResponse(['status' => 'error', 'message' => "Inventory item ID {$id} not found"], 404);
            break;
        default:
             // Handle unsupported methods
            jsonResponse(['status' => 'error', 'message' => 'Method not allowed for /inventory'], 405);
            break;
    }
}

// --- Invoice Request Handler ---
// Added $sub_resource parameter for actions like 'deliver'
function handleInvoiceRequest($db, $method, $id, $sub_resource, $inputData) {
    switch ($method) {
        case 'GET':
            // Read filter parameters from query string
            // ** FIX DEPRECATED FILTER **
            $searchItemId = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
            $filterStore = filter_input(INPUT_GET, 'store_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Use FILTER_SANITIZE_FULL_SPECIAL_CHARS
            $filterDate = filter_input(INPUT_GET, 'invoice_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Use FILTER_SANITIZE_FULL_SPECIAL_CHARS

            // Validate date format if provided (check remains the same)
            if (!empty($filterDate)) {
                 $dateCheck = DateTime::createFromFormat('Y-m-d', $filterDate);
                 if (!$dateCheck || $dateCheck->format('Y-m-d') !== $filterDate) {
                     $filterDate = null; // Ignore invalid date format
                     error_log("Invalid date format received for invoice filter: " . ($_GET['invoice_date'] ?? ''));
                 }
             }

            if ($id) { // Get specific invoice details
                $stmtInv = $db->prepare("SELECT id AS invoice_id, store_name, invoice_date, scheduled_delivery_date, original_invoice_id, notes, created_at, delivered_at FROM invoices WHERE id = :id"); // Added delivered_at
                $stmtInv->execute([':id' => $id]);
                $invoice = $stmtInv->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    jsonResponse(['status' => 'error', 'message' => "Invoice ID {$id} not found"], 404);
                }

                // Fetch associated items with category name
                $stmtItems = $db->prepare("
                    SELECT ii.id, ii.invoice_id, ii.product_name, ii.quantity_purchased AS quantity, ii.unit_price_paid, ii.warranty_years, c.name AS category_name, ii.category_id
                    FROM invoice_items ii
                    LEFT JOIN categories c ON ii.category_id = c.id
                    WHERE ii.invoice_id = :id
                    ORDER BY ii.product_name
                "); // Changed alias for unit_price_paid back
                $stmtItems->execute([':id' => $id]);
                $invoice['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                // Calculate total_price for each item in the response
                foreach ($invoice['items'] as &$item) {
                     $unitPrice = floatval($item['unit_price_paid']); // Use the correct field name from query
                     $quantity = intval($item['quantity']);
                     $item['total_price'] = $unitPrice * $quantity; // Calculate actual total price for this item row
                     // Keep unit_price_paid if needed, or remove it
                     // unset($item['unit_price_paid']);
                }
                unset($item); // Unset reference

                jsonResponse(['status' => 'success', 'data' => $invoice]);

            } elseif ($searchItemId !== false && $searchItemId !== null && $searchItemId > 0) {
                 // Search for invoices containing a specific item ID (Batch ID from invoice_items)
                $query = "
                    SELECT
                        i.id AS invoice_id, i.store_name, i.invoice_date, i.scheduled_delivery_date, i.original_invoice_id, i.notes, i.created_at, i.delivered_at,
                        COUNT(it.id) as item_count,
                        COALESCE(SUM(it.quantity_purchased * it.unit_price_paid), 0) as total_value
                    FROM invoices i
                    JOIN invoice_items it ON i.id = it.invoice_id
                    WHERE it.id = :item_id
                    GROUP BY i.id, i.store_name, i.invoice_date, i.scheduled_delivery_date, i.original_invoice_id, i.notes, i.created_at, i.delivered_at
                    ORDER BY i.invoice_date DESC, i.created_at DESC";
                $stmt = $db->prepare($query);
                $stmt->execute([':item_id' => $searchItemId]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                 // ** Restore loop adding items:[] **
                 foreach ($results as &$inv) {
                    $inv['items'] = []; // Add empty items array for frontend compatibility
                 }
                 unset($inv);
                 error_log("GET /invoices (search by item ID) successful. Found " . count($results) . " invoices."); // Log success
                jsonResponse(['status' => 'success', 'data' => $results]);

            } else {
                // Get list of invoices WITH optional Store and Date filters
                // *** Using LEFT JOIN and GROUP BY ***
                $params = [];
                $whereClauses = [];

                if (!empty($filterStore)) {
                    $whereClauses[] = "i.store_name = :store_name";
                    $params[':store_name'] = $filterStore;
                }
                if (!empty($filterDate)) {
                    $whereClauses[] = "i.invoice_date = :invoice_date";
                    $params[':invoice_date'] = $filterDate;
                }

                 // Select necessary fields including id AS invoice_id and delivered_at
                 // Calculate item_count and total_value using LEFT JOIN/GROUP BY
                $baseQuery = "
                    SELECT
                        i.id AS invoice_id,
                        i.store_name,
                        i.invoice_date,
                        i.scheduled_delivery_date,
                        i.original_invoice_id,
                        i.notes,
                        i.created_at,
                        i.delivered_at,
                        COUNT(it.id) as item_count,
                        COALESCE(SUM(it.quantity_purchased * it.unit_price_paid), 0) as total_value
                    FROM invoices i
                    LEFT JOIN invoice_items it ON i.id = it.invoice_id"; // LEFT JOIN here

                $sql = $baseQuery;
                if (!empty($whereClauses)) {
                    $sql .= " WHERE " . implode(" AND ", $whereClauses);
                }
                // GROUP BY all non-aggregated columns from the invoices table
                $sql .= " GROUP BY i.id, i.store_name, i.invoice_date, i.scheduled_delivery_date, i.original_invoice_id, i.notes, i.created_at, i.delivered_at";
                $sql .= " ORDER BY i.invoice_date DESC, i.created_at DESC";

                error_log("Executing SQL for invoice list: " . $sql); // Log the query
                $stmt = $db->prepare($sql);
                $stmt->execute($params); // This is where the error likely occurs
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("GET /invoices list successful. Found " . count($results) . " invoices."); // Log success

                // ** Restore loop adding items:[] **
                // Add an empty 'items' array to each invoice for frontend compatibility
                foreach ($results as &$inv) {
                    $inv['items'] = [];
                }
                unset($inv); // Unset reference

                jsonResponse(['status' => 'success', 'data' => $results]);
            }
            break;

        case 'POST':
            // --- Validation ---
            if (empty($inputData['store_name'])) throw new Exception("Store Name is required.", 400);
            if (empty($inputData['invoice_date'])) throw new Exception("Invoice Date is required.", 400);

            $invoiceDateString = $inputData['invoice_date'];
            $invoiceDate = DateTime::createFromFormat('Y-m-d', $invoiceDateString);
            if (!$invoiceDate || $invoiceDate->format('Y-m-d') !== $invoiceDateString) { // Also check if format matches
                throw new Exception("Invalid Invoice Date format. Use YYYY-MM-DD.", 400);
            }

            $scheduledDeliveryDateString = $inputData['scheduled_delivery_date'] ?? null;
            $scheduledDeliveryDate = null;
            if (!empty($scheduledDeliveryDateString)) {
                $scheduledDeliveryDate = DateTime::createFromFormat('Y-m-d', $scheduledDeliveryDateString);
                 if (!$scheduledDeliveryDate || $scheduledDeliveryDate->format('Y-m-d') !== $scheduledDeliveryDateString) {
                    throw new Exception("Invalid Scheduled Delivery Date format. Use YYYY-MM-DD.", 400);
                }
                $scheduledDeliveryDateString = $scheduledDeliveryDate->format('Y-m-d'); // Use formatted string
            }

            $originalInvoiceId = $inputData['original_invoice_id'] ?? null;
            if (!isset($inputData['items']) || !is_array($inputData['items'])) throw new Exception("Invoice items must be an array.", 400);
            if (empty($inputData['items'])) throw new Exception("Invoice must contain at least one item.", 400);

            $db->beginTransaction();
            try {
                // 1. Create Invoice Record
                $sqlInvoice = "INSERT INTO invoices (store_name, invoice_date, scheduled_delivery_date, original_invoice_id, notes) VALUES (:store, :date, :scheduled_date, :original_id, :notes)";
                $stmtInvoice = $db->prepare($sqlInvoice);
                $stmtInvoice->execute([
                    ':store' => $inputData['store_name'],
                    ':date' => $invoiceDateString,
                    ':scheduled_date' => $scheduledDeliveryDateString, // Use potentially null formatted string
                    ':original_id' => $originalInvoiceId,
                    ':notes' => $inputData['notes'] ?? null
                ]);
                $invoiceId = $db->lastInsertId();
                if (!$invoiceId) {
                     throw new Exception("Failed to create invoice record.", 500);
                }

                // 2. Prepare statements for item processing and inventory updates
                $itemInsertSql = "INSERT INTO invoice_items (invoice_id, product_name, category_id, quantity_purchased, unit_price_paid, warranty_years) VALUES (?, ?, ?, ?, ?, ?)";
                $itemInsertStmt = $db->prepare($itemInsertSql);

                // Find existing IN_STOCK item
                $findInStockInvSql = "SELECT id, amount, unit_price FROM inventory WHERE store_name = ? AND product_name = ? AND status = 'in_stock' FOR UPDATE";
                $findInStockInvStmt = $db->prepare($findInStockInvSql);

                // Update existing IN_STOCK item
                $updateInStockSql = "UPDATE inventory SET amount = ?, unit_price = ?, last_received_date = ?, category_id = ?, warranty_years = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $updateInStockStmt = $db->prepare($updateInStockSql);

                // Insert new INVENTORY item (either 'in_stock' or 'ordered')
                $insertInvSql = "INSERT INTO inventory (store_name, product_name, category_id, amount, unit_price, status, last_received_date, warranty_years, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insertInvStmt = $db->prepare($insertInvSql);

                // 3. Process Items
                foreach ($inputData['items'] as $itemIndex => $item) {
                    // Item Validation (same as before)
                    $productName = trim($item['product_name'] ?? ''); if (empty($productName)) throw new Exception("Product Name is required for item #" . ($itemIndex+1) . ".", 400);
                    $categoryId = $item['category_id'] ?? null;
                    if ($categoryId !== null && $categoryId !== '' && (!is_numeric($categoryId) || intval($categoryId) <= 0)) throw new Exception("Invalid Category ID for item #" . ($itemIndex+1) . ".", 400);
                    if ($categoryId !== null && $categoryId !== '') { $stmtCheckCategory = $db->prepare("SELECT id FROM categories WHERE id = ?"); $stmtCheckCategory->execute([$categoryId]); if (!$stmtCheckCategory->fetch()) throw new Exception("Category ID {$categoryId} does not exist for item #" . ($itemIndex+1) . ".", 400); $categoryId = intval($categoryId); } else { $categoryId = null; }
                    $quantity = $item['quantity'] ?? null; if (!is_numeric($quantity) || intval($quantity) <= 0) throw new Exception("Valid Quantity (> 0) required for item #" . ($itemIndex+1) . ".", 400); $qtyPurchased = intval($quantity);
                    $totalPrice = $item['total_price'] ?? null; if (!isset($item['total_price']) || !is_numeric($totalPrice) || floatval($totalPrice) < 0) throw new Exception("Valid Total Price Paid (>= 0) required for item #" . ($itemIndex+1) . ".", 400); $totalPricePaid = floatval($totalPrice);
                    $warrantyYears = $item['warranty_years'] ?? null; if ($warrantyYears !== null && $warrantyYears !== '') { if (!is_numeric($warrantyYears) || floatval($warrantyYears) < 0) throw new Exception("Invalid Warranty (Years) for item #" . ($itemIndex+1) . ".", 400); $warrantyYears = floatval($warrantyYears); } else { $warrantyYears = null; }
                    $unitPricePaid = ($qtyPurchased > 0) ? $totalPricePaid / $qtyPurchased : 0; if (!is_numeric($unitPricePaid) || $unitPricePaid < 0) throw new Exception("Calculation error for unit price for item #" . ($itemIndex+1) . ".", 500);

                    // Insert into invoice_items table
                    $itemInsertStmt->execute([$invoiceId, $productName, $categoryId, $qtyPurchased, $unitPricePaid, $warrantyYears]);
                    $invoiceItemId = $db->lastInsertId(); // Get the ID of the invoice item (batch ID)

                    // Determine target inventory status and date based on delivery schedule
                    $targetInventoryStatus = 'in_stock';
                    $inventoryDateString = $invoiceDateString; // Default to invoice date
                    if ($scheduledDeliveryDate && $scheduledDeliveryDate > $invoiceDate) {
                        $targetInventoryStatus = 'ordered';
                        $inventoryDateString = $scheduledDeliveryDateString; // Use delivery date for inventory record
                    }

                    // ** Revised Inventory Handling Logic **
                    if ($targetInventoryStatus === 'ordered') {
                        // Always insert a NEW 'ordered' record for future deliveries
                        $notes = "Order from invoice #{$invoiceId} (Batch ID: {$invoiceItemId}), expected {$inventoryDateString}";
                        $insertInvStmt->execute([
                            $inputData['store_name'], $productName, $categoryId,
                            $qtyPurchased, $unitPricePaid, // Use the specific order price, not average
                            'ordered', // Explicitly set status
                            $inventoryDateString, // Expected arrival date
                            $warrantyYears, $notes
                        ]);
                    } else { // targetInventoryStatus === 'in_stock' (Item is being received now)
                        // Find existing 'in_stock' item for this store/product
                        $findInStockInvStmt->execute([$inputData['store_name'], $productName]);
                        $existingInStockItem = $findInStockInvStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existingInStockItem) {
                            // Update existing 'in_stock' record
                            $existingId = $existingInStockItem['id'];
                            $oldAmount = intval($existingInStockItem['amount']);
                            $oldAvgPrice = floatval($existingInStockItem['unit_price']);
                            $newAmount = $oldAmount + $qtyPurchased;
                            // Calculate new weighted average price
                            $newAvgPrice = ($newAmount > 0) ? (($oldAmount * $oldAvgPrice) + ($qtyPurchased * $unitPricePaid)) / $newAmount : $unitPricePaid;
                            // Update amount, avg price, last received date, category, warranty
                            $updateInStockStmt->execute([$newAmount, $newAvgPrice, $invoiceDateString, $categoryId, $warrantyYears, $existingId]);
                        } else {
                            // No existing 'in_stock' item, insert a new one
                            $notes = "Initial stock from invoice #{$invoiceId} (Batch ID: {$invoiceItemId})";
                            $insertInvStmt->execute([
                                $inputData['store_name'], $productName, $categoryId,
                                $qtyPurchased, $unitPricePaid, // Use the specific price for initial stock
                                'in_stock', // Explicitly set status
                                $invoiceDateString, // Received date
                                $warrantyYears, $notes
                            ]);
                        }
                    }
                } // End foreach item

                $db->commit();
                jsonResponse(['status' => 'success', 'message' => 'Invoice created and inventory updated successfully', 'invoice_id' => $invoiceId], 201); // Created
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log("Invoice POST Error (Rolling Back): " . $e->getMessage());
                // Provide more specific error if code is available
                jsonResponse(['status' => 'error', 'message' => 'Failed to process invoice: ' . $e->getMessage()], ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500);
            }
            break;

        case 'PUT':
             // Handle updates to an invoice - specifically marking as delivered
             if (!$id) throw new Exception("Invoice ID required for PUT", 400);

             // Check if the request is for the 'deliver' sub-resource
             if ($sub_resource === 'deliver') {
                 // Mark the invoice as delivered AND update/merge associated inventory items
                 $db->beginTransaction();
                 try {
                     // 1. Check invoice status
                     $stmtCheck = $db->prepare("SELECT id, delivered_at, store_name, invoice_date, scheduled_delivery_date FROM invoices WHERE id = :id FOR UPDATE");
                     $stmtCheck->execute([':id' => $id]);
                     $invoice = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                     if (!$invoice) {
                         $db->rollBack(); // No need to proceed
                         jsonResponse(['status' => 'error', 'message' => "Invoice ID {$id} not found"], 404);
                     }
                     if ($invoice['delivered_at'] !== null) {
                         $db->rollBack(); // No action needed
                         $deliveredDateFormatted = date('Y-m-d', strtotime($invoice['delivered_at']));
                         jsonResponse(['status' => 'info', 'message' => "Invoice ID {$id} was already marked as delivered on " . $deliveredDateFormatted], 200);
                     }
                     $storeName = $invoice['store_name']; // Get store name for inventory lookup
                     // Determine the expected arrival date used when creating the 'ordered' record
                     $expectedArrivalDate = $invoice['scheduled_delivery_date'] ?? $invoice['invoice_date'];
                     $actualDeliveryDate = date('Y-m-d'); // Today's date

                     // 2. Fetch associated invoice items (needed for product name, qty, price etc.)
                     $stmtItems = $db->prepare("SELECT id as invoice_item_id, product_name, quantity_purchased, unit_price_paid, category_id, warranty_years FROM invoice_items WHERE invoice_id = :id");
                     $stmtItems->execute([':id' => $id]);
                     $invoiceItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                     // 3. Prepare statements
                     // Find the specific 'ordered' record to be potentially deleted/updated
                     $stmtFindOrdered = $db->prepare("
                         SELECT id, amount, unit_price, category_id, warranty_years
                         FROM inventory
                         WHERE store_name = :store_name
                           AND product_name = :product_name
                           AND status = 'ordered'
                           AND last_received_date = :expected_arrival_date
                         LIMIT 1 FOR UPDATE
                     ");
                     // Find existing 'in_stock' record
                     $stmtFindInStock = $db->prepare("SELECT id, amount, unit_price FROM inventory WHERE store_name = :store_name AND product_name = :product_name AND status = 'in_stock' FOR UPDATE");
                     // Update existing 'in_stock' record
                     $stmtUpdateInStock = $db->prepare("UPDATE inventory SET amount = :amount, unit_price = :unit_price, last_received_date = :delivery_date, category_id = :category_id, warranty_years = :warranty_years, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                     // Delete 'ordered' record
                     $stmtDeleteOrdered = $db->prepare("DELETE FROM inventory WHERE id = :id");
                     // Update 'ordered' record to 'in_stock' (if no existing 'in_stock' found)
                     $stmtUpdateOrderedToInStock = $db->prepare("UPDATE inventory SET status = 'in_stock', last_received_date = :delivery_date, updated_at = CURRENT_TIMESTAMP WHERE id = :id");


                     // 4. Process each item from the delivered invoice
                     $updatedInventoryCount = 0;
                     $mergedInventoryCount = 0;
                     $failedUpdates = [];

                     foreach ($invoiceItems as $item) {
                         // Find the specific 'ordered' record for this item/delivery date
                         $stmtFindOrdered->execute([
                             ':store_name' => $storeName,
                             ':product_name' => $item['product_name'],
                             ':expected_arrival_date' => $expectedArrivalDate
                         ]);
                         $orderedItem = $stmtFindOrdered->fetch(PDO::FETCH_ASSOC);

                         if ($orderedItem) {
                             $orderedItemId = $orderedItem['id'];
                             $arrivingQty = intval($item['quantity_purchased']); // Qty from invoice item
                             $arrivingUnitPrice = floatval($item['unit_price_paid']); // Price from invoice item
                             $arrivingCategoryId = $item['category_id'];
                             $arrivingWarranty = $item['warranty_years'];

                             // Look for an existing 'in_stock' record
                             $stmtFindInStock->execute([
                                 ':store_name' => $storeName,
                                 ':product_name' => $item['product_name']
                             ]);
                             $inStockItem = $stmtFindInStock->fetch(PDO::FETCH_ASSOC);

                             if ($inStockItem) {
                                 // --- Merge into existing in_stock ---
                                 $inStockItemId = $inStockItem['id'];
                                 $oldAmount = intval($inStockItem['amount']);
                                 $oldAvgPrice = floatval($inStockItem['unit_price']);
                                 $newAmount = $oldAmount + $arrivingQty;
                                 // Calculate new weighted average price
                                 $newAvgPrice = ($newAmount > 0) ? (($oldAmount * $oldAvgPrice) + ($arrivingQty * $arrivingUnitPrice)) / $newAmount : $arrivingUnitPrice;

                                 // Update the in_stock record
                                 $updateResult = $stmtUpdateInStock->execute([
                                     ':amount' => $newAmount,
                                     ':unit_price' => $newAvgPrice,
                                     ':delivery_date' => $actualDeliveryDate,
                                     ':category_id' => $arrivingCategoryId, // Use latest info
                                     ':warranty_years' => $arrivingWarranty, // Use latest info
                                     ':id' => $inStockItemId
                                 ]);

                                 if ($updateResult) {
                                     // Delete the now-processed 'ordered' record
                                     $stmtDeleteOrdered->execute([':id' => $orderedItemId]);
                                     $mergedInventoryCount++;
                                 } else {
                                     $failedUpdates[] = $item['product_name'] . " (merge update failed)";
                                 }
                             } else {
                                 // --- No existing in_stock, convert ordered to in_stock ---
                                 $updateResult = $stmtUpdateOrderedToInStock->execute([
                                     ':delivery_date' => $actualDeliveryDate,
                                     ':id' => $orderedItemId
                                 ]);
                                 if ($updateResult && $stmtUpdateOrderedToInStock->rowCount() > 0) {
                                     $updatedInventoryCount++;
                                 } else {
                                     $failedUpdates[] = $item['product_name'] . " (status update failed)";
                                 }
                             }
                         } else {
                             // Log if the expected 'ordered' record wasn't found
                             error_log("Marking invoice {$id} delivered: Could not find 'ordered' inventory item for store '{$storeName}', product '{$item['product_name']}', expected '{$expectedArrivalDate}'. Might have been delivered/merged already or data mismatch.");
                             $failedUpdates[] = $item['product_name'] . " (ordered record not found)";
                         }
                     } // end foreach

                     $messageSuffix = "";
                     if (!empty($failedUpdates)) {
                        $messageSuffix = " Warning: Could not process delivery for some items: " . implode(', ', $failedUpdates) . ".";
                        error_log("Invoice {$id} delivery warning: " . $messageSuffix);
                     }

                     // 5. Update the invoice itself
                     $stmtUpdateInvoice = $db->prepare("UPDATE invoices SET delivered_at = CURRENT_TIMESTAMP WHERE id = :id");
                     $stmtUpdateInvoice->execute([':id' => $id]);

                     // 6. Commit transaction
                     $db->commit();
                     jsonResponse(['status' => 'success', 'message' => "Invoice ID {$id} marked as delivered. Merged {$mergedInventoryCount}, updated {$updatedInventoryCount} inventory items." . $messageSuffix]);

                 } catch (Exception $e) {
                     if ($db->inTransaction()) { $db->rollBack(); }
                     error_log("Error marking invoice {$id} as delivered: " . $e->getMessage());
                     throw new Exception("Failed to mark invoice as delivered: " . $e->getMessage(), 500);
                 }
             } else {
                 // Handle other potential PUT actions for invoices if needed in the future
                 jsonResponse(['status' => 'error', 'message' => "Unsupported PUT action for /invoices/{$id}" . ($sub_resource ? "/{$sub_resource}" : "")], 400);
             }
             break;

        case 'DELETE':
            // Delete an invoice and its items (consider cascading delete in DB schema)
            if (!$id) throw new Exception("Invoice ID required for DELETE", 400);

            $db->beginTransaction();
            try {
                // Check if invoice exists first
                $stmtCheck = $db->prepare("SELECT id FROM invoices WHERE id = :id");
                $stmtCheck->execute([':id' => $id]);
                if (!$stmtCheck->fetch()) {
                    $db->rollBack(); // No need to proceed if invoice doesn't exist
                    jsonResponse(['status' => 'error', 'message' => "Invoice ID {$id} not found"], 404);
                }

                // Delete associated items (optional if CASCADE DELETE is set on FK)
                // $stmtDeleteItems = $db->prepare("DELETE FROM invoice_items WHERE invoice_id = :id");
                // $stmtDeleteItems->execute([':id' => $id]);

                // Delete the invoice itself
                $stmtDeleteInvoice = $db->prepare("DELETE FROM invoices WHERE id = :id");
                $stmtDeleteInvoice->execute([':id' => $id]);

                if ($stmtDeleteInvoice->rowCount() > 0) {
                    $db->commit();
                    jsonResponse(['status' => 'success', 'message' => 'Invoice deleted successfully.']);
                } else {
                    // Should have been caught by the check above, but as a safeguard
                    $db->rollBack();
                    jsonResponse(['status' => 'error', 'message' => 'Invoice deletion failed (might have been deleted already).'], 404);
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                // Check for foreign key constraint errors if not using CASCADE
                // if ($e->getCode() == '23000') { ... }
                error_log("Error deleting invoice ID $id: " . $e->getMessage());
                throw new Exception("Failed to delete invoice: " . $e->getMessage(), 500);
            }
            break;
        default:
            jsonResponse(['status' => 'error', 'message' => 'Method not allowed for /invoices'], 405);
            break;
    }
} // End handleInvoiceRequest function


// --- Category Request Handler ---
// (No changes needed in this function)
function handleCategoryRequest($db, $method, $id, $inputData) {
     switch ($method) {
         case 'GET':
             // Fetch all categories
             $query = "SELECT id, name FROM categories ORDER BY name ASC";
             $stmt = $db->query($query);
             jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
             break;

         case 'POST':
             // Add a new category
             $categoryName = trim($inputData['name'] ?? '');
             if (empty($categoryName)) throw new Exception("Category name is required.", 400);

             // Check if category already exists (case-insensitive check might be better depending on DB collation)
             $stmtCheck = $db->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?)");
             $stmtCheck->execute([$categoryName]);
             if ($stmtCheck->fetchColumn() > 0) {
                 throw new Exception("Category '$categoryName' already exists.", 409); // Conflict
             }

             $db->beginTransaction();
             try {
                 $stmtInsert = $db->prepare("INSERT INTO categories (name) VALUES (?)");
                 $stmtInsert->execute([$categoryName]);
                 $newCategoryId = $db->lastInsertId();
                 $db->commit();
                 jsonResponse(['status' => 'success', 'message' => 'Category added successfully', 'id' => $newCategoryId, 'name' => $categoryName], 201); // Created
             } catch (PDOException $e) {
                 if ($db->inTransaction()) $db->rollBack();
                 // Check for unique constraint violation (just in case the initial check missed due to race condition or case sensitivity)
                 if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062)) { // MySQL specific code for duplicate entry
                     throw new Exception("Category '$categoryName' already exists.", 409);
                 }
                 error_log("Category POST Error: " . $e->getMessage());
                 throw new Exception("Failed to add category: " . $e->getMessage(), 500);
             } catch (Exception $e) { // Catch other potential exceptions
                  if ($db->inTransaction()) $db->rollBack();
                  throw $e; // Re-throw
             }
             break;

         case 'DELETE':
             // Delete a category
             if (!$id) throw new Exception("Category ID required for DELETE", 400);
             $db->beginTransaction();
             try {
                 // Check if category exists
                 $stmtCheck = $db->prepare("SELECT id FROM categories WHERE id = :id");
                 $stmtCheck->execute([':id' => $id]);
                 if (!$stmtCheck->fetch()) {
                     $db->rollBack();
                     jsonResponse(['status' => 'error', 'message' => "Category ID {$id} not found"], 404);
                 }

                 // IMPORTANT: Decide how to handle items associated with this category.
                 // Option 1: Set category_id to NULL (requires FK constraint ON DELETE SET NULL) - Assumed here
                 // Option 2: Prevent deletion if items exist (requires checking inventory/invoice_items first)
                 // Option 3: Delete associated items (requires FK constraint ON DELETE CASCADE - DANGEROUS)

                 // Assuming Option 1 (ON DELETE SET NULL in DB schema)
                 $stmtDelete = $db->prepare("DELETE FROM categories WHERE id = :id");
                 $stmtDelete->execute([':id' => $id]);

                 if ($stmtDelete->rowCount() > 0) {
                     $db->commit();
                     jsonResponse(['status' => 'success', 'message' => 'Category deleted successfully. Associated items are now uncategorized.']);
                 } else {
                     // Should have been caught by the check above
                     $db->rollBack();
                     jsonResponse(['status' => 'error', 'message' => 'Category deletion failed (might have been deleted already).'], 404);
                 }
             } catch (PDOException $e) {
                 if ($db->inTransaction()) $db->rollBack();
                 // If Option 2 was chosen and FK prevents deletion:
                 // if ($e->getCode() == '23000') { // Foreign key constraint violation
                 //     throw new Exception("Cannot delete category: It is still associated with inventory or invoice items.", 409); // Conflict
                 // }
                 error_log("Category DELETE Error: " . $e->getMessage());
                 throw new Exception("Failed to delete category: " . $e->getMessage(), 500);
              } catch (Exception $e) { // Catch other potential exceptions
                   if ($db->inTransaction()) $db->rollBack();
                   throw $e; // Re-throw
              }
             break;

         default:
             // Handle unsupported methods
             jsonResponse(['status' => 'error', 'message' => 'Method not allowed for /categories'], 405);
             break;
     }
 } // End handleCategoryRequest function
