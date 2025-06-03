<?php 
    // Enable error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    require "../includes/header.php"; 
    require "../config/config.php";
    
    // Debug session
    error_log("Session data in transactions: " . print_r($_SESSION, true));

    if(!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        error_log("Missing session data - username: " . (isset($_SESSION['username']) ? 'set' : 'not set') . 
                 ", user_id: " . (isset($_SESSION['user_id']) ? 'set' : 'not set'));
        echo "<script> window.location.href='".APPURL."'; </script>";
        exit();
    }

    if(isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $session_id = (int)$_SESSION['user_id'];
        error_log("Transactions page - User ID from GET: " . $id);
        error_log("Transactions page - Session user_id: " . $session_id);

        if($id !== $session_id) {
            error_log("ID mismatch: GET ID = " . $id . ", Session ID = " . $session_id);
            echo "<script> window.location.href='".APPURL."'; </script>";
            exit();
        }

        try {
            // First verify the user exists
            $userCheck = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $userCheck->execute([$id]);
            if (!$userCheck->fetch()) {
                error_log("User not found with ID: " . $id);
                echo "<script>alert('User not found!'); window.location.href='".APPURL."';</script>";
                exit();
            }

            // Use prepared statement to prevent SQL injection
            $select = $conn->prepare("
                SELECT o.*
                FROM orders o
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
            ");
            
            if (!$select->execute([$id])) {
                error_log("Failed to execute orders query for user ID: " . $id);
                throw new PDOException("Failed to execute query");
            }
            
            $allOrders = $select->fetchAll(PDO::FETCH_OBJ);
            
            if(!$allOrders) {
                error_log("No orders found for user ID: " . $id);
            } else {
                error_log("Orders found: " . count($allOrders));
                error_log("First order data: " . print_r($allOrders[0], true));
            }
        } catch(PDOException $e) {
            error_log("Database error in transactions: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            error_log("Error Info: " . print_r($select->errorInfo(), true));
            error_log("User ID: " . $id);
            error_log("Session ID: " . $session_id);
            echo "<script>alert('Database error occurred! Please try again later.'); window.location.href='".APPURL."';</script>";
            exit();
        }
    } else {
        error_log("No ID parameter in URL");
        echo "<script> window.location.href='".APPURL."/404.php'; </script>";
        exit();
    }
?>
    <div id="page-content" class="page-content">
        <div class="banner">
            <div class="jumbotron jumbotron-bg text-center rounded-0" style="background-image: url('<?php echo APPURL; ?>/assets/img/bg-header.jpg');">
                <div class="container">
                    <h1 class="pt-5">
                        Your Orders
                    </h1>
                    <p class="lead">
                        View your order history
                    </p>
                </div>
            </div>
        </div>

        <section id="checkout">
            <div class="container">
                <div class="row">
                    <div class="col-xs-12 col-sm-12">
                        <h5 class="mb-3">ORDER HISTORY</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Address</th>
                                        <th>Total Price</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($allOrders)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No orders found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($allOrders as $order): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($order->id); ?></td>
                                                <td><?php echo htmlspecialchars($order->name . ' ' . $order->lname); ?></td>
                                                <td><?php echo htmlspecialchars($order->email); ?></td>
                                                <td><?php echo htmlspecialchars($order->phone_number); ?></td>
                                                <td>
                                                    <?php 
                                                        echo htmlspecialchars($order->address . ', ' . 
                                                            $order->city . ', ' . 
                                                            $order->country . ' ' . 
                                                            $order->zip_code); 
                                                    ?>
                                                </td>
                                                <td>USD <?php echo number_format($order->price, 2); ?></td>
                                                <td>
                                                    <?php if($order->status == 'Pending'): ?>
                                                        <span class="badge badge-warning">Pending</span>
                                                    <?php elseif($order->status == 'Confirmed'): ?>
                                                        <span class="badge badge-info">Confirmed</span>
                                                    <?php elseif($order->status == 'Completed'): ?>
                                                        <span class="badge badge-success">Completed</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($order->status); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($order->created_at)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php require "../includes/footer.php"; ?>
