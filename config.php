<?php
// config.php - Database configuration
// IMPORTANT: Set your actual database credentials below
$db_host = 'localhost';
$db_name = 'inventory'; // Database name
$db_user = 'inventory'; // CHANGE ME: Your MariaDB/MySQL username
$db_pass = 'pass'; // CHANGE ME: Your MariaDB/MySQL password

// Connect to the database
function connectDB() {
    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Use real prepared statements
        ];
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        // Avoid echoing sensitive info in production
        // Consider throwing an exception or returning null/false for the calling code to handle
        // die("Database connection failed. Please check server logs or configuration.");
        // For API context, it's better to let the main script handle errors than using die() here
        throw new Exception("Database connection failed. Please check configuration."); // Throw exception
    }
}

// Setup database and tables if they don't exist
function setupDatabase() {
    global $db_host, $db_name, $db_user, $db_pass;
    $pdoSys = null; // Initialize to null
    try { // <--- Outer TRY block starts here
        // Connect without specific database selected initially
        $pdoSys = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Create database if it doesn't exist
        $pdoSys->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdoSys->exec("USE `$db_name`");

        // --- Create/Modify categories table first (dependency) ---
        $pdoSys->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "Checked/Created categories table.<br>";

        // --- Create/Modify inventory table ---
        $pdoSys->exec("
            CREATE TABLE IF NOT EXISTS inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_name VARCHAR(255) NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                category_id INT NULL COMMENT 'Category from last received batch', -- Added category_id
                unit_price DECIMAL(12, 4) NOT NULL COMMENT 'Order unit price if status=ordered, Average unit price if status=in_stock',
                amount INT NOT NULL COMMENT 'Amount ordered if status=ordered, Amount in stock if status=in_stock',
                status VARCHAR(20) NOT NULL DEFAULT 'ordered' COMMENT 'e.g., ordered, in_stock',
                last_received_date DATE NULL COMMENT 'Date item was last received or expected arrival for ordered',
                warranty_years DECIMAL(5, 2) NULL COMMENT 'Warranty from last received batch', -- Added warranty_years
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_last_received_date (last_received_date),
                INDEX idx_store_product (store_name, product_name),
                INDEX idx_inventory_category (category_id), -- Index for category FK
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL -- FK to categories
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "Checked/Created inventory table.<br>";

        // Ensure columns exist/Modify columns (status, last_received_date, unit_price precision, warranty_years, category_id, FKs, indices)
        $columnsResult = $pdoSys->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnsResult, 'Field'); // Get just column names

        // Status column check
        if (!in_array('status', $columns)) {
            $pdoSys->exec("ALTER TABLE inventory ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'ordered' COMMENT 'e.g., ordered, in_stock' AFTER amount");
             $indexCheck = $pdoSys->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_status'")->fetch();
             if (!$indexCheck) $pdoSys->exec("ALTER TABLE inventory ADD INDEX idx_status (status)");
            echo "Added/Checked 'status' column and index.<br>";
        }
        // Remove legacy price_paid
        if (in_array('price_paid', $columns)) {
            $pdoSys->exec("ALTER TABLE inventory DROP COLUMN price_paid");
            echo "Removed legacy 'price_paid' column.<br>";
        }
        // Last received date check (rename/add)
        if (in_array('available_from', $columns) && !in_array('last_received_date', $columns)) {
            $pdoSys->exec("ALTER TABLE inventory CHANGE available_from last_received_date DATE NULL COMMENT 'Date item was last received or expected arrival for ordered'");
             $indexCheck = $pdoSys->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_last_received_date'")->fetch();
             if (!$indexCheck) $pdoSys->exec("ALTER TABLE inventory ADD INDEX idx_last_received_date (last_received_date)");
             echo "Renamed 'available_from' to 'last_received_date' and added index.<br>";
        } elseif (!in_array('last_received_date', $columns)) {
            $pdoSys->exec("ALTER TABLE inventory ADD COLUMN last_received_date DATE NULL COMMENT 'Date item was last received or expected arrival for ordered' AFTER status");
             $indexCheck = $pdoSys->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_last_received_date'")->fetch();
             if (!$indexCheck) $pdoSys->exec("ALTER TABLE inventory ADD INDEX idx_last_received_date (last_received_date)");
             echo "Added 'last_received_date' column and index.<br>";
        }
        // Unit price precision check
         $colInfo = $pdoSys->query("SHOW COLUMNS FROM inventory LIKE 'unit_price'")->fetch(PDO::FETCH_ASSOC);
         if ($colInfo && !str_contains(strtolower($colInfo['Type']), 'decimal(12,4)')) {
             $pdoSys->exec("ALTER TABLE inventory MODIFY unit_price DECIMAL(12, 4) NOT NULL COMMENT 'Order unit price if status=ordered, Average unit price if status=in_stock'");
              echo "Adjusted 'unit_price' precision.<br>";
         }
        // Warranty years check
        if (!in_array('warranty_years', $columns)) {
            $pdoSys->exec("ALTER TABLE inventory ADD COLUMN warranty_years DECIMAL(5, 2) NULL COMMENT 'Warranty from last received batch' AFTER last_received_date");
            echo "Added 'warranty_years' column.<br>";
        }
        // Category ID check
        if (!in_array('category_id', $columns)) {
            // Add after product_name for logical grouping
            $pdoSys->exec("ALTER TABLE inventory ADD COLUMN category_id INT NULL COMMENT 'Category from last received batch' AFTER product_name");
            $indexCheck = $pdoSys->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_inventory_category'")->fetch();
            if (!$indexCheck) $pdoSys->exec("ALTER TABLE inventory ADD INDEX idx_inventory_category (category_id)");
            // Add Foreign Key Constraint (assuming categories table exists)
            $fkCheck = $pdoSys->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'category_id' AND REFERENCED_TABLE_NAME = 'categories'")->fetch();
            if (!$fkCheck) {
                try {
                    $pdoSys->exec("ALTER TABLE inventory ADD CONSTRAINT fk_inventory_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
                    echo "Added 'category_id' column, index, and foreign key.<br>";
                } catch (PDOException $e) {
                    echo "Warning: Could not add foreign key for inventory.category_id (perhaps categories table doesn't exist yet or has incompatible data?): " . $e->getMessage() . "<br>";
                }
            } else {
                 echo "Checked 'category_id' column, index, and foreign key.<br>";
            }
        }
         // *** REMOVE UNIQUE CONSTRAINT ***
         $constraintCheck = $pdoSys->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'inventory' AND CONSTRAINT_NAME = 'uq_inventory_item'")->fetch();
         if ($constraintCheck) {
             try {
                 $pdoSys->exec("ALTER TABLE inventory DROP KEY uq_inventory_item");
                 echo "Removed unique constraint 'uq_inventory_item' from inventory table.<br>";
             } catch (PDOException $e) {
                 echo "Warning: Could not remove unique constraint 'uq_inventory_item': " . $e->getMessage() . "<br>";
             }
         } else {
             echo "Checked unique constraint 'uq_inventory_item' (already removed or never existed).<br>";
         }

         // Combined index check (still useful for lookups)
         $indexCheck = $pdoSys->query("SHOW INDEX FROM inventory WHERE Key_name = 'idx_store_product'")->fetch();
         if (!$indexCheck) { $pdoSys->exec("ALTER TABLE inventory ADD INDEX idx_store_product (store_name, product_name)"); echo "Added combined index on store_name, product_name.<br>"; }


        // --- Create/Modify invoices table ---
        $pdoSys->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_name VARCHAR(255) NOT NULL,
                invoice_date DATE NOT NULL,
                scheduled_delivery_date DATE NULL,
                delivered_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when invoice marked delivered', -- Added delivered_at
                original_invoice_id VARCHAR(255) NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_invoice_date (invoice_date),
                INDEX idx_invoice_store (store_name),
                INDEX idx_delivered_at (delivered_at) -- Index for delivered_at
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "Checked/Created invoices table.<br>";
        // Ensure scheduled_delivery_date, original_invoice_id, and delivered_at columns exist
         $invoiceColumnsResult = $pdoSys->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_ASSOC);
         $invoiceColumns = array_column($invoiceColumnsResult, 'Field');
         if (!in_array('scheduled_delivery_date', $invoiceColumns)) { $pdoSys->exec("ALTER TABLE invoices ADD COLUMN scheduled_delivery_date DATE NULL AFTER invoice_date"); echo "Added 'scheduled_delivery_date' column to invoices table.<br>"; }
         if (!in_array('original_invoice_id', $invoiceColumns)) { $pdoSys->exec("ALTER TABLE invoices ADD COLUMN original_invoice_id VARCHAR(255) NULL AFTER notes"); echo "Added 'original_invoice_id' column to invoices table.<br>"; }
         // *** ADD CHECK FOR delivered_at ***
         if (!in_array('delivered_at', $invoiceColumns)) {
             $pdoSys->exec("ALTER TABLE invoices ADD COLUMN delivered_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when invoice marked delivered' AFTER scheduled_delivery_date");
             $indexCheck = $pdoSys->query("SHOW INDEX FROM invoices WHERE Key_name = 'idx_delivered_at'")->fetch();
             if (!$indexCheck) $pdoSys->exec("ALTER TABLE invoices ADD INDEX idx_delivered_at (delivered_at)");
             echo "Added 'delivered_at' column and index to invoices table.<br>";
         }


        // --- Create/Modify invoice_items table ---
        $pdoSys->exec("
            CREATE TABLE IF NOT EXISTS invoice_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                category_id INT NULL, -- Added previously
                quantity_purchased INT NOT NULL,
                unit_price_paid DECIMAL(12, 4) NOT NULL COMMENT 'Actual unit price paid for this batch',
                warranty_years DECIMAL(5, 2) NULL DEFAULT NULL, -- Changed default to NULL, allow NULL values
                FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL, -- Added previously
                INDEX idx_item_product (product_name)
                -- Removed index on category_id here, might be redundant with FK index
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "Checked/Created invoice_items table.<br>";
        // Ensure warranty_years and category_id columns exist
         $invoiceItemColumnsResult = $pdoSys->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_ASSOC);
         $invoiceItemColumns = array_column($invoiceItemColumnsResult, 'Field');
        if (!in_array('warranty_years', $invoiceItemColumns)) {
             $pdoSys->exec("ALTER TABLE invoice_items ADD COLUMN warranty_years DECIMAL(5, 2) NULL DEFAULT NULL AFTER unit_price_paid"); // Add as NULLable, default NULL
             echo "Added 'warranty_years' column to invoice_items table.<br>";
        } else {
            // Ensure it allows NULL and default is NULL
             $colInfo = $pdoSys->query("SHOW COLUMNS FROM invoice_items LIKE 'warranty_years'")->fetch(PDO::FETCH_ASSOC);
             if ($colInfo && (strtoupper($colInfo['Null']) === 'NO' || $colInfo['Default'] !== NULL)) {
                 $pdoSys->exec("ALTER TABLE invoice_items MODIFY warranty_years DECIMAL(5, 2) NULL DEFAULT NULL"); // Change to allow NULL, default NULL
                 echo "Modified 'warranty_years' column to allow NULL.<br>";
             }
        }
        if (!in_array('category_id', $invoiceItemColumns)) {
              $pdoSys->exec("ALTER TABLE invoice_items ADD COLUMN category_id INT NULL AFTER product_name");
              // Try adding FK, might fail if categories table doesn't exist *yet* during a very first run
              try {
                  $pdoSys->exec("ALTER TABLE invoice_items ADD CONSTRAINT fk_invoice_item_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
                  echo "Added 'category_id' column and foreign key to invoice_items table.<br>";
              } catch (PDOException $e) {
                   echo "Warning: Could not add foreign key for invoice_items.category_id (perhaps categories table doesn't exist yet?): " . $e->getMessage() . "<br>";
              }
        }

        // --- Remove invoice_item_units table (Obsolete) ---
        if ($pdoSys->query("SHOW TABLES LIKE 'invoice_item_units'")->rowCount() > 0) {
             $pdoSys->exec("DROP TABLE IF EXISTS invoice_item_units");
             echo "Removed obsolete 'invoice_item_units' table.<br>";
        }

        echo "Database setup checked/completed successfully!";

    } // <--- Outer TRY block ends here
    catch (PDOException $e) { // This catch corresponds to the OUTER try block
        error_log("Database setup failed: " . $e->getMessage());
        die("Database setup failed. Check server logs. Error: " . $e->getMessage()); // Provide slightly more info for direct setup call
    } finally {
        // Ensure connection is closed
        $pdoSys = null;
    }
} // End of setupDatabase function

// Run setup if requested via URL parameter
if (isset($_GET['setup']) && $_GET['setup'] === 'true') {
    setupDatabase();
    // Check for add_categories flag only if setup is being run
    if (isset($_GET['add_categories']) && $_GET['add_categories'] === 'true') {
        addInitialCategories();
    }
    exit; // Stop script after setup
}

// Example of adding initial categories (run once after setup)
function addInitialCategories() {
    $db = null;
    try {
        $db = connectDB();
        $categories = ['Electronics', 'Books', 'Clothing', 'Furniture', 'Groceries', 'Other'];
        $stmt = $db->prepare("INSERT IGNORE INTO categories (name) VALUES (?)"); // INSERT IGNORE prevents duplicates
        $db->beginTransaction();
        foreach ($categories as $category) {
            $stmt->execute([$category]);
        }
        $db->commit();
        echo "Initial categories added/checked.<br>";
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        error_log("Failed to add initial categories: " . $e->getMessage());
        echo "Failed to add initial categories: " . $e->getMessage() . "<br>";
    } catch (Exception $e) { // Catch connection errors etc.
         error_log("Failed to add initial categories: " . $e->getMessage());
         echo "Failed to add initial categories: " . $e->getMessage() . "<br>";
    } finally {
        $db = null; // Close connection
    }
}
?>
