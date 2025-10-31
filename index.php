<?php
session_start();
include 'config.php';

// Check if database connection is successful
if(!isset($pdo)) {
    die("Database connection failed. Please check your configuration.");
}

// Handle product addition (admin functionality)
if(isset($_POST['add_product']) && isset($_SESSION['user_id'])) {
    // Simple admin check - in real app, you'd have proper admin roles
    $admin_users = ['admin', 'manager']; // Example admin usernames
    if(in_array($_SESSION['username'], $admin_users)) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        $stock = $_POST['stock'];
        $image = 'default.jpg'; // Default image
        
        // Handle image upload
        if(isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/';
            if(!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = uniqid() . '_' . basename($_FILES['product_image']['name']);
            $targetPath = $uploadDir . $fileName;
            
            // Check file type
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
            $fileExtension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
            
            if(in_array($fileExtension, $allowedTypes)) {
                // Check file size (2MB max)
                if($_FILES['product_image']['size'] <= 2 * 1024 * 1024) {
                    if(move_uploaded_file($_FILES['product_image']['tmp_name'], $targetPath)) {
                        $image = $fileName;
                    } else {
                        $error = "Failed to upload image.";
                    }
                } else {
                    $error = "Image size too large. Maximum 2MB allowed.";
                }
            } else {
                $error = "Invalid file type. Only JPG, PNG, GIF allowed.";
            }
        }
        
        if(!isset($error)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, image, stock) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $price, $image, $stock]);
                $success = "Product added successfully!";
                
                // Refresh the page to show the new product
                header("Location: index.php");
                exit();
            } catch(PDOException $e) {
                $error = "Error adding product: " . $e->getMessage();
            }
        }
    }
}

// Handle product deletion (admin functionality)
if(isset($_POST['delete_product']) && isset($_SESSION['user_id'])) {
    $admin_users = ['admin', 'manager'];
    if(in_array($_SESSION['username'], $admin_users)) {
        $product_id = $_POST['product_id'];
        
        try {
            // First delete from order_items to maintain referential integrity
            $stmt = $pdo->prepare("DELETE FROM order_items WHERE product_id = ?");
            $stmt->execute([$product_id]);
            
            // Then delete the product
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $success = "Product deleted successfully!";
            
            // Refresh the page
            header("Location: index.php");
            exit();
        } catch(PDOException $e) {
            $error = "Error deleting product: " . $e->getMessage();
        }
    }
}

// Get user's orders for the orders page
$user_orders = [];
if(isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $user_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // Silently fail - orders will just be empty
    }
}

try {
    // Fetch products
    $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Error fetching products: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Commerce Dashboard - ShopEasy</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>ShopEasy</h1>
            <nav>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <li><a href="cart.php">Cart</a></li>
                        <li><a href="orders.php">Orders (<?php echo count($user_orders); ?>)</a></li>
                        <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="signup.php">Sign Up</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h2>Our Products</h2>
        
        <!-- Admin Product Management -->
        <?php 
        $admin_users = ['admin', 'manager'];
        if(isset($_SESSION['user_id']) && in_array($_SESSION['username'], $admin_users)): 
        ?>
            <div class="admin-panel">
                <h3>Product Management</h3>
                <?php if(isset($success)): ?>
                    <div class="success"><?php echo $success; ?></div>
                <?php endif; ?>
                <?php if(isset($error)): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="admin-actions">
                    <div class="add-product-form">
                        <h4>Add New Product</h4>
                        <form method="POST" class="product-form" enctype="multipart/form-data">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="name">Product Name:</label>
                                    <input type="text" id="name" name="name" required>
                                </div>
                                <div class="form-group">
                                    <label for="price">Price (₹):</label>
                                    <input type="number" id="price" name="price" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="description">Description:</label>
                                <textarea id="description" name="description" required placeholder="Enter product description"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="stock">Stock Quantity:</label>
                                <input type="number" id="stock" name="stock" min="0" required>
                            </div>
                            <div class="form-group">
                                <label for="product_image">Product Image:</label>
                                <input type="file" id="product_image" name="product_image" accept="image/*">
                                <small>Supported formats: JPG, PNG, GIF (Max 2MB)</small>
                            </div>
                            <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="products">
            <?php if(empty($products)): ?>
                <div class="no-products">
                    <p>No products available at the moment.</p>
                    <?php if(isset($_SESSION['user_id']) && in_array($_SESSION['username'], ['admin', 'manager'])): ?>
                        <p>Use the form above to add products.</p>
                    <?php else: ?>
                        <p>Please check back later or <a href="login.php">login</a> to see more options.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach($products as $product): ?>
                    <div class="product-card">
                        <div class="product-image">
                            <?php 
                            $imagePath = '';
                            if($product['image'] && $product['image'] != 'default.jpg') {
                                // Check if image path already includes uploads/
                                if(strpos($product['image'], 'uploads/') === 0) {
                                    $imagePath = $product['image'];
                                } else {
                                    $imagePath = 'uploads/' . $product['image'];
                                }
                                
                                if(file_exists($imagePath)) {
                                    echo '<img src="' . $imagePath . '" alt="' . htmlspecialchars($product['name']) . '" style="width: 100%; height: 200px; object-fit: cover; border-radius: 5px;">';
                                } else {
                                    echo '<div class="placeholder-image">' . strtoupper(substr($product['name'], 0, 2)) . '</div>';
                                }
                            } else {
                                echo '<div class="placeholder-image">' . strtoupper(substr($product['name'], 0, 2)) . '</div>';
                            }
                            ?>
                        </div>
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p class="description"><?php echo htmlspecialchars($product['description']); ?></p>
                        <p class="price">₹<?php echo number_format($product['price'], 2); ?></p>
                        <p class="stock <?php echo $product['stock'] > 0 ? 'in-stock' : 'out-of-stock'; ?>">
                            <?php echo $product['stock'] > 0 ? "In stock: {$product['stock']}" : "Out of stock"; ?>
                        </p>
                        
                        <?php if(isset($_SESSION['user_id'])): ?>
                            <?php if($product['stock'] > 0): ?>
                                <button class="add-to-cart" data-id="<?php echo $product['id']; ?>">Add to Cart</button>
                            <?php else: ?>
                                <button class="btn-disabled" disabled>Out of Stock</button>
                            <?php endif; ?>
                            
                            <!-- Admin delete button -->
                            <?php 
                            if(isset($_SESSION['user_id']) && in_array($_SESSION['username'], $admin_users)): 
                            ?>
                                <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <button type="submit" name="delete_product" class="btn-delete">Delete Product</button>
                                </form>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <a href="login.php" class="btn">Login to Purchase</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="script.js"></script>
</body>
</html>