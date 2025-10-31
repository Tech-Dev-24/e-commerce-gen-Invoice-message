<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle adding to cart via AJAX
if(isset($_POST['add_to_cart'])) {
    $product_id = $_POST['product_id'];
    
    if(!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    if(isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]++;
    } else {
        $_SESSION['cart'][$product_id] = 1;
    }
    
    echo json_encode(['success' => true]);
    exit();
}

// Handle quantity update
if(isset($_POST['update_quantity'])) {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    
    if($quantity <= 0) {
        unset($_SESSION['cart'][$product_id]);
    } else {
        $_SESSION['cart'][$product_id] = $quantity;
    }
    
    header("Location: cart.php");
    exit();
}

// Handle remove item
if(isset($_POST['remove_item'])) {
    $product_id = $_POST['product_id'];
    unset($_SESSION['cart'][$product_id]);
    
    header("Location: cart.php");
    exit();
}

// Handle clear cart
if(isset($_POST['clear_cart'])) {
    $_SESSION['cart'] = [];
    header("Location: cart.php");
    exit();
}

// Handle checkout
if(isset($_POST['checkout'])) {
    $payment_method = $_POST['payment_method'];
    $shipping_address = $_POST['shipping_address'];
    
    // Validate required fields
    if(empty($payment_method) || empty($shipping_address)) {
        $error = "Please fill in all required fields.";
    } else {
        $transactionStarted = false;
        try {
            // First, check if the orders table has the required columns
            $check_columns = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_method'");
            if($check_columns->rowCount() == 0) {
                throw new Exception("Database structure is outdated. Please run the database fix script.");
            }
            
            // Start transaction only if not already in one
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }
            
            // Create order
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, payment_method, shipping_address) VALUES (?, ?, ?, ?)");
            $total = 0;
            
            // Calculate total and check stock availability
            foreach($_SESSION['cart'] as $product_id => $quantity) {
                $product_stmt = $pdo->prepare("SELECT id, name, price, stock FROM products WHERE id = ?");
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch();
                
                if(!$product) {
                    throw new Exception("Product not found.");
                }
                
                if($product['stock'] < $quantity) {
                    throw new Exception("Insufficient stock for {$product['name']}. Available: {$product['stock']}, Requested: {$quantity}");
                }
                
                $total += $product['price'] * $quantity;
            }
            
            // Add shipping cost
            $shipping_cost = 10.00;
            $grand_total = $total + $shipping_cost;
            
            $stmt->execute([$_SESSION['user_id'], $grand_total, $payment_method, $shipping_address]);
            $order_id = $pdo->lastInsertId();
            
            // Add order items and update stock
            foreach($_SESSION['cart'] as $product_id => $quantity) {
                $product_stmt = $pdo->prepare("SELECT price, stock FROM products WHERE id = ?");
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch();
                
                $item_stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $item_stmt->execute([$order_id, $product_id, $quantity, $product['price']]);
                
                // Update product stock
                $update_stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $update_stmt->execute([$quantity, $product_id]);
            }
            
            // Commit only if we started the transaction
            if ($transactionStarted) {
                $pdo->commit();
            }
            
            $_SESSION['cart'] = [];
            $_SESSION['last_order'] = $order_id;
            header("Location: invoice.php");
            exit();
            
        } catch(Exception $e) {
            // Rollback only if we started the transaction and it's still active
            if ($transactionStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Checkout failed: " . $e->getMessage();
        }
    }
}

// Get cart items with details
$cart_items = [];
$total = 0;
$cart_count = 0;

if(isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    foreach($_SESSION['cart'] as $product_id => $quantity) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if($product) {
            $product['quantity'] = $quantity;
            $product['subtotal'] = $product['price'] * $quantity;
            $total += $product['subtotal'];
            $cart_count += $quantity;
            $cart_items[] = $product;
        }
    }
}

