# Inventory Management System

A straightforward web-based inventory management solution designed to track ordered items and manage invoices.

View Inventory:
![View Inventory](./view%20inventory.png)

Add Invoice:
![Add Invoice](./add%20invoice.png)

## Features

- **Invoice Management**: Create and manage invoices for ordered items
- **Delivery Tracking**: Monitor items with pending delivery status
- **Categorization**: Organize items by categories (electronics, books, etc.)
- **Store Management**: Track items by store location
- **Search & Filter**: Easily locate items by ID, product name, store, or category
- **Delivery Status Updates**: Mark invoices as delivered to update inventory status
- **Automatic Unit Price Calculation**: System calculates price per unit (e.g., if quantity is 10 pieces and total price paid is 12€, it will calculate 1.2€/unit)
- **User-friendly Interface**: Clean, intuitive design for ease of use

## Requirements

- PHP 7.4+ (8.0+ recommended)
- MySQL or MariaDB database

## Installation

### Setup Instructions

1. **Upload files to your web server**
   - `style.css`
   - `invoices.php`
   - `index.php`
   - `app.js`
   - `api.php`
   - `config.php`

2. **Edit the database configuration**
   Open `config.php` and update the database credentials:
   ```php
   // config.php - Database configuration
   // IMPORTANT: Set your actual database credentials below
   $db_host = 'localhost';
   $db_name = 'inventory'; // Database name
   $db_user = 'inventory'; // CHANGE ME: Your MariaDB/MySQL username
   $db_pass = 'cWBjKKP24nym07laRPS3'; // CHANGE ME: Your MariaDB/MySQL password 
   ```

3. **Run the setup script**
   Navigate to `https://example.com/config.php?setup=true` in your browser to initialize the database.

4. **Access your inventory system**
   Open `https://example.com` in your browser.

## Usage

### Creating Invoices & Tracking Orders

1. Navigate to the "Invoices/Receive Stock" tab
2. Fill in the invoice details including store name, date, and scheduled delivery date
3. Add products with quantities, total prices, and warranty information
4. The system automatically calculates the unit price (e.g., 10 pieces at 12€ total = 1.2€ per unit)
5. Click "Save Invoice & Update Stock" to record the order
6. Items will appear in "Ordered Items (Pending Arrival)" until marked as delivered

### Managing Inventory

1. Go to the "Inventory/Orders" tab
2. Use the search and filter options to find specific items by ID, product name, store, or category
3. View items with pending delivery in the "Ordered Items" section
4. Update delivery status through the invoice management interface

### Category Management

Organize your inventory with custom categories like:
- Electronics
- Books
- Office Supplies
- Furniture
- And more!

## Security Notes

- Consider implementing additional authentication for the system

## Troubleshooting

- If you encounter a database connection error, verify your database credentials in `config.php`
- For permission issues, ensure your web server has write access to the application directory
- Check the PHP error logs if the setup script fails to execute

## About

This inventory management system was created with the assistance of AI to provide a simple yet effective solution for tracking ordered items and managing invoices.

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

I do not provide any support for this, and take no responsibility for any data loss that may occur.
