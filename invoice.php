<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if(!isset($_SESSION['last_order'])) {
    header("Location: index.php");
    exit();
}

$order_id = $_SESSION['last_order'];

// Get order details
$stmt = $pdo->prepare("
    SELECT o.*, oi.product_id, oi.quantity, oi.price, p.name, p.description 
    FROM orders o 
    JOIN order_items oi ON o.id = oi.order_id 
    JOIN products p ON oi.product_id = p.id 
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->execute([$order_id, $_SESSION['user_id']]);
$order_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if(empty($order_items)) {
    header("Location: index.php");
    exit();
}

$order = $order_items[0];

// Calculate shipping and totals
$subtotal = 0;
foreach($order_items as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$shipping_cost = 10.00;
$grand_total = $subtotal + $shipping_cost;

// Estimate delivery date (3-7 business days)
$order_date = new DateTime($order['order_date']);
$delivery_date = clone $order_date;
$delivery_date->modify('+'.rand(3,7).' weekdays');

// Get payment method and shipping address from database
$payment_method = $order['payment_method'] ?? 'Not specified';
$shipping_address = $order['shipping_address'] ?? 'Not specified';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - ShopEasy</title>
    <link rel="stylesheet" href="style.css">
    <style>
        @media print {
            header, .invoice-actions, .continue-shopping {
                display: none !important;
            }
            .invoice {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            body {
                background: white !important;
            }
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid #333;
            padding-bottom: 1rem;
        }
        .company-info {
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .invoice-sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .invoice-section {
            background: #f9f9f9;
            padding: 1rem;
            border-radius: 5px;
            border: 1px solid #e9ecef;
        }
        .totals-breakdown {
            background: #f0f8ff;
            padding: 1.5rem;
            border-radius: 5px;
            margin: 1.5rem 0;
            border: 1px solid #cce7ff;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0;
            padding: 0.25rem 0;
        }
        .grand-total {
            border-top: 2px solid #333;
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 1rem;
            padding-top: 1rem;
        }
        .delivery-info {
            background: #fff3cd;
            padding: 1rem;
            border-radius: 5px;
            margin: 1.5rem 0;
            border: 1px solid #ffeaa7;
        }
        .thank-you {
            text-align: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: #d1ecf1;
            border-radius: 5px;
            border: 1px solid #bee5eb;
        }
        .status {
            color: #28a745;
            font-weight: bold;
            padding: 0.25rem 0.5rem;
            background: #d4edda;
            border-radius: 3px;
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
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="invoice">
            <div class="invoice-header">
                <h2>ORDER INVOICE</h2>
                <p>Thank you for your purchase!</p>
            </div>
            
            <div class="company-info">
                <h3>ShopEasy Store</h3>
                <p>123 Commerce Street</p>
                <p>Business City, BC 12345</p>
                <p>Email: support@shopeasy.com</p>
                <p>Phone: (555) 123-4567</p>
            </div>

            <div class="invoice-sections">
                <div class="invoice-section">
                    <h4>Order Information</h4>
                    <p><strong>Order ID:</strong> #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></p>
                    <p><strong>Order Date:</strong> <?php echo date('F j, Y, g:i a', strtotime($order['order_date'])); ?></p>
                    <p><strong>Order Status:</strong> <span class="status"><?php echo ucfirst($order['status']); ?></span></p>
                </div>
                
                <div class="invoice-section">
                    <h4>Shipping & Payment</h4>
                    <p><strong>Payment Method:</strong> <?php echo ucwords(str_replace('_', ' ', $payment_method)); ?></p>
                    <p><strong>Estimated Delivery:</strong> <?php echo $delivery_date->format('F j, Y'); ?></p>
                    <p><strong>Shipping Address:</strong></p>
                    <p><?php echo nl2br(htmlspecialchars($shipping_address)); ?></p>
                </div>
            </div>
            
            <h3>Order Items</h3>
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Description</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($order_items as $item): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td>₹<?php echo number_format($item['price'], 2); ?></td>
                            <td>₹<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="totals-breakdown">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>₹<?php echo number_format($subtotal, 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Shipping Cost:</span>
                    <span>₹<?php echo number_format($shipping_cost, 2); ?></span>
                </div>
                <div class="total-row">
                    <span>Tax (0%):</span>
                    <span>₹0.00</span>
                </div>
                <div class="total-row grand-total">
                    <span>Grand Total:</span>
                    <span>₹<?php echo number_format($grand_total, 2); ?></span>
                </div>
            </div>
            
            <div class="delivery-info">
                <h4>Delivery Information</h4>
                <p><strong>Shipping Method:</strong> Standard Ground Shipping</p>
                <p><strong>Estimated Delivery Duration:</strong> 3-7 business days</p>
                <p><strong>Tracking Number:</strong> TRK<?php echo str_pad($order['id'], 8, '0', STR_PAD_LEFT); ?>ESE</p>
            </div>
            
            <div class="thank-you">
                <p><strong>Thank you for shopping with ShopEasy!</strong></p>
                <p>If you have any questions about your order, please contact our customer service.</p>
            </div>
            
            <div class="invoice-actions">
                <button onclick="window.print()" class="btn">Print Invoice</button>
                <a href="index.php" class="btn continue-shopping">Continue Shopping</a>
            </div>
        </div>
    </main>
</body>
</html>