$shipping_cost = 10.00;
$grand_total = $total + $shipping_cost;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - ShopEasy</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>ShopEasy</h1>
            <nav>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="cart.php">Cart (<?php echo $cart_count; ?>)</a></li>
                    <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h2>Your Shopping Cart</h2>
        
        <?php if(empty($cart_items)): ?>
            <div class="empty-cart">
                <p>Your cart is empty.</p>
                <a href="index.php" class="btn">Continue Shopping</a>
            </div>
        <?php else: ?>
            <?php if(isset($error)): ?>
                <div class="error">
                    <strong>Error:</strong> <?php echo $error; ?>
                    <?php if(strpos($error, 'Database structure is outdated') !== false): ?>
                        <br><small>Please <a href="fix_database.php" style="color: #c62828;">run the database fix script</a> to resolve this issue.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['success'])): ?>
                <div class="success"><?php echo htmlspecialchars($_GET['success']); ?></div>
            <?php endif; ?>
            
            <div class="cart-actions">
                <form method="POST">
                    <button type="submit" name="clear_cart" class="btn-clear" onclick="return confirm('Are you sure you want to clear your entire cart?');">
                        Clear Entire Cart
                    </button>
                </form>
            </div>
            
            <div class="cart-items">
                <?php foreach($cart_items as $item): ?>
                    <div class="cart-item">
                        <div class="item-image">
                            <div class="placeholder-image">
                                <?php echo strtoupper(substr($item['name'], 0, 2)); ?>
                            </div>
                        </div>
                        <div class="item-details">
                            <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                            <p class="item-description"><?php echo htmlspecialchars($item['description']); ?></p>
                            <p class="price">₹<?php echo number_format($item['price'], 2); ?></p>
                            
                            <form method="POST" class="quantity-form">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <div class="quantity-controls">
                                    <label for="quantity_<?php echo $item['id']; ?>">Quantity:</label>
                                    <input type="number" id="quantity_<?php echo $item['id']; ?>" name="quantity" 
                                           value="<?php echo $item['quantity']; ?>" min="1" max="<?php echo min(10, $item['stock']); ?>">
                                    <button type="submit" name="update_quantity" class="btn-small">Update</button>
                                </div>
                                <small>Max: <?php echo $item['stock']; ?> available</small>
                            </form>
                            
                            <p class="subtotal">Subtotal: ₹<?php echo number_format($item['subtotal'], 2); ?></p>
                            
                            <form method="POST" class="remove-form">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" name="remove_item" class="btn-remove">Remove Item</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="cart-summary">
                <h3>Order Summary</h3>
                <div class="summary-item">
                    <span>Subtotal (<?php echo $cart_count; ?> items):</span>
                    <span>₹<?php echo number_format($total, 2); ?></span>
                </div>
                <div class="summary-item">
                    <span>Shipping:</span>
                    <span>₹<?php echo number_format($shipping_cost, 2); ?></span>
                </div>
                <div class="summary-item">
                    <span>Tax:</span>
                    <span>₹0.00</span>
                </div>
                <div class="summary-item total">
                    <span>Grand Total:</span>
                    <span>₹<?php echo number_format($grand_total, 2); ?></span>
                </div>
            </div>

            <div class="checkout-form">
                <h3>Checkout Details</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="shipping_address">Shipping Address: *</label>
                        <textarea id="shipping_address" name="shipping_address" required 
                                  placeholder="Enter your complete shipping address including street, city, state, and zip code"><?php echo isset($_POST['shipping_address']) ? htmlspecialchars($_POST['shipping_address']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method">Payment Method: *</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">Select Payment Method</option>
                            <option value="credit_card" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'credit_card') ? 'selected' : ''; ?>>Credit Card</option>
                            <option value="debit_card" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'debit_card') ? 'selected' : ''; ?>>Debit Card</option>
                            <option value="paypal" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'paypal') ? 'selected' : ''; ?>>PayPal</option>
                            <option value="cash_on_delivery" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'cash_on_delivery') ? 'selected' : ''; ?>>Cash on Delivery</option>
                            <option value="bank_transfer" <?php echo (isset($_POST['payment_method']) && $_POST['payment_method'] == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                        </select>
                    </div>
                    
                    <div class="checkout-actions">
                        <a href="index.php" class="btn-continue">Continue Shopping</a>
                        <button type="submit" name="checkout" class="btn-checkout">Proceed to Checkout</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <script src="script.js"></script>
</body>
</html>