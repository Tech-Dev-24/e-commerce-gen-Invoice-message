<?php
// fix_database.php - Run this once to fix the database structure
include 'config.php';

try {
    // Add missing columns to orders table if they don't exist
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50)");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_address TEXT");
    
    echo "Database structure updated successfully!<br>";
    echo "Missing columns have been added to the orders table.<br>";
    echo "You can now use the cart and checkout features properly.<br>";
    echo '<a href="index.php">Go to Home</a> | <a href="cart.php">Go to Cart</a>';
    
} catch(PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
}
?>