<?php 

    if(!isset($_SERVER['HTTP_REFERER'])){
        // redirect them to your desired location
        header('location: http://localhost/Freshcery/index.php');
        exit;
    }

?>
<?php require "../includes/header.php"; ?>
<?php require "../config/config.php"; ?>
<?php 
    if(!isset($_SESSION['username'])) {
        echo "<script> window.location.href='".APPURL."'; </script>";
    }

    $products = $conn->query("SELECT * FROM cart WHERE user_id='$_SESSION[user_id]'");
    $products->execute();

    $allProducts = $products->fetchAll(PDO::FETCH_OBJ);

    // Initialize price variables
    $shipping_cost = 20;
    $total_price = 0;
    $cart_subtotal = 0;

    // Calculate cart subtotal from products
    foreach($allProducts as $product) {
        $cart_subtotal += floatval($product->pro_price) * intval($product->pro_qty);
    }

    // Set session price if not set
    if(!isset($_SESSION['price']) || empty($_SESSION['price'])) {
        $_SESSION['price'] = $cart_subtotal;
    }

    // Calculate total price
    $total_price = floatval($_SESSION['price']) + $shipping_cost;
    $_SESSION['total_price'] = $total_price;

    if(isset($_POST['submit'])) {
        if(empty($_POST['name']) OR empty($_POST['lname']) OR empty($_POST['company_name'])
        OR empty($_POST['address']) OR empty($_POST['city']) OR empty($_POST['country'])
        OR empty($_POST['zip_code']) OR empty($_POST['email']) OR empty($_POST['phone_number'])
        OR empty($_POST['order_notes'])) {
            echo "<script>alert('one or more inputs are empty')</script>";
        } else {
            $name = $_POST['name'];
            $lname = $_POST['lname'];
            $company_name = $_POST['company_name'];
            $address = $_POST['address'];
            $city = $_POST['city'];
            $country = $_POST['country'];
            $zip_code = $_POST['zip_code'];
            $email = $_POST['email'];
            $phone_number = $_POST['phone_number'];
            $order_notes = $_POST['order_notes'];
            $price = floatval($_SESSION['total_price']);
            $user_id = $_SESSION['user_id'];

            $insert = $conn->prepare("INSERT INTO orders(name, lname, company_name, address, city,
           country, zip_code, email, phone_number, order_notes, price, user_id)
            VALUES(:name, :lname, :company_name, :address, :city, :country, :zip_code, :email, :phone_number,
            :order_notes, :price, :user_id)");

            $insert->execute([
                ":name" => $name,
                ":lname" => $lname,
                ":company_name" => $company_name,
                ":address" => $address,
                ":city" => $city,
                ":country" => $country,
                ":zip_code" => $zip_code,
                ":email" => $email,
                ":phone_number" => $phone_number,
                ":order_notes" => $order_notes,
                ":price" => $price,
                ":user_id" => $user_id,
            ]);

            echo "<script> window.location.href='".APPURL."/products/charge.php'; </script>";
        }
    }
?>
    <div id="page-content" class="page-content">
        <div class="banner">
            <div class="jumbotron jumbotron-bg text-center rounded-0" style="background-image: url('<?php echo APPURL; ?>/assets/img/bg-header.jpg');">
                <div class="container">
                    <h1 class="pt-5">
                        Checkout
                    </h1>
                    <p class="lead">
                        Save time and leave the groceries to us.
                    </p>
                </div>
            </div>
        </div>

        <section id="checkout">
            <div class="container">
                <div class="row">
                    <div class="col-xs-12 col-sm-7">
                        <h5 class="mb-3">BILLING DETAILS</h5>
                        <!-- Bill Detail of the Page -->
                        <form action="checkout.php" method="POST" class="bill-detail">
                            <fieldset>
                                <div class="form-group row">
                                    <div class="col">
                                        <input class="form-control" placeholder="Name" type="text" name="name" required>
                                    </div>
                                    <div class="col">
                                        <input class="form-control" placeholder="Last Name" type="text" name="lname" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" placeholder="Company Name" type="text" name="company_name" required>
                                </div>
                                <div class="form-group">
                                    <textarea class="form-control" name="address" placeholder="Address" required></textarea>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="city" placeholder="Town / City" type="text" required>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="country" placeholder="State / Country" type="text" required>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="zip_code" placeholder="Postcode / Zip" type="text" required>
                                </div>
                                <div class="form-group row">
                                    <div class="col">
                                        <input class="form-control" name="email" placeholder="Email Address" type="email" required>
                                    </div>
                                    <div class="col">
                                        <input class="form-control" name="phone_number" placeholder="Phone Number" type="tel" required>
                                    </div>
                                </div>
                              
                                <div class="form-group">
                                    <textarea class="form-control" name="order_notes" placeholder="Order Notes" required></textarea>
                                </div>
                            </fieldset>
                            <button name="submit" type="submit" class="btn btn-primary float-left">PROCEED TO CHECKOUT <i class="fa fa-check"></i></button>
                        </form>
                    </div>
                    <div class="col-xs-12 col-sm-5">
                        <div class="holder">
                            <h5 class="mb-3">YOUR ORDER</h5>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Products</th>
                                            <th class="text-right">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($allProducts as $product) : ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($product->pro_title); ?> x<?php echo htmlspecialchars($product->pro_qty); ?>
                                                </td>
                                                <td class="text-right">
                                                    USD <?php echo number_format(floatval($product->pro_price) * intval($product->pro_qty), 2); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfooter>
                                        <tr>
                                            <td>
                                                <strong>Cart Subtotal</strong>
                                            </td>
                                            <td class="text-right">
                                                USD <?php echo number_format($cart_subtotal, 2); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <strong>Shipping</strong>
                                            </td>
                                            <td class="text-right">
                                                USD <?php echo number_format($shipping_cost, 2); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <strong>ORDER TOTAL</strong>
                                            </td>
                                            <td class="text-right">
                                                <strong>USD <?php echo number_format($total_price, 2); ?></strong>
                                            </td>
                                        </tr>
                                    </tfooter>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php require "../includes/footer.php"; ?>

