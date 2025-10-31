<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle cancel order request
if(isset($_POST['cancel_order'])) {
    $order_id = $_POST['order_id'];
    
    // Verify the order belongs to the current user and is still pending
    $stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if($order) {
        if($order['status'] == 'pending') {
            try {
                // Start transaction
                $pdo->beginTransaction();
                
                // Restore product stock first
                $items_stmt = $pdo->prepare("
                    SELECT product_id, quantity 
                    FROM order_items 
                    WHERE order_id = ?
                ");
                $items_stmt->execute([$order_id]);
                $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Restore stock for each product
                foreach($order_items as $item) {
                    $update_stmt = $pdo->prepare("
                        UPDATE products 
                        SET stock = stock + ? 
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$item['quantity'], $item['product_id']]);
                }
                
                // Update order status to cancelled
                $update_order_stmt = $pdo->prepare("
                    UPDATE orders 
                    SET status = 'cancelled' 
                    WHERE id = ? AND user_id = ?
                ");
                $update_order_stmt->execute([$order_id, $_SESSION['user_id']]);
                
                $pdo->commit();
                $success = "Order #" . str_pad($order_id, 6, '0', STR_PAD_LEFT) . " has been cancelled successfully.";
                
            } catch(Exception $e) {
                $pdo->rollBack();
                $error = "Failed to cancel order: " . $e->getMessage();
            }
        } else {
            $error = "Cannot cancel order. Order status is: " . $order['status'];
        }
    } else {
        $error = "Order not found or access denied.";
    }
}

// Get user's orders
$user_orders = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COUNT(oi.id) as item_count,
               SUM(oi.quantity) as total_items
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        WHERE o.user_id = ? 
        GROUP BY o.id 
        ORDER BY o.order_date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "Error fetching orders: " . $e->getMessage();
}

// Handle view invoice request
if(isset($_GET['view_invoice'])) {
    $order_id = $_GET['view_invoice'];
    
    // Verify the order belongs to the current user
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $_SESSION['user_id']]);
    $order = $stmt->fetch();
    
    if($order) {
        $_SESSION['last_order'] = $order_id;
        header("Location: invoice.php");
        exit();
    } else {
        $error = "Order not found or access denied.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - ShopEasy</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .orders-container {
            background: white;
            padding: 2rem;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .order-card {
            border: 1px solid #e9ecef;
            border-radius: 5px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .order-info {
            flex: 1;
        }
        
        .order-actions {
            display: flex;
            gap: 1rem;
        }
        
        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-weight: bold;
            color: #666;
            font-size: 0.9rem;
        }
        
        .detail-value {
            font-size: 1.1rem;
        }
        
        .no-orders {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .status-pending {
            color: #ffc107;
            font-weight: bold;
        }
        
        .status-completed {
            color: #28a745;
            font-weight: bold;
        }
        
        .status-cancelled {
            color: #dc3545;
            font-weight: bold;
        }
        
        .status-shipped {
            color: #17a2b8;
            font-weight: bold;
        }
        
        .btn-cancel {
            background: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
        }
        
        .btn-cancel:hover {
            background: #c82333;
        }
        
        .btn-cancel:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        .cancellation-note {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 3px;
            font-size: 0.8rem;
            color: #856404;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>ShopEasy</h1>
            <nav>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="cart.php">Cart</a></li>
                    <li><a href="orders.php">Orders (<?php echo count($user_orders); ?>)</a></li>
                    <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h2>My Orders</h2>
        
        <?php if(isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if(isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="orders-container">
            <?php if(empty($user_orders)): ?>
                <div class="no-orders">
                    <h3>No orders yet</h3>
                    <p>You haven't placed any orders yet.</p>
                    <a href="index.php" class="btn">Start Shopping</a>
                </div>
            <?php else: ?>
                <?php foreach($user_orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div class="order-info">
                                <h3>Order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></h3>
                                <p class="order-date">Placed on <?php echo date('F j, Y, g:i a', strtotime($order['order_date'])); ?></p>
                            </div>
                            <div class="order-actions">
                                <a href="orders.php?view_invoice=<?php echo $order['id']; ?>" class="btn">View Invoice</a>
                                
                                <!-- Cancel Order Button -->
                                <?php if($order['status'] == 'pending'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" name="cancel_order" class="btn-cancel" 
                                                onclick="return confirm('Are you sure you want to cancel order #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?>?')">
                                            Cancel Order
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="order-details">
                            <div class="detail-item">
                                <span class="detail-label">Total Amount</span>
                                <span class="detail-value">₹<?php echo number_format($order['total'], 2); ?></span>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Items</span>
                                <span class="detail-value"><?php echo $order['total_items'] ?? 0; ?> items</span>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value status-<?php echo $order['status']; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </span>
                            </div>
                            
                            <div class="detail-item">
                                <span class="detail-label">Payment Method</span>
                                <span class="detail-value"><?php echo ucwords(str_replace('_', ' ', $order['payment_method'] ?? 'N/A')); ?></span>
                            </div>
                        </div>
                        
                        <!-- Cancellation Note -->
                        <?php if($order['status'] == 'cancelled'): ?>
                            <div class="cancellation-note">
                                <strong>Note:</strong> This order has been cancelled. Stock has been restored.
                            </div>
                        <?php elseif($order['status'] == 'shipped'): ?>
                            <div class="cancellation-note" style="background: #d1ecf1; border-color: #bee5eb; color: #0c5460;">
                                <strong>Note:</strong> This order has been shipped and cannot be cancelled.
                            </div>
                        <?php elseif($order['status'] == 'completed'): ?>
                            <div class="cancellation-note" style="background: #d4edda; border-color: #c3e6cb; color: #155724;">
                                <strong>Note:</strong> This order has been completed.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="script.js"></script>
</body>
</html>