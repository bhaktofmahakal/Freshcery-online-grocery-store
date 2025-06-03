<?php 
    // Enable error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    require "../includes/header.php"; 
    require "../config/config.php";
    
    // Debug session
    error_log("Session data in settings: " . print_r($_SESSION, true));

    if(!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        error_log("Missing session data - username: " . (isset($_SESSION['username']) ? 'set' : 'not set') . 
                 ", user_id: " . (isset($_SESSION['user_id']) ? 'set' : 'not set'));
        echo "<script> window.location.href='".APPURL."'; </script>";
        exit();
    }

    if(isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $session_id = (int)$_SESSION['user_id'];
        error_log("Settings page - User ID from GET: " . $id);
        error_log("Settings page - Session user_id: " . $session_id);

        if($id !== $session_id) {
            error_log("ID mismatch: GET ID = " . $id . ", Session ID = " . $session_id);
            echo "<script> window.location.href='".APPURL."'; </script>";
            exit();
        }

        try {
            // Use prepared statement to prevent SQL injection
            $select = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $select->execute([$id]);
            $users = $select->fetch(PDO::FETCH_OBJ);
            
            if(!$users) {
                error_log("No user found with ID: " . $id);
                echo "<script>alert('User not found!'); window.location.href='".APPURL."';</script>";
                exit();
            }
            
            error_log("User data retrieved: " . print_r($users, true));
        } catch(PDOException $e) {
            error_log("Database error in settings: " . $e->getMessage());
            echo "<script>alert('Database error occurred!'); window.location.href='".APPURL."';</script>";
            exit();
        }

        if(isset($_POST['submit'])) {
            try {
                $fullname = trim($_POST['fullname']);
                $address = trim($_POST['address']);
                $city = trim($_POST['city']);
                $country = trim($_POST['country']);
                $zip_code = trim($_POST['zip_code']);
                $phone_number = trim($_POST['phone_number']);

                // Use prepared statement for update
                $update = $conn->prepare("UPDATE users SET 
                    fullname = ?, 
                    address = ?,
                    city = ?, 
                    country = ?, 
                    zip_code = ?, 
                    phone_number = ? 
                    WHERE id = ?");

                $update->execute([
                    $fullname,
                    $address,
                    $city,
                    $country,
                    $zip_code,
                    $phone_number,
                    $id
                ]);

                echo "<script>alert('Profile updated successfully!'); window.location.href='".APPURL."';</script>";
            } catch(PDOException $e) {
                error_log("Update error in settings: " . $e->getMessage());
                echo "<script>alert('Error updating profile!');</script>";
            }
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
                        Settings
                    </h1>
                    <p class="lead">
                        Update Your Account Info
                    </p>
                </div>
            </div>
        </div>

        <section id="checkout">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-xs-12 col-sm-6">
                        <h5 class="mb-3">ACCOUNT DETAILS</h5>
                        <!-- Bill Detail of the Page -->
                        <form action="setting.php?id=<?php echo htmlspecialchars($id); ?>" method="POST" class="bill-detail">
                            <fieldset>
                                <div class="form-group row">
                                    <div class="col">
                                        <input class="form-control" placeholder="Full Name" name="fullname" value="<?php echo htmlspecialchars($users->fullname); ?>" type="text" required>
                                    </div>
                                </div>
                               
                                <div class="form-group">
                                    <textarea class="form-control" name="address" placeholder="Address" required><?php echo htmlspecialchars($users->address); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="city" value="<?php echo htmlspecialchars($users->city); ?>" placeholder="Town / City" type="text" required>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="country" value="<?php echo htmlspecialchars($users->country); ?>" placeholder="State / Country" type="text" required>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="zip_code" value="<?php echo htmlspecialchars($users->zip_code); ?>" placeholder="Postcode / Zip" type="text" required>
                                </div>
                                <div class="form-group">
                                    <input class="form-control" name="phone_number" value="<?php echo htmlspecialchars($users->phone_number); ?>" placeholder="Phone Number" type="tel" required>     
                                </div>
                              
                                <div class="form-group text-right">
                                    <button type="submit" name="submit" class="btn btn-primary">UPDATE</button>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php require "../includes/footer.php"; ?>

